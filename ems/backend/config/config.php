<?php
// CORS
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// JWT Secret Key
define('JWT_SECRET', 'eams_jwt_secret_key_2026_secure_!@#$');
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRY', 43200); // 12 hours (was 24 hours)
define('SESSION_EXPIRY', 43200); // 12 hours session expiry

// App config
define('APP_NAME', 'EAMS');
define('APP_VERSION', '1.0.0');
define('OFFICE_LATITUDE', 22.804566);
define('OFFICE_LONGITUDE', 86.202875);
define('OFFICE_RADIUS', 500); // 500 meters - zyada area cover karega
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', "$scheme://$host/ems/backend/");

// Timezone
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../helpers/jwt_helper.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/error_messages.php';
require_once __DIR__ . '/../helpers/permission_helper.php';
require_once __DIR__ . '/../helpers/attendance_rules.php';
require_once __DIR__ . '/../helpers/face_match.php';
require_once __DIR__ . '/../helpers/schema_migrations.php';
require_once __DIR__ . '/../helpers/fcm_helper.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Bring the schema up to what the code actually writes. Each step runs once and
// is then recorded, so the steady-state cost is one indexed SELECT per request
// rather than a SHOW COLUMNS + ALTER probe on every call.
try {
    runSchemaMigrations((new Database())->getConnection());
} catch (Throwable $e) {
    error_log("Schema migration: " . $e->getMessage());
}

/**
 * A date (Y-m-d) or month (Y-m) from a request, or a sane default.
 *
 * `$_GET['date'] ?? date('Y-m-d')` only substitutes when the key is absent.
 * A client that sends the parameter empty — a cleared filter field — passed
 * '' straight into a DATE comparison, which MySQL rejects with error 1525
 * and the whole request failed.
 */
if (!function_exists('filterDate')) {
    function filterDate($value, $default = null)
    {
        $value = trim((string) $value);
        $d = DateTime::createFromFormat('Y-m-d', $value);
        if ($d !== false && $d->format('Y-m-d') === $value) return $value;
        return $default ?? date('Y-m-d');
    }
}

if (!function_exists('filterMonth')) {
    function filterMonth($value, $default = null)
    {
        $value = trim((string) $value);
        $d = DateTime::createFromFormat('Y-m', $value);
        if ($d !== false && $d->format('Y-m') === $value) return $value;
        return $default ?? date('Y-m');
    }
}
