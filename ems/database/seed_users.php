<?php
// Run: php C:\xampp\htdocs\ems\database\seed_users.php
$host = 'localhost';
$dbname = 'ems_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $accounts = [
        ['username' => 'hr_admin',       'email' => 'hr@ems.com',         'password' => 'hr@123',       'role_id' => 6,  'role_name' => 'HR Admin',            'first_name' => 'HR',       'last_name' => 'Admin'],
        ['username' => 'marketing_admin', 'email' => 'marketing@ems.com', 'password' => 'marketing@123','role_id' => 7, 'role_name' => 'Marketing Admin',     'first_name' => 'Marketing', 'last_name' => 'Admin'],
        ['username' => 'telecaller_admin','email' => 'telecaller@ems.com','password' => 'telecaller@123','role_id' => 8,'role_name' => 'Telecaller Admin',   'first_name' => 'Telecaller','last_name' => 'Admin'],
        ['username' => 'accounts_admin', 'email' => 'accounts@ems.com',  'password' => 'accounts@123', 'role_id' => 9,  'role_name' => 'Accounts Admin',       'first_name' => 'Accounts', 'last_name' => 'Admin'],
        ['username' => 'sales_admin',    'email' => 'sales@ems.com',     'password' => 'sales@123',    'role_id' => 10, 'role_name' => 'Sales Admin',         'first_name' => 'Sales',    'last_name' => 'Admin'],
        ['username' => 'manager1',       'email' => 'manager@ems.com',    'password' => 'manager@123',  'role_id' => 4,  'role_name' => 'Manager',             'first_name' => 'Manager',  'last_name' => 'User'],
    ];

    $inserted = 0;
    foreach ($accounts as $acc) {
        // Check if user already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$acc['username'], $acc['email']]);
        if ($stmt->fetch()) {
            echo "SKIP: {$acc['username']} already exists\n";
            continue;
        }

        $hashed = password_hash($acc['password'], PASSWORD_BCRYPT);

        $pdo->beginTransaction();

        // Insert user
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role_id, status) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$acc['username'], $acc['email'], $hashed, $acc['role_id']]);
        $userId = $pdo->lastInsertId();

        // Insert employee record
        $empCode = $acc['username'];
        $stmt = $pdo->prepare("INSERT INTO employees (user_id, employee_code, first_name, last_name, email, joining_date, status) VALUES (?, ?, ?, ?, ?, CURDATE(), 1)");
        $stmt->execute([$userId, $empCode, $acc['first_name'], $acc['last_name'], $acc['email']]);

        $pdo->commit();
        echo "CREATED: {$acc['username']} / {$acc['password']} (role_id={$acc['role_id']})\n";
        $inserted++;
    }

    echo "\nDone. $inserted new users created.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
}
