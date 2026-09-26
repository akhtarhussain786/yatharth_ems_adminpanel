<?php
require_once '../includes/config.php';
require_once '../../backend/helpers/payroll_helper.php';

if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('salary');

$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$deptFilter = $_GET['department_id'] ?? '';
$statusFilter = $_GET['payment_status'] ?? '';
$yearFilter = $_GET['year'] ?? '';

$message = '';
$messageType = 'success';

// Handle Record Payment POST action
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
        header("Location: salary_ledger?employee_id=" . $payEmpId . ($deptFilter ? "&department_id=$deptFilter" : ""));
        exit;
    }
}

// Fetch all active departments for filter
$departments = $pdo->query("SELECT id, name FROM departments WHERE status = 1 ORDER BY name ASC")->fetchAll();

// Fetch all active employees for selection dropdown
$allEmployees = $pdo->query("
    SELECT e.id, e.first_name, e.last_name, e.employee_code, e.salary, e.joining_date, d.name as department_name, des.name as designation_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN designations des ON des.id = e.designation_id
    WHERE e.status = 1
    ORDER BY e.first_name ASC
")->fetchAll();

// If single employee selected, load their full month-by-month ledger
$selectedEmployee = null;
$employeeLedger = [];
$employeePayments = [];
$ledgerSummary = [
    'total_earned' => 0.00,
    'total_paid'   => 0.00,
    'total_due'    => 0.00,
    'total_months' => 0,
    'paid_months'  => 0,
    'unpaid_months'=> 0,
];

if ($employee_id > 0) {
    $empStmt = $pdo->prepare("
        SELECT e.*, d.name as department_name, des.name as designation_name
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN designations des ON des.id = e.designation_id
        WHERE e.id = ?
    ");
    $empStmt->execute([$employee_id]);
    $selectedEmployee = $empStmt->fetch();

    if ($selectedEmployee) {
        $joiningDate = !empty($selectedEmployee['joining_date']) ? $selectedEmployee['joining_date'] : date('Y-m-01', strtotime('-3 months'));
        $startMonth = date('Y-m', strtotime($joiningDate));
        $currentMonth = date('Y-m');

        // Generate months from joining to current
        $monthsList = [];
        $cursor = strtotime($startMonth . '-01');
        $endCursor = strtotime($currentMonth . '-01');

        while ($cursor <= $endCursor) {
            $m = date('Y-m', $cursor);
            if (!$yearFilter || strpos($m, $yearFilter) === 0) {
                $monthsList[] = $m;
            }
            $cursor = strtotime('+1 month', $cursor);
        }

        // Fetch existing salary processing records
        $procStmt = $pdo->prepare("SELECT * FROM salary_processing WHERE employee_id = ? ORDER BY month_year DESC");
        $procStmt->execute([$employee_id]);
        $procRecords = [];
        foreach ($procStmt->fetchAll() as $pr) {
            $procRecords[$pr['month_year']] = $pr;
        }

        // Fetch all payments for this employee
        try {
            $payStmt = $pdo->prepare("
                SELECT sp.*, 
                       u.username as created_by_name,
                       spr.month_year as payroll_month_year
                FROM salary_payments sp
                LEFT JOIN users u ON u.id = sp.created_by
                LEFT JOIN salary_processing spr ON spr.id = sp.payroll_id
                WHERE sp.employee_id = ?
                ORDER BY sp.payment_date DESC, sp.id DESC
            ");
            $payStmt->execute([$employee_id]);
            $employeePayments = $payStmt->fetchAll();
            foreach ($employeePayments as &$epRef) {
                $epRef['payment_amount'] = (float)($epRef['payment_amount'] ?? $epRef['amount'] ?? 0);
                $epRef['amount'] = $epRef['payment_amount'];
            }
            unset($epRef);
        } catch (Throwable $pe) {
            $employeePayments = [];
        }

        // Process months reverse chronologically
        for ($i = count($monthsList) - 1; $i >= 0; $i--) {
            $m = $monthsList[$i];
            $mName = date('F Y', strtotime($m . '-01'));

            try {
                if (isset($procRecords[$m])) {
                    $pr = $procRecords[$m];
                    $netSal = (float)($pr['net_salary'] ?? $pr['current_net_salary'] ?? $pr['base_earned_salary'] ?? 0);
                    $paidAmt = (float)($pr['paid_amount'] ?? 0);
                    $status = strtolower($pr['payment_status'] ?? ($paidAmt >= $netSal ? 'paid' : ($paidAmt > 0 ? 'partially_paid' : 'unpaid')));
                    $remDue = (float)($pr['remaining_due'] ?? max(0, $netSal - $paidAmt));
                    $prevDue = (float)($pr['previous_due'] ?? 0);
                    $totPayable = (float)($pr['total_payable'] ?? ($netSal + $prevDue));
                    $payrollId = (int)$pr['id'];
                    $calcObj = $pr;
                } else {
                    $calc = calculateEmployeeSalary($pdo, $employee_id, $m);
                    $netSal = (float)($calc['current_net_salary'] ?? $calc['monthly_salary'] ?? 0);
                    $paidAmt = (float)($calc['paid_amount'] ?? 0);
                    $status = strtolower($calc['payment_status'] ?? ($paidAmt >= $netSal ? 'paid' : ($paidAmt > 0 ? 'partially_paid' : 'unpaid')));
                    $remDue = (float)($calc['remaining_due'] ?? max(0, $netSal - $paidAmt));
                    $prevDue = (float)($calc['previous_due'] ?? 0);
                    $totPayable = (float)($calc['total_payable'] ?? ($netSal + $prevDue));
                    $payrollId = (int)($calc['payroll_id'] ?? 0);
                    $calcObj = $calc;
                }
            } catch (Throwable $ce) {
                $netSal = (float)($selectedEmployee['salary'] ?? 0);
                $paidAmt = 0.0;
                $status = 'unpaid';
                $remDue = $netSal;
                $prevDue = 0.0;
                $totPayable = $netSal;
                $payrollId = 0;
                $calcObj = [];
            }

            // Filter payments for this month
            $mPayments = array_values(array_filter($employeePayments, function($p) use ($payrollId, $m) {
                if (!empty($p['payroll_id']) && (int)$p['payroll_id'] > 0) {
                    return ($payrollId > 0 && (int)$p['payroll_id'] === $payrollId);
                }
                if (!empty($p['payment_date']) && date('Y-m', strtotime($p['payment_date'])) === $m) {
                    return true;
                }
                return false;
            }));

            // Filter status if filter applied
            if ($statusFilter && $status !== $statusFilter) {
                continue;
            }

            $ledgerSummary['total_earned'] += $netSal;
            $ledgerSummary['total_paid'] += $paidAmt;
            $ledgerSummary['total_due'] += $remDue;
            $ledgerSummary['total_months']++;

            if ($status === 'paid' || ($paidAmt >= $netSal && $netSal > 0)) {
                $ledgerSummary['paid_months']++;
            } else {
                $ledgerSummary['unpaid_months']++;
            }

            $employeeLedger[] = [
                'month_year'     => $m,
                'month_name'     => $mName,
                'payroll_id'     => $payrollId,
                'net_salary'     => $netSal,
                'previous_due'   => $prevDue,
                'total_payable'  => $totPayable,
                'paid_amount'    => $paidAmt,
                'remaining_due'  => $remDue,
                'payment_status' => $status,
                'is_current'     => ($m === $currentMonth),
                'payments'       => $mPayments,
                'raw_data'       => $calcObj,
            ];
        }
    }
} else {
    // All Employees Summary Overview
    $masterLedger = [];
    $grandTotals = [
        'total_payroll' => 0.00,
        'total_paid'    => 0.00,
        'total_due'     => 0.00,
    ];

    foreach ($allEmployees as $emp) {
        if ($deptFilter && (int)$emp['department_id'] !== (int)$deptFilter) {
            continue;
        }

        $eId = (int)$emp['id'];
        $jDate = !empty($emp['joining_date']) ? $emp['joining_date'] : date('Y-m-01', strtotime('-3 months'));
        $sMonth = date('Y-m', strtotime($jDate));
        $cMonth = date('Y-m');

        $eEarned = 0.0;
        $ePaid = 0.0;
        $eDue = 0.0;
        $mCount = 0;
        $paidCount = 0;
        $unpaidCount = 0;

        try {
            // Fetch all processing records
            $stmtP = $pdo->prepare("SELECT * FROM salary_processing WHERE employee_id = ?");
            $stmtP->execute([$eId]);
            $rows = $stmtP->fetchAll();

            foreach ($rows as $r) {
                $n = (float)($r['net_salary'] ?? $r['current_net_salary'] ?? $r['base_earned_salary'] ?? 0);
                $p = (float)($r['paid_amount'] ?? 0);
                $d = (float)($r['remaining_due'] ?? max(0, $n - $p));
                $st = strtolower($r['payment_status'] ?? ($p >= $n ? 'paid' : 'unpaid'));

                $eEarned += $n;
                $ePaid += $p;
                $eDue += $d;
                $mCount++;

                if ($st === 'paid' || ($p >= $n && $n > 0)) {
                    $paidCount++;
                } else {
                    $unpaidCount++;
                }
            }
        } catch (Throwable $e) {}

        $grandTotals['total_payroll'] += $eEarned;
        $grandTotals['total_paid']    += $ePaid;
        $grandTotals['total_due']     += $eDue;

        $masterLedger[] = [
            'employee'     => $emp,
            'total_earned' => $eEarned,
            'total_paid'   => $ePaid,
            'total_due'    => $eDue,
            'total_months' => $mCount,
            'paid_months'  => $paidCount,
            'unpaid_months'=> $unpaidCount,
        ];
    }
}

require_once '../includes/header.php';
?>

<style>
.ledger-header-card {
    background: linear-gradient(135deg, #1E3A5F 0%, #2A5298 100%);
    color: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 4px 15px rgba(30, 58, 95, 0.15);
}
.stat-widget {
    background: white;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    border: 1px solid #e2e8f0;
    transition: transform 0.2s;
}
.stat-widget:hover {
    transform: translateY(-2px);
}
.stat-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
}
.stat-value {
    font-size: 20px;
    font-weight: 800;
    margin-top: 4px;
    margin-bottom: 0;
}
.badge-paid {
    background-color: #10b981;
    color: white;
    font-size: 11px;
    font-weight: 700;
    padding: 5px 10px;
    border-radius: 6px;
}
.badge-unpaid {
    background-color: #ef4444;
    color: white;
    font-size: 11px;
    font-weight: 700;
    padding: 5px 10px;
    border-radius: 6px;
}
.badge-partial {
    background-color: #f59e0b;
    color: white;
    font-size: 11px;
    font-weight: 700;
    padding: 5px 10px;
    border-radius: 6px;
}
.ledger-table th {
    background-color: #f8fafc;
    color: #334155;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}
.ledger-table td {
    vertical-align: middle;
    font-size: 13px;
}
.payment-detail-box {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 6px;
    padding: 6px 10px;
    font-size: 11.5px;
    color: #166534;
}
@media print {
    .no-print, .main-sidebar, .main-header, .btn, .breadcrumb, form {
        display: none !important;
    }
    .content-wrapper {
        margin: 0 !important;
        padding: 0 !important;
    }
    .ledger-header-card {
        background: #1E3A5F !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<div class="container-fluid py-3">
    <!-- Breadcrumb & Top Bar -->
    <div class="row mb-3 align-items-center no-print">
        <div class="col-md-6">
            <h4 class="m-0 font-weight-bold text-dark">
                <i class="fas fa-book-open text-primary mr-2"></i> Employee Salary Ledger
            </h4>
            <small class="text-muted">Month-by-month comprehensive salary, dues, payments & balance statement</small>
        </div>
        <div class="col-md-6 text-md-right mt-2 mt-md-0">
            <a href="salary" class="btn btn-outline-secondary btn-sm mr-1">
                <i class="fas fa-list-alt mr-1"></i> Monthly Salary Sheet
            </a>
            <button onclick="window.print()" class="btn btn-primary btn-sm mr-1">
                <i class="fas fa-print mr-1"></i> Print Statement
            </button>
            <?php if ($selectedEmployee): ?>
            <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#recordPaymentModal">
                <i class="fas fa-hand-holding-usd mr-1"></i> Record Payment
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card shadow-sm border-0 mb-4 no-print">
        <div class="card-body p-3">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
                    <label class="small font-weight-bold text-muted mb-1">Select Employee:</label>
                    <select name="employee_id" class="form-control form-control-sm select2" onchange="this.form.submit()">
                        <option value="">-- All Employees Master Overview --</option>
                        <?php foreach ($allEmployees as $ae): ?>
                            <option value="<?= $ae['id'] ?>" <?= $employee_id === (int)$ae['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ae['first_name'] . ' ' . ($ae['last_name'] ?? '')) ?> (<?= htmlspecialchars($ae['employee_code'] ?? 'EMP') ?>) - <?= htmlspecialchars($ae['department_name'] ?? 'General') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                    <label class="small font-weight-bold text-muted mb-1">Payment Status:</label>
                    <select name="payment_status" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="">All Statuses (Paid & Dues)</option>
                        <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>🟢 Paid Only</option>
                        <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>🔴 Unpaid / Baki Only</option>
                        <option value="partially_paid" <?= $statusFilter === 'partially_paid' ? 'selected' : '' ?>>🟡 Partially Paid</option>
                    </select>
                </div>

                <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                    <label class="small font-weight-bold text-muted mb-1">Year Filter:</label>
                    <select name="year" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="">All Years</option>
                        <option value="2026" <?= $yearFilter === '2026' ? 'selected' : '' ?>>2026</option>
                        <option value="2025" <?= $yearFilter === '2025' ? 'selected' : '' ?>>2025</option>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6 text-right">
                    <a href="salary_ledger" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="fas fa-undo mr-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedEmployee): ?>
        <!-- ========================================== -->
        <!-- SINGLE EMPLOYEE DETAILED LEDGER VIEW      -->
        <!-- ========================================== -->

        <!-- Employee Profile Header Card -->
        <div class="ledger-header-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center font-weight-bold mr-3 shadow-sm" style="width: 54px; height: 54px; font-size: 22px;">
                            <?= strtoupper(substr($selectedEmployee['first_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <h4 class="mb-1 font-weight-bold text-white">
                                <?= htmlspecialchars($selectedEmployee['first_name'] . ' ' . ($selectedEmployee['last_name'] ?? '')) ?>
                                <span class="badge badge-light text-primary ml-2 font-weight-bold" style="font-size: 12px;">
                                    <?= htmlspecialchars($selectedEmployee['employee_code'] ?? 'YGI001') ?>
                                </span>
                            </h4>
                            <p class="mb-0 text-white-50" style="font-size: 13px;">
                                <span><i class="fas fa-building mr-1"></i> <?= htmlspecialchars($selectedEmployee['department_name'] ?? 'N/A') ?></span>
                                <span class="mx-2">•</span>
                                <span><i class="fas fa-user-tag mr-1"></i> <?= htmlspecialchars($selectedEmployee['designation_name'] ?? 'Staff') ?></span>
                                <span class="mx-2">•</span>
                                <span><i class="fas fa-calendar-check mr-1"></i> Joined: <?= !empty($selectedEmployee['joining_date']) ? date('d M Y', strtotime($selectedEmployee['joining_date'])) : 'N/A' ?></span>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-right mt-3 mt-md-0">
                    <div class="d-inline-block bg-white text-dark rounded px-3 py-2 text-left shadow-sm">
                        <div class="small text-muted font-weight-bold text-uppercase">Monthly Base Salary</div>
                        <div class="h5 mb-0 font-weight-bold text-primary">
                            ₹ <?= number_format((float)$selectedEmployee['salary'], 2) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-widget">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Total Earned (All Months)</div>
                            <div class="stat-value text-dark">₹ <?= number_format($ledgerSummary['total_earned'], 2) ?></div>
                        </div>
                        <div class="text-primary opacity-50"><i class="fas fa-coins fa-2x"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-widget">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label text-success">Total Received (Paid)</div>
                            <div class="stat-value text-success">₹ <?= number_format($ledgerSummary['total_paid'], 2) ?></div>
                            <small class="text-muted"><?= $ledgerSummary['paid_months'] ?> Month(s) Paid</small>
                        </div>
                        <div class="text-success opacity-50"><i class="fas fa-check-circle fa-2x"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-widget">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label text-danger">Outstanding Dues (Baki)</div>
                            <div class="stat-value text-danger">₹ <?= number_format($ledgerSummary['total_due'], 2) ?></div>
                            <small class="text-muted"><?= $ledgerSummary['unpaid_months'] ?> Month(s) Pending</small>
                        </div>
                        <div class="text-danger opacity-50"><i class="fas fa-exclamation-triangle fa-2x"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-widget">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label text-info">Total Working Months</div>
                            <div class="stat-value text-info"><?= $ledgerSummary['total_months'] ?></div>
                            <small class="text-muted">Since Joining Date</small>
                        </div>
                        <div class="text-info opacity-50"><i class="fas fa-calendar-alt fa-2x"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Month-by-Month Statement Table -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-dark">
                    <i class="fas fa-table text-primary mr-2"></i> Month-by-Month Salary Ledger & Statement
                </h6>
                <span class="badge badge-primary px-2 py-1 font-weight-bold">
                    <?= count($employeeLedger) ?> Month Records
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover ledger-table mb-0">
                    <thead>
                        <tr>
                            <th>Month / Period</th>
                            <th class="text-right">Base Salary</th>
                            <th class="text-right">Net Salary</th>
                            <th class="text-right">Prev Due</th>
                            <th class="text-right">Total Payable</th>
                            <th class="text-right text-success">Paid Amount</th>
                            <th class="text-right text-danger">Balance Due (Baki)</th>
                            <th class="text-center">Status</th>
                            <th>Payment Details</th>
                            <th class="text-center no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employeeLedger)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">
                                    No salary records found for the selected filter.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($employeeLedger as $row): 
                                $isPaid = ($row['payment_status'] === 'paid' || ($row['paid_amount'] >= $row['net_salary'] && $row['net_salary'] > 0));
                                $isPartial = ($row['payment_status'] === 'partially_paid' || ($row['paid_amount'] > 0 && $row['remaining_due'] > 0));
                            ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-bold text-dark">
                                            <?= htmlspecialchars($row['month_name']) ?>
                                            <?php if ($row['is_current']): ?>
                                                <span class="badge badge-info ml-1" style="font-size: 10px;">Current</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?= $row['month_year'] ?></small>
                                    </td>
                                    <td class="text-right">
                                        ₹ <?= number_format((float)$selectedEmployee['salary'], 2) ?>
                                    </td>
                                    <td class="text-right font-weight-bold">
                                        ₹ <?= number_format($row['net_salary'], 2) ?>
                                    </td>
                                    <td class="text-right text-muted">
                                        ₹ <?= number_format($row['previous_due'], 2) ?>
                                    </td>
                                    <td class="text-right font-weight-bold text-primary">
                                        ₹ <?= number_format($row['total_payable'], 2) ?>
                                    </td>
                                    <td class="text-right font-weight-bold text-success">
                                        ₹ <?= number_format($row['paid_amount'], 2) ?>
                                    </td>
                                    <td class="text-right font-weight-bold <?= $row['remaining_due'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                        ₹ <?= number_format($row['remaining_due'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($isPaid): ?>
                                            <span class="badge-paid"><i class="fas fa-check-circle mr-1"></i> PAID</span>
                                        <?php elseif ($isPartial): ?>
                                            <span class="badge-partial"><i class="fas fa-clock mr-1"></i> PARTIAL</span>
                                        <?php else: ?>
                                            <span class="badge-unpaid"><i class="fas fa-times-circle mr-1"></i> UNPAID</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['payments'])): ?>
                                            <?php foreach ($row['payments'] as $p): ?>
                                                <div class="payment-detail-box mb-1">
                                                    <strong>₹ <?= number_format((float)($p['payment_amount'] ?? $p['amount']), 2) ?></strong>
                                                    on <?= date('d M Y', strtotime($p['payment_date'])) ?>
                                                    via <span class="text-uppercase"><?= htmlspecialchars(str_replace('_', ' ', $p['payment_method'] ?? 'bank')) ?></span>
                                                    <?php if (!empty($p['reference_no'])): ?>
                                                        <br><small class="text-muted">Ref: <?= htmlspecialchars($p['reference_no']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">No payment recorded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center no-print">
                                        <div class="btn-group btn-group-sm">
                                            <a href="salary?month=<?= urlencode($row['month_year']) ?>&employee_id=<?= $selectedEmployee['id'] ?>" class="btn btn-outline-primary" title="View Month Slip">
                                                <i class="fas fa-file-invoice"></i> Slip
                                            </a>
                                            <?php if ($row['remaining_due'] > 0): ?>
                                                <button type="button" class="btn btn-outline-success" onclick="openQuickPay(<?= $selectedEmployee['id'] ?>, <?= $row['payroll_id'] ?>, <?= $row['remaining_due'] ?>, '<?= $row['month_name'] ?>')" title="Pay Dues">
                                                    <i class="fas fa-rupee-sign"></i> Pay
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment Transactions History Section -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-dark">
                    <i class="fas fa-receipt text-success mr-2"></i> All Payment Transactions & Receipts
                </h6>
                <span class="badge badge-success px-2 py-1 font-weight-bold">
                    <?= count($employeePayments) ?> Payment Records
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0" style="font-size: 13px;">
                    <thead class="bg-light">
                        <tr>
                            <th>Receipt / ID</th>
                            <th>Payment Date</th>
                            <th>Allocated Month</th>
                            <th>Payment Mode</th>
                            <th>Reference / UTR #</th>
                            <th class="text-right">Amount Disbursed</th>
                            <th>Notes</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employeePayments)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    No transaction receipts found for this employee.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($employeePayments as $ep): ?>
                                <tr>
                                    <td class="font-weight-bold text-dark">#PAY-<?= $ep['id'] ?></td>
                                    <td><?= date('d M Y', strtotime($ep['payment_date'])) ?></td>
                                    <td>
                                        <span class="badge badge-light border text-dark font-weight-bold">
                                            <?= !empty($ep['payroll_month_year']) ? date('F Y', strtotime($ep['payroll_month_year'] . '-01')) : 'General Disbursal' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary text-uppercase" style="font-size: 10.5px;">
                                            <?= htmlspecialchars(str_replace('_', ' ', $ep['payment_method'] ?? 'bank_transfer')) ?>
                                        </span>
                                    </td>
                                    <td class="font-mono"><?= htmlspecialchars($ep['reference_no'] ?? '-') ?></td>
                                    <td class="text-right font-weight-bold text-success font-weight-bold">
                                        ₹ <?= number_format((float)($ep['payment_amount'] ?? $ep['amount']), 2) ?>
                                    </td>
                                    <td><small class="text-muted"><?= htmlspecialchars($ep['notes'] ?? '-') ?></small></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($ep['created_by_name'] ?? 'Admin') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>
        <!-- ========================================== -->
        <!-- MASTER LEDGER: ALL EMPLOYEES OVERVIEW     -->
        <!-- ========================================== -->

        <!-- Master KPI Metrics -->
        <div class="row mb-4">
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="stat-widget">
                    <div class="stat-label">Total Payroll Generated (All Time)</div>
                    <div class="stat-value text-dark">₹ <?= number_format($grandTotals['total_payroll'], 2) ?></div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="stat-widget">
                    <div class="stat-label text-success">Total Salary Disbursed (Paid)</div>
                    <div class="stat-value text-success">₹ <?= number_format($grandTotals['total_paid'], 2) ?></div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="stat-widget">
                    <div class="stat-label text-danger">Total Outstanding Dues (Total Baki)</div>
                    <div class="stat-value text-danger">₹ <?= number_format($grandTotals['total_due'], 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Master Employees Table -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-dark">
                    <i class="fas fa-users text-primary mr-2"></i> All Employees Salary Ledger Summary
                </h6>
                <span class="badge badge-primary px-2 py-1 font-weight-bold"><?= count($masterLedger) ?> Employees</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover ledger-table mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th class="text-right">Monthly Base</th>
                            <th class="text-right">Total Net Earned</th>
                            <th class="text-right text-success">Total Paid</th>
                            <th class="text-right text-danger">Total Due (Baki)</th>
                            <th class="text-center">Months (Paid / Unpaid)</th>
                            <th class="text-center no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($masterLedger as $ml): 
                            $e = $ml['employee'];
                        ?>
                            <tr>
                                <td class="font-weight-bold text-muted"><?= htmlspecialchars($e['employee_code'] ?? 'EMP') ?></td>
                                <td class="font-weight-bold text-dark">
                                    <a href="salary_ledger?employee_id=<?= $e['id'] ?>" class="text-dark font-weight-bold">
                                        <?= htmlspecialchars($e['first_name'] . ' ' . ($e['last_name'] ?? '')) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($e['department_name'] ?? 'General') ?></td>
                                <td class="text-right">₹ <?= number_format((float)$e['salary'], 2) ?></td>
                                <td class="text-right font-weight-bold">₹ <?= number_format($ml['total_earned'], 2) ?></td>
                                <td class="text-right font-weight-bold text-success">₹ <?= number_format($ml['total_paid'], 2) ?></td>
                                <td class="text-right font-weight-bold <?= $ml['total_due'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                    ₹ <?= number_format($ml['total_due'], 2) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-success"><?= $ml['paid_months'] ?> Paid</span>
                                    <?php if ($ml['unpaid_months'] > 0): ?>
                                        <span class="badge badge-danger"><?= $ml['unpaid_months'] ?> Due</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center no-print">
                                    <a href="salary_ledger?employee_id=<?= $e['id'] ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye mr-1"></i> View Ledger
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- Quick Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-hand-holding-usd mr-2"></i> Record Salary Payment</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="post_action" value="pay_salary">
                <input type="hidden" name="pay_employee_id" id="modal_pay_employee_id" value="<?= $selectedEmployee['id'] ?? 0 ?>">
                <input type="hidden" name="pay_payroll_id" id="modal_pay_payroll_id" value="">

                <div class="modal-body">
                    <div class="form-group">
                        <label class="small font-weight-bold">Employee:</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(($selectedEmployee['first_name'] ?? '') . ' ' . ($selectedEmployee['last_name'] ?? '')) ?>" readonly>
                    </div>

                    <div class="form-group" id="target_month_group" style="display:none;">
                        <label class="small font-weight-bold">Target Month:</label>
                        <input type="text" class="form-control" id="modal_target_month_name" readonly>
                    </div>

                    <div class="form-group">
                        <label class="small font-weight-bold">Payment Amount (₹): <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="pay_amount" id="modal_pay_amount" class="form-control font-weight-bold" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold">Payment Date: <span class="text-danger">*</span></label>
                            <input type="date" name="pay_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold">Payment Method:</label>
                            <select name="pay_method" class="form-control">
                                <option value="bank_transfer">Bank Transfer (NEFT/IMPS)</option>
                                <option value="upi">UPI (GPay/PhonePe/Paytm)</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="small font-weight-bold">Reference / UTR Number:</label>
                        <input type="text" name="pay_reference" class="form-control" placeholder="e.g. UTR12345678 or Txn Ref">
                    </div>

                    <div class="form-group mb-0">
                        <label class="small font-weight-bold">Payment Notes / Remarks:</label>
                        <textarea name="pay_notes" class="form-control" rows="2" placeholder="e.g. Monthly salary disbursed"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm font-weight-bold">
                        <i class="fas fa-check mr-1"></i> Submit Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openQuickPay(empId, payrollId, remainingDue, monthName) {
    document.getElementById('modal_pay_employee_id').value = empId;
    document.getElementById('modal_pay_payroll_id').value = payrollId || '';
    document.getElementById('modal_pay_amount').value = remainingDue || '';
    
    if (monthName) {
        document.getElementById('target_month_group').style.display = 'block';
        document.getElementById('modal_target_month_name').value = monthName;
    } else {
        document.getElementById('target_month_group').style.display = 'none';
    }
    
    $('#recordPaymentModal').modal('show');
}
</script>

<?php require_once '../includes/footer.php'; ?>
