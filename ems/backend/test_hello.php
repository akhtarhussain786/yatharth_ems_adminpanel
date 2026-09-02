<?php
// Diagnostic test - verify PHP, Database, JWT are all working
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$result = ['success' => true, 'tests' => []];

// Test 1: PHP version
$result['tests'][] = [
    'name' => 'PHP Version',
    'status' => 'ok',
    'detail' => phpversion(),
];

// Test 2: Required extensions
$extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'hash'];
foreach ($extensions as $ext) {
    $result['tests'][] = [
        'name' => "Extension: $ext",
        'status' => extension_loaded($ext) ? 'ok' : 'missing',
    ];
}

// Test 3: Database connection
try {
    require_once __DIR__ . '/config/database.php';
    $db = (new Database())->getConnection();
    $stmt = $db->query("SELECT DATABASE() as db");
    $row = $stmt->fetch();
    $result['tests'][] = [
        'name' => 'Database Connection',
        'status' => 'ok',
        'detail' => 'Connected to: ' . ($row['db'] ?? 'unknown'),
    ];

    // Check attendance table columns
    $stmt = $db->query("SHOW COLUMNS FROM attendance");
    $columns = $stmt->fetchAll();
    $colNames = array_column($columns, 'Field');
    $expected = ['latitude', 'longitude', 'address', 'status', 'check_in', 'check_out', 'attendance_date'];
    $missing = array_diff($expected, $colNames);
    if ($missing) {
        $result['tests'][] = [
            'name' => 'Attendance Table Columns',
            'status' => 'missing',
            'detail' => 'Missing columns: ' . implode(', ', $missing),
        ];
    } else {
        $result['tests'][] = [
            'name' => 'Attendance Table Columns',
            'status' => 'ok',
            'detail' => 'All required columns present',
        ];
    }
} catch (Exception $e) {
    $result['tests'][] = [
        'name' => 'Database Connection',
        'status' => 'fail',
        'detail' => $e->getMessage(),
    ];
    $result['success'] = false;
}

// Test 4: JWT helper
try {
    require_once __DIR__ . '/helpers/jwt_helper.php';
    if (class_exists('JWT')) {
        // Encode and decode a test token
        $payload = ['user_id' => 1, 'test' => true];
        $token = JWT::encode($payload);
        $decoded = JWT::decode($token);
        $result['tests'][] = [
            'name' => 'JWT Helper',
            'status' => 'ok',
            'detail' => 'Encode/decode successful',
        ];
    } else {
        $result['tests'][] = ['name' => 'JWT Helper', 'status' => 'missing', 'detail' => 'JWT class not found'];
    }
} catch (Exception $e) {
    $result['tests'][] = ['name' => 'JWT Helper', 'status' => 'fail', 'detail' => $e->getMessage()];
    $result['success'] = false;
}

$result['summary'] = $result['success'] ? 'All checks passed' : 'Some checks failed - see tests above';

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
