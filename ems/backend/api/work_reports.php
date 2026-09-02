<?php
function handleWorkReportRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            return getWorkReports($db, $auth, $data);
        case 'my':
            return getMyWorkReports($db, $auth, $param);
        case 'create':
            return createWorkReport($db, $auth, $data);
        case 'update':
            return updateWorkReport($db, $auth, $param, $data);
        case 'delete':
            return deleteWorkReport($db, $auth, $param);
        case 'review':
            return reviewWorkReport($db, $auth, $param, $data);
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

function getWorkReports($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'daily_work_reports', 'can_view');
    $deptFilter = $data['department_id'] ?? '';
    $empFilter = $data['employee_id'] ?? '';
    $dateFilter = $data['report_date'] ?? '';
    $statusFilter = $data['status'] ?? '';

    $sql = "SELECT w.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
            FROM daily_work_reports w
            JOIN employees e ON e.id = w.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE 1=1";
    $params = [];

    if ($deptFilter) { $sql .= " AND e.department_id = ?"; $params[] = $deptFilter; }
    if ($empFilter) { $sql .= " AND w.employee_id = ?"; $params[] = $empFilter; }
    if ($dateFilter) { $sql .= " AND w.report_date = ?"; $params[] = $dateFilter; }
    if ($statusFilter) { $sql .= " AND w.status = ?"; $params[] = $statusFilter; }

    $sql .= " ORDER BY w.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyWorkReports($db, $auth, $month) {
    $eid = $auth['employee_id'];
    if (!$month) $month = date('Y-m');
    $stmt = $db->prepare("
        SELECT w.*, e.first_name, e.last_name, d.name as department_name
        FROM daily_work_reports w
        JOIN employees e ON e.id = w.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE w.employee_id = ? AND DATE_FORMAT(w.report_date, '%Y-%m') = ?
        ORDER BY w.report_date DESC
    ");
    $stmt->execute([$eid, $month]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createWorkReport($db, $auth, $data) {
    $eid = $auth['employee_id'];
    $reportDate = Validator::sanitize($data['report_date'] ?? date('Y-m-d'));
    $title = Validator::sanitize($data['title'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');
    $hoursWorked = floatval($data['hours_worked'] ?? 0);
    $status = Validator::sanitize($data['status'] ?? 'submitted');
    $attachment = '';

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        $attachment = 'report_' . $eid . '_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['attachment']['tmp_name'], UPLOAD_PATH . 'reports/' . $attachment);
    }

    $stmt = $db->prepare("
        INSERT INTO daily_work_reports (employee_id, report_date, title, description, hours_worked, status, attachment)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$eid, $reportDate, $title, $description, $hoursWorked, $status, $attachment]);
    return ['success' => true, 'message' => 'Work report submitted', 'id' => $db->lastInsertId()];
}

function updateWorkReport($db, $auth, $id, $data) {
    PermissionHelper::checkPermission($db, $auth, 'daily_work_reports', 'can_edit');
    $title = Validator::sanitize($data['title'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');
    $hoursWorked = floatval($data['hours_worked'] ?? 0);
    $stmt = $db->prepare("UPDATE daily_work_reports SET title=?, description=?, hours_worked=? WHERE id=?");
    $stmt->execute([$title, $description, $hoursWorked, $id]);
    return ['success' => true, 'message' => 'Work report updated'];
}

function deleteWorkReport($db, $auth, $id) {
    PermissionHelper::checkPermission($db, $auth, 'daily_work_reports', 'can_delete');
    $stmt = $db->prepare("DELETE FROM daily_work_reports WHERE id=?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'Work report deleted'];
}

function reviewWorkReport($db, $auth, $id, $data) {
    PermissionHelper::checkPermission($db, $auth, 'daily_work_reports', 'can_edit');
    $status = Validator::sanitize($data['status'] ?? 'submitted');
    $remarks = Validator::sanitize($data['remarks'] ?? '');
    $stmt = $db->prepare("UPDATE daily_work_reports SET status=?, remarks=?, reviewed_by=? WHERE id=?");
    $stmt->execute([$status, $remarks, $auth['user_id'], $id]);
    return ['success' => true, 'message' => 'Work report reviewed'];
}
