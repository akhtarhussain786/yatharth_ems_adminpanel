<?php
// /ems/admin_panel/ajax/send_all_notices.php
require_once '../includes/config.php';
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get all active notices
    $notices = $pdo->query("SELECT id, title, content FROM notices WHERE status = 1")->fetchAll();
    
    if (empty($notices)) {
        echo json_encode(['success' => false, 'message' => 'No active notices found']);
        exit;
    }
    
    // Get all active users
    $users = $pdo->query("SELECT id FROM users WHERE status = 1")->fetchAll();
    
    if (empty($users)) {
        echo json_encode(['success' => false, 'message' => 'No active users found']);
        exit;
    }
    
    $sent = 0;
    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, send_to, title, message, type, link, created_at) 
                               VALUES (?, 'employee', ?, ?, 'notice', '/notices', NOW())");
    
    // Send each notice to each user
    foreach ($users as $user) {
        foreach ($notices as $notice) {
            $notifStmt->execute([
                $user['id'],
                $notice['title'],
                $notice['content']
            ]);
            $sent++;
        }
    }
    
    echo json_encode([
        'success' => true, 
        'count' => $sent, 
        'message' => 'Sent ' . $sent . ' notifications to ' . count($users) . ' employees'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>