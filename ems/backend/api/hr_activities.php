<?php
function handleHRActivityRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            return getHRActivities($db, $auth, $data);
        case 'my':
            return getMyHRActivities($db, $auth, $param);
        case 'create':
            return createHRActivity($db, $auth, $data);
        case 'update':
            return updateHRActivity($db, $auth, $param, $data);
        case 'delete':
            return deleteHRActivity($db, $auth, $param);
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

function getHRActivities($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'hr_activities', 'can_view');
    $deptFilter = $data['department_id'] ?? '';
    $empFilter = $data['employee_id'] ?? '';
    $typeFilter = $data['activity_type'] ?? '';

    $sql = "SELECT h.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
            FROM hr_activities h
            JOIN employees e ON e.id = h.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE 1=1";
    $params = [];

    if ($deptFilter) { $sql .= " AND e.department_id = ?"; $params[] = $deptFilter; }
    if ($empFilter) { $sql .= " AND h.employee_id = ?"; $params[] = $empFilter; }
    if ($typeFilter) { $sql .= " AND h.activity_type = ?"; $params[] = $typeFilter; }

    $sql .= " ORDER BY h.activity_date DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyHRActivities($db, $auth, $month) {
    $eid = $auth['employee_id'];
    if (!$month) $month = date('Y-m');
    $stmt = $db->prepare("
        SELECT * FROM hr_activities 
        WHERE employee_id = ? AND DATE_FORMAT(activity_date, '%Y-%m') = ?
        ORDER BY activity_date DESC
    ");
    $stmt->execute([$eid, $month]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createHRActivity($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'hr_activities', 'can_create');
    $eid = $auth['employee_id'];
    $activityType = Validator::sanitize($data['activity_type'] ?? 'other');
    $title = Validator::sanitize($data['title'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');
    $activityDate = Validator::sanitize($data['activity_date'] ?? date('Y-m-d'));

    $stmt = $db->prepare("INSERT INTO hr_activities (employee_id, activity_type, title, description, activity_date) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$eid, $activityType, $title, $description, $activityDate]);
    return ['success' => true, 'message' => 'HR activity created', 'id' => $db->lastInsertId()];
}

function updateHRActivity($db, $auth, $id, $data) {
    PermissionHelper::checkPermission($db, $auth, 'hr_activities', 'can_edit');
    $activityType = Validator::sanitize($data['activity_type'] ?? 'other');
    $title = Validator::sanitize($data['title'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');
    $status = Validator::sanitize($data['status'] ?? 'scheduled');
    $stmt = $db->prepare("UPDATE hr_activities SET activity_type=?, title=?, description=?, status=? WHERE id=?");
    $stmt->execute([$activityType, $title, $description, $status, $id]);
    return ['success' => true, 'message' => 'HR activity updated'];
}

function deleteHRActivity($db, $auth, $id) {
    PermissionHelper::checkPermission($db, $auth, 'hr_activities', 'can_delete');
    $stmt = $db->prepare("DELETE FROM hr_activities WHERE id=?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'HR activity deleted'];
}
