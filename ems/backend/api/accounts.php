<?php
function handleAccountRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'vendors':
            return handleVendors($db, $auth, $param, $data);
        case 'income_expense':
            return handleIncomeExpense($db, $auth, $param, $data);
        case 'fee_collections':
            return handleFeeCollections($db, $auth, $param, $data);
        case 'salary_process':
            return handleSalaryProcess($db, $auth, $data);
        default:
            return ['success' => false, 'message' => 'Invalid account action'];
    }
}

function handleVendors($db, $auth, $param, $data) {
    $method = $_SERVER['REQUEST_METHOD'];
    $subAction = $data['action'] ?? '';

    if ($method === 'GET' || $subAction === 'list') {
        $stmt = $db->prepare("SELECT * FROM vendors WHERE status = 1 ORDER BY name ASC");
        $stmt->execute();
        return ['success' => true, 'data' => $stmt->fetchAll()];
    }

    if ($method === 'POST' || $subAction === 'create') {
        AuthMiddleware::checkRole(['super_admin', 'accounts']);
        $name = Validator::sanitize($data['name'] ?? '');
        if (!$name) return ['success' => false, 'message' => 'Vendor name required'];

        $stmt = $db->prepare("
            INSERT INTO vendors (name, contact_person, mobile, email, address, gst_no, pan_no)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name,
            Validator::sanitize($data['contact_person'] ?? ''),
            Validator::sanitize($data['mobile'] ?? ''),
            Validator::sanitize($data['email'] ?? ''),
            Validator::sanitize($data['address'] ?? ''),
            Validator::sanitize($data['gst_no'] ?? ''),
            Validator::sanitize($data['pan_no'] ?? ''),
        ]);
        return ['success' => true, 'message' => 'Vendor created', 'id' => $db->lastInsertId()];
    }

    if (($method === 'PUT' || $subAction === 'update') && $param) {
        AuthMiddleware::checkRole(['super_admin', 'accounts']);
        $stmt = $db->prepare("
            UPDATE vendors SET name=?, contact_person=?, mobile=?, email=?, address=?, gst_no=?, pan_no=?
            WHERE id=?
        ");
        $stmt->execute([
            Validator::sanitize($data['name'] ?? ''),
            Validator::sanitize($data['contact_person'] ?? ''),
            Validator::sanitize($data['mobile'] ?? ''),
            Validator::sanitize($data['email'] ?? ''),
            Validator::sanitize($data['address'] ?? ''),
            Validator::sanitize($data['gst_no'] ?? ''),
            Validator::sanitize($data['pan_no'] ?? ''),
            $param,
        ]);
        return ['success' => true, 'message' => 'Vendor updated'];
    }

    if (($method === 'DELETE' || $subAction === 'delete') && $param) {
        AuthMiddleware::checkRole(['super_admin', 'accounts']);
        $stmt = $db->prepare("UPDATE vendors SET status = 0 WHERE id = ?");
        $stmt->execute([$param]);
        return ['success' => true, 'message' => 'Vendor deleted'];
    }

    return ['success' => false, 'message' => 'Invalid vendor action'];
}

function handleIncomeExpense($db, $auth, $param, $data) {
    $method = $_SERVER['REQUEST_METHOD'];
    $subAction = $data['action'] ?? '';

    if ($method === 'GET' || $subAction === 'list') {
        $type = $data['type'] ?? '';
        $fromDate = $data['from_date'] ?? '';
        $toDate = $data['to_date'] ?? '';

        $sql = "SELECT ie.*, v.name as vendor_name
                FROM income_expense ie
                LEFT JOIN vendors v ON v.id = ie.vendor_id
                WHERE 1=1";
        $params = [];

        if ($type) { $sql .= " AND ie.type = ?"; $params[] = $type; }
        if ($fromDate) { $sql .= " AND ie.entry_date >= ?"; $params[] = $fromDate; }
        if ($toDate) { $sql .= " AND ie.entry_date <= ?"; $params[] = $toDate; }

        $sql .= " ORDER BY ie.entry_date DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return ['success' => true, 'data' => $stmt->fetchAll()];
    }

    if ($method === 'POST' || $subAction === 'create') {
        AuthMiddleware::checkRole(['super_admin', 'accounts']);
        $type = $data['type'] ?? '';
        $amount = (float)($data['amount'] ?? 0);
        $entryDate = $data['entry_date'] ?? '';

        if (!in_array($type, ['income', 'expense']) || !$amount || !$entryDate) {
            return ['success' => false, 'message' => 'type, amount, and entry_date required'];
        }

        $billPhoto = '';
        if (isset($_FILES['bill_photo'])) {
            $billPhoto = uploadFileAccounts($_FILES['bill_photo'], 'accounts');
        } elseif (!empty($data['bill_photo'])) {
            $billPhoto = uploadBase64Accounts($data['bill_photo'], 'accounts');
        }

        $stmt = $db->prepare("
            INSERT INTO income_expense (type, category, amount, entry_date, description, payment_method, reference_no, vendor_id, bill_photo, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $type,
            Validator::sanitize($data['category'] ?? ''),
            $amount,
            $entryDate,
            Validator::sanitize($data['description'] ?? ''),
            Validator::sanitize($data['payment_method'] ?? ''),
            Validator::sanitize($data['reference_no'] ?? ''),
            $data['vendor_id'] ?? null,
            $billPhoto,
            $auth['user_id'],
        ]);
        return ['success' => true, 'message' => 'Entry created', 'id' => $db->lastInsertId()];
    }

    if (($method === 'DELETE' || $subAction === 'delete') && $param) {
        AuthMiddleware::checkRole(['super_admin', 'accounts']);
        $stmt = $db->prepare("DELETE FROM income_expense WHERE id = ?");
        $stmt->execute([$param]);
        return ['success' => true, 'message' => 'Entry deleted'];
    }

    return ['success' => false, 'message' => 'Invalid income/expense action'];
}

