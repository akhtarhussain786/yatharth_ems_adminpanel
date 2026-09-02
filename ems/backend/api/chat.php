<?php
function handleChatRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();

    switch ($action) {
        case 'conversations': return getConversations($db, $auth);
        case 'messages': return getMessages($db, $auth, $param);
        case 'send': return sendMessage($db, $auth);
        case 'create': return createConversation($db, $auth);
        default: return ['success' => false, 'message' => 'Invalid action'];
    }
}

function getConversations($db, $auth) {
    $stmt = $db->prepare("
        SELECT c.*, cm.message as last_message, cm.created_at as last_message_at,
               u.username as other_user
        FROM chat_conversations c
        JOIN chat_participants cp ON cp.conversation_id = c.id AND cp.user_id = ?
        LEFT JOIN (
            SELECT conversation_id, message, created_at
            FROM chat_messages ORDER BY id DESC LIMIT 1
        ) cm ON cm.conversation_id = c.id
        LEFT JOIN chat_participants cp2 ON cp2.conversation_id = c.id AND cp2.user_id != ?
        LEFT JOIN users u ON u.id = cp2.user_id
        ORDER BY cm.created_at DESC
    ");
    $stmt->execute([$auth['user_id'], $auth['user_id']]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMessages($db, $auth, $conversationId) {
    $stmt = $db->prepare("
        SELECT m.*, u.username as sender_name, e.profile_photo
        FROM chat_messages m
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN employees e ON e.user_id = u.id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$conversationId]);

    $stmt->prepare("UPDATE chat_participants SET last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?")
         ->execute([$conversationId, $auth['user_id']]);

    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function sendMessage($db, $auth) {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $conversationId = $data['conversation_id'] ?? $param;
    $message = $data['message'] ?? '';

    if (!$conversationId) return ['success' => false, 'message' => 'Conversation required'];

    $filePath = null;
    if (isset($_FILES['file'])) {
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'chat_' . time() . '_' . uniqid() . '.' . $ext;
        $target = UPLOAD_PATH . 'chat/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $target)) $filePath = 'uploads/chat/' . $filename;
    }

    $stmt = $db->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message, file_path, message_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$conversationId, $auth['user_id'], $message, $filePath, $filePath ? 'file' : 'text']);

    return ['success' => true, 'message' => 'Message sent', 'message_id' => $db->lastInsertId()];
}

function createConversation($db, $auth) {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $participantIds = $data['participant_ids'] ?? [];
    if (!is_array($participantIds)) $participantIds = [$participantIds];

    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO chat_conversations (title, type, created_by) VALUES (?, 'individual', ?)");
    $stmt->execute([null, $auth['user_id']]);
    $convId = $db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO chat_participants (conversation_id, user_id) VALUES (?, ?)");
    $stmt->execute([$convId, $auth['user_id']]);
    foreach ($participantIds as $uid) $stmt->execute([$convId, $uid]);

    $db->commit();
    return ['success' => true, 'conversation_id' => $convId];
}
