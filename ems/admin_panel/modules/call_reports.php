<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('call_reports');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'create') {
    requireModuleAccess('call_reports', 'can_create');
    $eid = intval($_POST['employee_id'] ?? 0);
    $name = sanitize($_POST['customer_name'] ?? '');
    $phone = sanitize($_POST['customer_phone'] ?? '');
    $duration = intval($_POST['call_duration'] ?? 0);
    $type = sanitize($_POST['call_type'] ?? 'outgoing');
    $status = sanitize($_POST['call_status'] ?? 'completed');
    $notes = sanitize($_POST['notes'] ?? '');
    $followUp = intval($_POST['follow_up_required'] ?? 0);
    $date = $_POST['call_date'] ?? date('Y-m-d');
    $pdo->prepare("INSERT INTO call_reports (employee_id, customer_name, customer_phone, call_duration, call_type, status, notes, follow_up_required, call_date) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$eid, $name, $phone, $duration, $type, $status, $notes, $followUp, $date]);
    $_SESSION['flash'] = 'Call report created';
    redirect('call_reports.php');
}

if (isset($_GET['delete'])) {
    requireModuleAccess('call_reports', 'can_delete');
    $pdo->prepare("DELETE FROM call_reports WHERE id=?")->execute([intval($_GET['delete'])]);
    $_SESSION['flash'] = 'Call report deleted';
    redirect('call_reports.php');
}

$calls = $pdo->query("SELECT c.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM call_reports c JOIN employees e ON e.id = c.employee_id LEFT JOIN departments d ON d.id = e.department_id ORDER BY c.created_at DESC")->fetchAll();
$emps = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-phone-alt me-2"></i>Call Reports</h4>
    <?php if (hasModuleAccess('call_reports', 'can_create')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus"></i> Add Call</button>
    <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert-success py-2"><?php echo $flash; ?></div><?php endif; ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead><tr><th>Customer</th><th>Phone</th><th>Employee</th><th>Duration</th><th>Type</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($calls as $c): ?>
                    <tr>
                        <td><?php echo sanitize($c['customer_name']); ?></td>
                        <td><?php echo sanitize($c['customer_phone']); ?></td>
                        <td><?php echo sanitize($c['first_name'] . ' ' . $c['last_name']); ?></td>
                        <td><?php echo gmdate('i:s', $c['call_duration']); ?></td>
                        <td><span class="badge bg-<?php echo $c['call_type'] == 'incoming' ? 'success' : ($c['call_type'] == 'outgoing' ? 'primary' : 'warning'); ?>"><?php echo ucfirst(str_replace('_', ' ', $c['call_type'])); ?></span></td>
                        <td><span class="badge bg-<?php echo $c['status'] == 'completed' ? 'success' : ($c['status'] == 'callback' ? 'warning' : ($c['status'] == 'not_interested' ? 'danger' : 'secondary')); ?>"><?php echo ucfirst(str_replace('_', ' ', $c['status'])); ?></span></td>
                        <td><?php echo $c['call_date']; ?></td>
                        <td>
                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $c['id']; ?>"><i class="fas fa-eye"></i></button>
                            <?php if (hasModuleAccess('call_reports', 'can_delete')): ?>
                            <a href="?delete=<?php echo $c['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this call report?')"><i class="fas fa-trash"></i></a>
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
            <div class="modal-header"><h5 class="modal-title">Add Call Report</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Employee</label><select name="employee_id" class="form-select" required><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>"><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="mb-2"><label class="form-label">Customer Name</label><input type="text" name="customer_name" class="form-control" required></div>
                <div class="mb-2"><label class="form-label">Phone</label><input type="text" name="customer_phone" class="form-control"></div>
                <div class="mb-2"><label class="form-label">Duration (seconds)</label><input type="number" name="call_duration" class="form-control" value="0"></div>
                <div class="mb-2"><label class="form-label">Type</label><select name="call_type" class="form-select"><option value="outgoing">Outgoing</option><option value="incoming">Incoming</option><option value="follow_up">Follow Up</option></select></div>
                <div class="mb-2"><label class="form-label">Status</label><select name="call_status" class="form-select"><option value="completed">Completed</option><option value="busy">Busy</option><option value="no_answer">No Answer</option><option value="callback">Callback</option><option value="not_interested">Not Interested</option></select></div>
                <div class="mb-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
                <div class="mb-2"><label class="form-label">Follow Up Required</label><select name="follow_up_required" class="form-select"><option value="0">No</option><option value="1">Yes</option></select></div>
                <div class="mb-2"><label class="form-label">Date</label><input type="date" name="call_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
        </div>
    </form></div>
</div>

<?php foreach ($calls as $c): ?>
<div class="modal fade" id="viewModal<?php echo $c['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Call Detail</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p><strong>Customer:</strong> <?php echo sanitize($c['customer_name']); ?> (<?php echo sanitize($c['customer_phone']); ?>)</p>
            <p><strong>Employee:</strong> <?php echo sanitize($c['first_name'] . ' ' . $c['last_name']); ?></p>
            <p><strong>Duration:</strong> <?php echo gmdate('i:s', $c['call_duration']); ?></p>
            <p><strong>Type:</strong> <?php echo ucfirst(str_replace('_', ' ', $c['call_type'])); ?></p>
            <p><strong>Status:</strong> <?php echo ucfirst(str_replace('_', ' ', $c['status'])); ?></p>
            <p><strong>Notes:</strong> <?php echo nl2br(sanitize($c['notes'])); ?></p>
            <p><strong>Follow Up:</strong> <?php echo $c['follow_up_required'] ? 'Yes' : 'No'; ?></p>
        </div>
    </div></div>
</div>
<?php endforeach; ?>

<?php require_once '../includes/footer.php'; ?>
