<?php
function handleExpenseRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'accounts']);
            return getExpenseList($db, $data);
        case 'my':
            return getMyExpenses($db, $auth);
        case 'create':
            return createExpense($db, $auth, $data);
        case 'update_status':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'accounts']);
            return updateExpenseStatus($db, $param, $data);
        default:
            return ['success' => false, 'message' => 'Invalid expense action'];
    }
}

function getExpenseList($db, $data) {
    $status = $data['status'] ?? '';
    $employeeId = $data['employee_id'] ?? '';
    $categoryId = $data['expense_category_id'] ?? '';

    $sql = "SELECT e.*, ec.name as category_name, emp.first_name, emp.last_name, emp.employee_code
            FROM expenses e
            LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
            JOIN employees emp ON emp.id = e.employee_id
            WHERE 1=1";
    $params = [];

    if ($status) { $sql .= " AND e.status = ?"; $params[] = $status; }
    if ($employeeId) { $sql .= " AND e.employee_id = ?"; $params[] = $employeeId; }
    if ($categoryId) { $sql .= " AND e.expense_category_id = ?"; $params[] = $categoryId; }

    $sql .= " ORDER BY e.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyExpenses($db, $auth) {
    $eid = $auth['employee_id'];
    $stmt = $db->prepare("
        SELECT e.*, ec.name as category_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
        WHERE e.employee_id = ?
        ORDER BY e.created_at DESC
    ");
    $stmt->execute([$eid]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createExpense($db, $auth, $data) {
    // Whose claim this is comes from the token, not the request body. It used
    // to accept employee_id from the caller with no role check, so anyone could
    // file an expense in a colleague's name. Only the roles that legitimately
    // enter claims on behalf of staff may name someone else.
    $onBehalf = in_array($auth['role'] ?? '', ['super_admin', 'hr', 'hr_admin', 'accounts', 'accounts_admin'], true);
    $employeeId = $onBehalf && !empty($data['employee_id'])
        ? (int) $data['employee_id']
        : $auth['employee_id'];
    $categoryId = $data['expense_category_id'] ?? null;
    $amount = (float)($data['amount'] ?? 0);
    $expenseDate = Validator::sanitize($data['expense_date'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');

    if (!$expenseDate || $amount <= 0) {
        return ['success' => false, 'message' => 'expense_date and amount are required'];
    }

    $billPhoto = '';
    if (isset($_FILES['bill_photo'])) {
        $billPhoto = uploadFileExpense($_FILES['bill_photo'], 'expenses');
    } elseif (!empty($data['bill_photo'])) {
        $billPhoto = uploadBase64Expense($data['bill_photo'], 'expenses');
    }

    $stmt = $db->prepare("
        INSERT INTO expenses (employee_id, expense_category_id, amount, expense_date, description, bill_photo)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$employeeId, $categoryId, $amount, $expenseDate, $description, $billPhoto]);

    return ['success' => true, 'message' => 'Expense created', 'id' => $db->lastInsertId()];
}

function updateExpenseStatus($db, $id, $data) {
    if (!$id) return ['success' => false, 'message' => 'Expense ID required'];

    $status = $data['status'] ?? '';
    $approvedBy = $data['approved_by'] ?? null;
    $remarks = Validator::sanitize($data['remarks'] ?? '');

    if (!in_array($status, ['approved', 'rejected'])) {
        return ['success' => false, 'message' => 'Invalid status'];
    }

    $stmt = $db->prepare("UPDATE expenses SET status = ?, approved_by = ?, approval_date = NOW(), remarks = ? WHERE id = ?");
    $stmt->execute([$status, $approvedBy, $remarks, $id]);

    return ['success' => true, 'message' => 'Expense updated'];
}

function uploadFileExpense($file, $subdir) {
    $targetDir = UPLOAD_PATH . $subdir . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    if (!in_array($ext, $allowed)) return '';

    $filename = $subdir . '_' . time() . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
        return 'uploads/' . $subdir . '/' . $filename;
    }
    return '';
}

function uploadBase64Expense($base64Data, $subdir) {
    $targetDir = UPLOAD_PATH . $subdir . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
        $ext = strtolower($type[1]);
        $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
    } else {
        return '';
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($ext, $allowed)) return '';

    $base64Data = base64_decode($base64Data);
    if ($base64Data === false) return '';

    $filename = $subdir . '_' . time() . '_' . uniqid() . '.' . $ext;
    file_put_contents($targetDir . $filename, $base64Data);

    return 'uploads/' . $subdir . '/' . $filename;
}
