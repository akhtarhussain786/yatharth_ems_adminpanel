<?php
function handlePermissionRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();

    switch ($action) {
        case 'my':
            return getMyPermissions($db, $auth);
        case 'roles':
            return getAllRoles($db);
        case 'role_permissions':
            return getRolePermissions($db, $param);
        default:
            return ['success' => false, 'message' => 'Invalid permission action'];
    }
}

function getMyPermissions($db, $auth) {
    $roleId = $auth['role_id'];
    $role = $auth['role'];
    if ($role === 'super_admin') {
        $modules = ['employees','attendance','departments','designations','holidays','leave_requests','salary','reports','settings','daily_work_reports','tasks','leads','campaigns','call_reports','follow_ups','hr_activities','notifications','activity_logs'];
        $perms = [];
        foreach ($modules as $m) {
            $perms[$m] = ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => true];
        }
        return ['success' => true, 'data' => $perms, 'role' => $role];
    }

    $stmt = $db->prepare("SELECT module, can_view, can_create, can_edit, can_delete FROM permissions WHERE role_id = ?");
    $stmt->execute([$roleId]);
    $perms = [];
    foreach ($stmt->fetchAll() as $row) {
        $perms[$row['module']] = [
            'can_view' => (bool)$row['can_view'],
            'can_create' => (bool)$row['can_create'],
            'can_edit' => (bool)$row['can_edit'],
            'can_delete' => (bool)$row['can_delete'],
        ];
    }
    return ['success' => true, 'data' => $perms, 'role' => $role];
}

function getAllRoles($db) {
    AuthMiddleware::checkRole(['super_admin']);
    return ['success' => true, 'data' => $db->query("SELECT id, name, description FROM roles ORDER BY id")->fetchAll()];
}

function getRolePermissions($db, $roleId) {
    AuthMiddleware::checkRole(['super_admin']);
    $stmt = $db->prepare("SELECT p.*, r.name as role_name FROM permissions p JOIN roles r ON r.id = p.role_id WHERE p.role_id = ? ORDER BY p.module");
    $stmt->execute([$roleId]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}
