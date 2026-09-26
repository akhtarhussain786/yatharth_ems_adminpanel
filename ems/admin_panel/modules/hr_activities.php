<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('hr_activities');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'create') {
    requireModuleAccess('hr_activities', 'can_create');
    $eid = intval($_POST['employee_id'] ?? 0);
    $type = sanitize($_POST['activity_type'] ?? 'other');
    $title = sanitize($_POST['title'] ?? '');
    $desc = sanitize($_POST['description'] ?? '');
    $date = $_POST['activity_date'] ?? date('Y-m-d');
    $status = sanitize($_POST['status'] ?? 'scheduled');
    $pdo->prepare("INSERT INTO hr_activities (employee_id, activity_type, title, description, activity_date, status) VALUES (?,?,?,?,?,?)")->execute([$eid, $type, $title, $desc, $date, $status]);
    $_SESSION['flash'] = 'HR activity created';
    redirect('hr_activities.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'update') {
    requireModuleAccess('hr_activities', 'can_edit');
    $id = intval($_POST['id'] ?? 0);
    $type = sanitize($_POST['activity_type'] ?? 'other');
    $title = sanitize($_POST['title'] ?? '');
    $desc = sanitize($_POST['description'] ?? '');
    $status = sanitize($_POST['status'] ?? 'scheduled');
    $pdo->prepare("UPDATE hr_activities SET activity_type=?, title=?, description=?, status=? WHERE id=?")->execute([$type, $title, $desc, $status, $id]);
    $_SESSION['flash'] = 'HR activity updated';
    redirect('hr_activities.php');
}

if (isset($_GET['delete'])) {
    requireModuleAccess('hr_activities', 'can_delete');
    $pdo->prepare("DELETE FROM hr_activities WHERE id=?")->execute([intval($_GET['delete'])]);
    $_SESSION['flash'] = 'HR activity deleted';
    redirect('hr_activities.php');
}

$activities = $pdo->query("SELECT h.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM hr_activities h JOIN employees e ON e.id = h.employee_id LEFT JOIN departments d ON d.id = e.department_id ORDER BY h.activity_date DESC")->fetchAll();
$emps = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-handshake me-2"></i>HR Activities</h4>
    <?php if (hasModuleAccess('hr_activities', 'can_create')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus"></i> New Activity</button>
    <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert-success py-2"><?php echo $flash; ?></div><?php endif; ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead><tr><th>Employee</th><th>Type</th><th>Title</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($activities as $h): ?>
                    <tr>
                        <td><?php echo sanitize($h['first_name'] . ' ' . $h['last_name']); ?></td>
                        <td><span class="badge bg-info"><?php echo ucfirst($h['activity_type']); ?></span></td>
                        <td><?php echo sanitize($h['title']); ?></td>
                        <td><?php echo $h['activity_date']; ?></td>
                        <td><span class="badge bg-<?php echo $h['status'] == 'completed' ? 'success' : ($h['status'] == 'cancelled' ? 'danger' : 'warning'); ?>"><?php echo ucfirst($h['status']); ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $h['id']; ?>"><i class="fas fa-edit"></i></button>
                            <?php if (hasModuleAccess('hr_activities', 'can_delete')): ?>
                            <a href="?delete=<?php echo $h['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog"><form method="POST" action="?action=create">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">New HR Activity</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Employee</label><select name="employee_id" class="form-select" required><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>"><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="mb-2"><label class="form-label">Activity Type</label><select name="activity_type" class="form-select"><option value="interview">Interview</option><option value="training">Training</option><option value="onboarding">Onboarding</option><option value="meeting">Meeting</option><option value="review">Review</option><option value="other">Other</option></select></div>
                <div class="mb-2"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
                <div class="mb-2"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                <div class="mb-2"><label class="form-label">Date</label><input type="date" name="activity_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="mb-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="scheduled">Scheduled</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
        </div>
    </form></div>
</div>

<?php foreach ($activities as $h): ?>
<div class="modal fade" id="editModal<?php echo $h['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><form method="POST" action="?action=update">
        <input type="hidden" name="id" value="<?php echo $h['id']; ?>">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit Activity</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Type</label><select name="activity_type" class="form-select"><?php foreach(['interview','training','onboarding','meeting','review','other'] as $t): ?><option value="<?php echo $t; ?>" <?php echo $h['activity_type']==$t?'selected':''; ?>><?php echo ucfirst($t); ?></option><?php endforeach; ?></select></div>
                <div class="mb-2"><label class="form-label">Title</label><input type="text" name="title" class="form-control" value="<?php echo sanitize($h['title']); ?>" required></div>
                <div class="mb-2"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"><?php echo sanitize($h['description']); ?></textarea></div>
                <div class="mb-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="scheduled" <?php echo $h['status']=='scheduled'?'selected':''; ?>>Scheduled</option><option value="completed" <?php echo $h['status']=='completed'?'selected':''; ?>>Completed</option><option value="cancelled" <?php echo $h['status']=='cancelled'?'selected':''; ?>>Cancelled</option></select></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Update</button></div>
        </div>
    </form></div>
</div>
<?php endforeach; ?>

<?php require_once '../includes/footer.php'; ?>
