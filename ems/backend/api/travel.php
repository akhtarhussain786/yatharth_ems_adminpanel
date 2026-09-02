<?php
/** Roles allowed to see everyone's travel and approve it. */
function travelAdminRoles() {
    // 'accounts' was checked here but no such role exists — the accounts team
    // is 'accounts_admin', so they were locked out of the whole module.
    return ['super_admin', 'admin', 'hr', 'hr_admin', 'accounts_admin'];
}

/**
 * total_km is a generated column in the shipped schema, so it can never be
 * written — the previous INSERT and UPDATE both assigned to it, which made
 * every create and every approval fail outright. It is derived on read instead,
 * which is correct whether or not the live column is generated.
 */
function travelSelectColumns() {
    return "t.*, GREATEST(COALESCE(t.end_km,0) - COALESCE(t.start_km,0), 0) AS total_km";
}

function handleTravelRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode($GLOBALS['_RAW_INPUT'] ?? '', true) ?: $_POST;

    switch ($action) {
        case 'list':
            AuthMiddleware::checkRole(travelAdminRoles());
            return getTravelList($db, $data);
        case 'my':
            return getMyTravel($db, $auth);
        case 'create':
            return createTravel($db, $auth, $data);
        case 'complete':
            // An employee closes out their own trip; this is what makes the
            // mileage allowance calculable without an admin doing it for them.
            return completeTravel($db, $auth, $param ?: ($data['id'] ?? 0), $data);
        case 'update_status':
            AuthMiddleware::checkRole(travelAdminRoles());
            return updateTravelStatus($db, $auth, $param ?: ($data['id'] ?? 0), $data);
        default:
            return ['success' => false, 'message' => 'Invalid travel action'];
    }
}

