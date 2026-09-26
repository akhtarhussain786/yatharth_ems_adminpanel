<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('daily_work_reports');

$depts = $pdo->query("SELECT id, name FROM departments WHERE status = 1 ORDER BY name")->fetchAll();
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    requireModuleAccess('daily_work_reports', 'can_create');
    $eid = intval($_POST['employee_id'] ?? 0);
    $date = $_POST['report_date'] ?? date('Y-m-d');
    $title = sanitize($_POST['title'] ?? '');
    $desc = sanitize($_POST['description'] ?? '');
    $hours = floatval($_POST['hours_worked'] ?? 0);
    $st = sanitize($_POST['status'] ?? 'submitted');
    $pdo->prepare("INSERT INTO daily_work_reports (employee_id, report_date, title, description, hours_worked, status) VALUES (?,?,?,?,?,?)")->execute([$eid, $date, $title, $desc, $hours, $st]);
    $_SESSION['flash'] = 'Work report created';
    redirect('work_reports.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'review') {
    requireModuleAccess('daily_work_reports', 'can_edit');
    $id = intval($_POST['id'] ?? 0);
    $st = sanitize($_POST['status'] ?? 'submitted');
    $remarks = sanitize($_POST['remarks'] ?? '');
    $pdo->prepare("UPDATE daily_work_reports SET status=?, remarks=? WHERE id=?")->execute([$st, $remarks, $id]);
    $_SESSION['flash'] = 'Report reviewed';
    redirect('work_reports.php');
}

if ($action === 'delete' && isset($_GET['id'])) {
    requireModuleAccess('daily_work_reports', 'can_delete');
    $pdo->prepare("DELETE FROM daily_work_reports WHERE id=?")->execute([intval($_GET['id'])]);
    $_SESSION['flash'] = 'Report deleted';
    redirect('work_reports.php');
}

$reports = $pdo->query("SELECT w.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM daily_work_reports w JOIN employees e ON e.id = w.employee_id LEFT JOIN departments d ON d.id = e.department_id ORDER BY w.created_at DESC")->fetchAll();
$emps = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-file-signature me-2"></i>Work Reports</h4>
    <?php if (hasModuleAccess('daily_work_reports', 'can_create')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus"></i> Add Report</button>
    <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert-success py-2"><?php echo $flash; ?></div><?php endif; ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead><tr><th>Employee</th><th>Code</th><th>Dept</th><th>Date</th><th>Title</th><th>Hours</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($reports as $r): ?>
                    <tr>
                        <td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td>
                        <td><?php echo sanitize($r['employee_code']); ?></td>
                        <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                        <td><?php echo $r['report_date']; ?></td>
                        <td><?php echo sanitize($r['title']); ?></td>
                        <td><?php echo $r['hours_worked']; ?></td>
                        <td><span class="badge bg-<?php echo $r['status'] == 'approved' ? 'success' : ($r['status'] == 'rejected' ? 'danger' : ($r['status'] == 'submitted' ? 'primary' : 'secondary')); ?>"><?php echo ucfirst($r['status']); ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $r['id']; ?>"><i class="fas fa-eye"></i></button>
                            <?php if (hasModuleAccess('daily_work_reports', 'can_edit')): ?>
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo $r['id']; ?>"><i class="fas fa-check"></i></button>
                            <?php endif; ?>
                            <?php if (hasModuleAccess('daily_work_reports', 'can_delete')): ?>
                            <a href="?action=delete&id=<?php echo $r['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this report?')"><i class="fas fa-trash"></i></a>
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
            <div class="modal-header"><h5 class="modal-title">Add Work Report</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Employee</label><select name="employee_id" class="form-select" required><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>"><?php echo sanitize($e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['employee_code'] . ')'); ?></option><?php endforeach; ?></select></div>
                <div class="mb-2"><label class="form-label">Date</label><input type="date" name="report_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="mb-2"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
                <div class="mb-2"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                <div class="mb-2"><label class="form-label">Hours Worked</label><input type="number" step="0.5" name="hours_worked" class="form-control" value="0"></div>
                <div class="mb-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="submitted">Submitted</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
        </div>
    </form></div>
</div>

<?php foreach ($reports as $r): ?>
<!-- View Modal -->
<div class="modal fade" id="viewModal<?php echo $r['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Report Detail</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p><strong>Employee:</strong> <?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?> (<?php echo sanitize($r['employee_code']); ?>)</p>
            <p><strong>Department:</strong> <?php echo sanitize($r['department_name'] ?? '-'); ?></p>
            <p><strong>Date:</strong> <?php echo $r['report_date']; ?></p>
            <p><strong>Title:</strong> <?php echo sanitize($r['title']); ?></p>
            <p><strong>Description:</strong> <?php echo nl2br(sanitize($r['description'])); ?></p>
            <p><strong>Hours:</strong> <?php echo $r['hours_worked']; ?></p>
            <p><strong>Status:</strong> <?php echo ucfirst($r['status']); ?></p>
            <?php if ($r['remarks']): ?><p><strong>Remarks:</strong> <?php echo sanitize($r['remarks']); ?></p><?php endif; ?>
        </div>
    </div></div>
</div>
<!-- Review Modal -->
<div class="modal fade" id="reviewModal<?php echo $r['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><form method="POST" action="?action=review">
        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Review Report</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p><strong><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></strong> - <?php echo $r['title']; ?></p>
                <div class="mb-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="approved" <?php echo $r['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option><option value="rejected" <?php echo $r['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option><option value="submitted" <?php echo $r['status'] == 'submitted' ? 'selected' : ''; ?>>Submitted</option></select></div>
                <div class="mb-2"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="3"><?php echo sanitize($r['remarks']); ?></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save Review</button></div>
        </div>
    </form></div>
</div>
<?php endforeach; ?>

<?php require_once '../includes/footer.php'; ?>
