<?php
require_once '../includes/config.php';
require_once '../../backend/helpers/payroll_helper.php';

if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('salary');

$month = filterMonth($_GET['month'] ?? '');
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$action = $_GET['action'] ?? '';
$deptFilter = $_GET['department_id'] ?? '';
$statusFilter = $_GET['payment_status'] ?? '';
$message = '';
$messageType = 'success';

// Handle POST actions: Pay Salary, Save/Generate Payroll, Lock/Finalize, Reopen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'pay_salary') {
        requireCreate('salary');
        $payEmpId = (int)($_POST['pay_employee_id'] ?? 0);
        $payAmount = (float)($_POST['pay_amount'] ?? 0);
        $payDate = filterDate($_POST['pay_date'] ?? date('Y-m-d'));
        $payMethod = $_POST['pay_method'] ?? 'bank_transfer';
        $payRef = sanitize($_POST['pay_reference'] ?? '');
        $payNotes = sanitize($_POST['pay_notes'] ?? '');
        $payPayrollId = !empty($_POST['pay_payroll_id']) ? (int)$_POST['pay_payroll_id'] : null;

        $res = recordSalaryPayment($pdo, $payEmpId, $payAmount, $payDate, $payMethod, $payRef, $payNotes, $_SESSION['admin_id'] ?? null, $payPayrollId);
        if ($res['success']) {
            setFlash($res['message'], 'success');
        } else {
            setFlash($res['message'], 'danger');
        }
        header("Location: salary?month=" . urlencode($month) . ($employee_id ? "&employee_id=$employee_id" : ""));
        exit;
    }

    if ($postAction === 'lock_payroll') {
        requireEdit('salary');
        $lockPayrollId = (int)($_POST['payroll_id'] ?? 0);
        if ($lockPayrollId > 0) {
            $pdo->prepare("UPDATE salary_processing SET lock_status = 'finalized', finalized_by = ?, finalized_at = NOW() WHERE id = ?")
                ->execute([$_SESSION['admin_id'] ?? null, $lockPayrollId]);
            logSalaryAudit($pdo, $lockPayrollId, 0, 'payroll_finalized', null, null, $_SESSION['admin_id'] ?? null);
            setFlash('Payroll record finalized and locked successfully.', 'success');
        }
        header("Location: salary?month=" . urlencode($month));
        exit;
    }

    if ($postAction === 'reopen_payroll') {
        requireEdit('salary');
        $reopenPayrollId = (int)($_POST['payroll_id'] ?? 0);
        $reason = sanitize($_POST['reopen_reason'] ?? 'Admin correction');
        if ($reopenPayrollId > 0) {
            $pdo->prepare("UPDATE salary_processing SET lock_status = 'generated', finalized_by = NULL, finalized_at = NULL WHERE id = ?")
                ->execute([$reopenPayrollId]);
            logSalaryAudit($pdo, $reopenPayrollId, 0, 'payroll_reopened', null, json_encode(['reason' => $reason]), $_SESSION['admin_id'] ?? null);
            setFlash('Payroll record unlocked and reopened for edits.', 'info');
        }
        header("Location: salary?month=" . urlencode($month));
        exit;
    }

    if ($postAction === 'generate_all_payroll') {
        requireCreate('salary');
        $allEmps = $pdo->query("SELECT id FROM employees WHERE status = 1")->fetchAll();
        $savedCount = 0;
        foreach ($allEmps as $ae) {
            $c = calculateEmployeeSalary($pdo, (int)$ae['id'], $month);
            if ($c['success'] && $c['is_employed_this_month']) {
                saveSalaryPayrollRecord($pdo, $c, $_SESSION['admin_id'] ?? null);
                $savedCount++;
            }
        }
        setFlash("Payroll generated and saved for $savedCount employees.", 'success');
        header("Location: salary?month=" . urlencode($month));
        exit;
    }
}

// Fetch departments for filter
$departments = $pdo->query("SELECT id, name FROM departments WHERE status = 1 ORDER BY name ASC")->fetchAll();

// Query employees
$empSql = "SELECT id FROM employees WHERE status = 1";
$empParams = [];
if ($deptFilter) {
    $empSql .= " AND department_id = ?";
    $empParams[] = (int)$deptFilter;
}
if ($employee_id > 0) {
    $empSql .= " AND id = ?";
    $empParams[] = $employee_id;
}
$empSql .= " ORDER BY first_name ASC";
$empStmt = $pdo->prepare($empSql);
$empStmt->execute($empParams);
$empList = $empStmt->fetchAll();

// Calculate salary records for all matching employees
$records = [];
$kpi = [
    'total_payroll' => 0.00,
    'total_paid'    => 0.00,
    'total_due'     => 0.00,
    'count_paid'    => 0,
    'count_partial' => 0,
    'count_unpaid'  => 0,
];

