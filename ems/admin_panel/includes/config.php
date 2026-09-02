<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

define('DB_HOST', 'localhost');
define('DB_NAME', 'yatharth_ems_db');
define('DB_USER', 'yatharth_yatharth_ems_db1');
define('DB_PASS', 'ems_db@1122');
define('BASE_URL', '/ems/admin_panel/');
define('BACKEND_URL', '/ems/backend/');
define('SESSION_EXPIRY', 43200); // 12 hours in seconds
define('SALT_KEY', 'eams_admin_salt_2026_secure');

date_default_timezone_set('Asia/Kolkata');

function formatMinutes($minutes) {
    if (!$minutes || $minutes <= 0) return '-';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h > 0) return $h . 'h ' . $m . 'm';
    return $m . 'm';
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// The admin panel and the API share one database, so they must share one set of
// migrations. Previously only the API ran them, which meant a page here could
// query a table that existed only after the phone app had happened to call the
// backend first. Each step runs once and is then recorded, so the steady-state
// cost is a single indexed SELECT.
try {
    require_once __DIR__ . '/../../backend/helpers/schema_migrations.php';
    runSchemaMigrations($pdo);
} catch (Throwable $e) {
    // A failed migration must not take the admin panel down; the pages that
    // need a missing table handle its absence themselves.
    error_log('Admin panel schema migrations: ' . $e->getMessage());
}

// Auto-migrate: add is_field_staff column if missing
try {
    $cols = $pdo->query("SHOW COLUMNS FROM employees");
    $existing = [];
    while ($c = $cols->fetch()) $existing[] = $c['Field'];
    if (!in_array('is_field_staff', $existing)) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN is_field_staff TINYINT(1) DEFAULT 0 AFTER status");
    }
} catch (Exception $e) {
    error_log("Employees migration is_field_staff: " . $e->getMessage());
}

// =============================================
// Flash Messages System
// =============================================
function setFlash($message, $type = 'success') {
    $_SESSION['_flash'] = ['message' => $message, 'type' => $type];
}

function getFlash() {
    if (isset($_SESSION['_flash'])) {
        $flash = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $flash;
    }
    return null;
}