function getTravelList($db, $data) {
    $status = $data['status'] ?? '';
    $employeeId = $data['employee_id'] ?? '';

    $sql = "SELECT " . travelSelectColumns() . ", e.first_name, e.last_name, e.employee_code,
                   d.name as department_name
            FROM travel_requests t
            JOIN employees e ON e.id = t.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE 1=1";
    $params = [];

    if ($status) { $sql .= " AND t.status = ?"; $params[] = $status; }
    if ($employeeId) { $sql .= " AND t.employee_id = ?"; $params[] = $employeeId; }

    $sql .= " ORDER BY t.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyTravel($db, $auth) {
    $stmt = $db->prepare("SELECT " . travelSelectColumns() . "
                          FROM travel_requests t
                          WHERE t.employee_id = ? ORDER BY t.created_at DESC");
    $stmt->execute([$auth['employee_id']]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createTravel($db, $auth, $data) {
    $employeeId = $data['employee_id'] ?? $auth['employee_id'];
    $travelDate = Validator::sanitize($data['travel_date'] ?? '');
    $purpose = Validator::sanitize($data['purpose'] ?? '');
    $startKm = (int)($data['start_km'] ?? 0);
    $startLocation = Validator::sanitize($data['start_location'] ?? '');
    $ratePerKm = (float)($data['rate_per_km'] ?? 2.50);

    if (!$travelDate || !$purpose) {
        return ['success' => false, 'message' => 'travel_date and purpose are required'];
    }

    $startPhoto = '';
    if (isset($_FILES['start_photo'])) {
        $startPhoto = uploadFile($_FILES['start_photo'], 'travel');
    } elseif (!empty($data['start_photo'])) {
        $startPhoto = uploadBase64($data['start_photo'], 'travel');
    }

    $odometerPhoto = '';
    if (isset($_FILES['odometer_start_photo'])) {
        $odometerPhoto = uploadFile($_FILES['odometer_start_photo'], 'travel');
    } elseif (!empty($data['odometer_start_photo'])) {
        $odometerPhoto = uploadBase64($data['odometer_start_photo'], 'travel');
    }

    // total_km is deliberately absent: it is a generated column, and assigning
    // to it is what made every create fail.
    $stmt = $db->prepare("
        INSERT INTO travel_requests (employee_id, travel_date, purpose, start_km,
                                     travel_allowance, rate_per_km, start_location,
                                     start_photo, odometer_start_photo, status)
        VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $employeeId, $travelDate, $purpose, $startKm,
        $ratePerKm, $startLocation, $startPhoto, $odometerPhoto
    ]);

    return ['success' => true, 'message' => 'Travel request created', 'id' => $db->lastInsertId()];
}

/**
 * Records the closing odometer reading and works out the allowance.
 *
 * Completing a trip used to be possible only through the admin-only approval
 * endpoint, so an employee could start a journey and had no way to finish it —
 * the allowance could never be calculated. When $auth is an ordinary user the
 * update is scoped to their own row.
 */
function completeTravel($db, $auth, $id, $data, $adminOverride = false) {
    $id = (int) $id;
    if (!$id) return ['success' => false, 'message' => 'Travel ID required'];

    $endKm = (int) ($data['end_km'] ?? 0);
    if ($endKm <= 0) return ['success' => false, 'message' => 'Closing odometer reading is required'];

    $scope = $adminOverride ? '' : ' AND employee_id = ?';
    $lookup = $db->prepare("SELECT id, start_km, end_km, status FROM travel_requests WHERE id = ?" . $scope);
    $lookup->execute($adminOverride ? [$id] : [$id, $auth['employee_id']]);
    $trip = $lookup->fetch();

    if (!$trip) return ['success' => false, 'message' => 'Travel request not found'];
    if ($endKm < (int) $trip['start_km']) {
        return ['success' => false, 'message' => 'Closing reading cannot be lower than the starting reading'];
    }
    if (!$adminOverride && $trip['status'] !== 'pending') {
        return ['success' => false, 'message' => 'This trip has already been ' . $trip['status']];
    }

    $endLocation = Validator::sanitize($data['end_location'] ?? '');
    $endPhoto = travelUpload('end_photo', $data);
    $odometerEndPhoto = travelUpload('odometer_end_photo', $data);

    // The allowance is derived in SQL from the row's own start_km and
    // rate_per_km, so the formula lives in one place. total_km is not written:
    // it is generated.
    $sql = "UPDATE travel_requests
            SET end_km = ?,
                travel_allowance = GREATEST(? - start_km, 0) * COALESCE(rate_per_km, 0)";
    $params = [$endKm, $endKm];

    if ($endLocation !== '')     { $sql .= ", end_location = ?";         $params[] = $endLocation; }
    if ($endPhoto !== '')        { $sql .= ", end_photo = ?";            $params[] = $endPhoto; }
    if ($odometerEndPhoto !== '') { $sql .= ", odometer_end_photo = ?";  $params[] = $odometerEndPhoto; }

    $sql .= " WHERE id = ?";
    $params[] = $id;

    $db->prepare($sql)->execute($params);

    $read = $db->prepare("SELECT " . travelSelectColumns() . ", t.travel_allowance
                          FROM travel_requests t WHERE t.id = ?");
    $read->execute([$id]);

    return ['success' => true, 'message' => 'Trip completed', 'data' => $read->fetch()];
}

/** Approve or reject. Recording the closing reading is a separate concern. */
function updateTravelStatus($db, $auth, $id, $data) {
    $id = (int) $id;
    if (!$id) return ['success' => false, 'message' => 'Travel ID required'];

    $status = $data['status'] ?? '';
    if (!in_array($status, ['approved', 'rejected'], true)) {
        return ['success' => false, 'message' => 'Status must be approved or rejected'];
    }

    // An admin may still close the trip in the same step, for staff who forgot.
    if ((int) ($data['end_km'] ?? 0) > 0) {
        completeTravel($db, $auth, $id, $data, true);
    }

    $db->prepare("UPDATE travel_requests SET status = ?, approved_by = ?, remarks = ? WHERE id = ?")
       ->execute([
           $status,
           $data['approved_by'] ?? $auth['user_id'] ?? null,
           Validator::sanitize($data['remarks'] ?? ''),
           $id,
       ]);

    return ['success' => true, 'message' => 'Travel request ' . $status];
}

/** Accepts either a multipart file or a base64 payload under the same name. */
function travelUpload($field, $data) {
    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        return uploadFile($_FILES[$field], 'travel');
    }
    if (!empty($data[$field])) {
        return uploadBase64($data[$field], 'travel');
    }
    return '';
}

function uploadFile($file, $subdir) {
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

function uploadBase64($base64Data, $subdir) {
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