foreach ($empList as $el) {
    $calc = calculateEmployeeSalary($pdo, (int)$el['id'], $month);
    if (!$calc['success']) continue;

    // Apply status filter if active
    if ($statusFilter && $calc['payment_status'] !== $statusFilter) {
        continue;
    }

    // Auto-save/upsert draft or generated payroll in salary_processing so ledger & carry-forwards stay up-to-date
    if ($calc['is_employed_this_month'] && empty($calc['payroll_id'])) {
        $saveRes = saveSalaryPayrollRecord($pdo, $calc, $_SESSION['admin_id'] ?? null);
        if ($saveRes['success']) {
            $calc['payroll_id'] = $saveRes['payroll_id'];
        }
    }

    $records[] = $calc;

    // Aggregate KPI totals
    $kpi['total_payroll'] += $calc['current_net_salary'];
    $kpi['total_paid']    += $calc['paid_amount'];
    $kpi['total_due']     += $calc['remaining_due'];

    if ($calc['payment_status'] === 'paid') {
        $kpi['count_paid']++;
    } elseif ($calc['payment_status'] === 'partially_paid') {
        $kpi['count_partial']++;
    } else {
        $kpi['count_unpaid']++;
    }
}

$singleEmployee = ($employee_id > 0 && count($records) === 1) ? $records[0] : null;

require_once '../includes/header.php';
?>

<style>
.salary-kpi-card {
    border-radius: 10px;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: transform 0.2s;
}
.salary-kpi-card:hover {
    transform: translateY(-2px);
}
.status-badge-paid {
    background-color: #28a745;
    color: white;
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 12px;
    font-weight: 600;
}
.status-badge-partial {
    background-color: #fd7e14;
    color: white;
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 12px;
    font-weight: 600;
}
.status-badge-unpaid {
    background-color: #dc3545;
    color: white;
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 12px;
    font-weight: 600;
}
.status-badge-no-emp {
    background-color: #6c757d;
    color: white;
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 12px;
    font-weight: 600;
}
.breakdown-table th {
    background-color: #f1f5f9;
    font-size: 12px;
    text-transform: uppercase;
    color: #475569;
}
.breakdown-table td {
    font-size: 13px;
    vertical-align: middle;
}
.running-badge {
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 600;
}

/* Salary Slip Specific Print Styles */
.salary-slip-container {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 0 15px rgba(0,0,0,0.08);
    margin-top: 15px;
}
.salary-slip {
    max-width: 820px;
    margin: 0 auto;
    padding: 30px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: white;
}
.salary-slip .header {
    text-align: center;
    border-bottom: 2px solid #1E3A5F;
    padding-bottom: 12px;
    margin-bottom: 15px;
}
.salary-slip .header h2 {
    color: #1E3A5F;
    margin: 0;
    font-size: 22px;
    font-weight: 700;
}
.salary-slip .header p {
    margin: 4px 0 0;
    color: #64748b;
    font-size: 13px;
}
.salary-slip .employee-info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 24px;
    margin-bottom: 18px;
    padding: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
.salary-slip .employee-info .label {
    font-weight: 600;
    color: #64748b;
    font-size: 12px;
}
.salary-slip .employee-info .value {
    color: #1E3A5F;
    font-weight: 600;
    font-size: 13px;
}
.salary-slip table {
    width: 100%;
    border-collapse: collapse;
    margin: 12px 0;
}
.salary-slip table th {
    background: #1E3A5F;
    color: white;
    padding: 8px 12px;
    font-size: 12px;
    text-transform: uppercase;
}
.salary-slip table td {
    padding: 7px 12px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}
.salary-slip table .total-row {
    background: #0f172a;
    color: white;
    font-weight: bold;
}
.salary-slip table .total-row td {
    color: white;
}
.salary-slip .footer {
    text-align: center;
    margin-top: 25px;
    padding-top: 15px;
    border-top: 1px solid #e2e8f0;
    font-size: 11px;
    color: #94a3b8;
}

@media print {
    .no-print {
        display: none !important;
    }
    .salary-slip {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }
    .salary-slip-container {
        box-shadow: none !important;
        padding: 0 !important;
    }
    body {
        background: white !important;
    }
}
</style>

