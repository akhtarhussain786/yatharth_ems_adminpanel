<?php
session_start();

// Clean up session from database if possible
if (isset($_SESSION['admin_id'])) {
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=yatharth_ems_db;charset=utf8mb4",
            'yatharth_yatharth_ems_db1',
            'ems_db@1122',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->prepare("UPDATE user_sessions SET is_active = 0, logout_time = NOW() WHERE user_id = ? AND is_active = 1")
            ->execute([$_SESSION['admin_id']]);
        $pdo->prepare("UPDATE users SET api_token = NULL, token_expiry = NULL, is_logged_in = 0, last_activity = NOW() WHERE id = ?")
            ->execute([$_SESSION['admin_id']]);
    } catch (Exception $e) {
        // Non-critical
    }
}

$_SESSION = [];
session_destroy();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
header("Location: index.php");
exit;
