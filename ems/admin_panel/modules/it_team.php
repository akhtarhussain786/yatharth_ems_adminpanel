<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('it_team');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action == 'add_points_config' || $action == 'edit_points_config') {
            $task_type = sanitize($_POST['task_type']);
            $points = (int)$_POST['points'];
            $status = $_POST['status'] ?? 1;

            if ($action == 'add_points_config') {
                $stmt = $pdo->prepare("INSERT INTO task_points_config (task_type, points, status) VALUES (?,?,?)");
                $stmt->execute([$task_type, $points, $status]);
                $message = 'Task points config added';
            } else {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE task_points_config SET task_type=?, points=?, status=? WHERE id=?");
                $stmt->execute([$task_type, $points, $status, $id]);
                $message = 'Task points config updated';
            }
        } elseif ($action == 'delete_points_config') {
            $pdo->prepare("DELETE FROM task_points_config WHERE id=?")->execute([(int)$_POST['id']]);
            $message = 'Points config deleted';
        } elseif ($action == 'add_target') {
            $employee_id = (int)$_POST['employee_id'];
            $target_type = sanitize($_POST['target_type']);
            $daily_target = (int)$_POST['daily_target'];
            $monthly_target = (int)$_POST['monthly_target'];
            $points_per_unit = (int)$_POST['points_per_unit'];
            $month_year = $_POST['month_year'];

            $stmt = $pdo->prepare("INSERT INTO employee_targets (employee_id, target_type, daily_target, monthly_target, points_per_unit, month_year) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$employee_id, $target_type, $daily_target, $monthly_target, $points_per_unit, $month_year]);
            $message = 'Target added successfully';
        } elseif ($action == 'delete_target') {
            $pdo->prepare("DELETE FROM employee_targets WHERE id=?")->execute([(int)$_POST['id']]);
            $message = 'Target deleted';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

$points_config = $pdo->query("SELECT * FROM task_points_config ORDER BY task_type")->fetchAll();

$submissions = $pdo->query("
    SELECT ts.*, t.title as task_title, t.points as task_points, e.first_name, e.last_name
    FROM task_submissions ts
    LEFT JOIN tasks t ON t.id = ts.task_id
    LEFT JOIN employees e ON e.id = ts.employee_id
    ORDER BY ts.submitted_at DESC LIMIT 100
")->fetchAll();

$targets = $pdo->query("
    SELECT et.*, e.first_name, e.last_name, e.employee_code
    FROM employee_targets et
    LEFT JOIN employees e ON e.id = et.employee_id
    ORDER BY et.month_year DESC, e.first_name ASC
")->fetchAll();

$employees = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

$performance = $pdo->query("
    SELECT e.id, e.first_name, e.last_name, e.employee_code,
           COALESCE(SUM(t.points), 0) as total_points_earned,
           COALESCE(SUM(CASE WHEN t.status = 'approved' THEN t.points ELSE 0 END), 0) as approved_points,
           COUNT(DISTINCT t.id) as total_tasks,
           COUNT(DISTINCT CASE WHEN t.status = 'approved' THEN t.id END) as completed_tasks
    FROM employees e
    LEFT JOIN tasks t ON t.assigned_to = e.id
    GROUP BY e.id
    ORDER BY approved_points DESC
")->fetchAll();

require_once '../includes/header.php';
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#configTab">Task Points Config</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#submissionsTab">Task Submissions</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#targetsTab">Employee Targets</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#performanceTab">Performance</a></li>
</ul>
<?php if ($message): ?>
<div class="alert alert-info alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<div class="tab-content">
    <div class="tab-pane fade show active" id="configTab">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold"><i class="fas fa-cogs me-2"></i>Task Points Configuration</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#pointsModal">
                <i class="fas fa-plus me-1"></i>Add Config
            </button>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Task Type</th><th>Points</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($points_config as $pc): ?>
                            <tr>
                                <td><?php echo sanitize($pc['task_type']); ?></td>
                                <td><span class="badge bg-primary"><?php echo $pc['points']; ?> pts</span></td>
                                <td><span class="badge bg-<?php echo $pc['status'] ? 'success' : 'secondary'; ?>"><?php echo $pc['status'] ? 'Active' : 'Inactive'; ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editPoints(<?php echo htmlspecialchars(json_encode($pc)); ?>)"><i class="fas fa-edit"></i></button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this config?')">
                                        <input type="hidden" name="action" value="delete_points_config">
                                        <input type="hidden" name="id" value="<?php echo $pc['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="submissionsTab">
        <h5 class="fw-bold mb-3"><i class="fas fa-upload me-2"></i>Task Submissions</h5>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Employee</th><th>Task</th><th>Description</th><th>Rating</th><th>Status</th><th>Submitted</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $s): ?>
                            <tr>
                                <td><?php echo sanitize($s['first_name'] . ' ' . $s['last_name']); ?></td>
                                <td><?php echo sanitize($s['task_title'] ?? '-'); ?></td>
                                <td><?php echo sanitize(substr($s['description'] ?? '', 0, 50)); ?></td>
                                <td><?php echo $s['rating'] ? $s['rating'] . ' / 5' : '-'; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $s['status'] == 'approved' ? 'success' : ($s['status'] == 'rejected' ? 'danger' : ($s['status'] == 'correction' ? 'warning text-dark' : 'secondary')); ?>">
                                        <?php echo ucfirst($s['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo sanitize($s['submitted_at']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="targetsTab">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold"><i class="fas fa-bullseye me-2"></i>Employee Targets</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#targetModal">
                <i class="fas fa-plus me-1"></i>Add Target
            </button>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Employee</th><th>Target Type</th><th>Daily</th><th>Monthly</th><th>Points/Unit</th><th>Month</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($targets as $tg): ?>
                            <tr>
                                <td><?php echo sanitize($tg['first_name'] . ' ' . $tg['last_name']); ?></td>
                                <td><?php echo sanitize($tg['target_type']); ?></td>
                                <td><?php echo $tg['daily_target']; ?></td>
                                <td><?php echo $tg['monthly_target']; ?></td>
                                <td><span class="badge bg-info"><?php echo $tg['points_per_unit']; ?></span></td>
                                <td><?php echo sanitize($tg['month_year']); ?></td>
                                <td>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this target?')">
                                        <input type="hidden" name="action" value="delete_target">
                                        <input type="hidden" name="id" value="<?php echo $tg['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="performanceTab">
        <h5 class="fw-bold mb-3"><i class="fas fa-chart-line me-2"></i>Performance Tracking</h5>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Employee</th><th>Total Tasks</th><th>Completed Tasks</th><th>Total Points</th><th>Approved Points</th><th>Performance</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($performance as $p): ?>
                            <tr>
                                <td><?php echo sanitize($p['first_name'] . ' ' . $p['last_name']); ?></td>
                                <td><?php echo $p['total_tasks']; ?></td>
                                <td><?php echo $p['completed_tasks']; ?></td>
                                <td><?php echo $p['total_points_earned']; ?></td>
                                <td><?php echo $p['approved_points']; ?></td>
                                <td>
                                    <?php
                                    $rate = $p['total_tasks'] > 0 ? round(($p['completed_tasks'] / $p['total_tasks']) * 100) : 0;
                                    $color = $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning text-dark' : 'danger');
                                    ?>
                                    <div class="progress">
                                        <div class="progress-bar bg-<?php echo $color; ?>" style="width: <?php echo $rate; ?>%"><?php echo $rate; ?>%</div>
                                    </div>
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

<!-- Points Config Modal -->
<div class="modal fade" id="pointsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="pModalTitle">Add Points Config</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="pFormAction" value="add_points_config">
                    <input type="hidden" name="id" id="pFormId" value="">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Task Type *</label>
                            <input type="text" name="task_type" id="p_task_type" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Points *</label>
                            <input type="number" name="points" id="p_points" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="p_status" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
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

<!-- Target Modal -->
<div class="modal fade" id="targetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Employee Target</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_target">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Employee *</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Target Type *</label>
                            <input type="text" name="target_type" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Daily Target</label>
                            <input type="number" name="daily_target" class="form-control" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Monthly Target</label>
                            <input type="number" name="monthly_target" class="form-control" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Points/Unit</label>
                            <input type="number" name="points_per_unit" class="form-control" value="0">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Month/Year</label>
                            <input type="text" name="month_year" class="form-control" placeholder="e.g. 2024-01">
                        </div>
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

<script>
function editPoints(pc) {
    document.getElementById('pModalTitle').textContent = 'Edit Points Config';
    document.getElementById('pFormAction').value = 'edit_points_config';
    document.getElementById('pFormId').value = pc.id;
    document.getElementById('p_task_type').value = pc.task_type;
    document.getElementById('p_points').value = pc.points;
    document.getElementById('p_status').value = pc.status;
    new bootstrap.Modal(document.getElementById('pointsModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
