<?php
function handleSettingsRequest($action) {
    // 'office' returns the geofence centre and radius used for attendance, so
    // this must not be readable without a token.
    AuthMiddleware::authenticate();
    $db = (new Database())->getConnection();

    switch ($action) {
        case 'office':
            return getOfficeSettings($db);
        case 'branches':
            return getBranches($db);
        case 'salary_rules':
            return getSalaryRules($db);
        case 'departments':
            return getDepartments($db);
        case 'designations':
            return getDesignations($db, $_GET['department_id'] ?? null);
        default:
            return ['success' => false, 'message' => 'Invalid settings action'];
    }
}

function getOfficeSettings($db) {
    // ORDER BY: without it "LIMIT 1" has no defined winner, and the rows here
    // describe two sites 10 km apart. Whichever row the app measures against
    // must be the same one the admin edits, every time.
    $stmt = $db->query("SELECT * FROM office_locations WHERE status = 1 ORDER BY id LIMIT 1");
    $office = $stmt->fetch() ?: null;

    $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    return [
        'success' => true,
        'data' => [
            'office' => $office,
            'settings' => $settings,
        ],
    ];
}

function getSalaryRules($db) {
    $stmt = $db->query("SELECT * FROM salary_rules WHERE status = 1 LIMIT 1");
    $rules = $stmt->fetch() ?: null;

    return ['success' => true, 'data' => $rules];
}

function getDepartments($db) {
    $stmt = $db->query("SELECT * FROM departments WHERE status = 1 ORDER BY name ASC");
    $departments = $stmt->fetchAll();

    return ['success' => true, 'data' => $departments];
}

function getDesignations($db, $departmentId) {
    if ($departmentId) {
        $stmt = $db->prepare("SELECT * FROM designations WHERE status = 1 AND department_id = ? ORDER BY name ASC");
        $stmt->execute([$departmentId]);
    } else {
        $stmt = $db->query("SELECT des.*, d.name as department_name FROM designations des 
                             LEFT JOIN departments d ON d.id = des.department_id
                             WHERE des.status = 1 ORDER BY des.name ASC");
    }
    $designations = $stmt->fetchAll();

    return ['success' => true, 'data' => $designations];
}

function getBranches($db) {
    $stmt = $db->query("SELECT * FROM branches ORDER BY branch_name ASC");
    $branches = $stmt->fetchAll();

    return ['success' => true, 'data' => $branches];
}