<div class="no-print">
    <!-- Top Action Bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h4 class="fw-bold m-0 text-primary">
            <i class="fas fa-money-bill-wave me-2"></i>
            <?php echo $employee_id > 0 ? 'Salary Slip Breakdown' : 'Payroll & Salary Ledger — ' . date('F Y', strtotime($month . '-01')); ?>
        </h4>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <form method="GET" class="d-flex align-items-center gap-2 m-0">
                <input type="month" name="month" value="<?php echo $month; ?>" class="form-control form-control-sm w-auto" onchange="this.form.submit()">
                
                <?php if (!$employee_id): ?>
                <select name="department_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo (string)$deptFilter === (string)$d['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($d['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <select name="payment_status" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="partially_paid" <?php echo $statusFilter === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                    <option value="unpaid" <?php echo $statusFilter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                </select>
                <?php endif; ?>
            </form>

            <?php if ($employee_id > 0): ?>
                <a href="salary.php?month=<?php echo $month; ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>All Employees
                </a>
            <?php else: ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Generate and save payroll snapshots for all eligible employees in <?php echo date('F Y', strtotime($month . '-01')); ?>?');">
                    <input type="hidden" name="post_action" value="generate_all_payroll">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-calculator me-1"></i>Generate Payroll
                    </button>
                </form>
            <?php endif; ?>

            <button class="btn btn-success btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- Flash message -->
    <?php displayFlash(); ?>

    <?php if (!$employee_id): ?>
    <!-- KPI Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2 col-sm-6">
            <div class="card salary-kpi-card bg-light">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold">Total Payroll (This Month)</span>
                    <h5 class="fw-bold text-dark mt-1 mb-0">₹<?php echo number_format($kpi['total_payroll'], 2); ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card salary-kpi-card bg-light border-start border-4 border-success">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold">Total Amount Paid</span>
                    <h5 class="fw-bold text-success mt-1 mb-0">₹<?php echo number_format($kpi['total_paid'], 2); ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card salary-kpi-card bg-light border-start border-4 border-danger">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold">Total Outstanding Due</span>
                    <h5 class="fw-bold text-danger mt-1 mb-0">₹<?php echo number_format($kpi['total_due'], 2); ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card salary-kpi-card text-center bg-light">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold">Fully Paid</span>
                    <h5 class="fw-bold text-success mt-1 mb-0"><?php echo $kpi['count_paid']; ?> <small class="text-muted fs-6">emps</small></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card salary-kpi-card text-center bg-light">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold">Partially Paid</span>
                    <h5 class="fw-bold text-warning mt-1 mb-0"><?php echo $kpi['count_partial']; ?> <small class="text-muted fs-6">emps</small></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card salary-kpi-card text-center bg-light">
                <div class="card-body p-3">
                    <span class="text-muted small fw-bold">Unpaid</span>
                    <h5 class="fw-bold text-danger mt-1 mb-0"><?php echo $kpi['count_unpaid']; ?> <small class="text-muted fs-6">emps</small></h5>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($employee_id > 0 && $singleEmployee): ?>
    <!-- Single Employee Salary Slip & Full Details View -->
    <?php renderSalarySlipView($singleEmployee, $pdo); ?>
