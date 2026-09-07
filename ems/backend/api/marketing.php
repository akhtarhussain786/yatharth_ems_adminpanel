<?php
/**
 * Field marketing: daily duty sessions and institute visits.
 *
 * The app has always called these four actions; the handler was missing, so
 * every request fell through the router to "Invalid endpoint". Request and
 * response shapes here match what marketing_screen.dart already sends and reads.
 */

function handleMarketingRequest($action, $param) {
    try {
        $auth = AuthMiddleware::authenticate();
        $db = (new Database())->getConnection();
        $data = json_decode($GLOBALS['_RAW_INPUT'] ?? '', true) ?: $_POST;

        switch ($action) {
            case 'dashboard':
                PermissionHelper::checkPermission($db, $auth, 'marketing', 'can_view');
                return getMarketingDashboard($db, $auth);
            case 'duty-start':
            case 'duty_start':
                PermissionHelper::checkPermission($db, $auth, 'marketing', 'can_create');
                return startDuty($db, $auth, $data);
            case 'duty-end':
            case 'duty_end':
                PermissionHelper::checkPermission($db, $auth, 'marketing', 'can_edit');
                return endDuty($db, $auth, $data);
            case 'visit-create':
            case 'visit_create':
                PermissionHelper::checkPermission($db, $auth, 'marketing', 'can_create');
                return createFieldVisit($db, $auth, $data);
            case 'visits':
                PermissionHelper::checkPermission($db, $auth, 'marketing', 'can_view');
                return ['success' => true, 'data' => getRecentVisits($db, $auth, $data)];
            case 'overview':
                PermissionHelper::checkPermission($db, $auth, 'marketing', 'can_view');
                return getMarketingOverview($db, $auth, $data);
            default:
                return ['success' => false, 'message' => 'Invalid marketing action'];
        }
    } catch (Exception $e) {
        error_log('Marketing Error: ' . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}

/** Per-km reimbursement rate, overridable from the settings table. */
function marketingRatePerKm($db) {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'travel_rate_per_km' LIMIT 1");
        $stmt->execute();
        $value = $stmt->fetchColumn();
        if ($value !== false && $value !== null && $value !== '') return (float) $value;
    } catch (Exception $e) {
        // fall through to the default used elsewhere in the codebase
    }
    return 2.50;
}

function getActiveDuty($db, $employeeId) {
    $stmt = $db->prepare("SELECT * FROM duty_logs
        WHERE employee_id = ? AND status = 'active'
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$employeeId]);
    return $stmt->fetch();
}