function handleFeeCollections($db, $auth, $param, $data) {
    $method = $_SERVER['REQUEST_METHOD'];
    $subAction = $data['action'] ?? '';

    if ($method === 'GET' || $subAction === 'list') {
        $fromDate = $data['from_date'] ?? '';
        $toDate = $data['to_date'] ?? '';

        $sql = "SELECT fc.*, CONCAT(e.first_name, ' ', e.last_name) as collected_by_name
                FROM fee_collections fc
                LEFT JOIN users u ON u.id = fc.collected_by
                LEFT JOIN employees e ON e.user_id = u.id
                WHERE 1=1";
        $params = [];

        if ($fromDate) { $sql .= " AND fc.fee_date >= ?"; $params[] = $fromDate; }
        if ($toDate) { $sql .= " AND fc.fee_date <= ?"; $params[] = $toDate; }

        $sql .= " ORDER BY fc.fee_date DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return ['success' => true, 'data' => $stmt->fetchAll()];
    }

    if ($method === 'POST' || $subAction === 'create') {
        AuthMiddleware::checkRole(['super_admin', 'accounts']);
        $studentName = Validator::sanitize($data['student_name'] ?? '');
        $amount = (float)($data['amount'] ?? 0);
        $feeDate = $data['fee_date'] ?? '';

        if (!$studentName || !$amount || !$feeDate) {
            return ['success' => false, 'message' => 'student_name, amount, and fee_date required'];
        }

        $receiptNo = $data['receipt_no'] ?? 'RCP-' . date('Ymd') . '-' . uniqid();

        $stmt = $db->prepare("
            INSERT INTO fee_collections (lead_id, student_name, amount, fee_date, fee_type, payment_method, reference_no, receipt_no, remarks, collected_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['lead_id'] ?? null,
            $studentName,
            $amount,
            $feeDate,
            $data['fee_type'] ?? 'tuition',
            Validator::sanitize($data['payment_method'] ?? ''),
            Validator::sanitize($data['reference_no'] ?? ''),
            $receiptNo,
            Validator::sanitize($data['remarks'] ?? ''),
            $auth['user_id'],
        ]);
        return ['success' => true, 'message' => 'Fee collection created', 'id' => $db->lastInsertId(), 'receipt_no' => $receiptNo];
    }

    if (($method === 'DELETE' || $subAction === 'delete') && $param) {
        AuthMiddleware::checkRole(['super_admin', 'accounts']);
        $stmt = $db->prepare("DELETE FROM fee_collections WHERE id = ?");
        $stmt->execute([$param]);
        return ['success' => true, 'message' => 'Fee collection deleted'];
    }

    return ['success' => false, 'message' => 'Invalid fee collection action'];
}

require_once __DIR__ . '/../helpers/payroll_helper.php';

function handleSalaryProcess($db, $auth, $data) {
    AuthMiddleware::checkRole(['super_admin', 'accounts', 'accounts_admin']);
    $monthYear = filterMonth($data['month_year'] ?? date('Y-m'));
    $employeeId = $data['employee_id'] ?? '';

    $sql = "SELECT id FROM employees WHERE status = 1";
    $params = [];
    if ($employeeId) { $sql .= " AND id = ?"; $params[] = $employeeId; }
    $sql .= " ORDER BY first_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    $processed = 0;
    foreach ($employees as $emp) {
        $calc = calculateEmployeeSalary($db, (int)$emp['id'], $monthYear);
        if ($calc['success'] && $calc['is_employed_this_month']) {
            if (isset($data['allowances'])) $calc['allowances'] = (float)$data['allowances'];
            if (isset($data['bonus_amount'])) $calc['bonus_amount'] = (float)$data['bonus_amount'];
            if (isset($data['other_deductions'])) $calc['other_deductions'] = (float)$data['other_deductions'];
            
            saveSalaryPayrollRecord($db, $calc, $auth['user_id']);
            $processed++;
        }
    }

    return ['success' => true, 'message' => "Salary processed for $processed employees for $monthYear"];
}

function uploadFileAccounts($file, $subdir) {
    $targetDir = UPLOAD_PATH . $subdir . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    if (!in_array($ext, $allowed)) return '';

    $filename = $subdir . '_' . time() . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
        return 'uploads/' . $subdir . '/' . $filename;
    }
    return '';
}

function uploadBase64Accounts($base64Data, $subdir) {
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
