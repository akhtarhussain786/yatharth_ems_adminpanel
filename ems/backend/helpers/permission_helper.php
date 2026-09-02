<?php
class PermissionHelper {
    /**
     * Modules a role can always reach, keyed by role name, used only when the
     * permissions table has no row at all for that role and module.
     *
     * This exists because the seeded permissions never granted the telecaller
     * roles the 'leads' module — yet leads are auto-assigned to exactly those
     * roles, so the assignee got a hard 403 on the list they were meant to work
     * from. The map is deliberately narrow: it only ever grants the modules a
     * role's own screens need, and it can never widen an explicit DB row,
     * because it is consulted only when no row exists.
     *
     * Delete an entry here once the equivalent row exists in `permissions`.
     */
    private static $roleModuleFallback = [
        'telecaller'       => ['leads', 'call_reports', 'follow_ups', 'telecaller'],
        'telecaller_admin' => ['leads', 'call_reports', 'follow_ups', 'telecaller'],
        'sales_executive'  => ['leads', 'follow_ups', 'sales', 'meetings'],
        'sales_manager'    => ['leads', 'follow_ups', 'sales', 'meetings'],
        'sales_admin'      => ['leads', 'follow_ups', 'sales', 'meetings'],
        'digital_marketing'       => ['leads', 'campaigns', 'marketing'],
        'digital_marketing_admin' => ['leads', 'campaigns', 'marketing'],
    ];

    /**
     * Modules the seeds name inconsistently.
     *
     * schema.sql and migration.sql grant 'leave_requests'; migration_rbac.sql
     * and migration_ems.sql grant 'leaves' — and the code asks for
     * 'leave_requests'. Depending on which seed ran last, leave approval was
     * refused for everyone below super_admin. A permission on either name counts.
     */
    private static $moduleAliases = [
        'leave_requests' => ['leaves'],
        'leaves'         => ['leave_requests'],
        'daily_work_reports' => ['work_reports'],
        'work_reports'       => ['daily_work_reports'],
    ];

    private static function moduleNames($module) {
        return array_merge([$module], self::$moduleAliases[$module] ?? []);
    }

    /** True when no row exists for this role+module, so the fallback may apply. */
    private static function hasExplicitRow($db, $roleId, $module) {
        $stmt = $db->prepare("SELECT 1 FROM permissions WHERE role_id = ? AND module = ? LIMIT 1");
        $stmt->execute([$roleId, $module]);
        return (bool) $stmt->fetch();
    }

    private static function fallbackGrants($role, $module) {
        return isset(self::$roleModuleFallback[$role])
            && in_array($module, self::$roleModuleFallback[$role], true);
    }

    public static function hasPermission($db, $roleId, $module, $action = 'can_view', $role = null) {
        $allowed = ['can_view', 'can_create', 'can_edit', 'can_delete'];
        if (!in_array($action, $allowed, true)) $action = 'can_view';

        $names = self::moduleNames($module);
        $in = implode(',', array_fill(0, count($names), '?'));

        $stmt = $db->prepare("SELECT MAX($action) AS granted FROM permissions
                              WHERE role_id = ? AND module IN ($in)");
        $stmt->execute(array_merge([$roleId], $names));
        $result = $stmt->fetch();

        // MAX() returns NULL when no row matched any of the names.
        if ($result && $result['granted'] !== null) return $result['granted'] == 1;

        // No row at all — fall back to the role's own modules. Deletion stays
        // admin-only so the fallback cannot hand out destructive rights.
        if ($role !== null && self::fallbackGrants($role, $module)) {
            return $action !== 'can_delete';
        }
        return false;
    }

    public static function checkPermission($db, $auth, $module, $action = 'can_view') {
        $roleId = $auth['role_id'];
        $role = $auth['role'];
        if ($role === 'super_admin') return true;
        if (!self::hasPermission($db, $roleId, $module, $action, $role)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied: insufficient permissions']);
            exit;
        }
        return true;
    }

    /**
     * Adds the role's fallback modules to a permission map, without overwriting
     * anything the permissions table already states. Keeps what the app is told
     * at login consistent with what checkPermission() will actually allow.
     */
    public static function applyRoleFallback(array $permissions, $role) {
        if (!isset(self::$roleModuleFallback[$role])) return $permissions;

        foreach (self::$roleModuleFallback[$role] as $module) {
            if (isset($permissions[$module])) continue;
            $permissions[$module] = [
                'can_view' => true,
                'can_create' => true,
                'can_edit' => true,
                'can_delete' => false,
            ];
        }
        return $permissions;
    }

    public static function getUserPermissions($db, $roleId) {
        $stmt = $db->prepare("SELECT module, can_view, can_create, can_edit, can_delete FROM permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);
        $perms = [];
        foreach ($stmt->fetchAll() as $row) {
            $perms[$row['module']] = [
                'can_view' => (bool)$row['can_view'],
                'can_create' => (bool)$row['can_create'],
                'can_edit' => (bool)$row['can_edit'],
                'can_delete' => (bool)$row['can_delete'],
            ];
        }
        return $perms;
    }

    public static function getRolePermissionsForAdmin($db, $roleId) {
        $stmt = $db->prepare("
            SELECT p.*, r.name as role_name, r.description 
            FROM permissions p 
            JOIN roles r ON r.id = p.role_id 
            WHERE p.role_id = ?
            ORDER BY p.module
        ");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll();
    }

    public static function getAllRoles($db) {
        return $db->query("SELECT id, name, description FROM roles ORDER BY id")->fetchAll();
    }
}
