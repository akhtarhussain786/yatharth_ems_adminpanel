<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('tasks');

$depts = $pdo->query("SELECT id, name FROM departments WHERE status = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'create') {
    requireModuleAccess('tasks', 'can_create');
    $eid = intval($_POST['employee_id'] ?? 0);
    $title = sanitize($_POST['title'] ?? '');
    $desc = sanitize($_POST['description'] ?? '');
    $priority = sanitize($_POST['priority'] ?? 'medium');
    $due = $_POST['due_date'] ?? '';
    $pdo->prepare("INSERT INTO tasks (assigned_to, title, description, priority, assigned_by, due_date) VALUES (?,?,?,?,?,?)")->execute([$eid, $title, $desc, $priority, $_SESSION['admin_id'], $due ?: null]);
    $_SESSION['flash'] = 'Task created';
    redirect('tasks.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'update') {
    requireModuleAccess('tasks', 'can_edit');
    $id = intval($_POST['id'] ?? 0);
    $status = sanitize($_POST['status'] ?? 'pending');
    $priority = sanitize($_POST['priority'] ?? 'medium');
    $pdo->prepare("UPDATE tasks SET status=?, priority=? WHERE id=?")->execute([$status, $priority, $id]);
    $_SESSION['flash'] = 'Task updated';
    redirect('tasks.php');
}

if (isset($_GET['delete'])) {
    requireModuleAccess('tasks', 'can_delete');
    $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([intval($_GET['delete'])]);
    $_SESSION['flash'] = 'Task deleted';
    redirect('tasks.php');
}

$tasks = $pdo->query("SELECT t.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM tasks t JOIN employees e ON e.id = t.assigned_to LEFT JOIN departments d ON d.id = e.department_id ORDER BY FIELD(t.status,'pending','in_progress','completed','cancelled'), t.due_date ASC")->fetchAll();
$emps = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-tasks me-2"></i>Tasks</h4>
    <?php if (hasModuleAccess('tasks', 'can_create')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus"></i> Assign Task</button>
    <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert-success py-2"><?php echo $flash; ?></div><?php endif; ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead><tr><th>Employee</th><th>Dept</th><th>Title</th><th>Priority</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td><?php echo sanitize($t['first_name'] . ' ' . $t['last_name']); ?></td>
                        <td><?php echo sanitize($t['department_name'] ?? '-'); ?></td>
                        <td><?php echo sanitize($t['title']); ?></td>
                        <td><span class="badge bg-<?php echo $t['priority'] == 'urgent' ? 'danger' : ($t['priority'] == 'high' ? 'warning' : ($t['priority'] == 'medium' ? 'info' : 'secondary')); ?>"><?php echo ucfirst($t['priority']); ?></span></td>
                        <td><?php echo $t['due_date'] ?? '-'; ?></td>
                        <td><?php $st = strtolower($t['status'] ?? 'pending'); ?><span class="badge bg-<?php echo $st == 'completed' ? 'success' : ($st == 'in_progress' ? 'primary' : ($st == 'cancelled' ? 'secondary' : 'warning')); ?>"><?php echo ucwords(str_replace('_', ' ', $st)); ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $t['id']; ?>"><i class="fas fa-edit"></i></button>
                            <?php if (hasModuleAccess('tasks', 'can_delete')): ?>
                            <a href="?delete=<?php echo $t['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this task?')"><i class="fas fa-trash"></i></a>
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
            <div class="modal-header"><h5 class="modal-title">Assign Task</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Employee</label><select name="employee_id" class="form-select" required><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>"><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="mb-2"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
                <div class="mb-2"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                <div class="mb-2"><label class="form-label">Priority</label><select name="priority" class="form-select"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
                <div class="mb-2"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Assign</button></div>
        </div>
    </form></div>
</div>

<?php foreach ($tasks as $t): ?>
<!-- Edit Modal -->
<div class="modal fade" id="editModal<?php echo $t['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><form method="POST" action="?action=update">
        <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Update Task</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p><strong><?php echo sanitize($t['title']); ?></strong> - <?php echo sanitize($t['first_name'] . ' ' . $t['last_name']); ?></p>
                <div class="mb-2"><label class="form-label">Status</label><select name="status" class="form-select"><?php
                    $st = strtolower($t['status'] ?? 'pending');
                    // The IT module puts 'approved' and 'correction' on this same
                    // table. Neither is offered below, so without this the box
                    // would fall back to Pending and saving would overwrite the
                    // real status.
                    if (!in_array($st, ['pending','in_progress','completed','cancelled'], true)) {
                        echo '<option value="' . htmlspecialchars($st) . '" selected>'
                           . htmlspecialchars(ucwords(str_replace('_', ' ', $st))) . '</option>';
                    }
                ?><option value="pending" <?php echo $st=='pending'?'selected':''; ?>>Pending</option><option value="in_progress" <?php echo $st=='in_progress'?'selected':''; ?>>In Progress</option><option value="completed" <?php echo $st=='completed'?'selected':''; ?>>Completed</option><option value="cancelled" <?php echo $st=='cancelled'?'selected':''; ?>>Cancelled</option></select></div>
                <div class="mb-2"><label class="form-label">Priority</label><select name="priority" class="form-select"><option value="low" <?php echo strtolower($t['priority'] ?? '')=='low'?'selected':''; ?>>Low</option><option value="medium" <?php echo strtolower($t['priority'] ?? '')=='medium'?'selected':''; ?>>Medium</option><option value="high" <?php echo strtolower($t['priority'] ?? '')=='high'?'selected':''; ?>>High</option><option value="urgent" <?php echo strtolower($t['priority'] ?? '')=='urgent'?'selected':''; ?>>Urgent</option></select></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Update</button></div>
        </div>
    </form></div>
</div>
<?php endforeach; ?>

<?php require_once '../includes/footer.php'; ?>
