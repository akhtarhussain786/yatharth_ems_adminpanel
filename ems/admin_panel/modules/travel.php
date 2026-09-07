<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('travel');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $employee_id = (int)$_POST['employee_id'];
    $travel_date = $_POST['travel_date'];
    $purpose = sanitize($_POST['purpose'] ?? '');
    $start_km = (int)$_POST['start_km'];
    $end_km = (int)$_POST['end_km'];
    $start_location = sanitize($_POST['start_location'] ?? '');
    $end_location = sanitize($_POST['end_location'] ?? '');
    $rate_per_km = $_POST['rate_per_km'] ?? 2.50;
    $remarks = sanitize($_POST['remarks'] ?? '');

    try {
        if ($action == 'add') {
            $stmt = $pdo->prepare("INSERT INTO travel_requests (employee_id, travel_date, purpose, start_km, end_km, start_location, end_location, rate_per_km, travel_allowance, remarks) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $allowance = max(0, $end_km - $start_km) * $rate_per_km;
            $stmt->execute([$employee_id, $travel_date, $purpose, $start_km, $end_km, $start_location, $end_location, $rate_per_km, $allowance, $remarks]);
            $message = 'Travel request added successfully';
        } elseif ($action == 'edit') {
            $id = (int)$_POST['id'];
            $allowance = max(0, $end_km - $start_km) * $rate_per_km;
            $stmt = $pdo->prepare("UPDATE travel_requests SET employee_id=?, travel_date=?, purpose=?, start_km=?, end_km=?, start_location=?, end_location=?, rate_per_km=?, travel_allowance=?, remarks=? WHERE id=?");
            $stmt->execute([$employee_id, $travel_date, $purpose, $start_km, $end_km, $start_location, $end_location, $rate_per_km, $allowance, $remarks, $id]);
            $message = 'Travel request updated successfully';
        } elseif ($action == 'update_status') {
            $id = (int)$_POST['id'];
            $status = $_POST['status'];
            $admin_remarks = sanitize($_POST['remarks'] ?? '');
            $stmt = $pdo->prepare("UPDATE travel_requests SET status=?, remarks=?, approved_by=? WHERE id=?");
            $stmt->execute([$status, $admin_remarks, $_SESSION['admin_id'] ?? null, $id]);
            $message = 'Status updated successfully';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM travel_requests WHERE id = ?")->execute([$id]);
    $message = 'Travel request deleted';
}

$travel_requests = $pdo->query("
    SELECT t.*, GREATEST(COALESCE(t.end_km,0) - COALESCE(t.start_km,0), 0) AS total_km,
           e.first_name, e.last_name, e.employee_code
    FROM travel_requests t
    LEFT JOIN employees e ON e.id = t.employee_id
    ORDER BY t.created_at DESC
")->fetchAll();

$employees = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-plane me-2"></i>Travel Management</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#travelModal">
        <i class="fas fa-plus me-1"></i>Add Travel
    </button>
</div>
<?php if ($message): ?>
<div class="alert alert-info alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead>
                    <tr><th>Employee</th><th>Date</th><th>Purpose</th><th>Start KM</th><th>End KM</th><th>Total KM</th><th>Allowance</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($travel_requests as $t): ?>
                    <tr>
                        <td><?php echo sanitize($t['first_name'] . ' ' . $t['last_name'] . ' (' . $t['employee_code'] . ')'); ?></td>
                        <td><?php echo sanitize($t['travel_date']); ?></td>
                        <td><?php echo sanitize($t['purpose']); ?></td>
                        <td><?php echo $t['start_km']; ?></td>
                        <td><?php echo $t['end_km']; ?></td>
                        <td><?php echo (int)($t['total_km'] ?? 0); ?></td>
                        <td><?php echo number_format((float)($t['travel_allowance'] ?? 0), 2); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $t['status'] == 'approved' ? 'success' : ($t['status'] == 'rejected' ? 'danger' : 'warning'); ?>">
                                <?php echo ucfirst($t['status']); ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editTravel(<?php echo htmlspecialchars(json_encode($t)); ?>)"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-success" onclick="updateStatus(<?php echo $t['id']; ?>, 'approved')"><i class="fas fa-check"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="updateStatus(<?php echo $t['id']; ?>, 'rejected')"><i class="fas fa-times"></i></button>
                            <a href="?delete=<?php echo $t['id']; ?>" class="btn btn-sm btn-secondary btn-delete"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="travelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Travel Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Employee *</label>
                            <select name="employee_id" id="f_employee" class="form-select" required>
                                <option value="">Select</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Travel Date *</label>
                            <input type="date" name="travel_date" id="f_date" class="form-control" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Purpose</label>
                            <textarea name="purpose" id="f_purpose" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Start KM</label>
                            <input type="number" name="start_km" id="f_start_km" class="form-control" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End KM</label>
                            <input type="number" name="end_km" id="f_end_km" class="form-control" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Rate per KM</label>
                            <input type="number" step="0.01" name="rate_per_km" id="f_rate" class="form-control" value="2.50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Start Location</label>
                            <input type="text" name="start_location" id="f_start_loc" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Location</label>
                            <input type="text" name="end_location" id="f_end_loc" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" id="f_remarks" class="form-control" rows="2"></textarea>
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

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Update Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="id" id="statusId" value="">
                    <input type="hidden" name="status" id="statusValue" value="">
                    <p>Are you sure you want to <strong id="statusLabel"></strong> this request?</p>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editTravel(t) {
    document.getElementById('modalTitle').textContent = 'Edit Travel Request';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = t.id;
    document.getElementById('f_employee').value = t.employee_id;
    document.getElementById('f_date').value = t.travel_date;
    document.getElementById('f_purpose').value = t.purpose || '';
    document.getElementById('f_start_km').value = t.start_km;
    document.getElementById('f_end_km').value = t.end_km;
    document.getElementById('f_rate').value = t.rate_per_km;
    document.getElementById('f_start_loc').value = t.start_location || '';
    document.getElementById('f_end_loc').value = t.end_location || '';
    document.getElementById('f_remarks').value = t.remarks || '';
    new bootstrap.Modal(document.getElementById('travelModal')).show();
}

function updateStatus(id, status) {
    document.getElementById('statusId').value = id;
    document.getElementById('statusValue').value = status;
    document.getElementById('statusLabel').textContent = status.charAt(0).toUpperCase() + status.slice(1);
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
