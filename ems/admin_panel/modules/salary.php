<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('salary');

$month = filterMonth($_GET['month'] ?? '');
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$action = $_GET['action'] ?? '';

// ✅ Individual Salary Slip Print
if ($action == 'print_slip' && $employee_id > 0) {
    generateSalarySlip($employee_id, $month);
    exit;
}

// ✅ Bulk Salary PDF
if ($action == 'export_pdf') {
    generateBulkSalaryPDF($month);
    exit;
}

// ✅ Updated SQL with Leave Types
$salaryData = $pdo->prepare("
    SELECT e.id, e.first_name, e.last_name, e.employee_code, e.salary, 
           e.mobile, e.email, e.joining_date, e.address,
           d.name as department_name, ds.name as designation_name,
           
           -- Attendance Counts
           COALESCE(att.present_days, 0) as present_days,
           COALESCE(att.paid_leave_days, 0) as paid_leave_days,
           COALESCE(att.earned_leave_days, 0) as earned_leave_days,
           COALESCE(att.unpaid_leave_days, 0) as unpaid_leave_days,
           COALESCE(att.absent_days, 0) as absent_days,
           COALESCE(att.weekly_off_days, 0) as weekly_off_days,
           COALESCE(att.holiday_days, 0) as holiday_days,
           COALESCE(att.late_days, 0) as late_days,
           COALESCE(att.half_days, 0) as half_days,
           
           (COALESCE(att.paid_leave_days, 0) + COALESCE(att.earned_leave_days, 0)) as total_paid_leaves,
           (COALESCE(att.unpaid_leave_days, 0) + COALESCE(att.absent_days, 0)) as total_unpaid_days,
           
           COALESCE(ded.total_deduction, 0) as other_deductions
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN designations ds ON ds.id = e.designation_id
    
    LEFT JOIN (
         SELECT employee_id,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN leave_type = 'paid_leave' THEN 1 ELSE 0 END) as paid_leave_days,
                SUM(CASE WHEN leave_type = 'earned_leave' THEN 1 ELSE 0 END) as earned_leave_days,
                SUM(CASE WHEN leave_type = 'unpaid_leave' THEN 1 ELSE 0 END) as unpaid_leave_days,
                SUM(CASE WHEN status = 'absent' OR leave_type = 'absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN leave_type = 'weekly_off' THEN 1 ELSE 0 END) as weekly_off_days,
                SUM(CASE WHEN leave_type = 'holiday' THEN 1 ELSE 0 END) as holiday_days,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_days,
                COALESCE(SUM(working_hours_decimal), 0) as total_hours
        FROM attendance 
        WHERE DATE_FORMAT(attendance_date, '%Y-%m') = ? 
        GROUP BY employee_id
    ) att ON att.employee_id = e.id
    
    LEFT JOIN (
        SELECT employee_id, SUM(deduction_amount) as total_deduction
        FROM salary_deductions 
        WHERE DATE_FORMAT(deduction_date, '%Y-%m') = ? 
        GROUP BY employee_id
    ) ded ON ded.employee_id = e.id
    
    WHERE e.status = 1 
    " . ($employee_id > 0 ? " AND e.id = $employee_id" : "") . "
    ORDER BY e.first_name ASC
");
$salaryData->execute([$month, $month]);
$records = $salaryData->fetchAll();

$monthNum = (int)date('m', strtotime($month . '-01'));
$yearNum = (int)date('Y', strtotime($month . '-01'));
$totalDaysInMonth = (int)date('t', strtotime($month . '-01'));


// Fetch salary rules from database
// Only the two deduction percentages are still applied. The late rules were
// removed when lateness stopped being charged, and overtime is not paid here,
// so reading either was just carrying dead settings into the page.
$salaryRules = ['half_day_deduction_percent' => 50, 'absent_deduction_percent' => 100];
try {
    $rulesStmt = $pdo->query("SELECT * FROM salary_rules WHERE id = 1");
    $rulesRow = $rulesStmt->fetch();
    if ($rulesRow) {
        $salaryRules['half_day_deduction_percent'] = (float)$rulesRow['half_day_deduction_percent'];
        $salaryRules['absent_deduction_percent'] = (float)$rulesRow['absent_deduction_percent'];
    }
} catch (Exception $e) {}

foreach ($records as &$r) {
    $paid_days = $r['present_days'] + $r['paid_leave_days'] + $r['earned_leave_days'];
    $unpaid_days = $r['unpaid_leave_days'] + $r['absent_days'];
    $weekly_off = $r['weekly_off_days'] ?? 0;
    $holidays = $r['holiday_days'] ?? 0;
    $half_days = $r['half_days'] ?? 0;
    $late_days = $r['late_days'] ?? 0;
    $totalHours = (float)($r['total_hours'] ?? 0);

    $working_days = $totalDaysInMonth - $weekly_off - $holidays;
    $attended_days = $paid_days;
    $attendance_percentage = $working_days > 0 ? round(($attended_days / $working_days) * 100, 2) : 0;

    // Daily rate calculation
    // A day is the monthly salary divided by 30, in every month — the same rule
    // the employee's own app uses. Dividing by the length of the calendar month
    // made a day worth more in February than in August, so the payslip here and
    // the figure in the app disagreed by up to a few hundred rupees for
    // identical attendance.
    $dailyRate = round($r['salary'] / 30, 2);

    // Apply salary rules from settings
    $hdPercent = $salaryRules['half_day_deduction_percent'] / 100;
    $abPercent = $salaryRules['absent_deduction_percent'] / 100;

    // Lateness is not charged. A day is either full, half (checked in past the
    // half-day time, or out early) or absent — nothing in between. Lates are
    // still counted and displayed, they simply cost nothing.
    $totalHalfDays = $half_days;

    // Calculate deductions
    $absentDeduction = round($unpaid_days * $dailyRate * $abPercent, 2);
    $halfDayDeduction = round($totalHalfDays * $dailyRate * $hdPercent, 2);
    $lateDeduction = 0.0;
    $other_deductions = $r['other_deductions'] ?? 0;

    $total_deduction = $absentDeduction + $halfDayDeduction + $lateDeduction + $other_deductions;
    $net_salary = max(0, $r['salary'] - $total_deduction);

    $r['paid_days'] = $paid_days;
    $r['unpaid_days'] = $unpaid_days;
    $r['weekly_off'] = $weekly_off;
    $r['holidays'] = $holidays;
    $r['working_days'] = $working_days;
    $r['total_hours'] = $totalHours;
    $r['daily_rate'] = $dailyRate;
    $r['attendance_percentage'] = $attendance_percentage;
    $r['late_deduction'] = $lateDeduction;
    $r['half_day_deduction'] = $halfDayDeduction;
    $r['absent_deduction'] = $absentDeduction;
    $r['total_half_days'] = $totalHalfDays;
    $r['half_day_percent'] = $hdPercent;
    $r['late_days_remaining'] = $late_days;
    $r['total_deduction'] = $total_deduction;
    $r['net_salary'] = $net_salary;
}
unset($r);

$singleEmployee = ($employee_id > 0 && count($records) == 1) ? $records[0] : null;

require_once '../includes/header.php';
?>

<style>
/* Salary Slip Styles */
.salary-slip-container {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 0 15px rgba(0,0,0,0.1);
    margin-top: 20px;
}
.salary-slip {
    max-width: 800px;
    margin: 0 auto;
    padding: 30px;
    border: 1px solid #ddd;
    border-radius: 10px;
    background: white;
}
.salary-slip .header {
    text-align: center;
    border-bottom: 2px solid #1E3A5F;
    padding-bottom: 15px;
    margin-bottom: 20px;
}
.salary-slip .header h2 {
    color: #1E3A5F;
    margin: 0;
    font-size: 22px;
}
.salary-slip .header p {
    margin: 5px 0 0;
    color: #666;
}
.salary-slip .employee-info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 20px;
    margin-bottom: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}
