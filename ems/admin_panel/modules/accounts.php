<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action == 'add_vendor' || $action == 'edit_vendor') {
            $name = sanitize($_POST['name']);
            $contact_person = sanitize($_POST['contact_person'] ?? '');
            $mobile = sanitize($_POST['mobile'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $address = sanitize($_POST['address'] ?? '');
            $gst_no = sanitize($_POST['gst_no'] ?? '');
            $pan_no = sanitize($_POST['pan_no'] ?? '');
            $status = $_POST['status'] ?? 1;

            if ($action == 'add_vendor') {
                $stmt = $pdo->prepare("INSERT INTO vendors (name, contact_person, mobile, email, address, gst_no, pan_no, status) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$name, $contact_person, $mobile, $email, $address, $gst_no, $pan_no, $status]);
                $message = 'Vendor added successfully';
            } else {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE vendors SET name=?, contact_person=?, mobile=?, email=?, address=?, gst_no=?, pan_no=?, status=? WHERE id=?");
                $stmt->execute([$name, $contact_person, $mobile, $email, $address, $gst_no, $pan_no, $status, $id]);
                $message = 'Vendor updated successfully';
            }
        } elseif ($action == 'delete_vendor') {
            $pdo->prepare("DELETE FROM vendors WHERE id=?")->execute([(int)$_POST['id']]);
            $message = 'Vendor deleted';
        } elseif ($action == 'add_transaction') {
            $type = $_POST['type'];
            $category = sanitize($_POST['category'] ?? '');
            $amount = $_POST['amount'];
            $entry_date = $_POST['entry_date'];
            $description = sanitize($_POST['description'] ?? '');
            $payment_method = sanitize($_POST['payment_method'] ?? '');
            $reference_no = sanitize($_POST['reference_no'] ?? '');
            $vendor_id = $_POST['vendor_id'] ? (int)$_POST['vendor_id'] : null;

            $stmt = $pdo->prepare("INSERT INTO income_expense (type, category, amount, entry_date, description, payment_method, reference_no, vendor_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$type, $category, $amount, $entry_date, $description, $payment_method, $reference_no, $vendor_id, $_SESSION['admin_id'] ?? null]);
            $message = 'Transaction added successfully';
        } elseif ($action == 'delete_transaction') {
            $pdo->prepare("DELETE FROM income_expense WHERE id=?")->execute([(int)$_POST['id']]);
            $message = 'Transaction deleted';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

$vendors = $pdo->query("SELECT * FROM vendors ORDER BY name ASC")->fetchAll();
$transactions = $pdo->query("SELECT ie.*, v.name as vendor_name FROM income_expense ie LEFT JOIN vendors v ON v.id = ie.vendor_id ORDER BY ie.entry_date DESC LIMIT 100")->fetchAll();
$fees = $pdo->query("SELECT fc.*, u.username as collected_by_name FROM fee_collections fc LEFT JOIN users u ON u.id = fc.collected_by ORDER BY fc.created_at DESC LIMIT 100")->fetchAll();

require_once '../includes/header.php';
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#vendorsTab">Vendors</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#incomeTab">Income / Expense</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#feesTab">Fee Collections</a></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="vendorsTab">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold"><i class="fas fa-truck me-2"></i>Vendors</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#vendorModal">
                <i class="fas fa-plus me-1"></i>Add Vendor
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
                            <tr><th>Name</th><th>Contact Person</th><th>Mobile</th><th>Email</th><th>GST No</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vendors as $v): ?>
                            <tr>
                                <td><?php echo sanitize($v['name']); ?></td>
                                <td><?php echo sanitize($v['contact_person'] ?: '-'); ?></td>
                                <td><?php echo sanitize($v['mobile'] ?: '-'); ?></td>
                                <td><?php echo sanitize($v['email'] ?: '-'); ?></td>
                                <td><?php echo sanitize($v['gst_no'] ?: '-'); ?></td>
                                <td><span class="badge bg-<?php echo $v['status'] ? 'success' : 'danger'; ?>"><?php echo $v['status'] ? 'Active' : 'Inactive'; ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editVendor(<?php echo htmlspecialchars(json_encode($v)); ?>)"><i class="fas fa-edit"></i></button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this vendor?')">
                                        <input type="hidden" name="action" value="delete_vendor">
                                        <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
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
    <div class="tab-pane fade" id="incomeTab">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold"><i class="fas fa-money-bill-wave me-2"></i>Income / Expense</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#transactionModal">
                <i class="fas fa-plus me-1"></i>Add Transaction
            </button>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Type</th><th>Category</th><th>Amount</th><th>Date</th><th>Vendor</th><th>Payment Method</th><th>Reference</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $tr): ?>
                            <tr>
                                <td><span class="badge bg-<?php echo $tr['type'] == 'income' ? 'success' : 'danger'; ?>"><?php echo ucfirst($tr['type']); ?></span></td>
                                <td><?php echo sanitize($tr['category'] ?: '-'); ?></td>
                                <td><?php echo number_format($tr['amount'], 2); ?></td>
                                <td><?php echo sanitize($tr['entry_date']); ?></td>
                                <td><?php echo sanitize($tr['vendor_name'] ?: '-'); ?></td>
                                <td><?php echo sanitize($tr['payment_method'] ?: '-'); ?></td>
                                <td><?php echo sanitize($tr['reference_no'] ?: '-'); ?></td>
                                <td>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this transaction?')">
                                        <input type="hidden" name="action" value="delete_transaction">
                                        <input type="hidden" name="id" value="<?php echo $tr['id']; ?>">
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
    <div class="tab-pane fade" id="feesTab">
        <h5 class="fw-bold mb-3"><i class="fas fa-hand-holding-usd me-2"></i>Fee Collections</h5>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Student Name</th><th>Amount</th><th>Date</th><th>Fee Type</th><th>Payment Method</th><th>Receipt No</th><th>Collected By</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fees as $f): ?>
                            <tr>
                                <td><?php echo sanitize($f['student_name']); ?></td>
                                <td><?php echo number_format($f['amount'], 2); ?></td>
                                <td><?php echo sanitize($f['fee_date']); ?></td>
                                <td><?php echo sanitize($f['fee_type']); ?></td>
                                <td><?php echo sanitize($f['payment_method'] ?: '-'); ?></td>
                                <td><?php echo sanitize($f['receipt_no'] ?: '-'); ?></td>
                                <td><?php echo sanitize($f['collected_by_name'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Vendor Modal -->
<div class="modal fade" id="vendorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="vModalTitle">Add Vendor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="vFormAction" value="add_vendor">
                    <input type="hidden" name="id" id="vFormId" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" id="v_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" id="v_contact" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile</label>
                            <input type="text" name="mobile" id="v_mobile" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="v_email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">GST No</label>
                            <input type="text" name="gst_no" id="v_gst" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">PAN No</label>
                            <input type="text" name="pan_no" id="v_pan" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" id="v_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="v_status" class="form-select">
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

<!-- Transaction Modal -->
<div class="modal fade" id="transactionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Transaction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_transaction">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Type *</label>
                            <select name="type" class="form-select" required>
                                <option value="income">Income</option>
                                <option value="expense">Expense</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <input type="text" name="category" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount *</label>
                            <input type="number" step="0.01" name="amount" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date *</label>
                            <input type="date" name="entry_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method</label>
                            <input type="text" name="payment_method" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reference No</label>
                            <input type="text" name="reference_no" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Vendor</label>
                            <select name="vendor_id" class="form-select">
                                <option value="">Select</option>
                                <?php foreach ($vendors as $v): ?>
                                <option value="<?php echo $v['id']; ?>"><?php echo sanitize($v['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
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
function editVendor(v) {
    document.getElementById('vModalTitle').textContent = 'Edit Vendor';
    document.getElementById('vFormAction').value = 'edit_vendor';
    document.getElementById('vFormId').value = v.id;
    document.getElementById('v_name').value = v.name;
    document.getElementById('v_contact').value = v.contact_person || '';
    document.getElementById('v_mobile').value = v.mobile || '';
    document.getElementById('v_email').value = v.email || '';
    document.getElementById('v_gst').value = v.gst_no || '';
    document.getElementById('v_pan').value = v.pan_no || '';
    document.getElementById('v_address').value = v.address || '';
    document.getElementById('v_status').value = v.status;
    new bootstrap.Modal(document.getElementById('vendorModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
