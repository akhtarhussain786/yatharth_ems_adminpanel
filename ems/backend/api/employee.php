<?php
// employees.is_field_staff is handled once by runSchemaMigrations() in config.php
// rather than re-probed on every request.

function handleEmployeeRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();

    switch ($action) {
        case 'profile':
            return getProfile($db, $auth);
        case 'update':
            return updateProfile($db, $auth);
        case 'change_password':
            return changePassword($db, $auth);
        case 'attendance':
            return getMyAttendance($db, $auth, $param);
        case 'list':
            AuthMiddleware::checkRole(['super_admin', 'admin', 'hr', 'hr_admin', 'sub_admin']);
            return getEmployeeList($db, $param);
        case 'create':
            AuthMiddleware::checkRole(['super_admin', 'admin', 'hr', 'hr_admin']);
            return createEmployee($db);
        case 'update_employee':
            AuthMiddleware::checkRole(['super_admin', 'admin', 'hr', 'hr_admin']);
            return updateEmployee($db, $param);
        case 'roles':
            return getRoles($db);
        default:
            return ['success' => false, 'message' => 'Invalid employee action'];
    }
}

function getProfile($db, $auth) {
    $stmt = $db->prepare("
        SELECT e.*, d.name as department_name, des.name as designation_name
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN designations des ON des.id = e.designation_id
        WHERE e.id = ?
    ");
    $stmt->execute([$auth['employee_id']]);
    $employee = $stmt->fetch();

    if (!$employee) {
        return ['success' => false, 'message' => 'Employee not found'];
    }

    $stmt = $db->prepare("
        SELECT * FROM attendance 
        WHERE employee_id = ? AND attendance_date = CURDATE()
        LIMIT 1
    ");
    $stmt->execute([$auth['employee_id']]);
    $todayAttendance = $stmt->fetch();

    return [
        'success' => true,
        'data' => [
            'employee' => $employee,
            'today_attendance' => $todayAttendance,
        ],
    ];
}

function updateProfile($db, $auth) {
    $data = json_decode($GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input'), true) ?? $_POST;
    $allowed = ['mobile', 'address', 'city', 'state', 'pincode'];

    $updates = [];
    $params = [];
    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = :$field";
            $params[":$field"] = Validator::sanitize($data[$field]);
        }
    }

    // A failed photo upload used to be swallowed silently, leaving the caller
    // with "No data to update" and no idea why the picture never appeared.
    $photoPath = null;
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['profile_photo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Photo upload failed (code ' . $file['error'] . ')'];
        }
        if ($file['size'] > 8 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Photo must be 8MB or smaller'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return ['success' => false, 'message' => 'Photo must be a JPG or PNG'];
        }

        $mime = @getimagesize($file['tmp_name']);
        if ($mime === false || !in_array($mime['mime'], ['image/jpeg', 'image/png'], true)) {
            return ['success' => false, 'message' => 'That file is not a valid image'];
        }

        $dir = UPLOAD_PATH . 'profiles/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('updateProfile: cannot create ' . $dir);
            return ['success' => false, 'message' => 'Server cannot store photos right now'];
        }

        $filename = 'profile_' . $auth['employee_id'] . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            error_log('updateProfile: move_uploaded_file failed for ' . $filename);
            return ['success' => false, 'message' => 'Could not save the photo'];
        }

        $photoPath = 'uploads/profiles/' . $filename;
        $updates[] = "profile_photo = :profile_photo";
        $params[':profile_photo'] = $photoPath;
    }

    if (empty($updates)) {
        return ['success' => false, 'message' => 'No data to update'];
    }

    $params[':id'] = $auth['employee_id'];
    $sql = "UPDATE employees SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $db->prepare($sql);

    if ($stmt->execute($params)) {
        $response = ['success' => true, 'message' => 'Profile updated successfully'];
        // Returned so the app can show the server copy immediately.
        if ($photoPath !== null) {
            $response['profile_photo'] = $photoPath;
            $response['profile_photo_url'] = rtrim(BASE_URL, '/') . '/' . $photoPath;
        }
        return $response;
    }
    return ['success' => false, 'message' => 'Update failed'];
}

