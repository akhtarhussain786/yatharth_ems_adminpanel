<?php
/**
 * Applies schema changes from PHP, once each, for deployments where no SQL
 * console is available.
 *
 * The project already carried ad-hoc "SHOW COLUMNS then ALTER" blocks that ran
 * on every single request. This does the same job but records what it has
 * applied in `schema_migrations`, so the steady-state cost is one indexed
 * SELECT per request instead of a pile of DDL probes.
 */
class SchemaGuard
{
    private static $applied = null;

    /** Runs $fn once, ever, for the given $key. Never fatal: a failed migration is logged and retried next request. */
    public static function ensure($db, $key, callable $fn)
    {
        try {
            self::loadApplied($db);
            if (isset(self::$applied[$key])) return;

            $fn($db);

            $db->prepare("INSERT INTO schema_migrations (migration_key) VALUES (?)")->execute([$key]);
            self::$applied[$key] = true;
        } catch (Throwable $e) {
            error_log("SchemaGuard[$key]: " . $e->getMessage());
        }
    }

    private static function loadApplied($db)
    {
        if (self::$applied !== null) return;

        // Read first: once the table exists — which is the normal case on every
        // request after the first — this costs one SELECT and no DDL at all.
        try {
            self::$applied = self::readApplied($db);
            return;
        } catch (Throwable $e) {
            // table not there yet; fall through and create it
        }

        // Latch immediately so a CREATE that is not permitted is attempted once
        // per request, not once per migration.
        self::$applied = [];

        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            migration_key VARCHAR(100) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        self::$applied = self::readApplied($db);
    }

    private static function readApplied($db)
    {
        $applied = [];
        foreach ($db->query("SELECT migration_key FROM schema_migrations")->fetchAll() as $row) {
            $applied[$row['migration_key']] = true;
        }
        return $applied;
    }

    /**
     * Creates a table, and brings an existing one up to the same definition.
     *
     * `CREATE TABLE IF NOT EXISTS` does nothing at all when the table is
     * already there, so a table created by an older schema keeps its old
     * columns forever and the code that writes the new ones fails with
     * "Unknown column". That is exactly how duty_logs.start_selfie and
     * travel_requests.start_photo came to be missing on the live database
     * while every migration reported success.
     *
     * Passing the CREATE through here instead keeps one definition and adds
     * whatever an existing table is missing, so the two can never drift.
     * Columns are only ever added — nothing is dropped or retyped, because a
     * migration that runs unattended on live data has no business doing either.
     */
    public static function reconcile($db, $createSql)
    {
        $db->exec($createSql);

        if (!preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?\s*\(/i', $createSql, $m)) {
            error_log('SchemaGuard reconcile: could not read the table name');
            return;
        }
        self::addColumns($db, $m[1], self::columnsIn($createSql));
    }

    /**
     * Column name => definition, read out of a CREATE TABLE statement.
     *
     * Splits on top-level commas only, so DECIMAL(10,8) and ENUM('a','b') stay
     * intact, and skips the key and constraint clauses.
     */
    public static function columnsIn($createSql)
    {
        $open = strpos($createSql, '(');
        $body = substr($createSql, $open + 1, strrpos($createSql, ')') - $open - 1);

        $parts = [];
        $depth = 0;
        $current = '';
        $quote = null;

        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $c = $body[$i];

            // Commas inside a quoted ENUM value are not separators either.
            if ($quote !== null) {
                if ($c === $quote) $quote = null;
                $current .= $c;
                continue;
            }
            if ($c === "'" || $c === '"') { $quote = $c; $current .= $c; continue; }

            if ($c === '(') $depth++;
            if ($c === ')') $depth--;

            if ($c === ',' && $depth === 0) { $parts[] = $current; $current = ''; continue; }
            $current .= $c;
        }
        $parts[] = $current;

        $columns = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|FULLTEXT)\b/i', $part)) continue;
            if (!preg_match('/^`?(\w+)`?\s+(.+)$/s', $part, $m)) continue;

            // AUTO_INCREMENT only makes sense on a key, which an added column
            // is not; such a column belongs to the create, never to a backfill.
            if (stripos($m[2], 'AUTO_INCREMENT') !== false) continue;

            $columns[$m[1]] = trim(preg_replace('/\s+/', ' ', $m[2]));
        }
        return $columns;
    }

    /** Adds columns that are missing. $columns maps name => column definition. */
    public static function addColumns($db, $table, array $columns)
    {
        $existing = self::columnsOf($db, $table);
        if ($existing === null) return; // table absent; a create-migration owns it

        foreach ($columns as $name => $definition) {
            if (in_array($name, $existing, true)) continue;
            try {
                $db->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
            } catch (Throwable $e) {
                error_log("SchemaGuard addColumn $table.$name: " . $e->getMessage());
            }
        }
    }

    /** Widens an ENUM/VARCHAR column only when the column exists. */
    public static function modifyColumn($db, $table, $column, $definition)
    {
        $existing = self::columnsOf($db, $table);
        if ($existing === null || !in_array($column, $existing, true)) return;
        try {
            $db->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
        } catch (Throwable $e) {
            error_log("SchemaGuard modifyColumn $table.$column: " . $e->getMessage());
        }
    }

    /**
     * Removes a foreign key, if the table has one by that name.
     *
     * Used where a constraint contradicts how the column is actually used —
     * leads.created_by holds an employee id but was constrained to users(id),
     * so creating a lead failed outright whenever the two happened not to
     * coincide. Dropping the constraint is the honest fix; retyping the column
     * would mean rewriting live data on a guess about which id each row holds.
     */
    public static function dropForeignKey($db, $table, $constraint)
    {
        try {
            $sql = "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                      AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'";
            $stmt = $db->prepare($sql);
            $stmt->execute([$table, $constraint]);
            if (!$stmt->fetch()) return;   // already gone, or never existed

            $db->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$constraint`");
        } catch (Throwable $e) {
            error_log("SchemaGuard dropForeignKey $table.$constraint: " . $e->getMessage());
        }
    }

    /** Column names of $table, or null when the table does not exist. */
    public static function columnsOf($db, $table)
    {
        try {
            $rows = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        } catch (Throwable $e) {
            return null;
        }
        return array_column($rows, 'Field');
    }

    public static function tableExists($db, $table)
    {
        return self::columnsOf($db, $table) !== null;
    }
}