.salary-slip .employee-info .label {
    font-weight: bold;
    color: #555;
    font-size: 13px;
}
.salary-slip .employee-info .value {
    color: #1E3A5F;
    font-weight: 500;
}
.salary-slip table {
    width: 100%;
    border-collapse: collapse;
    margin: 15px 0;
}
.salary-slip table th {
    background: #1E3A5F;
    color: white;
    padding: 8px 12px;
    text-align: left;
    font-size: 13px;
}
.salary-slip table td {
    padding: 6px 12px;
    border-bottom: 1px solid #eee;
    font-size: 13px;
}
.salary-slip table .positive {
    color: #28a745;
}
.salary-slip table .negative {
    color: #dc3545;
}
.salary-slip table .total-row {
    background: #1E3A5F;
    color: white;
    font-weight: bold;
}
.salary-slip table .total-row td {
    color: white;
}
.salary-slip .footer {
    text-align: center;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
    font-size: 11px;
    color: #999;
}
.salary-slip .amount {
    text-align: right;
    font-weight: 500;
}
.btn-print {
    background: #1E3A5F;
    color: white;
    padding: 10px 30px;
    border: none;
    border-radius: 5px;
    font-size: 16px;
    cursor: pointer;
}
.btn-print:hover {
    background: #152b47;
}
@media print {
    .no-print {
        display: none !important;
    }
    .salary-slip {
        border: none !important;
        box-shadow: none !important;
    }
    .salary-slip-container {
        box-shadow: none !important;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-money-bill-wave me-2"></i>
        <?php echo $employee_id > 0 ? 'Salary Slip' : 'Salary - ' . date('F Y', strtotime($month . '-01')); ?>
    </h4>
    <div class="no-print">
        <form method="GET" class="d-inline">
            <input type="month" name="month" value="<?php echo $month; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()">
            <?php if ($employee_id > 0): ?>
            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
            <?php endif; ?>
        </form>
        <?php if ($employee_id > 0): ?>
            <a href="?month=<?php echo $month; ?>" class="btn btn-secondary btn-sm ms-2">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
        <?php endif; ?>
        <button class="btn btn-success btn-sm ms-2" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Print
        </button>
    </div>
</div>

<?php if ($employee_id > 0 && $singleEmployee): ?>
    <!-- ✅ Individual Salary Slip -->
    <?php displaySalarySlip($singleEmployee, $month); ?>
<?php else: ?>
    <!-- ✅ Salary List -->
    <div class="card no-print">
        <div class="card-body p-0">
            <div class="table-responsive">
                <div class="table-responsive">
                <table class="table table-hover datatable mb-0">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Code</th>
                            <th>Dept</th>
                            <th>Salary</th>
                            <th title="Present">P</th>
                            <th title="Paid Leave">PL</th>
                            <th title="Earned Leave">EL</th>
                            <th title="Unpaid Leave">UL</th>
                            <th title="Absent">A</th>
                            <th>Deduction</th>
                            <th>Net</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td>
                            <td><?php echo sanitize($r['employee_code']); ?></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td>₹<?php echo number_format($r['salary'], 2); ?></td>
                            <td><span class="text-success fw-bold"><?php echo $r['present_days']; ?></span></td>
                            <td><span class="text-info fw-bold"><?php echo $r['paid_leave_days']; ?></span></td>
                            <td><span class="text-primary fw-bold"><?php echo $r['earned_leave_days']; ?></span></td>
                            <td><span class="text-warning fw-bold"><?php echo $r['unpaid_leave_days']; ?></span></td>
                            <td><span class="text-danger fw-bold"><?php echo $r['absent_days']; ?></span></td>
                            <td class="text-danger">₹<?php echo number_format($r['total_deduction'], 2); ?></td>
                            <td class="fw-bold">₹<?php echo number_format($r['net_salary'], 2); ?></td>
                            <td>
                                <a href="?month=<?php echo $month; ?>&employee_id=<?php echo $r['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-file-invoice"></i> Slip
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>

<?php
// ✅ Display Salary Slip Function
function displaySalarySlip($employee, $month) {
    global $totalDaysInMonth;
    $monthName = date('F Y', strtotime($month . '-01'));
    ?>
    
    <div class="salary-slip-container">
        <div class="salary-slip" id="salarySlip">
            <div class="header">
                <h2>YATHARTH INSTITUTION</h2>
                <p>Salary Slip - <?php echo $monthName; ?></p>
            </div>
            
            <div class="employee-info">
                <div><span class="label">Employee Name:</span> <span class="value"><?php echo sanitize($employee['first_name'] . ' ' . $employee['last_name']); ?></span></div>
                <div><span class="label">Employee Code:</span> <span class="value"><?php echo sanitize($employee['employee_code']); ?></span></div>
                <div><span class="label">Department:</span> <span class="value"><?php echo sanitize($employee['department_name'] ?? '-'); ?></span></div>
                <div><span class="label">Designation:</span> <span class="value"><?php echo sanitize($employee['designation_name'] ?? '-'); ?></span></div>
                <div><span class="label">Mobile:</span> <span class="value"><?php echo sanitize($employee['mobile'] ?? '-'); ?></span></div>
                <div><span class="label">Email:</span> <span class="value"><?php echo sanitize($employee['email'] ?? '-'); ?></span></div>
                <div><span class="label">Joining Date:</span> <span class="value"><?php echo sanitize($employee['joining_date'] ?? '-'); ?></span></div>
                <div><span class="label">Month:</span> <span class="value"><?php echo $monthName; ?></span></div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width:55%">Description</th>
                        <th style="width:15%;text-align:center">Days</th>
                        <th style="width:30%;text-align:right">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Monthly Salary</strong></td>
                        <td style="text-align:center" colspan="2">₹ <?php echo number_format($employee['salary'], 2); ?></td>
                    </tr>
                    <tr style="background:#f3f4f6;">
                        <td>📅 Month Days</td>
                        <td style="text-align:center"><?php echo $totalDaysInMonth; ?></td>
                        <td style="text-align:right;color:#666;">WO: <?php echo $employee['weekly_off']; ?> | Hol: <?php echo $employee['holidays']; ?></td>
                    </tr>
                    <tr>
                        <td>📊 Attendance</td>
                        <td style="text-align:center" colspan="2">
                            <span style="font-weight:bold;color:<?php echo $employee['attendance_percentage'] >= 100 ? '#28a745' : ($employee['attendance_percentage'] >= 75 ? '#ff9800' : '#dc3545'); ?>">
                                <?php echo $employee['attendance_percentage']; ?>%
                            </span>
                            <small style="color:#999;"> (<?php echo $employee['present_days'] + $employee['paid_leave_days'] + $employee['earned_leave_days']; ?>/<?php echo $employee['working_days']; ?> days)</small>
                        </td>
                    </tr>
                    
                    <!-- Attendance Breakdown -->
                    <tr style="background:#e3f2fd;">
                        <td colspan="3" style="font-weight:bold;color:#1565c0;">ATTENDANCE & DEDUCTION BREAKDOWN</td>
                    </tr>
                    <tr>
                        <td>📅 Working Days</td>
                        <td style="text-align:center"><?php echo $employee['working_days']; ?>d</td>
                        <td style="text-align:right">Total - WO - Hol</td>
                    </tr>
                    <tr>
                        <td>✅ Present Days</td>
                        <td style="text-align:center"><?php echo $employee['present_days']; ?>d</td>
                        <td style="text-align:right;color:#28a745;">+<?php echo $employee['present_days']; ?>d</td>
                    </tr>
                    <tr>
                        <td>📅 Paid Leaves</td>
                        <td style="text-align:center"><?php echo $employee['paid_leave_days'] + $employee['earned_leave_days']; ?>d</td>
                        <td style="text-align:right;color:#28a745;">+<?php echo $employee['paid_leave_days'] + $employee['earned_leave_days']; ?>d</td>
                    </tr>
                    <tr>
                        <td><strong>💵 Daily Rate</strong></td>
                        <td style="text-align:center">Salary / Month Days</td>
                        <td style="text-align:right"><strong>₹ <?php echo number_format($employee['daily_rate'], 2); ?>/day</strong></td>
                    </tr>
                    
                    <!-- Deductions Section -->
                    <tr style="background:#ffebee;">
                        <td colspan="3" style="font-weight:bold;color:#c62828;">DEDUCTIONS</td>
                    </tr>
                    <tr>
                        <td style="padding-left:20px;">❌ Absent / Unpaid Leave</td>
                        <td style="text-align:center"><?php echo $employee['unpaid_days']; ?>d × <?php echo $employee['daily_rate']; ?></td>
                        <td style="text-align:right;color:#dc3545;">- ₹ <?php echo number_format($employee['absent_deduction'], 2); ?></td>
                    </tr>
                    <?php if ($employee['half_day_deduction'] > 0): ?>
                    <tr>
                        <td style="padding-left:20px;">⚠️ Half Day</td>
                        <td style="text-align:center"><?php echo $employee['total_half_days']; ?>d × ₹<?php echo number_format($employee['daily_rate'] * $employee['half_day_percent'], 2); ?></td>
                        <td style="text-align:right;color:#ff9800;">- ₹ <?php echo number_format($employee['half_day_deduction'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td style="padding-left:20px;">📌 Other Deductions</td>
                        <td style="text-align:center">-</td>
                        <td style="text-align:right;color:#dc3545;">- ₹ <?php echo number_format($employee['other_deductions'] ?? 0, 2); ?></td>
                    </tr>
                    
                    <!-- Total Row -->
                    <tr class="total-row">
                        <td><strong>NET PAYABLE</strong></td>
                        <td style="text-align:center">₹ <?php echo number_format($employee['daily_rate'], 2); ?>/day</td>
                        <td style="text-align:right;font-size:18px;">₹ <?php echo number_format($employee['net_salary'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <div style="margin-top:20px;padding:10px;background:#f8f9fa;border-radius:5px;display:grid;grid-template-columns:1fr 1fr;gap:5px;">
                <div><strong>Salary in Words:</strong> <?php echo numberToWords($employee['net_salary']); ?></div>
                <div style="text-align:right;"><strong>Payment Date:</strong> <?php echo date('d-m-Y'); ?></div>
            </div>
            
            <div style="margin-top:15px;display:flex;justify-content:space-between;padding-top:15px;border-top:1px solid #ddd;">
                <div>
                    <p style="margin:0;font-size:12px;color:#666;">Employee Signature</p>
                    <div style="height:30px;"></div>
                    <p style="margin:0;font-size:11px;color:#999;">_____________________</p>
                </div>
                <div style="text-align:right;">
                    <p style="margin:0;font-size:12px;color:#666;">Authorized Signature</p>
                    <div style="height:30px;"></div>
                    <p style="margin:0;font-size:11px;color:#999;">_____________________</p>
                </div>
            </div>
            
            <div class="footer">
                <p>This is a computer generated salary slip. No signature required.</p>
                <p>© <?php echo date('Y'); ?> Yatharth Institution - All Rights Reserved</p>
            </div>
        </div>
    </div>
    <?php
}

// ✅ Number to Words Function
function numberToWords($number) {
    $number = round($number, 2);
    $amount = explode('.', number_format($number, 2, '.', ''));
    $words = convertNumberToWords($amount[0]);
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