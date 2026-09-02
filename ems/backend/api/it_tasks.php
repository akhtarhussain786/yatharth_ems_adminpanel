<?php
function handleITTaskRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            AuthMiddleware::checkRole(['super_admin', 'it_admin']);
            return getTaskList($db, $data);
        case 'my_tasks':
            return getMyTasks($db, $auth);
        case 'create':
            AuthMiddleware::checkRole(['super_admin', 'it_admin']);
            return createTask($db, $auth, $data);
        case 'submit_work':
            return submitTaskWork($db, $auth, $param, $data);
        case 'approve':
            AuthMiddleware::checkRole(['super_admin', 'it_admin']);
            return approveTask($db, $param, $data);
        case 'reject':
            AuthMiddleware::checkRole(['super_admin', 'it_admin']);
            return rejectTask($db, $param, $data);
        case 'points_config':
            AuthMiddleware::checkRole(['super_admin', 'it_admin']);
            return getPointsConfig($db);
        case 'performance':
            return getTaskPerformance($db, $data);
        case 'dashboard':
            return getITDashboard($db, $auth);
        default:
            return ['success' => false, 'message' => 'Invalid IT task action'];
    }
}

/** What it_team_screen.dart reads: the caller's tasks plus their points summary. */
function getITDashboard($db, $auth) {
    $tasks = getMyTasks($db, $auth);

    $points = null;
    try {
        $stmt = $db->prepare("SELECT
                COUNT(t.id) AS total_tasks,
                SUM(CASE WHEN t.status = 'approved' THEN 1 ELSE 0 END) AS completed_tasks,
                SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending_tasks,
                COALESCE(SUM(CASE WHEN t.status = 'approved' THEN t.points ELSE 0 END), 0) AS total_points
            FROM tasks t
            WHERE t.assigned_to = ? AND DATE_FORMAT(t.created_at, '%Y-%m') = ?");
        $stmt->execute([$auth['employee_id'], date('Y-m')]);
        $points = $stmt->fetch() ?: null;

        if ($points) {
            $points['completion_percentage'] = $points['total_tasks'] > 0
                ? round(($points['completed_tasks'] / $points['total_tasks']) * 100, 2)
                : 0;
        }
    } catch (Exception $e) {
        error_log('IT dashboard points: ' . $e->getMessage());
    }

    return [
        'success' => true,
        'data' => [
            'tasks' => $tasks['data'] ?? [],
            'points' => $points,
        ],
    ];
}

function getTaskList($db, $data) {
    $status = $data['status'] ?? '';
    $assignedTo = $data['assigned_to'] ?? '';
    $taskType = $data['task_type'] ?? '';

    $sql = "SELECT t.*, e.first_name, e.last_name, e.employee_code,
                   (SELECT ts.status FROM task_submissions ts WHERE ts.task_id = t.id ORDER BY ts.submitted_at DESC LIMIT 1) as submission_status
            FROM tasks t
            JOIN employees e ON e.id = t.assigned_to
            WHERE 1=1";
    $params = [];

    if ($status) { $sql .= " AND t.status = ?"; $params[] = $status; }
    if ($assignedTo) { $sql .= " AND t.assigned_to = ?"; $params[] = $assignedTo; }
    if ($taskType) { $sql .= " AND t.task_type = ?"; $params[] = $taskType; }

    $sql .= " ORDER BY t.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyTasks($db, $auth) {
    $eid = $auth['employee_id'];
    $status = $_GET['status'] ?? '';

    $sql = "SELECT t.*,
                   (SELECT ts.status FROM task_submissions ts WHERE ts.task_id = t.id ORDER BY ts.submitted_at DESC LIMIT 1) as submission_status,
                   (SELECT ts.submitted_at FROM task_submissions ts WHERE ts.task_id = t.id ORDER BY ts.submitted_at DESC LIMIT 1) as last_submitted_at
            FROM tasks t
            WHERE t.assigned_to = ?";
    $params = [$eid];

    if ($status) { $sql .= " AND t.status = ?"; $params[] = $status; }

    $sql .= " ORDER BY t.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createTask($db, $auth, $data) {
    $title = Validator::sanitize($data['title'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');
    $taskType = Validator::sanitize($data['task_type'] ?? '');
    $priority = $data['priority'] ?? 'medium';
    $assignedTo = (int)($data['assigned_to'] ?? 0);
    $deadline = $data['deadline'] ?? null;
    $taskCategoryId = $data['task_category_id'] ?? null;

    if (!$title || !$assignedTo) {
        return ['success' => false, 'message' => 'title and assigned_to are required'];
    }

    $points = (int)($data['points'] ?? 0);
    if ($points === 0 && $taskType) {
        $stmt = $db->prepare("SELECT points FROM task_points_config WHERE task_type = ? AND status = 1");
        $stmt->execute([$taskType]);
        $config = $stmt->fetch();
        if ($config) $points = (int)$config['points'];
    }

    $stmt = $db->prepare("
        INSERT INTO tasks (title, description, task_category_id, task_type, priority, assigned_by, assigned_to, deadline, points, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$title, $description, $taskCategoryId, $taskType, $priority, $auth['user_id'], $assignedTo, $deadline, $points]);

    return ['success' => true, 'message' => 'Task created', 'id' => $db->lastInsertId()];
}

function submitTaskWork($db, $auth, $taskId, $data) {
    // The app posts the id as a form field rather than in the path.
    if (!$taskId) $taskId = $data['task_id'] ?? 0;
    $taskId = intval($taskId);
    if (!$taskId) return ['success' => false, 'message' => 'Task ID required'];

    $description = Validator::sanitize($data['description'] ?? '');
    $eid = $auth['employee_id'];

    // The app sends the attachment as 'work_file'.
    $upload = $_FILES['work_file'] ?? $_FILES['file'] ?? null;

    $filePath = '';
    $previewPath = '';
    if ($upload) {
        $result = uploadFileITTask($upload, 'it_tasks');
        if ($result) {
            $filePath = $result['path'];
            $previewPath = $result['preview_path'] ?? '';
        }
    } elseif (!empty($data['file'])) {
        $result = uploadBase64ITTask($data['file'], 'it_tasks');
        if ($result) {
            $filePath = $result['path'];
        }
    }

    $stmt = $db->prepare("
        INSERT INTO task_submissions (task_id, employee_id, file_path, preview_path, description, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$taskId, $eid, $filePath, $previewPath, $description]);

    $db->prepare("UPDATE tasks SET status = 'in_progress' WHERE id = ? AND status = 'pending'")->execute([$taskId]);

    return ['success' => true, 'message' => 'Work submitted', 'id' => $db->lastInsertId()];
}

function approveTask($db, $id, $data) {
    if (!$id) return ['success' => false, 'message' => 'Task ID required'];

    $rating = (int)($data['rating'] ?? 0);
    $feedback = Validator::sanitize($data['feedback'] ?? '');

    $stmt = $db->prepare("
        UPDATE task_submissions SET status = 'approved', rating = ?, feedback = ?
        WHERE task_id = ? AND status = 'pending'
    ");
    $stmt->execute([$rating, $feedback, $id]);

    $db->prepare("UPDATE tasks SET status = 'approved', completion_time = NOW() WHERE id = ?")->execute([$id]);

    return ['success' => true, 'message' => 'Task approved'];
}

function rejectTask($db, $id, $data) {
    if (!$id) return ['success' => false, 'message' => 'Task ID required'];

    $feedback = Validator::sanitize($data['feedback'] ?? '');

    $stmt = $db->prepare("
        UPDATE task_submissions SET status = 'rejected', feedback = ?
        WHERE task_id = ? AND status = 'pending'
    ");
    $stmt->execute([$feedback, $id]);

    $db->prepare("UPDATE tasks SET status = 'correction' WHERE id = ?")->execute([$id]);

    return ['success' => true, 'message' => 'Task sent for correction'];
}

function getPointsConfig($db) {
    $stmt = $db->prepare("SELECT * FROM task_points_config WHERE status = 1 ORDER BY task_type");
    $stmt->execute();
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getTaskPerformance($db, $data) {
    $employeeId = $data['employee_id'] ?? '';
    $monthYear = $data['month_year'] ?? date('Y-m');

    $sql = "SELECT t.assigned_to, e.first_name, e.last_name, e.employee_code,
                   COUNT(t.id) as total_tasks,
                   SUM(CASE WHEN t.status = 'approved' THEN 1 ELSE 0 END) as completed_tasks,
                   SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                   COALESCE(SUM(CASE WHEN t.status = 'approved' THEN t.points ELSE 0 END), 0) as total_points
            FROM tasks t
            JOIN employees e ON e.id = t.assigned_to
            WHERE DATE_FORMAT(t.created_at, '%Y-%m') = ?";
    $params = [$monthYear];

    if ($employeeId) {
        $sql .= " AND t.assigned_to = ?";
        $params[] = $employeeId;
    }

    $sql .= " GROUP BY t.assigned_to ORDER BY total_points DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    foreach ($results as &$row) {
        $row['completion_percentage'] = $row['total_tasks'] > 0
            ? round(($row['completed_tasks'] / $row['total_tasks']) * 100, 2)
            : 0;
    }

    return ['success' => true, 'data' => $results];
}

function uploadFileITTask($file, $subdir) {
    $targetDir = UPLOAD_PATH . $subdir . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'psd', 'ai', 'mp4', 'mov'];
    if (!in_array($ext, $allowed)) return null;

    $filename = $subdir . '_' . time() . '_' . uniqid() . '.' . $ext;
    $previewPath = '';
    $imageExts = ['jpg', 'jpeg', 'png', 'gif'];
    if (in_array($ext, $imageExts)) {
        $previewPath = 'uploads/' . $subdir . '/' . $filename;
    }

    if (move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
        return [
            'path' => 'uploads/' . $subdir . '/' . $filename,
            'preview_path' => $previewPath,
        ];
    }
    return null;
}

function uploadBase64ITTask($base64Data, $subdir) {
    $targetDir = UPLOAD_PATH . $subdir . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    if (preg_match('/^data:(\w+\/\w+);base64,/', $base64Data, $type)) {
        $mimeType = $type[1];
        $extMap = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'application/pdf' => 'pdf',
        ];
        $ext = $extMap[$mimeType] ?? 'bin';
        $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
    } else {
        return null;
    }

    $base64Data = base64_decode($base64Data);
    if ($base64Data === false) return null;

    $filename = $subdir . '_' . time() . '_' . uniqid() . '.' . $ext;
    file_put_contents($targetDir . $filename, $base64Data);

    return ['path' => 'uploads/' . $subdir . '/' . $filename];
}
