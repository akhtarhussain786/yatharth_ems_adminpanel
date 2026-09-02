<?php
function handleNoticeRequest($action) {
    // Company notices are internal; this endpoint previously served them to
    // anyone who knew the URL.
    AuthMiddleware::authenticate();
    $db = (new Database())->getConnection();

    switch ($action) {
        case 'list':
            return getNotices($db);
        default:
            return getNotices($db);
    }
}

function getNotices($db) {
    try {
        $stmt = $db->query("SELECT id, title, content as message, priority, created_at, created_by FROM notices WHERE status = 1 ORDER BY created_at DESC LIMIT 50");
        $notices = $stmt->fetchAll();
        return ['success' => true, 'data' => $notices];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error fetching notices'];
    }
}
