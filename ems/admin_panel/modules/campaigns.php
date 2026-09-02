<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('campaigns');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'create') {
    requireModuleAccess('campaigns', 'can_create');
    $eid = intval($_POST['employee_id'] ?? 0);
    $name = sanitize($_POST['campaign_name'] ?? '');
    $platform = sanitize($_POST['platform'] ?? '');
    $budget = floatval($_POST['budget'] ?? 0);
    $start = $_POST['start_date'] ?? '';
    $end = $_POST['end_date'] ?? '';
    $pdo->prepare("INSERT INTO campaigns (employee_id, campaign_name, platform, budget, start_date, end_date) VALUES (?,?,?,?,?,?)")->execute([$eid, $name, $platform, $budget, $start ?: null, $end ?: null]);
    $_SESSION['flash'] = 'Campaign created';
    redirect('campaigns.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'update') {
    requireModuleAccess('campaigns', 'can_edit');
    $id = intval($_POST['id'] ?? 0);
    $name = sanitize($_POST['campaign_name'] ?? '');
    $platform = sanitize($_POST['platform'] ?? '');
    $budget = floatval($_POST['budget'] ?? 0);
    $status = sanitize($_POST['status'] ?? 'planning');
    $results = sanitize($_POST['results'] ?? '');
    $pdo->prepare("UPDATE campaigns SET campaign_name=?, platform=?, budget=?, status=?, results=? WHERE id=?")->execute([$name, $platform, $budget, $status, $results, $id]);
    $_SESSION['flash'] = 'Campaign updated';
    redirect('campaigns.php');
}

if (isset($_GET['delete'])) {
    requireModuleAccess('campaigns', 'can_delete');
    $pdo->prepare("DELETE FROM campaigns WHERE id=?")->execute([intval($_GET['delete'])]);
    $_SESSION['flash'] = 'Campaign deleted';
    redirect('campaigns.php');
}

$campaigns = $pdo->query("SELECT c.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM campaigns c JOIN employees e ON e.id = c.employee_id LEFT JOIN departments d ON d.id = e.department_id ORDER BY c.created_at DESC")->fetchAll();
$emps = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-bullhorn me-2"></i>Campaigns</h4>
    <?php if (hasModuleAccess('campaigns', 'can_create')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus"></i> New Campaign</button>
    <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert-success py-2"><?php echo $flash; ?></div><?php endif; ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead><tr><th>Campaign</th><th>Platform</th><th>Budget</th><th>Employee</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td><?php echo sanitize($c['campaign_name']); ?></td>
                        <td><?php echo sanitize($c['platform']); ?></td>
                        <td>₹<?php echo number_format($c['budget'], 2); ?></td>
                        <td><?php echo sanitize($c['first_name'] . ' ' . $c['last_name']); ?></td>
                        <td><span class="badge bg-<?php echo $c['status'] == 'active' ? 'success' : ($c['status'] == 'planning' ? 'info' : ($c['status'] == 'paused' ? 'warning' : ($c['status'] == 'completed' ? 'primary' : 'secondary'))); ?>"><?php echo ucfirst($c['status']); ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $c['id']; ?>"><i class="fas fa-edit"></i></button>
                            <?php if (hasModuleAccess('campaigns', 'can_delete')): ?>
                            <a href="?delete=<?php echo $c['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this campaign?')"><i class="fas fa-trash"></i></a>
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
            <div class="modal-header"><h5 class="modal-title">New Campaign</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Employee</label><select name="employee_id" class="form-select" required><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>"><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="mb-2"><label class="form-label">Campaign Name</label><input type="text" name="campaign_name" class="form-control" required></div>
                <div class="mb-2"><label class="form-label">Platform</label><input type="text" name="platform" class="form-control" placeholder="Google, Facebook, LinkedIn, etc."></div>
                <div class="mb-2"><label class="form-label">Budget (₹)</label><input type="number" step="0.01" name="budget" class="form-control" value="0"></div>
                <div class="mb-2"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control"></div>
                <div class="mb-2"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
        </div>
    </form></div>
</div>

<?php foreach ($campaigns as $c): ?>
<!-- Edit Modal -->
<div class="modal fade" id="editModal<?php echo $c['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><form method="POST" action="?action=update">
        <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit Campaign</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label">Campaign Name</label><input type="text" name="campaign_name" class="form-control" value="<?php echo sanitize($c['campaign_name']); ?>" required></div>
                <div class="mb-2"><label class="form-label">Platform</label><input type="text" name="platform" class="form-control" value="<?php echo sanitize($c['platform']); ?>"></div>
                <div class="mb-2"><label class="form-label">Budget (₹)</label><input type="number" step="0.01" name="budget" class="form-control" value="<?php echo $c['budget']; ?>"></div>
                <div class="mb-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="planning" <?php echo $c['status']=='planning'?'selected':''; ?>>Planning</option><option value="active" <?php echo $c['status']=='active'?'selected':''; ?>>Active</option><option value="paused" <?php echo $c['status']=='paused'?'selected':''; ?>>Paused</option><option value="completed" <?php echo $c['status']=='completed'?'selected':''; ?>>Completed</option><option value="cancelled" <?php echo $c['status']=='cancelled'?'selected':''; ?>>Cancelled</option></select></div>
                <div class="mb-2"><label class="form-label">Results</label><textarea name="results" class="form-control" rows="3"><?php echo sanitize($c['results']); ?></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Update</button></div>
        </div>
    </form></div>
</div>
<?php endforeach; ?>

<?php require_once '../includes/footer.php'; ?>
