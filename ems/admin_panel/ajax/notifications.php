<?php
require_once '../includes/config.php';
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? 'poll';
$adminId = intval($_SESSION['admin_id'] ?? 0);

try {
    if ($action === 'poll') {
        $lastId = intval($_GET['last_id'] ?? 0);

        // Fetch unread count for current admin
        $cntStmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count 
            FROM notifications 
            WHERE (user_id = ? OR send_to = 'all' OR user_id IS NULL) 
            AND (is_read = 0 OR is_read IS NULL)
        ");
        $cntStmt->execute([$adminId]);
        $unreadCount = intval($cntStmt->fetchColumn() ?: 0);

        // Fetch recent notifications (top 15)
        $listStmt = $pdo->prepare("
            SELECT n.*, CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) as employee_name
            FROM notifications n
            LEFT JOIN employees e ON e.user_id = n.user_id
            WHERE (n.user_id = ? OR n.send_to = 'all' OR n.user_id IS NULL)
            ORDER BY n.id DESC
            LIMIT 15
        ");
        $listStmt->execute([$adminId]);
        $recent = $listStmt->fetchAll();

        // Max ID currently in database for this admin
        $maxId = 0;
        foreach ($recent as $r) {
            if (intval($r['id']) > $maxId) {
                $maxId = intval($r['id']);
            }
        }

        // Format links and icons
        foreach ($recent as &$item) {
            $item['is_read'] = intval($item['is_read'] ?? 0);
            $type = strtolower($item['type'] ?? 'general');

            // Ensure destination URL
            if (empty($item['link']) || $item['link'] === '#') {
                if ($type === 'lead') {
                    $item['target_url'] = BASE_URL . 'modules/leads.php';
                    $item['action_label'] = 'View Lead';
                } elseif ($type === 'leave') {
                    $item['target_url'] = BASE_URL . 'modules/leave_requests.php';
                    $item['action_label'] = 'View Leave';
                } elseif ($type === 'attendance') {
                    $item['target_url'] = BASE_URL . 'modules/attendance.php';
                    $item['action_label'] = 'View Attendance';
                } elseif ($type === 'task') {
                    $item['target_url'] = BASE_URL . 'modules/tasks.php';
                    $item['action_label'] = 'View Task';
                } elseif ($type === 'work' || $type === 'work_report') {
                    $item['target_url'] = BASE_URL . 'modules/work_reports.php';
                    $item['action_label'] = 'View Report';
                } else {
                    $item['target_url'] = BASE_URL . 'modules/notifications.php';
                    $item['action_label'] = 'View';
                }
            } else {
                $link = $item['link'];
                if (strpos($link, '/leads') !== false) {
                    $item['target_url'] = BASE_URL . 'modules/leads.php';
                    $item['action_label'] = 'View Lead';
                } elseif (strpos($link, '/leaves') !== false) {
                    $item['target_url'] = BASE_URL . 'modules/leave_requests.php';
                    $item['action_label'] = 'View Leave';
                } elseif (strpos($link, '/attendance') !== false) {
                    $item['target_url'] = BASE_URL . 'modules/attendance.php';
                    $item['action_label'] = 'View Attendance';
                } else {
                    $item['target_url'] = (strpos($link, 'http') === 0) ? $link : (BASE_URL . ltrim($link, '/'));
                    $item['action_label'] = 'View Details';
                }
            }
        }

        // New notifications since last poll
        $newNotifs = [];
        if ($lastId > 0) {
            foreach ($recent as $item) {
                if (intval($item['id']) > $lastId) {
                    $newNotifs[] = $item;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'unread_count' => $unreadCount,
            'max_id' => $maxId,
            'new_notifications' => $newNotifs,
            'notifications' => $recent
        ]);
        exit;
    }

    if ($action === 'mark_read') {
        $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR send_to = 'all' OR user_id IS NULL) AND (is_read = 0 OR is_read IS NULL)")->execute([$adminId]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Throwable $e) {
    error_log('Notifications AJAX error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
