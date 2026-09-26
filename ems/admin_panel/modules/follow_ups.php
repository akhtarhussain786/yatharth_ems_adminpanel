<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('follow_ups');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'create') {
    requireModuleAccess('follow_ups', 'can_create');
    $eid = intval($_POST['employee_id'] ?? 0);
    $name = sanitize($_POST['customer_name'] ?? '');
    $phone = sanitize($_POST['customer_phone'] ?? '');
    $type = sanitize($_POST['follow_up_type'] ?? 'call');
    $notes = sanitize($_POST['notes'] ?? '');
    $date = $_POST['follow_up_date'] ?? date('Y-m-d');
    $pdo->prepare("INSERT INTO follow_ups (employee_id, customer_name, customer_phone, follow_up_type, notes, follow_up_date) VALUES (?,?,?,?,?,?)")->execute([$eid, $name, $phone, $type, $notes, $date]);
    $_SESSION['flash'] = 'Follow-up created';
    redirect('follow_ups.php');
}

if (isset($_GET['complete'])) {
    requireModuleAccess('follow_ups', 'can_edit');
    $pdo->prepare("UPDATE follow_ups SET status='completed', completed_at=NOW() WHERE id=?")->execute([intval($_GET['complete'])]);
    $_SESSION['flash'] = 'Follow-up completed';
    redirect('follow_ups.php');
}

if (isset($_GET['delete'])) {
    requireModuleAccess('follow_ups', 'can_delete');
    $pdo->prepare("DELETE FROM follow_ups WHERE id=?")->execute([intval($_GET['delete'])]);
    $_SESSION['flash'] = 'Follow-up deleted';
    redirect('follow_ups.php');
}

$followUps = $pdo->query("SELECT f.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM follow_ups f JOIN employees e ON e.id = f.employee_id LEFT JOIN departments d ON d.id = e.department_id ORDER BY f.follow_up_date ASC")->fetchAll();
$emps = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-clock me-2"></i>Follow Ups</h4>
    <?php if (hasModuleAccess('follow_ups', 'can_create')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus"></i> New Follow Up</button>
    <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert-success py-2"><?php echo $flash; ?></div><?php endif; ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead><tr><th>Customer</th><th>Phone</th><th>Employee</th><th>Type</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($followUps as $f): ?>
                    <tr>
                        <td><?php echo sanitize($f['customer_name']); ?></td>
                        <td><?php echo sanitize($f['customer_phone']); ?></td>
                        <td><?php echo sanitize($f['first_name'] . ' ' . $f['last_name']); ?></td>
                        <td><?php echo ucfirst($f['follow_up_type']); ?></td>
                        <td><?php echo $f['follow_up_date']; ?></td>
                        <td><span class="badge bg-<?php echo $f['status'] == 'completed' ? 'success' : 'warning'; ?>"><?php echo ucfirst($f['status']); ?></span></td>
                        <td>
                            <?php if ($f['status'] == 'pending' && hasModuleAccess('follow_ups', 'can_edit')): ?>
                            <a href="?complete=<?php echo $f['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Mark as completed?')"><i class="fas fa-check"></i></a>
                            <?php endif; ?>
                            <?php if (hasModuleAccess('follow_ups', 'can_delete')): ?>
                            <a href="?delete=<?php echo $f['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
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
            <div class="modal-header"><h5 class="modal-title">New Follow Up</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Employee</label><select name="employee_id" class="form-select" required><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>"><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="mb-2"><label class="form-label">Customer Name</label><input type="text" name="customer_name" class="form-control" required></div>
                <div class="mb-2"><label class="form-label">Phone</label><input type="text" name="customer_phone" class="form-control"></div>
                <div class="mb-2"><label class="form-label">Type</label><select name="follow_up_type" class="form-select"><option value="call">Call</option><option value="meeting">Meeting</option><option value="email">Email</option><option value="other">Other</option></select></div>
                <div class="mb-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
                <div class="mb-2"><label class="form-label">Follow Up Date</label><input type="date" name="follow_up_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
        </div>
    </form></div>
</div>

<?php require_once '../includes/footer.php'; ?>
