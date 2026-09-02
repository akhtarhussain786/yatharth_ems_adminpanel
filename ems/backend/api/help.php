<?php
/**
 * Support tickets raised from the app's Help screen.
 *
 * help_screen.dart has always called help/tickets and help/create; the handler
 * was missing, so both fell through the router to "Invalid endpoint".
 */

function handleHelpRequest($action, $param) {
    try {
        $auth = AuthMiddleware::authenticate();
        $db = (new Database())->getConnection();
        $data = json_decode($GLOBALS['_RAW_INPUT'] ?? '', true) ?: $_POST;

        switch ($action) {
            case '':
            case 'tickets':
            case 'my':
                return getMyHelpTickets($db, $auth);
            case 'create':
                return createHelpTicket($db, $auth, $data);
            case 'details':
                return getHelpTicket($db, $auth, $param ?: ($data['id'] ?? 0));
            default:
                return ['success' => false, 'message' => 'Invalid help action'];
        }
    } catch (Exception $e) {
        error_log('Help Error: ' . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}

function getMyHelpTickets($db, $auth) {
    $stmt = $db->prepare("SELECT t.*, e.first_name AS assignee_first, e.last_name AS assignee_last
        FROM help_tickets t
        LEFT JOIN users u ON u.id = t.assigned_to
        LEFT JOIN employees e ON e.user_id = u.id
        WHERE t.employee_id = ?
        ORDER BY t.created_at DESC
        LIMIT 100");
    $stmt->execute([$auth['employee_id']]);

    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createHelpTicket($db, $auth, $data) {
    $subject = Validator::sanitize($data['subject'] ?? '');
    $message = Validator::sanitize($data['message'] ?? '');
    $priority = Validator::sanitize($data['priority'] ?? 'medium');

    if (!$subject) return ['success' => false, 'message' => 'Subject is required'];
    if (!$message) return ['success' => false, 'message' => 'Message is required'];

    $allowed = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($priority, $allowed, true)) $priority = 'medium';

    $stmt = $db->prepare("INSERT INTO help_tickets (employee_id, subject, message, priority, status)
        VALUES (?, ?, ?, ?, 'open')");
    $stmt->execute([$auth['employee_id'], $subject, $message, $priority]);
    $ticketId = $db->lastInsertId();

    // Let the admins know something is waiting.
    try {
        $admins = $db->query("SELECT id FROM users
            WHERE role_id IN (SELECT id FROM roles WHERE name IN ('super_admin','admin','it_admin'))
              AND status = 1")->fetchAll();

        $notify = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link)
            VALUES (?, 'New Support Ticket', ?, 'help', ?)");
        foreach ($admins as $admin) {
            $notify->execute([$admin['id'], $subject, '/help/' . $ticketId]);
        }
    } catch (Exception $e) {
        error_log('Help ticket notification: ' . $e->getMessage());
    }

    return ['success' => true, 'message' => 'Support ticket created', 'id' => $ticketId];
}

function getHelpTicket($db, $auth, $id) {
    $id = intval($id);
    if (!$id) return ['success' => false, 'message' => 'Ticket ID required'];

    // Scoped to the raiser so a ticket cannot be read by id alone.
    $stmt = $db->prepare("SELECT * FROM help_tickets WHERE id = ? AND employee_id = ?");
    $stmt->execute([$id, $auth['employee_id']]);
    $ticket = $stmt->fetch();

    if (!$ticket) return ['success' => false, 'message' => 'Ticket not found'];
    return ['success' => true, 'data' => $ticket];
}
