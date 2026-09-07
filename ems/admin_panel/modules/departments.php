<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('departments');

$message = '';

// Add / Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name']);
    $description = sanitize($_POST['description'] ?? '');
    
    if (isset($_POST['id']) && $_POST['id']) {
        $pdo->prepare("UPDATE departments SET name=?, description=? WHERE id=?")->execute([$name, $description, $_POST['id']]);
        $message = 'Department updated';
    } else {
        $pdo->prepare("INSERT INTO departments (name, description) VALUES (?,?)")->execute([$name, $description]);
        $message = 'Department added';
    }
}

// Delete
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([(int)$_GET['delete']]);
    $message = 'Department deleted';
}

$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$designations = $pdo->query("SELECT des.*, d.name as dept_name FROM designations des LEFT JOIN departments d ON d.id = des.department_id ORDER BY des.name ASC")->fetchAll();

// Add designation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['designation_name'])) {
    $desName = sanitize($_POST['designation_name']);
    $deptId = $_POST['designation_department'] ?: null;
    
    if (isset($_POST['des_id']) && $_POST['des_id']) {
        $pdo->prepare("UPDATE designations SET name=?, department_id=? WHERE id=?")->execute([$desName, $deptId, $_POST['des_id']]);
    } else {
        $pdo->prepare("INSERT INTO designations (name, department_id) VALUES (?,?)")->execute([$desName, $deptId]);
    }
    $message = 'Designation saved';
}

if (isset($_GET['delete_des'])) {
    $pdo->prepare("DELETE FROM designations WHERE id = ?")->execute([(int)$_GET['delete_des']]);
    $message = 'Designation deleted';
}

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-building me-2"></i>Departments & Designations</h4>
</div>

<?php if ($message): ?>
<div class="alert alert-info alert-dismissible"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span>Departments</span>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal"><i class="fas fa-plus"></i> Add</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Name</th><th>Description</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($departments as $d): ?>
                        <tr>
                            <td><?php echo sanitize($d['name']); ?></td>
                            <td><?php echo sanitize($d['description']); ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="editDept(<?php echo htmlspecialchars(json_encode($d)); ?>)"><i class="fas fa-edit"></i></button>
                                <a href="?delete=<?php echo $d['id']; ?>" class="btn btn-sm btn-danger btn-delete"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span>Designations</span>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#desigModal"><i class="fas fa-plus"></i> Add</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Name</th><th>Department</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($designations as $ds): ?>
                        <tr>
                            <td><?php echo sanitize($ds['name']); ?></td>
                            <td><?php echo sanitize($ds['dept_name'] ?? '-'); ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="editDesig(<?php echo htmlspecialchars(json_encode($ds)); ?>)"><i class="fas fa-edit"></i></button>
                                <a href="?delete_des=<?php echo $ds['id']; ?>" class="btn btn-sm btn-danger btn-delete"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Department Modal -->
<div class="modal fade" id="deptModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <div class="modal-header"><h5 class="modal-title">Department</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="id" id="deptId">
                <div class="mb-3"><label class="form-label">Name *</label><input type="text" name="name" id="deptName" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Description</label><textarea name="description" id="deptDesc" class="form-control"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div></div>
</div>

<!-- Designation Modal -->
<div class="modal fade" id="desigModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <div class="modal-header"><h5 class="modal-title">Designation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="des_id" id="desId">
                <div class="mb-3"><label class="form-label">Name *</label><input type="text" name="designation_name" id="desName" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Department</label>
                    <select name="designation_department" id="desDept" class="form-select"><option value="">None</option>
                        <?php foreach ($departments as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo sanitize($d['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div></div>
</div>

<script>
function editDept(d) { document.getElementById('deptId').value = d.id; document.getElementById('deptName').value = d.name; document.getElementById('deptDesc').value = d.description || ''; new bootstrap.Modal(document.getElementById('deptModal')).show(); }
function editDesig(d) { document.getElementById('desId').value = d.id; document.getElementById('desName').value = d.name; document.getElementById('desDept').value = d.department_id || ''; new bootstrap.Modal(document.getElementById('desigModal')).show(); }
</script>

<?php require_once '../includes/footer.php'; ?>
