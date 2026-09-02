<?php
function handleMeetingRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();

    switch ($action) {
        case 'all':
        case 'my':
            return getMyMeetings($db, $auth);
        case 'list':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin']);
            return getMeetings($db, $auth);
        case 'create':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin']);
            return createMeeting($db, $auth);
        case 'details':
            return getMeetingDetails($db, $auth, $param);
        case 'update':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin']);
            return updateMeeting($db, $auth, $param);
        case 'delete':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin']);
            return deleteMeeting($db, $param);
        case 'update_status':
            return updateMeetingStatus($db, $auth, $param);
        case 'mark_attendance':
            return markAttendance($db, $auth, $param);
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

function getMeetings($db, $auth) {
    $page = (int)($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $stmt = $db->query("
        SELECT m.*, u.username as created_by_name,
               (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id) as participant_count
        FROM meetings m
        LEFT JOIN users u ON u.id = m.created_by
        ORDER BY m.meeting_date DESC, m.start_time DESC
        LIMIT $limit OFFSET $offset
    ");
    $meetings = $stmt->fetchAll();
    $total = $db->query("SELECT COUNT(*) FROM meetings")->fetchColumn();

    return ['success' => true, 'data' => $meetings, 'total' => (int)$total];
}

function getMyMeetings($db, $auth) {
    $employeeId = $auth['employee_id'];

    $stmt = $db->prepare("
        SELECT m.*, mp.attendance as my_attendance,
               u.username as created_by_name,
               (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id) as participant_count
        FROM meetings m
        LEFT JOIN users u ON u.id = m.created_by
        LEFT JOIN meeting_participants mp ON mp.meeting_id = m.id AND mp.employee_id = ?
        ORDER BY m.meeting_date DESC, m.start_time DESC
    ");
    $stmt->execute([$employeeId]);
    $meetings = $stmt->fetchAll();

    return ['success' => true, 'data' => $meetings];
}

function getMeetingDetails($db, $auth, $id) {
    if (!$id) return ['success' => false, 'message' => 'Meeting ID required'];

    $stmt = $db->prepare("
        SELECT m.*, u.username as created_by_name,
               (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id) as participant_count
        FROM meetings m
        LEFT JOIN users u ON u.id = m.created_by
        WHERE m.id = ?
    ");
    $stmt->execute([$id]);
    $meeting = $stmt->fetch();

    if (!$meeting) return ['success' => false, 'message' => 'Meeting not found'];

    $stmt = $db->prepare("
        SELECT mp.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
        FROM meeting_participants mp
        JOIN employees e ON e.id = mp.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE mp.meeting_id = ?
    ");
    $stmt->execute([$id]);
    $meeting['participants'] = $stmt->fetchAll();

    return ['success' => true, 'data' => $meeting];
}

function createMeeting($db, $auth) {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $title = $data['title'] ?? '';
    $description = $data['description'] ?? '';
    $meetingDate = $data['meeting_date'] ?? '';
    $startTime = $data['start_time'] ?? '';
    $endTime = $data['end_time'] ?? null;
    $venue = $data['venue'] ?? '';
    $meetingLink = $data['meeting_link'] ?? null;
    $participantIds = $data['participant_ids'] ?? [];

    if (!$title || !$meetingDate || !$startTime) {
        return ['success' => false, 'message' => 'Title, date, and start time required'];
    }

    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO meetings (title, description, meeting_date, start_time, end_time, venue, meeting_link, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$title, $description, $meetingDate, $startTime, $endTime, $venue, $meetingLink, $auth['user_id']]);
    $meetingId = $db->lastInsertId();

    if (!empty($participantIds)) {
        $stmt = $db->prepare("INSERT INTO meeting_participants (meeting_id, employee_id) VALUES (?, ?)");
        foreach ($participantIds as $eid) {
            $stmt->execute([$meetingId, $eid]);
        }
    }

    $db->commit();

    return ['success' => true, 'message' => 'Meeting created', 'meeting_id' => $meetingId];
}

function updateMeeting($db, $auth, $id) {
    if (!$id) return ['success' => false, 'message' => 'Meeting ID required'];

    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $updates = [];
    $params = [];

    foreach (['title', 'description', 'meeting_date', 'start_time', 'end_time', 'venue', 'meeting_link', 'status'] as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
        }
    }

    if (empty($updates)) return ['success' => false, 'message' => 'No fields to update'];

    $params[] = $id;
    $stmt = $db->prepare("UPDATE meetings SET " . implode(', ', $updates) . " WHERE id = ?");
    $stmt->execute($params);

    if (isset($data['participant_ids'])) {
        $db->prepare("DELETE FROM meeting_participants WHERE meeting_id = ?")->execute([$id]);
        $stmt = $db->prepare("INSERT INTO meeting_participants (meeting_id, employee_id) VALUES (?, ?)");
        foreach ($data['participant_ids'] as $eid) {
            $stmt->execute([$id, $eid]);
        }
    }

    return ['success' => true, 'message' => 'Meeting updated'];
}

function deleteMeeting($db, $id) {
    if (!$id) return ['success' => false, 'message' => 'Meeting ID required'];

    $db->prepare("DELETE FROM meeting_participants WHERE meeting_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM meetings WHERE id = ?")->execute([$id]);

    return ['success' => true, 'message' => 'Meeting deleted'];
}

function updateMeetingStatus($db, $auth, $id) {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $status = $data['status'] ?? '';

    if (!$id || !$status) return ['success' => false, 'message' => 'ID and status required'];

    $stmt = $db->prepare("UPDATE meetings SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    return ['success' => true, 'message' => 'Status updated'];
}

function markAttendance($db, $auth, $id) {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $attendance = $data['attendance'] ?? 'accepted';

    if (!$id) return ['success' => false, 'message' => 'Meeting ID required'];

    $stmt = $db->prepare("UPDATE meeting_participants SET attendance = ? WHERE meeting_id = ? AND employee_id = ?");
    $stmt->execute([$attendance, $id, $auth['employee_id']]);

    return ['success' => true, 'message' => 'Attendance marked'];
}
