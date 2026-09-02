<?php
function handleSalesRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'pipeline':
            return salesPipeline($db, $auth);
        case 'leads':
            return salesLeads($db, $auth, $data);
        case 'update-status':
            return updateStage($db, $auth, $data);
        case 'create-payment':
            return createPayment($db, $auth, $data);
        case 'create-project':
            return createProject($db, $auth, $data);
        default:
            return ['success' => false, 'message' => 'Invalid sales action'];
    }
}

function salesLeads($db, $auth, $data) {
    $eid = $auth['employee_id'];
    $role = $auth['role'];

    $sql = "SELECT l.*, cr.first_name as creator_first, cr.last_name as creator_last,
            a.first_name as assigned_first, a.last_name as assigned_last
            FROM leads l
            LEFT JOIN employees cr ON cr.id = l.employee_id
            LEFT JOIN employees a ON a.id = l.assigned_to
            WHERE l.status IN ('qualified','meeting_scheduled','demo_scheduled','quotation_sent','negotiation','won','lost')";
    $params = [];

    if (!in_array($role, ['super_admin', 'admin'])) {
        $sql .= " AND (l.assigned_sales = ? OR (l.assigned_sales IS NULL AND l.status = 'qualified'))";
        $params[] = $eid;
    }

    if (isset($data['status']) && $data['status'] !== '') { $sql .= " AND l.status = ?"; $params[] = $data['status']; }
    $sql .= " ORDER BY FIELD(l.status, 'qualified','meeting_scheduled','demo_scheduled','quotation_sent','negotiation','won'), l.updated_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function salesPipeline($db, $auth) {
    $eid = $auth['employee_id'];
    $role = $auth['role'];

    $stages = ['qualified','meeting_scheduled','demo_scheduled','quotation_sent','negotiation','won','lost'];
    $pipeline = [];

    foreach ($stages as $stage) {
        $sql = "SELECT l.*, cr.first_name as creator_first, cr.last_name as creator_last FROM leads l LEFT JOIN employees cr ON cr.id = l.employee_id WHERE l.status = ?";
        $params = [$stage];
        if (!in_array($role, ['super_admin', 'admin'])) {
            $sql .= " AND (l.assigned_sales = ? OR (l.assigned_sales IS NULL AND l.status IN ('qualified','meeting_scheduled','demo_scheduled','quotation_sent','negotiation')))";
            $params[] = $eid;
        }
        $stmt = $db->prepare($sql . " ORDER BY l.updated_at DESC");
        $stmt->execute($params);
        $pipeline[$stage] = $stmt->fetchAll();
    }

    return ['success' => true, 'data' => $pipeline];
}

function updateStage($db, $auth, $data) {
    $leadId = intval($data['lead_id'] ?? 0);
    $status = Validator::sanitize($data['status'] ?? '');
    $notes = Validator::sanitize($data['notes'] ?? '');

    $allowed = ['qualified','meeting_scheduled','demo_scheduled','quotation_sent','negotiation','won','lost'];
    if (!in_array($status, $allowed)) return ['success' => false, 'message' => 'Invalid stage'];

    $db->prepare("UPDATE leads SET status = ?, notes = CONCAT_WS('\n',notes,?) WHERE id = ?")->execute([$status, $notes ? '[' . date('Y-m-d H:i') . '] ' . $notes : '', $leadId]);

    if ($status === 'won') {
        $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES ((SELECT user_id FROM employees WHERE id = (SELECT created_by FROM leads WHERE id = ?)), 'Lead Won', CONCAT('Lead converted to customer'), 'sales', ?)")->execute([$leadId, '/leads/' . $leadId]);
    }

    return ['success' => true, 'message' => 'Stage updated to ' . $status];
}

function createPayment($db, $auth, $data) {
    $leadId = intval($data['lead_id'] ?? 0);
    $amount = floatval($data['amount'] ?? 0);
    $paymentMode = Validator::sanitize($data['payment_mode'] ?? '');
    $transactionId = Validator::sanitize($data['transaction_id'] ?? '');

    if (!$leadId || !$amount) return ['success' => false, 'message' => 'Lead ID and amount required'];

    $stmt = $db->prepare("INSERT INTO payments (lead_id, amount, payment_mode, transaction_id, status, created_by) VALUES (?, ?, ?, ?, 'completed', ?)");
    $stmt->execute([$leadId, $amount, $paymentMode, $transactionId, $auth['user_id']]);

    return ['success' => true, 'message' => 'Payment recorded', 'id' => $db->lastInsertId()];
}

function createProject($db, $auth, $data) {
    $leadId = intval($data['lead_id'] ?? 0);
    $projectName = Validator::sanitize($data['project_name'] ?? '');
    $scope = Validator::sanitize($data['scope_of_work'] ?? '');
    $startDate = Validator::sanitize($data['start_date'] ?? '');
    $endDate = Validator::sanitize($data['expected_end_date'] ?? '');

    if (!$leadId || !$projectName) return ['success' => false, 'message' => 'Lead ID and project name required'];

    $code = 'PRJ-' . date('Y') . '-' . str_pad($leadId, 4, '0', STR_PAD_LEFT);

    $stmt = $db->prepare("INSERT INTO projects (lead_id, project_name, project_code, scope_of_work, start_date, expected_end_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
    $stmt->execute([$leadId, $projectName, $code, $scope, $startDate ?: null, $endDate ?: null, $auth['user_id']]);
    $projectId = $db->lastInsertId();

    $db->prepare("UPDATE leads SET status = 'closed' WHERE id = ?")->execute([$leadId]);

    return ['success' => true, 'message' => 'Project created', 'id' => $projectId, 'project_code' => $code];
}