function getRecentVisits($db, $auth, $data = [], $limit = 100) {
    $role = $auth['role'] ?? '';
    $eid = (int)($auth['employee_id'] ?? 0);
    $isAdminOrHR = in_array($role, ['super_admin', 'admin', 'hr_admin', 'hr', 'hr_executive', 'digital_marketing_admin', 'marketing_admin'], true);

    $sql = "SELECT fv.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
            FROM field_visits fv
            LEFT JOIN employees e ON e.id = fv.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE 1=1";
    $params = [];

    if (isset($data['employee_id']) && $data['employee_id'] !== '' && $isAdminOrHR) {
        $sql .= " AND fv.employee_id = ?";
        $params[] = (int)$data['employee_id'];
    } elseif (!$isAdminOrHR) {
        $sql .= " AND fv.employee_id = ?";
        $params[] = $eid;
    }

    if (isset($data['date']) && $data['date'] !== '') {
        $sql .= " AND fv.visit_date = ?";
        $params[] = $data['date'];
    }

    $sql .= " ORDER BY fv.visit_date DESC, fv.id DESC LIMIT $limit";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getMarketingOverview($db, $auth, $data) {
    $role = $auth['role'] ?? '';
    $eid = (int)($auth['employee_id'] ?? 0);
    $isAdminOrHR = in_array($role, ['super_admin', 'admin', 'hr_admin', 'hr', 'hr_executive', 'digital_marketing_admin', 'marketing_admin'], true);

    $dutySql = "SELECT dl.*, e.first_name, e.last_name, e.employee_code 
                FROM duty_logs dl 
                LEFT JOIN employees e ON e.id = dl.employee_id 
                WHERE 1=1";
    $params = [];

    if (isset($data['employee_id']) && $data['employee_id'] !== '' && $isAdminOrHR) {
        $dutySql .= " AND dl.employee_id = ?";
        $params[] = (int)$data['employee_id'];
    } elseif (!$isAdminOrHR) {
        $dutySql .= " AND dl.employee_id = ?";
        $params[] = $eid;
    }

    $dutySql .= " ORDER BY dl.duty_date DESC, dl.id DESC LIMIT 50";
    $stmt = $db->prepare($dutySql);
    $stmt->execute($params);
    $dutyLogs = $stmt->fetchAll();

    return [
        'success' => true,
        'data' => [
            'duty_logs' => $dutyLogs,
            'visits' => getRecentVisits($db, $auth, $data, 50),
        ],
    ];
}

function getMarketingDashboard($db, $auth) {
    $eid = $auth['employee_id'];

    // Today's session if one is open, otherwise the most recent one.
    $duty = getActiveDuty($db, $eid);
    if (!$duty) {
        $stmt = $db->prepare("SELECT * FROM duty_logs WHERE employee_id = ?
            ORDER BY duty_date DESC, id DESC LIMIT 1");
        $stmt->execute([$eid]);
        $duty = $stmt->fetch();
    }

    if ($duty) {
        $duty['is_active'] = ($duty['status'] ?? '') === 'active';

        // The app reads travel_allowance as a nested object; it is derived here
        // rather than stored so it always reflects the current rate.
        $startKm = (int) ($duty['start_km'] ?? 0);
        $endKm = (int) ($duty['end_km'] ?? 0);
        $totalKm = $endKm > $startKm ? $endKm - $startKm : 0;
        $rate = marketingRatePerKm($db);

        $duty['travel_allowance'] = [
            'total_km' => $totalKm,
            'rate_per_km' => $rate,
            'total_amount' => round($totalKm * $rate, 2),
        ];
    }

    return [
        'success' => true,
        'data' => [
            'duty' => $duty ?: null,
            'visits' => getRecentVisits($db, $auth),
        ],
    ];
}

function startDuty($db, $auth, $data) {
    $eid = $auth['employee_id'];

    if (getActiveDuty($db, $eid)) {
        return ['success' => false, 'message' => 'A duty session is already running'];
    }

    $selfie = saveMarketingUpload('selfie', 'duty', $eid);
    // Backs up the start_km the employee typed in. Optional, so a build that
    // sends only the selfie still starts a duty exactly as before.
    $odometer = saveMarketingUpload('odometer_photo', 'duty', $eid);

    try {
        $stmt = $db->prepare("INSERT INTO duty_logs
                (employee_id, duty_date, start_time, start_selfie, odometer_start_photo, start_km,
                 start_latitude, start_longitude, status)
            VALUES (?, CURDATE(), NOW(), ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([
            $eid,
            $selfie,
            $odometer,
            (int) ($data['start_km'] ?? 0),
            numericOrNull($data['latitude'] ?? null),
            numericOrNull($data['longitude'] ?? null),
        ]);
    } catch (Exception $e) {
        // Nothing will ever reference these, so do not keep them.
        discardMarketingUpload($selfie);
        discardMarketingUpload($odometer);
        throw $e;
    }

    return ['success' => true, 'message' => 'Duty started', 'id' => $db->lastInsertId()];
}

function endDuty($db, $auth, $data) {
    $eid = $auth['employee_id'];

    $duty = getActiveDuty($db, $eid);
    if (!$duty) return ['success' => false, 'message' => 'No active duty session'];

    $photo = saveMarketingUpload('odometer_photo', 'duty', $eid);
    // Closing selfie, the counterpart to the one taken at the start. Optional
    // for the same reason.
    $endSelfie = saveMarketingUpload('selfie', 'duty', $eid);

    // end_km may not be collected by the app; keep start_km so the derived
    // distance stays at zero rather than going negative.
    $endKm = isset($data['end_km']) && $data['end_km'] !== ''
        ? (int) $data['end_km']
        : (int) $duty['start_km'];

    try {
        $stmt = $db->prepare("UPDATE duty_logs
            SET end_time = NOW(), end_km = ?, odometer_end_photo = ?, end_selfie = ?,
                end_latitude = ?, end_longitude = ?, status = 'completed'
            WHERE id = ? AND employee_id = ?");
        $stmt->execute([
            $endKm,
            $photo,
            $endSelfie,
            numericOrNull($data['latitude'] ?? null),
            numericOrNull($data['longitude'] ?? null),
            $duty['id'],
            $eid,
        ]);
    } catch (Exception $e) {
        discardMarketingUpload($photo);
        discardMarketingUpload($endSelfie);
        throw $e;
    }

    return ['success' => true, 'message' => 'Duty ended'];
}

function createFieldVisit($db, $auth, $data) {
    $eid = $auth['employee_id'];

    $institute = Validator::sanitize($data['institute_name'] ?? '');
    if (!$institute) return ['success' => false, 'message' => 'Institute name is required'];

    $duty = getActiveDuty($db, $eid);
    $photo = saveMarketingUpload('stamp_photo', 'visits', $eid);

    $stmt = $db->prepare("INSERT INTO field_visits
            (duty_log_id, employee_id, visit_date, visit_time, institute_name,
             km_traveled, counts, latitude, longitude, photo_stamped, remarks)
        VALUES (?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?)");
    try {
        $stmt->execute([
            $duty ? $duty['id'] : null,
            $eid,
            $institute,
            (int) ($data['km_traveled'] ?? 0),
            (int) ($data['counts'] ?? 0),
            numericOrNull($data['latitude'] ?? null),
            numericOrNull($data['longitude'] ?? null),
            $photo,
            Validator::sanitize($data['remarks'] ?? ''),
        ]);
    } catch (Exception $e) {
        discardMarketingUpload($photo);
        throw $e;
    }

    if ($duty) {
        $db->prepare("UPDATE duty_logs SET total_visits = total_visits + 1 WHERE id = ?")
           ->execute([$duty['id']]);
    }

    return ['success' => true, 'message' => 'Visit recorded', 'id' => $db->lastInsertId()];
}

/** Empty coordinate strings must become NULL, not 0, or every visit lands off West Africa. */
function numericOrNull($value) {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    return $value;
}

/** Stores an uploaded image and returns its web-relative path, or '' if absent. */
/**
 * Removes a file uploaded for a row that was never written.
 *
 * The photo is saved to disk before the INSERT, so a failing INSERT used to
 * leave the file orphaned — three such files accumulated the day duty_logs was
 * missing its start_selfie column, each with no record pointing at it.
 */
function discardMarketingUpload($storedPath) {
    if (!$storedPath) return;
    $full = UPLOAD_PATH . preg_replace('#^uploads/#', '', $storedPath);
    if (is_file($full)) @unlink($full);
}

function saveMarketingUpload($field, $subdir, $employeeId) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';

    $file = $_FILES[$field];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) return '';
    if ($file['size'] > 8 * 1024 * 1024) return '';

    $dir = UPLOAD_PATH . 'marketing/' . $subdir . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log("saveMarketingUpload: cannot create $dir");
        return '';
    }

    $filename = $field . '_' . $employeeId . '_' . date('Ymd_His') . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        error_log("saveMarketingUpload: move failed for $filename");
        return '';
    }

    return 'uploads/marketing/' . $subdir . '/' . $filename;
}
