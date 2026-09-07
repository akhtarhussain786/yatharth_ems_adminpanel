<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('roles');

$message = '';

// Handle AJAX: save permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_permissions') {
    header('Content-Type: application/json');
    $roleId = (int)$_POST['role_id'];
    $modules = $_POST['modules'] ?? [];

    try {
        $pdo->prepare("DELETE FROM permissions WHERE role_id = ?")->execute([$roleId]);
        $stmt = $pdo->prepare("INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($modules as $mod => $perms) {
            $stmt->execute([$roleId, $mod,
                isset($perms['can_view']) ? 1 : 0,
                isset($perms['can_create']) ? 1 : 0,
                isset($perms['can_edit']) ? 1 : 0,
                isset($perms['can_delete']) ? 1 : 0
            ]);
        }
        echo json_encode(['success' => true, 'message' => 'Permissions updated']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle POST: create/update role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['role_action'])) {
    if ($_POST['role_action'] === 'create' || $_POST['role_action'] === 'edit') {
        $id = (int)($_POST['role_id'] ?? 0);
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description'] ?? '');

        try {
            if ($_POST['role_action'] === 'create') {
                $chk = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
                $chk->execute([$name]);
                if ($chk->fetch()) throw new Exception('Role already exists');
                $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)")->execute([$name, $description]);
                $message = 'Role created';
            } else {
                $pdo->prepare("UPDATE roles SET name = ?, description = ? WHERE id = ?")->execute([$name, $description, $id]);
                $message = 'Role updated';
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
}

// Handle AJAX: get permissions
if (isset($_GET['get_permissions'])) {
    header('Content-Type: application/json');
    $roleId = (int)$_GET['get_permissions'];
    $role = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
    $role->execute([$roleId]);
    $roleData = $role->fetch();
    if (!$roleData) { echo json_encode(['success' => false, 'message' => 'Role not found']); exit; }
    $stmt = $pdo->prepare("SELECT * FROM permissions WHERE role_id = ? ORDER BY module");
    $stmt->execute([$roleId]);
    echo json_encode(['success' => true, 'data' => ['role' => $roleData, 'permissions' => $stmt->fetchAll()]]);
    exit;
}

// Handle DELETE
if (isset($_GET['delete_role'])) {
    $id = (int)$_GET['delete_role'];
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
    $cnt->execute([$id]);
    if ($cnt->fetchColumn() > 0) {
        $message = 'Cannot delete role with assigned users';
    } else {
        $pdo->prepare("DELETE FROM permissions WHERE role_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);
        $message = 'Role deleted';
    }
}

// Handle 1-Click Auto Seed Permissions
if (isset($_GET['auto_seed_defaults'])) {
    try {
        $roleMap = [];
        foreach ($pdo->query("SELECT id, name FROM roles")->fetchAll() as $r) {
            $roleMap[$r['name']] = (int)$r['id'];
        }

        $allMods = [
            'employees','attendance','departments','designations','leaves','payroll','reports',
            'settings','daily_work_reports','tasks','leads','campaigns','call_reports','follow_ups',
            'hr_activities','notices','documents','activity_logs','roles','permissions','users',
            'marketing','telecaller','sales','travel','expenses','assets','meetings','notifications','downloads','help'
        ];

        $ins = $pdo->prepare("INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_create=VALUES(can_create), can_edit=VALUES(can_edit), can_delete=VALUES(can_delete)");

        foreach ($roleMap as $roleName => $rId) {
            // Delete old permissions
            $pdo->prepare("DELETE FROM permissions WHERE role_id = ?")->execute([$rId]);

            foreach ($allMods as $mod) {
                $v = 0; $c = 0; $e = 0; $d = 0;

                // 1. Super Admin
                if ($roleName === 'super_admin') {
                    $v = 1; $c = 1; $e = 1; $d = 1;
                }
                // 2. Admin
                elseif ($roleName === 'admin') {
                    $v = 1;
                    $c = in_array($mod, ['reports','settings','permissions','activity_logs'], true) ? 0 : 1;
                    $e = in_array($mod, ['reports','settings','permissions','activity_logs'], true) ? 0 : 1;
                    $d = in_array($mod, ['reports','settings','permissions','activity_logs','leads'], true) ? 0 : 1;
                }
                // 3. HR Admin / Sub Admin
                elseif ($roleName === 'hr_admin' || $roleName === 'sub_admin') {
                    if (in_array($mod, ['employees','attendance','leaves','leave_requests','payroll','tasks','travel','expenses','assets','documents','notices','departments','designations','hr_activities'], true)) {
                        $v = 1; $c = 1; $e = 1; $d = 1;
                    } elseif (in_array($mod, ['leads','marketing','campaigns','call_reports','follow_ups','reports','daily_work_reports','notifications','help','downloads'], true)) {
                        $v = 1; $c = ($mod === 'daily_work_reports') ? 1 : 0; $e = ($mod === 'daily_work_reports') ? 1 : 0; $d = 0;
                    }
                }
                // 4. HR / HR Executive
                elseif ($roleName === 'hr' || $roleName === 'hr_executive') {
                    if (in_array($mod, ['employees','leaves','leave_requests','payroll','tasks','travel','expenses','assets','hr_activities'], true)) {
                        $v = 1; $c = 1; $e = 1; $d = 1;
                    } elseif (in_array($mod, ['attendance','notices','documents','daily_work_reports','notifications','help','downloads'], true)) {
                        $v = 1; $c = 1; $e = 1; $d = 0;
                    } elseif (in_array($mod, ['leads','marketing','campaigns','call_reports','follow_ups','reports','departments','designations'], true)) {
                        $v = 1; $c = 0; $e = 0; $d = 0;
                    }
                }
                // 5. Accounts Admin / Accounts
                elseif (strpos($roleName, 'accounts') !== false) {
                    if (in_array($mod, ['payroll','expenses','accounts'], true)) {
                        $v = 1; $c = 1; $e = 1; $d = ($roleName === 'accounts_admin' ? 1 : 0);
                    } elseif (in_array($mod, ['employees','attendance','leaves','reports','daily_work_reports','notices','documents'], true)) {
                        $v = 1; $c = ($mod === 'daily_work_reports' ? 1 : 0); $e = 0; $d = 0;
                    }
                }
                // 6. IT Admin
                elseif (strpos($roleName, 'it_admin') !== false) {
                    if (in_array($mod, ['assets','downloads','help','it_team'], true)) {
                        $v = 1; $c = 1; $e = 1; $d = 1;
                    } elseif (in_array($mod, ['employees','attendance','notices','documents','daily_work_reports'], true)) {
                        $v = 1; $c = ($mod === 'daily_work_reports' ? 1 : 0); $e = 0; $d = 0;
                    }
                }
                // 7. Telecaller Admin / Telecaller
                elseif (strpos($roleName, 'telecaller') !== false) {
                    if (in_array($mod, ['call_reports','follow_ups'], true)) {
                        $v = 1; $c = 1; $e = 1; $d = ($roleName === 'telecaller_admin' ? 1 : 0);
                    } elseif ($mod === 'leads') {
                        $v = 1; $c = 0; $e = 1; $d = 0;
                    } elseif (in_array($mod, ['attendance','leaves','daily_work_reports','notices','documents'], true)) {
                        $v = 1; $c = in_array($mod, ['leaves','daily_work_reports']) ? 1 : 0; $e = 0; $d = 0;
                    }
                }
                // 8. Digital Marketing / Marketing Admin / Marketing Executive
                elseif (strpos($roleName, 'marketing') !== false || strpos($roleName, 'digital_marketing') !== false) {
                    if (in_array($mod, ['leads','marketing','campaigns'], true)) {
                        $v = 1; $c = 1; $e = 1; $d = (strpos($roleName, 'admin') !== false ? 1 : 0);
                    } elseif (in_array($mod, ['call_reports','follow_ups','reports'], true)) {
                        $v = 1; $c = 0; $e = 0; $d = 0;
                    } elseif (in_array($mod, ['attendance','leaves','daily_work_reports','notices','documents'], true)) {
                        $v = 1; $c = in_array($mod, ['leaves','daily_work_reports']) ? 1 : 0; $e = 0; $d = 0;
                    }
                }
                // 9. Employee / Standard Staff
                else {
                    if (in_array($mod, ['attendance','leaves','tasks','daily_work_reports','notices','documents','downloads','help'], true)) {
                        $v = 1;
                        $c = in_array($mod, ['leaves','daily_work_reports','help'], true) ? 1 : 0;
                        $e = 0; $d = 0;
                    }
                }

                $ins->execute([$rId, $mod, $v, $c, $e, $d]);
            }
        }
        $message = 'Sabhi roles ke recommended default permissions successfully configure ho gaye hain!';
    } catch (Exception $e) {
        $message = 'Error auto configuring permissions: ' . $e->getMessage();
    }
}

$roles = $pdo->query("SELECT r.*, (SELECT COUNT(*) FROM users WHERE role_id = r.id) as user_count FROM roles r ORDER BY r.id")->fetchAll();

$allModules = ['employees','attendance','departments','designations','leaves','payroll','reports','settings','daily_work_reports','tasks','leads','campaigns','call_reports','follow_ups','hr_activities','notices','documents','activity_logs','roles','permissions','users','marketing','telecaller','sales','travel','expenses','assets','meetings','notifications'];

$moduleLabels = [
    'employees' => 'Employees', 'attendance' => 'Attendance', 'departments' => 'Departments',
    'designations' => 'Designations', 'leaves' => 'Leaves', 'payroll' => 'Payroll',
    'reports' => 'Reports', 'settings' => 'Settings', 'daily_work_reports' => 'Work Reports',
    'tasks' => 'Tasks', 'leads' => 'Leads', 'campaigns' => 'Campaigns',
    'call_reports' => 'Call Reports', 'follow_ups' => 'Follow Ups', 'hr_activities' => 'HR Activities',
    'notices' => 'Notices', 'documents' => 'Documents', 'activity_logs' => 'Activity Logs',
    'roles' => 'Roles', 'permissions' => 'Permissions', 'users' => 'Users',
    'marketing' => 'Marketing', 'telecaller' => 'Telecaller', 'sales' => 'Sales',
    'travel' => 'Travel', 'expenses' => 'Expenses', 'assets' => 'Assets',
    'meetings' => 'Meetings', 'notifications' => 'Notifications',
];

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-shield-alt me-2"></i>Role Management</h4>
    <div>
        <a href="?auto_seed_defaults=1" class="btn btn-success me-2" onclick="return confirm('Kya aap sabhi roles ke liye recommended default permissions automatically apply karna chahte hain? Isse sabhi roles ke permissions 1-click me set ho jayenge.');">
            <i class="fas fa-magic me-1"></i>⚡ Auto-Configure All Permissions (1-Click)
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#roleModal">
            <i class="fas fa-plus me-1"></i>Add Role
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-info alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Users</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                    <tr>
                        <td><?php echo $r['id']; ?></td>
                        <td><code><?php echo sanitize($r['name']); ?></code></td>
                        <td><?php echo sanitize($r['description'] ?? '-'); ?></td>
                        <td><span class="badge bg-secondary"><?php echo $r['user_count']; ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editRole(<?php echo $r['id']; ?>, '<?php echo sanitize($r['name']); ?>', '<?php echo sanitize($r['description'] ?? ''); ?>')"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-warning" onclick="managePermissions(<?php echo $r['id']; ?>, '<?php echo sanitize($r['name']); ?>')"><i class="fas fa-key"></i></button>
                            <a href="?delete_role=<?php echo $r['id']; ?>" class="btn btn-sm btn-danger btn-delete"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Role Modal -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5 class="modal-title" id="roleModalTitle">Add Role</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="role_action" id="roleAction" value="create">
                    <input type="hidden" name="role_id" id="roleId" value="">
                    <div class="mb-3">
                        <label class="form-label">Role Name *</label>
                        <input type="text" name="name" id="roleName" class="form-control" required placeholder="e.g. support_agent">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" id="roleDesc" class="form-control" placeholder="e.g. Support Agent - handles customer queries">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Permissions Modal -->
<div class="modal fade" id="permsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" id="permsForm">
                <div class="modal-header"><h5 class="modal-title">Permissions for: <span id="permsRoleName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_permissions">
                    <input type="hidden" name="role_id" id="permsRoleId">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead><tr><th>Module</th><th>View</th><th>Create</th><th>Edit</th><th>Delete</th></tr></thead>
                            <tbody id="permsContainer"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Permissions</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editRole(id, name, desc) {
    document.getElementById('roleModalTitle').textContent = 'Edit Role';
    document.getElementById('roleAction').value = 'edit';
    document.getElementById('roleId').value = id;
    document.getElementById('roleName').value = name;
    document.getElementById('roleDesc').value = desc;
    new bootstrap.Modal(document.getElementById('roleModal')).show();
}

function managePermissions(roleId, roleName) {
    document.getElementById('permsRoleId').value = roleId;
    document.getElementById('permsRoleName').textContent = roleName;
    const container = document.getElementById('permsContainer');
    container.innerHTML = '<tr><td colspan="5" class="text-center">Loading...</td></tr>';
    new bootstrap.Modal(document.getElementById('permsModal')).show();

    fetch('roles.php?get_permissions=' + roleId)
    .then(r => r.json())
    .then(data => {
        container.innerHTML = '';
        if (!data.success) { container.innerHTML = '<tr><td colspan="5" class="text-danger">' + data.message + '</td></tr>'; return; }

        const existingPerms = {};
        (data.data.permissions || []).forEach(p => {
            existingPerms[p.module] = { can_view: p.can_view == 1, can_create: p.can_create == 1, can_edit: p.can_edit == 1, can_delete: p.can_delete == 1 };
        });

        const modules = <?php echo json_encode($allModules); ?>;
        const labels = <?php echo json_encode($moduleLabels); ?>;

        modules.forEach(m => {
            const perm = existingPerms[m] || { can_view: false, can_create: false, can_edit: false, can_delete: false };
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + (labels[m] || m) + '</td>' +
                '<td><input type="checkbox" class="form-check-input" name="modules[' + m + '][can_view]" ' + (perm.can_view ? 'checked' : '') + '></td>' +
                '<td><input type="checkbox" class="form-check-input" name="modules[' + m + '][can_create]" ' + (perm.can_create ? 'checked' : '') + '></td>' +
                '<td><input type="checkbox" class="form-check-input" name="modules[' + m + '][can_edit]" ' + (perm.can_edit ? 'checked' : '') + '></td>' +
                '<td><input type="checkbox" class="form-check-input" name="modules[' + m + '][can_delete]" ' + (perm.can_delete ? 'checked' : '') + '></td>';
            container.appendChild(tr);
        });
    })
    .catch(err => { container.innerHTML = '<tr><td colspan="5" class="text-danger">Error loading permissions</td></tr>'; });
}

document.getElementById('permsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(d => {
        if (d.success) { bootstrap.Modal.getInstance(document.getElementById('permsModal')).hide(); location.reload(); }
        else alert(d.message);
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
