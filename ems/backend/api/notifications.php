<?php
function handleNotificationRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            return getMyNotifications($db, $auth);
        case 'read':
            return markRead($db, $auth, $param);
        case 'read-all':
            return markAllRead($db, $auth);
        case 'unread_count':
            return getUnreadCount($db, $auth);
        case 'update_fcm_token':
            return updateFcmToken($db, $auth, $data);
        case 'send_push':
            return sendAdminPushNotification($db, $auth, $data);
        default:
            return ['success' => false, 'message' => 'Invalid notification action'];
    }
}

function getMyNotifications($db, $auth) {
    $uid = $auth['user_id'];
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? OR (send_to = 'all' AND user_id IS NULL) ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$uid]);
    $notifications = $stmt->fetchAll();

    foreach ($notifications as &$n) {
        $n['is_read'] = isset($n['is_read']) ? (bool)$n['is_read'] : false;
    }

    return ['success' => true, 'data' => $notifications];
}

function markRead($db, $auth, $id) {
    $uid = $auth['user_id'];
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $uid]);
    return ['success' => true, 'message' => 'Marked as read'];
}

function markAllRead($db, $auth) {
    $uid = $auth['user_id'];
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$uid]);
    return ['success' => true, 'message' => 'All marked as read'];
}

function getUnreadCount($db, $auth) {
    $uid = $auth['user_id'];
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$uid]);
    $res = $stmt->fetch();
    return ['success' => true, 'count' => (int)($res['count'] ?? 0)];
}

function updateFcmToken($db, $auth, $data) {
    $uid = $auth['user_id'];
    $token = Validator::sanitize($data['fcm_token'] ?? $data['token'] ?? '');

    if (!$token) {
        return ['success' => false, 'message' => 'FCM token required'];
    }

    $stmt = $db->prepare("UPDATE users SET fcm_token = ? WHERE id = ?");
    $stmt->execute([$token, $uid]);

    return ['success' => true, 'message' => 'FCM token updated successfully'];
}

function sendAdminPushNotification($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'notifications', 'can_create');
    
    $title  = Validator::sanitize($data['title'] ?? '');
    $body   = Validator::sanitize($data['message'] ?? $data['body'] ?? '');
    $target = Validator::sanitize($data['target'] ?? 'all'); // all, department, employee
    $deptId = $data['department_id'] ?? null;
    $empId  = $data['employee_id'] ?? null;

    if (!$title || !$body) {
        return ['success' => false, 'message' => 'Title and message are required'];
    }

    if ($target === 'employee' && $empId) {
        $empStmt = $db->prepare("SELECT user_id FROM employees WHERE id = ?");
        $empStmt->execute([$empId]);
        $uid = $empStmt->fetchColumn();

        if ($uid) {
            $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'announcement')")->execute([$uid, $title, $body]);
            return FCMHelper::sendToUsers($db, [$uid], $title, $body);
        } else {
            return ['success' => false, 'message' => 'Selected employee is not linked to a user account'];
        }
    } else {
        $db->prepare("INSERT INTO notifications (title, message, type, send_to, department_id) VALUES (?, ?, 'announcement', ?, ?)")->execute([$title, $body, $target === 'department' ? 'department' : 'all', $deptId]);
        return FCMHelper::sendToTopicOrGroup($db, $target, $deptId, $title, $body);
    }
}
