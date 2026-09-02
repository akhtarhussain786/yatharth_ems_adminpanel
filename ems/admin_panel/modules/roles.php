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
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#roleModal">
        <i class="fas fa-plus me-1"></i>Add Role
    </button>
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