function displayFlash() {
    $flash = getFlash();
    if ($flash) {
        $type = $flash['type'] === 'error' ? 'danger' : $flash['type'];
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show py-2" role="alert">';
        echo '<i class="fas fa-' . ($type === 'success' ? 'check-circle' : 'exclamation-circle') . ' me-2"></i>';
        echo htmlspecialchars($flash['message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }
}

// =============================================
// Auto-Logout / Session Timeout Check
// =============================================
function checkSessionTimeout() {
    if (!isset($_SESSION['admin_id'])) return;
    if (basename($_SERVER['PHP_SELF']) === 'index.php') return;

    $timeout = SESSION_EXPIRY;
    $lastActivity = $_SESSION['last_activity'] ?? 0;

    if ($lastActivity > 0 && (time() - $lastActivity) > $timeout) {
        $_SESSION = [];
        session_destroy();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        header("Location: " . BASE_URL . "index.php?timeout=1&rc=0");
        exit;
    }

    $_SESSION['last_activity'] = time();
}

function getRedirectCount() {
    return isset($_GET['rc']) ? (int)$_GET['rc'] : 0;
}

function buildRedirectUrl($path, $count = 0) {
    $separator = (strpos($path, '?') === false) ? '?' : '&';
    return $path . $separator . 'rc=' . ($count + 1);
}

// =============================================
// Auth Helpers
// =============================================
function isLoggedIn() {
    return isset($_SESSION['admin_id']);
}

function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Web URL for a file the backend saved under its uploads directory.
 *
 * Paths are stored relative to the backend root ("uploads/marketing/duty/x.jpg"),
 * but some older rows hold a bare filename instead, which is what $fallbackDir
 * is for. A full URL is returned unchanged.
 */
function uploadedFileUrl($path, $fallbackDir = '') {
    if (empty($path)) return null;
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) return $path;

    $path = ltrim($path, '/');
    $base = rtrim(BACKEND_URL, '/');

    if (strpos($path, 'uploads/') === 0) return $base . '/' . $path;
    return $fallbackDir === ''
        ? $base . '/' . $path
        : $base . '/uploads/' . trim($fallbackDir, '/') . '/' . $path;
}

/**
 * A round thumbnail that opens the full photo, or a muted placeholder when
 * there is no photo.
 *
 * The onerror handler matters: a row can hold a path whose file was never
 * written — an upload that failed after the record was saved — and without it
 * the page shows a broken-image icon with no explanation.
 *
 * Pages using this must include includes/photo_modal.php once, which carries
 * the styling and the viewer this links to.
 */
function photoThumb($path, $caption, $icon = 'fa-image', $fallbackDir = '') {
    $url = uploadedFileUrl($path, $fallbackDir);
    if (!$url) {
        echo '<div class="photo-thumb-empty" title="No photo"><i class="fas ' . $icon . '"></i></div>';
        return;
    }
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeCap = htmlspecialchars($caption, ENT_QUOTES, 'UTF-8');
    echo '<a href="#" onclick="viewPhoto(\'' . $safeUrl . '\', \'' . $safeCap . '\'); return false;" title="' . $safeCap . '">'
       . '<img src="' . $safeUrl . '" class="photo-thumb" alt="' . $safeCap . '"'
       . ' onerror="this.outerHTML=\'&lt;div class=&quot;photo-thumb-empty&quot; title=&quot;Photo missing&quot;&gt;&lt;i class=&quot;fas fa-triangle-exclamation&quot;&gt;&lt;/i&gt;&lt;/div&gt;\'">'
       . '</a>';
}

/**
 * A date from the query string, or today.
 *
 * `$_GET['date'] ?? date('Y-m-d')` looks like it does this, but ?? only
 * catches null — clearing the filter field submits an empty string, which
 * went into the query as a DATE literal and MySQL rejected it outright
 * (error 1525), taking the whole page down with a raw SQL error.
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

/**
 * A month (YYYY-MM) from the query string, or the current one. Same reason.
 */
if (!function_exists('filterMonth')) {
    function filterMonth($value, $default = null)
    {
        $value = trim((string) $value);
        $d = DateTime::createFromFormat('Y-m', $value);
        if ($d !== false && $d->format('Y-m') === $value) return $value;
        return $default ?? date('Y-m');
    }
}

/**
 * Renders attendance.working_hours (a TIME) as "9h 0m".
 *
 * The column stores HH:MM:SS; the pages want the short form, and a few rows
 * predate the fix and may still hold something else, so anything unparseable
 * is passed through as it is.
 */
function formatWorkedHours($value)
{
    $value = trim((string) $value);
    if ($value === '' || $value === '00:00:00') return null;
    if (preg_match('/^(\d{1,3}):(\d{2})(?::\d{2})?$/', $value, $m)) {
        return ((int) $m[1]) . 'h ' . ((int) $m[2]) . 'm';
    }
    return $value;
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// =============================================
// RBAC Functions
// =============================================
function hasModuleAccess($module, $action = 'can_view') {
    global $pdo;
    if (!isset($_SESSION['admin_role'])) return false;
    if ($_SESSION['admin_role'] === 'super_admin') return true;

    // The seeds name some modules two different ways — 'leave_requests' vs
    // 'leaves', 'daily_work_reports' vs 'work_reports' — so a permission granted
    // under either name counts. Without this, whether leave approval worked
    // depended on which SQL seed was applied last.
    $aliases = [
        'leave_requests'     => ['leaves'],
        'leaves'             => ['leave_requests'],
        'daily_work_reports' => ['work_reports'],
        'work_reports'       => ['daily_work_reports'],
    ];
    $names = array_merge([$module], $aliases[$module] ?? []);

    try {
        $in = implode(',', array_fill(0, count($names), '?'));
        $stmt = $pdo->prepare("SELECT MAX($action) AS granted FROM permissions
                               WHERE role_id = ? AND module IN ($in)");
        $stmt->execute(array_merge([$_SESSION['admin_role_id']], $names));
        $result = $stmt->fetch();
        return $result && $result['granted'] !== null && $result['granted'] == 1;
    } catch (Exception $e) {
        return false;
    }
}

function requireModuleAccess($module, $action = 'can_view') {
    if (!hasModuleAccess($module, $action)) {
        if (!isLoggedIn()) redirect('index.php');
        echo '<div class="alert alert-danger m-4">Access denied: insufficient permissions</div>';
        require_once 'footer.php';
        exit;
    }
}

function getAdminRoleName() {
    return $_SESSION['admin_role'] ?? '';
}

function getAdminRoleId() {
    return $_SESSION['admin_role_id'] ?? 0;
}

// =============================================
// Permission Helpers
// =============================================
function canView($module) { return hasModuleAccess($module, 'can_view'); }
function canCreate($module) { return hasModuleAccess($module, 'can_create'); }
function canEdit($module) { return hasModuleAccess($module, 'can_edit'); }
function canDelete($module) { return hasModuleAccess($module, 'can_delete'); }

function requireView($module) { requireModuleAccess($module, 'can_view'); }
function requireCreate($module) { requireModuleAccess($module, 'can_create'); }
function requireEdit($module) { requireModuleAccess($module, 'can_edit'); }
function requireDelete($module) { requireModuleAccess($module, 'can_delete'); }

// =============================================
// Token Helpers
// =============================================
function generateToken() {
    return bin2hex(random_bytes(32));
}

function validateAdminToken($token) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE api_token = ? AND token_expiry > NOW() AND is_logged_in = 1 AND status = 1 LIMIT 1");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

// =============================================
// Menu config: module => [label, icon, link, required_module]
// =============================================
function getMenuItems() {
    $menus = [
        ['module' => '', 'label' => 'Dashboard', 'icon' => 'chart-bar', 'link' => 'dashboard.php'],
        ['module' => 'employees', 'label' => 'Employees', 'icon' => 'users', 'link' => 'modules/employees.php'],
        ['module' => 'attendance', 'label' => 'Attendance', 'icon' => 'calendar-check', 'link' => 'modules/attendance.php'],
        ['module' => 'departments', 'label' => 'Departments', 'icon' => 'building', 'link' => 'modules/departments.php'],
        ['module' => 'leave_requests', 'label' => 'Leave Requests', 'icon' => 'envelope-open-text', 'link' => 'modules/leave_requests.php'],
        ['module' => 'leave_requests', 'label' => 'Leave Calendar', 'icon' => 'calendar-day', 'link' => 'modules/leave_calendar.php'],
        ['module' => 'holidays', 'label' => 'Holidays', 'icon' => 'calendar-alt', 'link' => 'modules/holidays.php'],
        ['module' => 'daily_work_reports', 'label' => 'Work Reports', 'icon' => 'file-signature', 'link' => 'modules/work_reports.php'],
        ['module' => 'tasks', 'label' => 'Tasks', 'icon' => 'tasks', 'link' => 'modules/tasks.php'],
        ['module' => 'leads', 'label' => 'Leads', 'icon' => 'users-cog', 'link' => 'modules/leads.php'],
        ['module' => 'campaigns', 'label' => 'Campaigns', 'icon' => 'bullhorn', 'link' => 'modules/campaigns.php'],
        ['module' => 'call_reports', 'label' => 'Call Reports', 'icon' => 'phone-alt', 'link' => 'modules/call_reports.php'],
        ['module' => 'follow_ups', 'label' => 'Follow Ups', 'icon' => 'clock', 'link' => 'modules/follow_ups.php'],
        ['module' => 'hr_activities', 'label' => 'HR Activities', 'icon' => 'handshake', 'link' => 'modules/hr_activities.php'],
        ['module' => 'salary', 'label' => 'Salary', 'icon' => 'money-bill-wave', 'link' => 'modules/salary.php'],
        ['module' => 'reports', 'label' => 'Reports', 'icon' => 'file-alt', 'link' => 'modules/reports.php'],
        ['module' => 'travel', 'label' => 'Travel', 'icon' => 'plane', 'link' => 'modules/travel.php'],
        ['module' => 'expenses', 'label' => 'Expenses', 'icon' => 'receipt', 'link' => 'modules/expenses.php'],
        ['module' => 'documents', 'label' => 'Documents', 'icon' => 'file-alt', 'link' => 'modules/documents.php'],
        ['module' => 'assets', 'label' => 'Assets', 'icon' => 'box', 'link' => 'modules/assets.php'],
        ['module' => 'notices', 'label' => 'Notices', 'icon' => 'bullhorn', 'link' => 'modules/notices.php'],
        ['module' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell', 'link' => 'modules/notifications.php'],
        ['module' => 'meetings', 'label' => 'Meetings', 'icon' => 'calendar', 'link' => 'modules/meetings.php'],
        ['module' => 'help', 'label' => 'Help Desk', 'icon' => 'headset', 'link' => 'modules/help.php'],
        ['module' => 'downloads', 'label' => 'Downloads', 'icon' => 'download', 'link' => 'modules/downloads.php'],
        ['module' => 'accounts', 'label' => 'Accounts', 'icon' => 'wallet', 'link' => 'modules/accounts.php'],
        ['module' => 'marketing', 'label' => 'Marketing', 'icon' => 'chart-line', 'link' => 'modules/marketing.php'],
        ['module' => 'it_team', 'label' => 'IT Team', 'icon' => 'laptop-code', 'link' => 'modules/it_team.php'],
        ['module' => 'roles', 'label' => 'Roles', 'icon' => 'shield-alt', 'link' => 'modules/roles.php'],
        ['module' => 'settings', 'label' => 'App Update', 'icon' => 'mobile-alt', 'link' => 'modules/app_update.php'],
        ['module' => 'settings', 'label' => 'Settings', 'icon' => 'cog', 'link' => 'modules/settings.php'],
    ];
    return $menus;
}

$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Run session timeout check on every page load
checkSessionTimeout();
