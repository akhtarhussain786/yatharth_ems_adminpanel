<?php
function mapTaskStatus($s) {
    // Stored lowercase to match the column and what the app sends. Accepts the
    // display spellings too, so an older client posting 'In Progress' still saves.
    $map = [
        'pending' => 'pending', 'in progress' => 'in_progress',
        'in_progress' => 'in_progress', 'completed' => 'completed',
        'cancelled' => 'cancelled', 'canceled' => 'cancelled',
    ];
    return $map[strtolower(trim($s))] ?? 'pending';
}

function mapPriority($p) {
    return strtolower(trim($p));
}

function handleTaskRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            return getTasks($db, $auth, $data);
        case 'my':
            return getMyTasks($db, $auth);
        case 'create':
            return createTask($db, $auth, $data);
        case 'update':
            return updateTask($db, $auth, $param, $data);
        case 'delete':
            return deleteTask($db, $auth, $param);
        case 'complete':
            return completeTask($db, $auth, $param);
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

function getTasks($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'tasks', 'can_view');
    $deptFilter = $data['department_id'] ?? '';
    $empFilter = $data['employee_id'] ?? '';
    $statusFilter = $data['status'] ?? '';

    $sql = "SELECT t.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
            FROM tasks t
            JOIN employees e ON e.id = t.assigned_to
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE 1=1";
    $params = [];

    if ($deptFilter) { $sql .= " AND e.department_id = ?"; $params[] = $deptFilter; }
    if ($empFilter) { $sql .= " AND t.assigned_to = ?"; $params[] = $empFilter; }
    if ($statusFilter) { $sql .= " AND t.status = ?"; $params[] = $statusFilter; }

    $sql .= " ORDER BY t.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyTasks($db, $auth) {
    $eid = $auth['employee_id'];
    $stmt = $db->prepare("
        SELECT t.*, e.first_name as assigned_by_name
        FROM tasks t
        LEFT JOIN employees e ON e.user_id = t.assigned_by
        WHERE t.assigned_to = ?
        ORDER BY FIELD(t.status,'pending','in_progress','completed','cancelled'), t.due_date ASC
    ");
    $stmt->execute([$eid]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createTask($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'tasks', 'can_create');
    $employeeId = intval($data['employee_id'] ?? 0);
    $title = Validator::sanitize($data['title'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');
    $priority = mapPriority(Validator::sanitize($data['priority'] ?? 'medium'));
    $dueDate = Validator::sanitize($data['due_date'] ?? '');

    $stmt = $db->prepare("INSERT INTO tasks (assigned_to, title, description, priority, assigned_by, due_date) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$employeeId, $title, $description, $priority, $auth['user_id'], $dueDate ?: null]);
    return ['success' => true, 'message' => 'Task created', 'id' => $db->lastInsertId()];
}

function updateTask($db, $auth, $id, $data) {
    PermissionHelper::checkPermission($db, $auth, 'tasks', 'can_edit');
    $title = Validator::sanitize($data['title'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');
    $priority = Validator::sanitize($data['priority'] ?? 'medium');
    $status = mapTaskStatus(Validator::sanitize($data['status'] ?? 'pending'));
    $dueDate = Validator::sanitize($data['due_date'] ?? '');
    $stmt = $db->prepare("UPDATE tasks SET title=?, description=?, priority=?, status=?, due_date=? WHERE id=?");
    $stmt->execute([$title, $description, mapPriority($priority), $status, $dueDate ?: null, $id]);
    return ['success' => true, 'message' => 'Task updated'];
}

function deleteTask($db, $auth, $id) {
    PermissionHelper::checkPermission($db, $auth, 'tasks', 'can_delete');
    $stmt = $db->prepare("DELETE FROM tasks WHERE id=?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'Task deleted'];
}

function completeTask($db, $auth, $id) {
    $eid = $auth['employee_id'];
    $stmt = $db->prepare("UPDATE tasks SET status='completed', completed_at=NOW() WHERE id=? AND assigned_to=?");
    $stmt->execute([$id, $eid]);
    return ['success' => true, 'message' => 'Task completed'];
}