function getMyAttendance($db, $auth, $param) {
    $month = $param ?: date('Y-m');

    $stmt = $db->prepare("
        SELECT * FROM attendance 
        WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
        ORDER BY attendance_date DESC
    ");
    $stmt->execute([$auth['employee_id'], $month]);
    $records = $stmt->fetchAll();

    return ['success' => true, 'data' => $records];
}

function changePassword($db, $auth) {
    $data = json_decode($GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input'), true) ?? $_POST;
    $current = $data['current_password'] ?? '';
    $new = $data['new_password'] ?? '';

    if (strlen($new) < 6) {
        return ['success' => false, 'message' => 'New password must be at least 6 characters'];
    }

    $stmt = $db->prepare("SELECT id, password FROM users WHERE id = ?");
    $stmt->execute([$auth['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }

    if (!password_verify($current, $user['password'])) {
        return ['success' => false, 'message' => 'Current password is incorrect'];
    }

    $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);

    return ['success' => true, 'message' => 'Password changed successfully'];
}

function getEmployeeList($db, $param) {
    $departmentId = $_GET['department_id'] ?? null;
    $search = $_GET['search'] ?? null;

    $sql = "SELECT e.*, d.name as department_name, des.name as designation_name,
                   u.id as user_id, u.role_id as user_role_id, u.status as user_active,
                   u.username, r.name as role_name, r.description as role_display
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN designations des ON des.id = e.designation_id
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE 1=1";
    $params = [];

    if ($departmentId) {
        $sql .= " AND e.department_id = :dept_id";
        $params[':dept_id'] = $departmentId;
    }
    if ($search) {
        $sql .= " AND (e.first_name LIKE :search OR e.last_name LIKE :search2 OR e.employee_code LIKE :search3)";
        $params[':search'] = "%$search%";
        $params[':search2'] = "%$search%";
        $params[':search3'] = "%$search%";
    }

    $sql .= " ORDER BY e.first_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    return ['success' => true, 'data' => $employees];
}

function createEmployee($db) {
    $data = json_decode($GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input'), true) ?? $_POST;

    $validator = Validator::validate($data, [
        'first_name' => 'required',
        'employee_code' => 'required',
        'email' => 'email',
    ]);

    if (!empty($validator)) {
        return ['success' => false, 'errors' => $validator];
    }

    $stmt = $db->prepare("SELECT id FROM employees WHERE employee_code = ?");
    $stmt->execute([$data['employee_code']]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Employee code already exists'];
    }

    $createLogin = isset($data['create_login']) ? (int)$data['create_login'] : 0;
    $userId = null;

    if ($createLogin) {
        $username = $data['username'] ?? $data['employee_code'];
        $password = password_hash($data['password'] ?? 'employee123', PASSWORD_BCRYPT);
        $roleId = $data['role_id'] ?? 5;

        $chk = $db->prepare("SELECT id FROM users WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            return ['success' => false, 'message' => 'Username already exists'];
        }

        // See the note in admin_panel/modules/employees.php: employee_id here
        // is an integer column being given the employee code, it is not in the
        // schema, and nothing reads it. The link is employees.user_id.
        $stmt = $db->prepare("INSERT INTO users (username, email, password, role_id, status) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$username, $data['email'] ?? '', $password, $roleId]);
        $userId = $db->lastInsertId();
    }

    $stmt = $db->prepare("
        INSERT INTO employees (user_id, employee_code, first_name, last_name, mobile, email,
                              department_id, designation_id, joining_date, salary, address,
                              city, state, pincode, status, is_field_staff)
        VALUES (:user_id, :employee_code, :first_name, :last_name, :mobile, :email,
                :department_id, :designation_id, :joining_date, :salary, :address,
                :city, :state, :pincode, :status, :is_field_staff)
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':employee_code' => $data['employee_code'],
        ':first_name' => $data['first_name'],
        ':last_name' => $data['last_name'] ?? '',
        ':mobile' => $data['mobile'] ?? '',
        ':email' => $data['email'] ?? '',
        ':department_id' => $data['department_id'] ?? null,
        ':designation_id' => $data['designation_id'] ?? null,
        ':joining_date' => $data['joining_date'] ?? null,
        ':salary' => $data['salary'] ?? 0,
        ':address' => $data['address'] ?? '',
        ':city' => $data['city'] ?? '',
        ':state' => $data['state'] ?? '',
        ':pincode' => $data['pincode'] ?? '',
        ':status' => $data['status'] ?? 1,
        ':is_field_staff' => $data['is_field_staff'] ?? 0,
    ]);

    return ['success' => true, 'message' => 'Employee created successfully', 'user_id' => $userId];
}

function updateEmployee($db, $id) {
    if (!$id) return ['success' => false, 'message' => 'Employee ID required'];

    $data = json_decode($GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input'), true) ?? $_POST;

    $allowed = ['first_name', 'last_name', 'mobile', 'email', 'department_id', 'designation_id',
                'joining_date', 'salary', 'address', 'city', 'state', 'pincode', 'status',
                'is_field_staff'];

    $updates = [];
    $params = [':id' => $id];
    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = :$field";
            $params[":$field"] = Validator::sanitize($data[$field]);
        }
    }

    if (!empty($updates)) {
        $sql = "UPDATE employees SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }

    if (isset($data['role_id']) || isset($data['user_status'])) {
        $emp = $db->prepare("SELECT user_id FROM employees WHERE id = ?")->execute([$id]);
        $empRow = $db->prepare("SELECT user_id FROM employees WHERE id = ?");
        $empRow->execute([$id]);
        $e = $empRow->fetch();
        if ($e && $e['user_id']) {
            $userUpdates = [];
            $userParams = [':id' => $e['user_id']];
            if (isset($data['role_id'])) {
                $userUpdates[] = "role_id = :role_id";
                $userParams[':role_id'] = (int)$data['role_id'];
            }
            if (isset($data['user_status'])) {
                $userUpdates[] = "status = :status";
                $userParams[':status'] = (int)$data['user_status'];
            }
            if (!empty($userUpdates)) {
                $db->prepare("UPDATE users SET " . implode(', ', $userUpdates) . " WHERE id = :id")->execute($userParams);
            }
        }
    }

    return ['success' => true, 'message' => 'Employee updated successfully'];
}

function getRoles($db) {
    return ['success' => true, 'data' => $db->query("SELECT id, name, description FROM roles ORDER BY id")->fetchAll()];
}