<?php else: ?>
    <!-- All Employees Ledger Table -->
    <div class="card shadow-sm border-0 mb-4 no-print">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 breakdown-table datatable">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Code</th>
                            <th>Dept</th>
                            <th>Joining Date</th>
                            <th class="text-end">Monthly Salary</th>
                            <th class="text-center" title="Eligible Employment Days in Month">Eligible Days</th>
                            <th class="text-end">Current Month Salary</th>
                            <th class="text-end" title="Accumulated unpaid salary from previous months">Previous Due</th>
                            <th class="text-end fw-bold text-primary">Total Payable</th>
                            <th class="text-end text-success">Paid</th>
                            <th class="text-end text-danger">Due</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Lock</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): 
                            $emp = $r['employee'];
                            $isEmployed = $r['is_employed_this_month'];
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width:32px;height:32px;font-size:12px;font-weight:bold;">
                                        <?php echo strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'] ?? '', 0, 1)); ?>
                                    </div>
                                    <div>
                                        <span class="fw-bold text-dark"><?php echo sanitize($emp['first_name'] . ' ' . ($emp['last_name'] ?? '')); ?></span>
                                        <div class="small text-muted"><?php echo sanitize($emp['designation_name'] ?? ''); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo sanitize($emp['employee_code']); ?></span></td>
                            <td><?php echo sanitize($emp['department_name'] ?? '-'); ?></td>
                            <td>
                                <small class="text-muted"><?php echo $r['joining_date'] ? date('d M Y', strtotime($r['joining_date'])) : '-'; ?></small>
                                <?php if (!empty($r['relieving_date'])): ?>
                                    <div class="small text-danger">Exit: <?php echo date('d M Y', strtotime($r['relieving_date'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end font-monospace">₹<?php echo number_format($r['monthly_salary'], 2); ?></td>
                            <td class="text-center">
                                <?php if ($isEmployed): ?>
                                    <span class="badge <?php echo $r['is_prorated'] ? 'bg-warning text-dark' : 'bg-light text-dark border'; ?>">
                                        <?php echo $r['eligible_days']; ?> / <?php echo $r['total_days_in_month']; ?>d
                                    </span>
                                    <?php if ($r['is_prorated']): ?>
                                        <div class="small text-muted" style="font-size:10px;">Prorated from <?php echo date('d M', strtotime($r['effective_start_date'])); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Not Employed</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end font-monospace fw-bold">
                                <?php if ($isEmployed): ?>
                                    ₹<?php echo number_format($r['current_net_salary'], 2); ?>
                                <?php else: ?>
                                    <span class="text-muted">₹0.00</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end font-monospace text-danger">
                                <?php echo $r['previous_due'] > 0 ? '+₹' . number_format($r['previous_due'], 2) : '₹0.00'; ?>
                            </td>
                            <td class="text-end font-monospace fw-bold text-primary">
                                ₹<?php echo number_format($r['total_payable'], 2); ?>
                            </td>
                            <td class="text-end font-monospace text-success">
                                ₹<?php echo number_format($r['paid_amount'], 2); ?>
                            </td>
                            <td class="text-end font-monospace fw-bold text-danger">
                                ₹<?php echo number_format($r['remaining_due'], 2); ?>
                            </td>
                            <td class="text-center">
                                <?php if (!$isEmployed): ?>
                                    <span class="status-badge-no-emp">Not Joined</span>
                                <?php elseif ($r['payment_status'] === 'paid'): ?>
                                    <span class="status-badge-paid"><i class="fas fa-check-circle me-1"></i>Paid</span>
                                <?php elseif ($r['payment_status'] === 'partially_paid'): ?>
                                    <span class="status-badge-partial"><i class="fas fa-clock me-1"></i>Partial</span>
                                <?php else: ?>
                                    <span class="status-badge-unpaid"><i class="fas fa-exclamation-circle me-1"></i>Unpaid</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($r['lock_status'] === 'finalized'): ?>
                                    <span class="badge bg-success" title="Finalized & Locked"><i class="fas fa-lock"></i> Locked</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary" title="Open Draft"><i class="fas fa-unlock"></i> Open</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <!-- View Details / Breakdown Button -->
                                    <button type="button" class="btn btn-outline-info" title="View Breakdown" onclick="viewSalaryBreakdown(<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <!-- Pay Salary Button -->
                                    <?php if ($r['remaining_due'] > 0 || $r['payment_status'] !== 'paid'): ?>
                                    <button type="button" class="btn btn-outline-success" title="Pay Salary" onclick="openPaymentModal(<?php echo $emp['id']; ?>, '<?php echo sanitize($emp['first_name'] . ' ' . ($emp['last_name'] ?? '')); ?>', <?php echo $r['remaining_due']; ?>, <?php echo $r['payroll_id'] ?? 'null'; ?>)">
                                        <i class="fas fa-hand-holding-usd"></i> Pay
                                    </button>
                                    <?php endif; ?>

                                    <!-- Payment History Button -->
                                    <button type="button" class="btn btn-outline-secondary" title="Payment History" onclick="viewPaymentHistory(<?php echo $emp['id']; ?>, '<?php echo sanitize($emp['first_name'] . ' ' . ($emp['last_name'] ?? '')); ?>')">
                                        <i class="fas fa-history"></i>
                                    </button>

                                    <!-- Salary Slip Button -->
                                    <a href="?month=<?php echo $month; ?>&employee_id=<?php echo $emp['id']; ?>" class="btn btn-outline-primary" title="View Salary Slip">
                                        <i class="fas fa-file-invoice"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================= -->
<!-- Modal 1: Pay Salary Modal (Custom Amount, Partial, FIFO) -->
<!-- ============================================= -->
<div class="modal fade" id="paySalaryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="post_action" value="pay_salary">
                <input type="hidden" name="pay_employee_id" id="pay_emp_id">
                <input type="hidden" name="pay_payroll_id" id="pay_payroll_id">
                
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Record Salary Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2">
                        <div class="fw-bold" id="pay_emp_name">Employee</div>
                        <div class="small">Total Outstanding Due: <strong class="text-danger" id="pay_emp_due">₹0.00</strong></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment Amount (₹) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="pay_amount" id="pay_amount" class="form-control form-control-lg" required>
                        <div class="form-text">Enter full or partial payment amount. Oldest unpaid balances will be cleared first (FIFO).</div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Date</label>
                            <input type="date" name="pay_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Method</label>
                            <select name="pay_method" class="form-select" required>
                                <option value="bank_transfer">Bank Transfer (NEFT/RTGS/IMPS)</option>
                                <option value="upi">UPI (GPay / PhonePe / Paytm)</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Transaction / Reference Number</label>
                        <input type="text" name="pay_reference" class="form-control" placeholder="e.g. UTR / Cheque / Txn ID">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Notes / Remarks</label>
                        <textarea name="pay_notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check-circle me-1"></i>Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================= -->
<!-- Modal 2: Salary Breakdown Details Modal -->
<!-- ============================================= -->
<div class="modal fade" id="breakdownModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-calculator me-2"></i>Detailed Salary Breakdown</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="breakdownContent">
                <!-- Injected via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================= -->
<!-- Modal 3: Payment History Modal -->
<!-- ============================================= -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-history me-2"></i>Payment Transaction History</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="historyContent">
                <div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openPaymentModal(empId, empName, remainingDue, payrollId) {
    document.getElementById('pay_emp_id').value = empId;
    document.getElementById('pay_payroll_id').value = payrollId || '';
    document.getElementById('pay_emp_name').textContent = empName;
    document.getElementById('pay_emp_due').textContent = '₹' + Number(remainingDue).toLocaleString('en-IN', {minimumFractionDigits: 2});
    document.getElementById('pay_amount').value = remainingDue > 0 ? remainingDue : '';
    new bootstrap.Modal(document.getElementById('paySalaryModal')).show();
}

function viewSalaryBreakdown(r) {
    var emp = r.employee;
    var html = `
        <div class="card bg-light border-0 mb-3">
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-6"><strong>Employee:</strong> ${emp.first_name} ${emp.last_name || ''} (${emp.code})</div>
                    <div class="col-md-6"><strong>Department:</strong> ${emp.department || '-'} / ${emp.designation || '-'}</div>
                    <div class="col-md-6"><strong>Date of Joining:</strong> ${r.joining_date || 'Not set'}</div>
                    <div class="col-md-6"><strong>Monthly Fixed Salary:</strong> ₹${Number(r.monthly_salary).toLocaleString('en-IN', {minimumFractionDigits:2})}</div>
                </div>
            </div>
        </div>

        <h6 class="fw-bold text-primary border-bottom pb-1"><i class="fas fa-calendar-alt me-1"></i>Employment & Eligibility Window</h6>
        <table class="table table-sm table-bordered mb-3">
            <tr>
                <td style="width:35%"><strong>Payroll Month</strong></td>
                <td>${r.month_name} (${r.total_days_in_month} Days)</td>
            </tr>
            <tr>
                <td><strong>Eligible Period</strong></td>
                <td>${r.effective_start_date} to ${r.effective_end_date} 
                    <span class="badge ${r.is_prorated ? 'bg-warning text-dark' : 'bg-success'} ms-1">
                        ${r.eligible_days} / ${r.total_days_in_month} Eligible Days
                    </span>
                </td>
            </tr>
            <tr>
                <td><strong>Daily Rate</strong></td>
                <td>₹${Number(r.daily_rate).toFixed(2)} / day <span class="text-muted small">(Monthly Salary ÷ 30)</span></td>
            </tr>
            <tr>
                <td><strong>Base Earned Salary</strong></td>
                <td class="fw-bold text-primary">₹${Number(r.base_earned_salary).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
            </tr>
        </table>

        <h6 class="fw-bold text-primary border-bottom pb-1"><i class="fas fa-user-check me-1"></i>Attendance Counts (After Joining Date)</h6>
        <div class="row g-2 mb-3 text-center">
            <div class="col"><div class="p-2 border rounded bg-white"><div class="text-success fw-bold">${r.present_days}</div><small class="text-muted">Present</small></div></div>
            <div class="col"><div class="p-2 border rounded bg-white"><div class="text-info fw-bold">${r.paid_leave_days}</div><small class="text-muted">Paid Leave</small></div></div>
            <div class="col"><div class="p-2 border rounded bg-white"><div class="text-warning fw-bold">${r.half_days}</div><small class="text-muted">Half Days</small></div></div>
            <div class="col"><div class="p-2 border rounded bg-white"><div class="text-danger fw-bold">${r.absent_days + r.unpaid_leave_days}</div><small class="text-muted">Absent/LWP</small></div></div>
            <div class="col"><div class="p-2 border rounded bg-white"><div class="text-secondary fw-bold">${r.weekly_off_days + r.holiday_days}</div><small class="text-muted">WO / Hol</small></div></div>
        </div>

        <h6 class="fw-bold text-danger border-bottom pb-1"><i class="fas fa-minus-circle me-1"></i>Deductions</h6>
        <table class="table table-sm table-bordered mb-3">
            <tr>
                <td>Absent / Unpaid Leave Deduction</td>
                <td class="text-end text-danger">- ₹${Number(r.absent_deduction).toFixed(2)}</td>
            </tr>
            <tr>
                <td>Half-Day Deduction (50%)</td>
                <td class="text-end text-danger">- ₹${Number(r.half_day_deduction).toFixed(2)}</td>
            </tr>
            <tr>
                <td>Other / Ad-hoc Deductions</td>
                <td class="text-end text-danger">- ₹${Number(r.other_deductions).toFixed(2)}</td>
            </tr>
            <tr class="table-danger fw-bold">
                <td>Total Deductions</td>
                <td class="text-end text-danger">- ₹${Number(r.total_deductions).toFixed(2)}</td>
            </tr>
        </table>

        <h6 class="fw-bold text-success border-bottom pb-1"><i class="fas fa-wallet me-1"></i>Ledger & Carry Forward Summary</h6>
        <table class="table table-sm table-bordered mb-0">
            <tr>
                <td><strong>Current Month Net Salary (Earned)</strong></td>
                <td class="text-end fw-bold">₹${Number(r.current_net_salary).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
            </tr>
            <tr>
                <td><strong>Previous Unpaid Outstanding (Carry Forward)</strong></td>
                <td class="text-end text-danger fw-bold">+ ₹${Number(r.previous_due).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
            </tr>
            <tr class="table-primary fw-bold">
                <td><strong>Total Payable Amount</strong></td>
                <td class="text-end text-primary fs-6">₹${Number(r.total_payable).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
            </tr>
            <tr>
                <td><strong>Amount Already Paid</strong></td>
                <td class="text-end text-success fw-bold">₹${Number(r.paid_amount).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
            </tr>
            <tr class="table-warning fw-bold">
                <td><strong>Remaining Balance Due</strong></td>
                <td class="text-end text-danger fs-6">₹${Number(r.remaining_due).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
            </tr>
        </table>
    `;

    document.getElementById('breakdownContent').innerHTML = html;
    new bootstrap.Modal(document.getElementById('breakdownModal')).show();
}

function viewPaymentHistory(empId, empName) {
    document.getElementById('historyContent').innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    new bootstrap.Modal(document.getElementById('historyModal')).show();

    fetch('../../backend/index.php?url=salary/payments/' + empId)
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.data || data.data.length === 0) {
                document.getElementById('historyContent').innerHTML = `
                    <div class="alert alert-info text-center my-3">
                        <i class="fas fa-info-circle me-1"></i>No payment transactions recorded yet for <strong>${empName}</strong>.
                    </div>`;
                return;
            }

            var rows = '';
            var total = 0;
            data.data.forEach(function(p, i) {
                total += Number(p.payment_amount);
                rows += `
                    <tr>
                        <td>${i + 1}</td>
                        <td>${p.payment_date}</td>
                        <td class="text-end fw-bold text-success font-monospace">₹${Number(p.payment_amount).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
                        <td><span class="badge bg-light text-dark border">${p.payment_method}</span></td>
                        <td>${p.reference_no || '-'}</td>
                        <td>${p.notes || '-'}</td>
                        <td><small class="text-muted">${p.created_by_name || 'Admin'}</small></td>
                    </tr>
                `;
            });

            var html = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold m-0 text-dark">Payments for: ${empName}</h6>
                    <span class="badge bg-success fs-6">Total Paid: ₹${total.toLocaleString('en-IN', {minimumFractionDigits:2})}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th class="text-end">Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Notes</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            `;
            document.getElementById('historyContent').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('historyContent').innerHTML = `<div class="alert alert-danger">Error loading payments: ${err}</div>`;
        });
}
</script>

<?php require_once '../includes/footer.php'; ?>

<?php
// =============================================
// Salary Slip Renderer Function (Updated with Joining Prorating & Ledger)
// =============================================
function renderSalarySlipView($r, $pdo) {
    $emp = $r['employee'];
    ?>
    <div class="salary-slip-container">
        <div class="salary-slip" id="salarySlip">
            <div class="header">
                <h2>YATHARTH INSTITUTION</h2>
                <p class="fw-bold text-secondary">Salary Slip &mdash; <?php echo $r['month_name']; ?></p>
            </div>
            
            <div class="employee-info">
                <div><span class="label">Employee Name:</span> <span class="value"><?php echo sanitize($emp['first_name'] . ' ' . ($emp['last_name'] ?? '')); ?></span></div>
                <div><span class="label">Employee Code:</span> <span class="value"><?php echo sanitize($emp['employee_code']); ?></span></div>
                <div><span class="label">Department:</span> <span class="value"><?php echo sanitize($emp['department_name'] ?? '-'); ?></span></div>
                <div><span class="label">Designation:</span> <span class="value"><?php echo sanitize($emp['designation_name'] ?? '-'); ?></span></div>
                <div><span class="label">Date of Joining:</span> <span class="value text-primary"><?php echo $r['joining_date'] ? date('d-M-Y', strtotime($r['joining_date'])) : '-'; ?></span></div>
                <div><span class="label">Payroll Month:</span> <span class="value"><?php echo $r['month_name']; ?> (<?php echo $r['total_days_in_month']; ?> Days)</span></div>
                <div><span class="label">Eligible Period:</span> <span class="value"><?php echo date('d M', strtotime($r['effective_start_date'])); ?> to <?php echo date('d M Y', strtotime($r['effective_end_date'])); ?></span></div>
                <div><span class="label">Eligible Days:</span> <span class="value badge <?php echo $r['is_prorated'] ? 'bg-warning text-dark' : 'bg-success'; ?>"><?php echo $r['eligible_days']; ?> Days</span></div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width:50%">Description</th>
                        <th style="width:20%;text-align:center">Days / Calculation</th>
                        <th style="width:30%;text-align:right">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Monthly Fixed Salary</strong></td>
                        <td style="text-align:center">30 Days Standard</td>
                        <td style="text-align:right;font-weight:600;">₹ <?php echo number_format($r['monthly_salary'], 2); ?></td>
                    </tr>
                    
                    <?php if ($r['is_prorated']): ?>
                    <tr style="background:#fffbeb;">
                        <td>
                            <strong>📅 Base Prorated Salary</strong>
                            <div style="font-size:11px;color:#666;">Joined on <?php echo date('d-M-Y', strtotime($r['joining_date'])); ?> (<?php echo $r['eligible_days']; ?> days eligible)</div>
                        </td>
                        <td style="text-align:center"><?php echo $r['eligible_days']; ?>d × ₹<?php echo number_format($r['daily_rate'], 2); ?></td>
                        <td style="text-align:right;font-weight:bold;color:#0369a1;">₹ <?php echo number_format($r['base_earned_salary'], 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <!-- Attendance Breakdown Section -->
                    <tr style="background:#f1f5f9;">
                        <td colspan="3" style="font-weight:bold;color:#1e293b;font-size:12px;">ATTENDANCE BREAKDOWN (ELIGIBLE PERIOD)</td>
                    </tr>
                    <tr>
                        <td>✅ Present Days (Full + Half)</td>
                        <td style="text-align:center"><?php echo $r['present_days']; ?> Days</td>
                        <td style="text-align:right;color:#16a34a;">+ <?php echo $r['present_days']; ?>d</td>
                    </tr>
                    <tr>
                        <td>📅 Paid Leaves / Weekly Off / Holidays</td>
                        <td style="text-align:center"><?php echo $r['paid_leave_days'] + $r['weekly_off_days'] + $r['holiday_days']; ?> Days</td>
                        <td style="text-align:right;color:#16a34a;">+ <?php echo $r['paid_leave_days'] + $r['weekly_off_days'] + $r['holiday_days']; ?>d</td>
                    </tr>

                    <!-- Deductions Section -->
                    <tr style="background:#fee2e2;">
                        <td colspan="3" style="font-weight:bold;color:#991b1b;font-size:12px;">DEDUCTIONS</td>
                    </tr>
                    <tr>
                        <td style="padding-left:16px;">❌ Absent / Unpaid Leave</td>
                        <td style="text-align:center"><?php echo $r['absent_days'] + $r['unpaid_leave_days']; ?>d × ₹<?php echo number_format($r['daily_rate'], 2); ?></td>
                        <td style="text-align:right;color:#dc2626;">- ₹ <?php echo number_format($r['absent_deduction'], 2); ?></td>
                    </tr>
                    <?php if ($r['half_day_deduction'] > 0): ?>
                    <tr>
                        <td style="padding-left:16px;">⚠️ Half Day Deductions</td>
                        <td style="text-align:center"><?php echo $r['half_days']; ?>d × 50%</td>
                        <td style="text-align:right;color:#ea580c;">- ₹ <?php echo number_format($r['half_day_deduction'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($r['other_deductions'] > 0): ?>
                    <tr>
                        <td style="padding-left:16px;">📌 Other Deductions</td>
                        <td style="text-align:center">-</td>
                        <td style="text-align:right;color:#dc2626;">- ₹ <?php echo number_format($r['other_deductions'], 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <!-- Current Month Earned Net -->
                    <tr style="background:#e0f2fe;font-weight:bold;">
                        <td>CURRENT MONTH NET SALARY (EARNED)</td>
                        <td style="text-align:center"><?php echo $r['month_name']; ?></td>
                        <td style="text-align:right;color:#0369a1;font-size:15px;">₹ <?php echo number_format($r['current_net_salary'], 2); ?></td>
                    </tr>

                    <!-- Outstanding Carry Forward & Total Payable -->
                    <?php if ($r['previous_due'] > 0): ?>
                    <tr style="background:#fef2f2;">
                        <td><strong>⚠️ Previous Months Outstanding Due (Carry Forward)</strong></td>
                        <td style="text-align:center;color:#dc2626;">Prior Unpaid</td>
                        <td style="text-align:right;font-weight:bold;color:#dc2626;">+ ₹ <?php echo number_format($r['previous_due'], 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <tr class="total-row">
                        <td><strong>TOTAL PAYABLE AMOUNT</strong></td>
                        <td style="text-align:center">Net + Prior Dues</td>
                        <td style="text-align:right;font-size:17px;">₹ <?php echo number_format($r['total_payable'], 2); ?></td>
                    </tr>

                    <tr>
                        <td><strong>Amount Paid</strong></td>
                        <td style="text-align:center"><?php echo $r['payment_status']; ?></td>
                        <td style="text-align:right;font-weight:bold;color:#16a34a;">₹ <?php echo number_format($r['paid_amount'], 2); ?></td>
                    </tr>
                    <tr style="background:#f8fafc;font-weight:bold;">
                        <td><strong>REMAINING BALANCE DUE</strong></td>
                        <td style="text-align:center">-</td>
                        <td style="text-align:right;font-size:15px;color:#dc2626;">₹ <?php echo number_format($r['remaining_due'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <div style="margin-top:15px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;display:grid;grid-template-columns:1fr 1fr;gap:5px;font-size:12px;">
                <div><strong>Amount in Words:</strong> <?php echo numberToWords($r['total_payable']); ?></div>
                <div style="text-align:right;"><strong>Generated On:</strong> <?php echo date('d-M-Y'); ?></div>
            </div>
            
            <div style="margin-top:25px;display:flex;justify-content:space-between;padding-top:15px;border-top:1px solid #cbd5e1;">
                <div>
                    <p style="margin:0;font-size:12px;color:#64748b;">Employee Signature</p>
                    <div style="height:35px;"></div>
                    <p style="margin:0;font-size:11px;color:#94a3b8;">_____________________</p>
                </div>
                <div style="text-align:right;">
                    <p style="margin:0;font-size:12px;color:#64748b;">Authorized Signatory</p>
                    <div style="height:35px;"></div>
                    <p style="margin:0;font-size:11px;color:#94a3b8;">_____________________</p>
                </div>
            </div>
            
            <div class="footer">
                <p style="margin:0 0 4px;">This is a computer generated salary slip. Yatharth Institution EMS.</p>
                <p style="margin:0;">© <?php echo date('Y'); ?> Yatharth Institution &mdash; All Rights Reserved</p>
            </div>
        </div>
    </div>
    <?php
}

// Number to Words Function
function numberToWords($number) {
    $number = round((float)$number, 2);
    $amount = explode('.', number_format($number, 2, '.', ''));
    $words = convertNumberToWords((int)$amount[0]);
    $paise = isset($amount[1]) ? $amount[1] : '00';
    return $words . ' Rupees and ' . $paise . '/100 Only';
}

function convertNumberToWords($number) {
    $words = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen',
        17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
        60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
    );
    
    if ($number == 0) return 'Zero';
    
    $result = '';
    $crore = floor($number / 10000000);
    $number = $number % 10000000;
    $lakh = floor($number / 100000);
    $number = $number % 100000;
    $thousand = floor($number / 1000);
    $number = $number % 1000;
    $hundred = floor($number / 100);
    $number = $number % 100;
    
    if ($crore > 0) {
        $result .= ($crore < 20) ? $words[$crore] : $words[floor($crore / 10) * 10] . ' ' . $words[$crore % 10];
        $result .= ' Crore ';
    }
    if ($lakh > 0) {
        $result .= ($lakh < 20) ? $words[$lakh] : $words[floor($lakh / 10) * 10] . ' ' . $words[$lakh % 10];
        $result .= ' Lakh ';
    }
    if ($thousand > 0) {
        $result .= ($thousand < 20) ? $words[$thousand] : $words[floor($thousand / 10) * 10] . ' ' . $words[$thousand % 10];
        $result .= ' Thousand ';
    }
    if ($hundred > 0) {
        $result .= $words[$hundred] . ' Hundred ';
    }
    if ($number > 0) {
        if ($hundred > 0) $result .= 'and ';
        $result .= ($number < 20) ? $words[$number] : $words[floor($number / 10) * 10] . ' ' . $words[$number % 10];
    }
    
    return trim($result);
}
?>