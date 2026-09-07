<?php
function handleFollowUpRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            return getFollowUps($db, $auth, $data);
        case 'my':
            return getMyFollowUps($db, $auth);
        case 'create':
            return createFollowUp($db, $auth, $data);
        case 'complete':
            return completeFollowUp($db, $auth, $param);
        case 'delete':
            return deleteFollowUp($db, $auth, $param);
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

function getFollowUps($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'follow_ups', 'can_view');
    $role = $auth['role'] ?? '';
    $authEid = (int)($auth['employee_id'] ?? 0);
    $isAdminOrHR = in_array($role, ['super_admin', 'admin', 'hr_admin', 'hr', 'hr_executive', 'telecaller_admin', 'sales_admin', 'digital_marketing_admin', 'marketing_admin'], true);

    $deptFilter = $data['department_id'] ?? '';
    $empFilter = $data['employee_id'] ?? '';
    $statusFilter = $data['status'] ?? '';
    $leadId = $data['lead_id'] ?? '';

    $sql = "SELECT f.*, e.first_name, e.last_name, e.employee_code, d.name as department_name,
                   l.customer_name as lead_customer_name, l.status as lead_status
            FROM follow_ups f
            LEFT JOIN employees e ON e.id = f.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN leads l ON l.id = f.lead_id
            WHERE 1=1";
    $params = [];

    if ($isAdminOrHR) {
        if ($deptFilter) { $sql .= " AND e.department_id = ?"; $params[] = $deptFilter; }
        if ($empFilter) { $sql .= " AND f.employee_id = ?"; $params[] = $empFilter; }
    } else {
        $sql .= " AND f.employee_id = ?";
        $params[] = $authEid;
    }

    if ($leadId) { $sql .= " AND f.lead_id = ?"; $params[] = intval($leadId); }
    if ($statusFilter) { $sql .= " AND f.status = ?"; $params[] = $statusFilter; }

    $sql .= " ORDER BY f.follow_up_date ASC, f.follow_up_time ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyFollowUps($db, $auth) {
    $eid = $auth['employee_id'];
    $stmt = $db->prepare("SELECT * FROM follow_ups WHERE employee_id = ? ORDER BY follow_up_date ASC");
    $stmt->execute([$eid]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createFollowUp($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'follow_ups', 'can_create');
    $eid = $auth['employee_id'];
    $customerName = Validator::sanitize($data['customer_name'] ?? '');
    $customerPhone = Validator::sanitize($data['customer_phone'] ?? '');
    $followUpType = Validator::sanitize($data['follow_up_type'] ?? 'call');
    $notes = Validator::sanitize($data['notes'] ?? '');
    $followUpDate = Validator::sanitize($data['follow_up_date'] ?? date('Y-m-d'));

    $stmt = $db->prepare("INSERT INTO follow_ups (employee_id, customer_name, customer_phone, follow_up_type, notes, follow_up_date) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$eid, $customerName, $customerPhone, $followUpType, $notes, $followUpDate]);
    return ['success' => true, 'message' => 'Follow-up created', 'id' => $db->lastInsertId()];
}

function completeFollowUp($db, $auth, $id) {
    PermissionHelper::checkPermission($db, $auth, 'follow_ups', 'can_edit');
    $stmt = $db->prepare("UPDATE follow_ups SET status='completed', completed_at=NOW() WHERE id=?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'Follow-up completed'];
}

function deleteFollowUp($db, $auth, $id) {
    PermissionHelper::checkPermission($db, $auth, 'follow_ups', 'can_delete');
    $stmt = $db->prepare("DELETE FROM follow_ups WHERE id=?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'Follow-up deleted'];
}
