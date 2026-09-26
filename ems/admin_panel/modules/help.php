<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('help');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action == 'update_status') {
            $id = (int)$_POST['id'];
            $status = $_POST['status'];
            $assigned_to = $_SESSION['admin_id'] ?? null;
            $params = [$status, $assigned_to];
            $extra = '';
            if ($status == 'resolved') {
                $extra = ', resolved_at = NOW()';
            }
            $stmt = $pdo->prepare("UPDATE help_tickets SET status=? $extra WHERE id=?");
            $stmt->execute([$status, $id]);
            $message = 'Ticket status updated successfully';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

$tickets = $pdo->query("
    SELECT t.*, e.first_name, e.last_name, e.employee_code
    FROM help_tickets t
    LEFT JOIN employees e ON e.id = t.employee_id
    ORDER BY FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'), t.created_at DESC
")->fetchAll();

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-life-ring me-2"></i>Help & Support</h4>
</div>
<?php if ($message): ?>
<div class="alert alert-info alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead>
                    <tr><th>Employee</th><th>Subject</th><th>Message</th><th>Priority</th><th>Status</th><th>Date</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td><?php echo sanitize($t['first_name'] . ' ' . $t['last_name']); ?></td>
                        <td><?php echo sanitize($t['subject']); ?></td>
                        <td><?php echo sanitize(substr($t['message'], 0, 60)) . (strlen($t['message']) > 60 ? '...' : ''); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $t['priority'] == 'urgent' ? 'danger' : ($t['priority'] == 'high' ? 'warning text-dark' : ($t['priority'] == 'medium' ? 'info' : 'secondary')); ?>">
                                <?php echo ucfirst($t['priority']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $t['status'] == 'open' ? 'danger' : ($t['status'] == 'in_progress' ? 'warning text-dark' : ($t['status'] == 'resolved' ? 'success' : 'secondary')); ?>">
                                <?php echo str_replace('_', ' ', ucfirst($t['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo sanitize($t['created_at']); ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-warning" onclick="updateTicket(<?php echo $t['id']; ?>, 'in_progress')" title="In Progress"><i class="fas fa-spinner"></i></button>
                                <button class="btn btn-success" onclick="updateTicket(<?php echo $t['id']; ?>, 'resolved')" title="Resolved"><i class="fas fa-check"></i></button>
                                <button class="btn btn-secondary" onclick="updateTicket(<?php echo $t['id']; ?>, 'closed')" title="Close"><i class="fas fa-times"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function updateTicket(id, status) {
    if (confirm('Are you sure you want to mark this ticket as ' + status.replace('_', ' ') + '?')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="' + id + '"><input type="hidden" name="status" value="' + status + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
