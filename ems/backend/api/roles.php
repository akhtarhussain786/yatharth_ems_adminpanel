<?php
function handleRoleRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();

    switch ($action) {
        case 'list':
            return getRolesList($db);
        case 'permissions':
            return getRolePermissions($db, $param);
        case 'save_permissions':
            AuthMiddleware::checkRole(['super_admin', 'admin']);
            return saveRolePermissions($db, $param);
        case 'create':
            AuthMiddleware::checkRole(['super_admin', 'admin']);
            return createRole($db);
        case 'update':
            AuthMiddleware::checkRole(['super_admin', 'admin']);
            return updateRole($db, $param);
        case 'delete':
            AuthMiddleware::checkRole(['super_admin']);
            return deleteRole($db, $param);
        case 'modules':
            return getModules($db);
        default:
            return ['success' => false, 'message' => 'Invalid role action'];
    }
}

function getRolesList($db) {
    $stmt = $db->query("SELECT r.*, (SELECT COUNT(*) FROM users WHERE role_id = r.id) as user_count FROM roles r ORDER BY r.id");
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getRolePermissions($db, $roleId) {
    if (!$roleId) return ['success' => false, 'message' => 'Role ID required'];

    $role = $db->prepare("SELECT * FROM roles WHERE id = ?");
    $role->execute([$roleId]);
    $roleData = $role->fetch();
    if (!$roleData) return ['success' => false, 'message' => 'Role not found'];

    $stmt = $db->prepare("SELECT * FROM permissions WHERE role_id = ? ORDER BY module");
    $stmt->execute([$roleId]);
    $permissions = $stmt->fetchAll();

    return ['success' => true, 'data' => ['role' => $roleData, 'permissions' => $permissions]];
}

function saveRolePermissions($db, $roleId) {
    if (!$roleId) return ['success' => false, 'message' => 'Role ID required'];
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $db->prepare("DELETE FROM permissions WHERE role_id = ?")->execute([$roleId]);

    $modules = $data['modules'] ?? [];
    $stmt = $db->prepare("INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($modules as $module) {
        $stmt->execute([
            $roleId,
            $module['module'],
            isset($module['can_view']) ? (int)$module['can_view'] : 0,
            isset($module['can_create']) ? (int)$module['can_create'] : 0,
            isset($module['can_edit']) ? (int)$module['can_edit'] : 0,
            isset($module['can_delete']) ? (int)$module['can_delete'] : 0,
        ]);
    }

    return ['success' => true, 'message' => 'Permissions updated successfully'];
}

function createRole($db) {
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $name = Validator::sanitize($data['name'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');

    if (!$name) return ['success' => false, 'message' => 'Role name required'];

    $chk = $db->prepare("SELECT id FROM roles WHERE name = ?");
    $chk->execute([$name]);
    if ($chk->fetch()) return ['success' => false, 'message' => 'Role already exists'];

    $stmt = $db->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
    $stmt->execute([$name, $description]);
    $roleId = $db->lastInsertId();

    return ['success' => true, 'message' => 'Role created', 'id' => $roleId];
}

function updateRole($db, $id) {
    if (!$id) return ['success' => false, 'message' => 'Role ID required'];
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $name = Validator::sanitize($data['name'] ?? '');
    $description = Validator::sanitize($data['description'] ?? '');

    if (!$name) return ['success' => false, 'message' => 'Role name required'];

    $chk = $db->prepare("SELECT id FROM roles WHERE name = ? AND id != ?");
    $chk->execute([$name, $id]);
    if ($chk->fetch()) return ['success' => false, 'message' => 'Role name already taken'];

    $stmt = $db->prepare("UPDATE roles SET name = ?, description = ? WHERE id = ?");
    $stmt->execute([$name, $description, $id]);

    return ['success' => true, 'message' => 'Role updated'];
}

function deleteRole($db, $id) {
    if (!$id) return ['success' => false, 'message' => 'Role ID required'];

    $cnt = $db->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
    $cnt->execute([$id]);
    if ($cnt->fetchColumn() > 0) return ['success' => false, 'message' => 'Cannot delete role with assigned users'];

    $db->prepare("DELETE FROM permissions WHERE role_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);

    return ['success' => true, 'message' => 'Role deleted'];
}

function getModules($db) {
    return ['success' => true, 'data' => [
        ['module' => 'employees', 'label' => 'Employees'],
        ['module' => 'attendance', 'label' => 'Attendance'],
        ['module' => 'departments', 'label' => 'Departments'],
        ['module' => 'designations', 'label' => 'Designations'],
        ['module' => 'leaves', 'label' => 'Leaves'],
        ['module' => 'payroll', 'label' => 'Payroll'],
        ['module' => 'reports', 'label' => 'Reports'],
        ['module' => 'settings', 'label' => 'Settings'],
        ['module' => 'daily_work_reports', 'label' => 'Work Reports'],
        ['module' => 'tasks', 'label' => 'Tasks'],
        ['module' => 'leads', 'label' => 'Leads'],
        ['module' => 'campaigns', 'label' => 'Campaigns'],
        ['module' => 'call_reports', 'label' => 'Call Reports'],
        ['module' => 'follow_ups', 'label' => 'Follow Ups'],
        ['module' => 'hr_activities', 'label' => 'HR Activities'],
        ['module' => 'notices', 'label' => 'Notices'],
        ['module' => 'documents', 'label' => 'Documents'],
        ['module' => 'activity_logs', 'label' => 'Activity Logs'],
        ['module' => 'roles', 'label' => 'Roles'],
        ['module' => 'permissions', 'label' => 'Permissions'],
        ['module' => 'users', 'label' => 'Users'],
        ['module' => 'marketing', 'label' => 'Marketing'],
        ['module' => 'telecaller', 'label' => 'Telecaller'],
        ['module' => 'sales', 'label' => 'Sales'],
        ['module' => 'travel', 'label' => 'Travel'],
        ['module' => 'expenses', 'label' => 'Expenses'],
        ['module' => 'assets', 'label' => 'Assets'],
        ['module' => 'meetings', 'label' => 'Meetings'],
        ['module' => 'notifications', 'label' => 'Notifications'],
    ]];
}
