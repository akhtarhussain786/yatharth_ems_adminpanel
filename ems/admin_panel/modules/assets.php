<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action == 'add_asset' || $action == 'edit_asset') {
            $name = sanitize($_POST['name']);
            $asset_code = sanitize($_POST['asset_code'] ?? '');
            $category = sanitize($_POST['category'] ?? '');
            $purchase_date = $_POST['purchase_date'] ?: null;
            $purchase_price = $_POST['purchase_price'] ?? 0;
            $current_value = $_POST['current_value'] ?? 0;
            $status = $_POST['status'] ?? 'available';
            $description = sanitize($_POST['description'] ?? '');

            if ($action == 'add_asset') {
                $stmt = $pdo->prepare("INSERT INTO assets (name, asset_code, category, purchase_date, purchase_price, current_value, status, description) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$name, $asset_code, $category, $purchase_date, $purchase_price, $current_value, $status, $description]);
                $message = 'Asset added successfully';
            } else {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE assets SET name=?, asset_code=?, category=?, purchase_date=?, purchase_price=?, current_value=?, status=?, description=? WHERE id=?");
                $stmt->execute([$name, $asset_code, $category, $purchase_date, $purchase_price, $current_value, $status, $description, $id]);
                $message = 'Asset updated successfully';
            }
        } elseif ($action == 'assign') {
            $asset_id = (int)$_POST['asset_id'];
            $employee_id = (int)$_POST['employee_id'];
            $assigned_date = $_POST['assigned_date'];
            $condition_on_assign = sanitize($_POST['condition_on_assign'] ?? '');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO asset_assignments (asset_id, employee_id, assigned_date, condition_on_assign) VALUES (?,?,?,?)");
            $stmt->execute([$asset_id, $employee_id, $assigned_date, $condition_on_assign]);
            $pdo->prepare("UPDATE assets SET status='assigned' WHERE id=?")->execute([$asset_id]);
            $pdo->commit();
            $message = 'Asset assigned successfully';
        } elseif ($action == 'return') {
            $id = (int)$_POST['id'];
            $return_date = $_POST['return_date'];
            $condition_on_return = sanitize($_POST['condition_on_return'] ?? '');
            $assign = $pdo->prepare("SELECT asset_id FROM asset_assignments WHERE id=?")->execute([$id]);
            $assign = $pdo->prepare("SELECT asset_id FROM asset_assignments WHERE id=?");
            $assign->execute([$id]);
            $a = $assign->fetch();
            if ($a) {
                $pdo->prepare("UPDATE asset_assignments SET return_date=?, condition_on_return=? WHERE id=?")->execute([$return_date, $condition_on_return, $id]);
                $pdo->prepare("UPDATE assets SET status='available' WHERE id=?")->execute([$a['asset_id']]);
                $message = 'Asset returned successfully';
            }
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

if (isset($_GET['delete_asset'])) {
    $id = (int)$_GET['delete_asset'];
    $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$id]);
    $message = 'Asset deleted';
}

