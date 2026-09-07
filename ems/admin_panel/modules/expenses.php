<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('expenses');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $employee_id = (int)$_POST['employee_id'];
    $expense_category_id = $_POST['expense_category_id'] ? (int)$_POST['expense_category_id'] : null;
    $amount = $_POST['amount'];
    $expense_date = $_POST['expense_date'];
    $description = sanitize($_POST['description'] ?? '');

    try {
        if ($action == 'add') {
            $stmt = $pdo->prepare("INSERT INTO expenses (employee_id, expense_category_id, amount, expense_date, description) VALUES (?,?,?,?,?)");
            $stmt->execute([$employee_id, $expense_category_id, $amount, $expense_date, $description]);
            $message = 'Expense added successfully';
        } elseif ($action == 'edit') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE expenses SET employee_id=?, expense_category_id=?, amount=?, expense_date=?, description=? WHERE id=?");
            $stmt->execute([$employee_id, $expense_category_id, $amount, $expense_date, $description, $id]);
            $message = 'Expense updated successfully';
        } elseif ($action == 'update_status') {
            $id = (int)$_POST['id'];
            $status = $_POST['status'];
            $remarks = sanitize($_POST['remarks'] ?? '');
            $stmt = $pdo->prepare("UPDATE expenses SET status=?, approved_by=?, approval_date=NOW(), remarks=? WHERE id=?");
            $stmt->execute([$status, $_SESSION['admin_id'] ?? null, $remarks, $id]);
            $message = 'Status updated successfully';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
    $message = 'Expense deleted';
}

$expenses = $pdo->query("
    SELECT e.*, emp.first_name, emp.last_name, emp.employee_code, ec.name as category_name
    FROM expenses e
    LEFT JOIN employees emp ON emp.id = e.employee_id
    LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
    ORDER BY e.created_at DESC
")->fetchAll();

$employees = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();
$categories = $pdo->query("SELECT * FROM expense_categories WHERE status = 1 ORDER BY name")->fetchAll();

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-receipt me-2"></i>Expense Management</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#expenseModal">
        <i class="fas fa-plus me-1"></i>Add Expense
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
                    <tr><th>Employee</th><th>Category</th><th>Amount</th><th>Date</th><th>Description</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $exp): ?>
                    <tr>
                        <td><?php echo sanitize($exp['first_name'] . ' ' . $exp['last_name']); ?></td>
                        <td><?php echo sanitize($exp['category_name'] ?? '-'); ?></td>
                        <td><?php echo number_format($exp['amount'], 2); ?></td>
                        <td><?php echo sanitize($exp['expense_date']); ?></td>
                        <td><?php echo sanitize($exp['description']); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $exp['status'] == 'approved' ? 'success' : ($exp['status'] == 'rejected' ? 'danger' : 'warning'); ?>">
                                <?php echo ucfirst($exp['status']); ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editExpense(<?php echo htmlspecialchars(json_encode($exp)); ?>)"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-success" onclick="updateStatus(<?php echo $exp['id']; ?>, 'approved')"><i class="fas fa-check"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="updateStatus(<?php echo $exp['id']; ?>, 'rejected')"><i class="fas fa-times"></i></button>
                            <a href="?delete=<?php echo $exp['id']; ?>" class="btn btn-sm btn-secondary btn-delete"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Employee *</label>
                            <select name="employee_id" id="f_employee" class="form-select" required>
                                <option value="">Select</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Category</label>
                            <select name="expense_category_id" id="f_category" class="form-select">
                                <option value="">Select</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo sanitize($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount *</label>
                            <input type="number" step="0.01" name="amount" id="f_amount" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date *</label>
                            <input type="date" name="expense_date" id="f_date" class="form-control" required>
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
                    <p>Are you sure you want to <strong id="statusLabel"></strong> this expense?</p>
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
function editExpense(exp) {
    document.getElementById('modalTitle').textContent = 'Edit Expense';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = exp.id;
    document.getElementById('f_employee').value = exp.employee_id;
    document.getElementById('f_category').value = exp.expense_category_id || '';
    document.getElementById('f_amount').value = exp.amount;
    document.getElementById('f_date').value = exp.expense_date;
    document.getElementById('f_description').value = exp.description || '';
    new bootstrap.Modal(document.getElementById('expenseModal')).show();
}

function updateStatus(id, status) {
    document.getElementById('statusId').value = id;
    document.getElementById('statusValue').value = status;
    document.getElementById('statusLabel').textContent = status.charAt(0).toUpperCase() + status.slice(1);
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
