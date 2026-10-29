<?php
function handleCallReportRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            return getCallReports($db, $auth, $data);
        case 'my':
            return getMyCallReports($db, $auth, $param);
        case 'create':
            return createCallReport($db, $auth, $data);
        case 'update':
            return updateCallReport($db, $auth, $param, $data);
        case 'delete':
            return deleteCallReport($db, $auth, $param);
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

function getCallReports($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'call_reports', 'can_view');
    $role = $auth['role'] ?? '';
    $authEid = (int)($auth['employee_id'] ?? 0);
    $isAdminOrHR = in_array($role, ['super_admin', 'admin', 'hr_admin', 'hr', 'hr_executive', 'telecaller_admin', 'sales_admin', 'digital_marketing_admin', 'marketing_admin'], true);

    $deptFilter = $data['department_id'] ?? '';
    $empFilter = $data['employee_id'] ?? '';
    $dateFilter = $data['call_date'] ?? '';
    $leadId = $data['lead_id'] ?? '';

    $sql = "SELECT c.*, e.first_name, e.last_name, e.employee_code, d.name as department_name,
                   l.customer_name as lead_customer_name
            FROM call_reports c
            LEFT JOIN employees e ON e.id = c.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN leads l ON l.id = c.lead_id
            WHERE 1=1";
    $params = [];

    if ($isAdminOrHR) {
        if ($deptFilter) { $sql .= " AND e.department_id = ?"; $params[] = $deptFilter; }
        if ($empFilter) { $sql .= " AND c.employee_id = ?"; $params[] = $empFilter; }
    } else {
        $sql .= " AND c.employee_id = ?";
        $params[] = $authEid;
    }

    if ($leadId) { $sql .= " AND c.lead_id = ?"; $params[] = intval($leadId); }
    if ($dateFilter) { $sql .= " AND c.call_date = ?"; $params[] = $dateFilter; }

    $sql .= " ORDER BY c.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyCallReports($db, $auth, $date) {
    $eid = $auth['employee_id'];
    $sql = "SELECT * FROM call_reports WHERE employee_id = ?";
    $params = [$eid];
    if ($date) { $sql .= " AND call_date = ?"; $params[] = $date; }
    $sql .= " ORDER BY created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createCallReport($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'call_reports', 'can_create');
    $eid = $auth['employee_id'] ?: null;
    $uid = $auth['user_id'] ?: null;
    $leadId = !empty($data['lead_id']) ? intval($data['lead_id']) : null;
    $customerName = Validator::sanitize($data['customer_name'] ?? '');
    $customerPhone = Validator::sanitize($data['customer_phone'] ?? '');
    $callDuration = intval($data['call_duration'] ?? 0);
    $callType = Validator::sanitize($data['call_type'] ?? 'outgoing');
    $status = Validator::sanitize($data['call_status'] ?? $data['status'] ?? 'completed');
    $callOutcome = Validator::sanitize($data['call_outcome'] ?? $status);
    $notes = Validator::sanitize($data['notes'] ?? '');
    $followUpReq = intval($data['follow_up_required'] ?? 0);
    $callDate = Validator::sanitize($data['call_date'] ?? date('Y-m-d'));

    if ($customerName === '' && $leadId) {
        $st = $db->prepare("SELECT customer_name, customer_phone, mobile FROM leads WHERE id = ?");
        $st->execute([$leadId]);
        if ($l = $st->fetch()) {
            $customerName = $l['customer_name'];
            if ($customerPhone === '') $customerPhone = $l['customer_phone'] ?: $l['mobile'];
        }
    }

    if ($customerName === '') return ['success' => false, 'message' => 'Customer name is required'];
    if ($customerPhone === '') return ['success' => false, 'message' => 'Customer phone is required'];

    $stmt = $db->prepare("INSERT INTO call_reports (employee_id, user_id, lead_id, customer_name, customer_phone, call_duration, call_type, status, call_outcome, notes, follow_up_required, call_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$eid, $uid, $leadId, $customerName, $customerPhone, $callDuration, $callType, $status, $callOutcome, $notes, $followUpReq, $callDate]);
    $callId = $db->lastInsertId();

    if ($leadId) {
        $db->prepare("UPDATE leads SET latest_call_status = ?, latest_call_notes = ?, latest_call_at = NOW() WHERE id = ?")
           ->execute([$callOutcome, $notes, $leadId]);
    }

    return ['success' => true, 'message' => 'Call report created', 'id' => $callId];
}

function updateCallReport($db, $auth, $id, $data) {
    PermissionHelper::checkPermission($db, $auth, 'call_reports', 'can_edit');
    $customerName = Validator::sanitize($data['customer_name'] ?? '');
    $customerPhone = Validator::sanitize($data['customer_phone'] ?? '');
    $callDuration = intval($data['call_duration'] ?? 0);
    $status = Validator::sanitize($data['status'] ?? 'completed');
    $notes = Validator::sanitize($data['notes'] ?? '');
    $stmt = $db->prepare("UPDATE call_reports SET customer_name=?, customer_phone=?, call_duration=?, status=?, notes=? WHERE id=?");
    $stmt->execute([$customerName, $customerPhone, $callDuration, $status, $notes, $id]);
    return ['success' => true, 'message' => 'Call report updated'];
}

function deleteCallReport($db, $auth, $id) {
    PermissionHelper::checkPermission($db, $auth, 'call_reports', 'can_delete');
    $stmt = $db->prepare("DELETE FROM call_reports WHERE id=?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'Call report deleted'];
}