$assets = $pdo->query("SELECT * FROM assets ORDER BY name ASC")->fetchAll();
$assignments = $pdo->query("
    SELECT aa.*, a.name as asset_name, a.asset_code, e.first_name, e.last_name, e.employee_code
    FROM asset_assignments aa
    LEFT JOIN assets a ON a.id = aa.asset_id
    LEFT JOIN employees e ON e.id = aa.employee_id
    ORDER BY aa.created_at DESC
")->fetchAll();
$employees = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#assetsTab">Assets</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#assignmentsTab">Assignments</a></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="assetsTab">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold"><i class="fas fa-box me-2"></i>Assets</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assetModal">
                <i class="fas fa-plus me-1"></i>Add Asset
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
                            <tr><th>Name</th><th>Code</th><th>Category</th><th>Purchase Price</th><th>Current Value</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assets as $a): ?>
                            <tr>
                                <td><?php echo sanitize($a['name']); ?></td>
                                <td><?php echo sanitize($a['asset_code']); ?></td>
                                <td><?php echo sanitize($a['category']); ?></td>
                                <td><?php echo number_format($a['purchase_price'], 2); ?></td>
                                <td><?php echo number_format($a['current_value'], 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $a['status'] == 'available' ? 'success' : ($a['status'] == 'assigned' ? 'primary' : ($a['status'] == 'maintenance' ? 'warning' : 'secondary')); ?>">
                                        <?php echo ucfirst($a['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editAsset(<?php echo htmlspecialchars(json_encode($a)); ?>)"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#assignModal" onclick="document.getElementById('assign_asset_id').value=<?php echo $a['id']; ?>"><i class="fas fa-handshake"></i></button>
                                    <a href="?delete_asset=<?php echo $a['id']; ?>" class="btn btn-sm btn-danger btn-delete"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="assignmentsTab">
        <h5 class="fw-bold mb-3"><i class="fas fa-handshake me-2"></i>Asset Assignments</h5>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Asset</th><th>Employee</th><th>Assigned Date</th><th>Return Date</th><th>Condition</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $as): ?>
                            <tr>
                                <td><?php echo sanitize($as['asset_name'] . ' (' . $as['asset_code'] . ')'); ?></td>
                                <td><?php echo sanitize($as['first_name'] . ' ' . $as['last_name']); ?></td>
                                <td><?php echo sanitize($as['assigned_date']); ?></td>
                                <td><?php echo $as['return_date'] ?: '-'; ?></td>
                                <td><?php echo sanitize($as['condition_on_assign'] ?: '-'); ?></td>
                                <td>
                                    <?php if (!$as['return_date']): ?>
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#returnModal" onclick="setReturn(<?php echo $as['id']; ?>)"><i class="fas fa-undo"></i></button>
                                    <?php endif; ?>
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

<!-- Add/Edit Asset Modal -->
<div class="modal fade" id="assetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add_asset">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" id="f_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Asset Code</label>
                            <input type="text" name="asset_code" id="f_code" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <input type="text" name="category" id="f_category" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" name="purchase_date" id="f_purchase_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purchase Price</label>
                            <input type="number" step="0.01" name="purchase_price" id="f_purchase_price" class="form-control" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Value</label>
                            <input type="number" step="0.01" name="current_value" id="f_current_value" class="form-control" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="f_status" class="form-select">
                                <option value="available">Available</option>
                                <option value="assigned">Assigned</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="retired">Retired</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="f_description" class="form-control" rows="2"></textarea>
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

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="asset_id" id="assign_asset_id" value="">
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
                            <label class="form-label">Assigned Date *</label>
                            <input type="date" name="assigned_date" class="form-control" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Condition on Assign</label>
                            <textarea name="condition_on_assign" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Return Asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="return">
                    <input type="hidden" name="id" id="return_id" value="">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Return Date *</label>
                            <input type="date" name="return_date" class="form-control" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Condition on Return</label>
                            <textarea name="condition_on_return" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editAsset(a) {
    document.getElementById('modalTitle').textContent = 'Edit Asset';
    document.getElementById('formAction').value = 'edit_asset';
    document.getElementById('formId').value = a.id;
    document.getElementById('f_name').value = a.name;
    document.getElementById('f_code').value = a.asset_code || '';
    document.getElementById('f_category').value = a.category || '';
    document.getElementById('f_purchase_date').value = a.purchase_date || '';
    document.getElementById('f_purchase_price').value = a.purchase_price;
    document.getElementById('f_current_value').value = a.current_value;
    document.getElementById('f_status').value = a.status;
    document.getElementById('f_description').value = a.description || '';
    new bootstrap.Modal(document.getElementById('assetModal')).show();
}

function setReturn(id) {
    document.getElementById('return_id').value = id;
}
</script>

<?php require_once '../includes/footer.php'; ?>
