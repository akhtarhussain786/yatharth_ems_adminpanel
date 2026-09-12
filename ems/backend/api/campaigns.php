<?php
function handleCampaignRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            return getCampaigns($db, $auth, $data);
        case 'my':
            return getMyCampaigns($db, $auth);
        case 'create':
            return createCampaign($db, $auth, $data);
        case 'update':
            return updateCampaign($db, $auth, $param, $data);
        case 'delete':
            return deleteCampaign($db, $auth, $param);
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

function getCampaigns($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'campaigns', 'can_view');
    $deptFilter = $data['department_id'] ?? '';
    $empFilter = $data['employee_id'] ?? '';

    $sql = "SELECT c.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
            FROM campaigns c
            JOIN employees e ON e.id = c.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE 1=1";
    $params = [];

    if ($deptFilter) { $sql .= " AND e.department_id = ?"; $params[] = $deptFilter; }
    if ($empFilter) { $sql .= " AND c.employee_id = ?"; $params[] = $empFilter; }

    $sql .= " ORDER BY c.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyCampaigns($db, $auth) {
    $eid = $auth['employee_id'];
    $stmt = $db->prepare("SELECT * FROM campaigns WHERE employee_id = ? ORDER BY created_at DESC");
    $stmt->execute([$eid]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createCampaign($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'campaigns', 'can_create');
    $eid = $auth['employee_id'];
    $campaignName = Validator::sanitize($data['campaign_name'] ?? '');
    $platform = Validator::sanitize($data['platform'] ?? '');
    $budget = floatval($data['budget'] ?? 0);
    $startDate = Validator::sanitize($data['start_date'] ?? '');
    $endDate = Validator::sanitize($data['end_date'] ?? '');

    // `name` is a legacy NOT NULL column with no default; omitting it made every
    // create fail with 1364. Both columns hold the same campaign name.
    $stmt = $db->prepare("INSERT INTO campaigns (employee_id, name, campaign_name, platform, budget, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$eid, $campaignName, $campaignName, $platform, $budget, $startDate ?: null, $endDate ?: null]);
    return ['success' => true, 'message' => 'Campaign created', 'id' => $db->lastInsertId()];
}

function updateCampaign($db, $auth, $id, $data) {
    PermissionHelper::checkPermission($db, $auth, 'campaigns', 'can_edit');
    $campaignName = Validator::sanitize($data['campaign_name'] ?? '');
    $platform = Validator::sanitize($data['platform'] ?? '');
    $budget = floatval($data['budget'] ?? 0);
    $status = Validator::sanitize($data['status'] ?? 'planning');
    $results = Validator::sanitize($data['results'] ?? '');
    $stmt = $db->prepare("UPDATE campaigns SET campaign_name=?, platform=?, budget=?, status=?, results=? WHERE id=?");
    $stmt->execute([$campaignName, $platform, $budget, $status, $results, $id]);
    return ['success' => true, 'message' => 'Campaign updated'];
}

function deleteCampaign($db, $auth, $id) {
    PermissionHelper::checkPermission($db, $auth, 'campaigns', 'can_delete');
    $stmt = $db->prepare("DELETE FROM campaigns WHERE id=?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'Campaign deleted'];
}
