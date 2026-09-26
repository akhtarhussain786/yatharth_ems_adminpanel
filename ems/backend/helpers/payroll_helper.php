<?php
/**
 * Payroll & Salary Ledger Helper
 * 
 * Handles:
 * - Joining Date & Relieving Date Prorating
 * - Non-employment Date Exemption (Pre-joining dates are not counted as absent)
 * - Attendance & Deduction Calculations
 * - Accurate Cumulative Previous Due Carry Forward (Zero Double-Counting)
 * - Multi-Payment FIFO Allocation (Oldest Dues First)
 * - Estimated / Running Salary Till Today
 * - Payroll Locking & Permanent Audit Trail
 */

require_once __DIR__ . '/attendance_rules.php';

/**
 * Calculate complete salary breakdown for an employee for a specific month.
 *
 * @param PDO $db
 * @param int $employeeId
 * @param string $month Format 'YYYY-MM'
 * @param array $options Optional overrides or flags (e.g. force_recalculate, lock_check)
 * @return array
 */
function calculateEmployeeSalary($db, $employeeId, $month, $options = []) {
    $month = filterMonth($month);
    $monthStart = $month . '-01';
    $totalDaysInMonth = (int)date('t', strtotime($monthStart));
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $today = date('Y-m-d');
    $isCurrentMonth = ($month === date('Y-m'));

    // 1. Fetch employee details
    $stmt = $db->prepare("
        SELECT e.*, d.name as department_name, des.name as designation_name
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN designations des ON des.id = e.designation_id
        WHERE e.id = ?
    ");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    if (!$employee) {
        return ['success' => false, 'message' => 'Employee not found'];
    }

    $monthlySalary = (float)($employee['salary'] ?? 0);
    // Daily rate calculation: monthly salary / 30 (standard system rate)
    $dailyRate = $monthlySalary > 0 ? round($monthlySalary / 30, 2) : 0.00;

    // 2. Joining & Relieving date range calculation
    $joiningDate = !empty($employee['joining_date']) ? date('Y-m-d', strtotime($employee['joining_date'])) : null;
    $relievingDate = !empty($employee['relieving_date']) ? date('Y-m-d', strtotime($employee['relieving_date'])) : null;

    $isEmployedThisMonth = true;
    $notEmployedReason = '';
    $effectiveStart = $monthStart;
    $effectiveEnd = $monthEnd;

    // Check if joining date is in future relative to this month
    if ($joiningDate && $joiningDate > $monthEnd) {
        $isEmployedThisMonth = false;
        $notEmployedReason = 'Employee joined after this month (' . date('d M Y', strtotime($joiningDate)) . ')';
    }

    // Check if relieving date is before this month
    if ($relievingDate && $relievingDate < $monthStart) {
        $isEmployedThisMonth = false;
        $notEmployedReason = 'Employee relieved before this month (' . date('d M Y', strtotime($relievingDate)) . ')';
    }

    if ($isEmployedThisMonth) {
        if ($joiningDate && $joiningDate > $monthStart) {
            $effectiveStart = $joiningDate;
        }
        if ($relievingDate && $relievingDate < $monthEnd) {
            $effectiveEnd = $relievingDate;
        }
    }

    // Calculate eligible days
    if (!$isEmployedThisMonth) {
        $eligibleDays = 0;
        $preJoiningDays = $totalDaysInMonth;
        $postRelievingDays = 0;
    } else {
        $startTs = strtotime($effectiveStart);
        $endTs = strtotime($effectiveEnd);
        $eligibleDays = max(0, (int)round(($endTs - $startTs) / 86400) + 1);

        // Calculate non-employed days in this month
        $preJoiningDays = ($joiningDate && $joiningDate > $monthStart) 
            ? max(0, (int)round((strtotime($joiningDate) - strtotime($monthStart)) / 86400)) 
            : 0;
        $postRelievingDays = ($relievingDate && $relievingDate < $monthEnd) 
            ? max(0, (int)round((strtotime($monthEnd) - strtotime($relievingDate)) / 86400)) 
            : 0;
    }

    // Base Earned Salary:
    // If employee worked full month (joined on or before 1st and not relieved early) -> full salary
    // If joined mid-month or relieved mid-month -> dailyRate * eligibleDays
    $isProrated = ($isEmployedThisMonth && $eligibleDays < $totalDaysInMonth && ($preJoiningDays > 0 || $postRelievingDays > 0));
    if (!$isEmployedThisMonth) {
        $baseEarnedSalary = 0.00;
    } elseif ($isProrated) {
        $baseEarnedSalary = round($dailyRate * $eligibleDays, 2);
    } else {
        $baseEarnedSalary = $monthlySalary;
    }

    // 3. Fetch attendance ONLY within the eligible employment window
    // Pre-joining dates are NEVER queried or marked absent!
    $presentDays = 0.0;
    $paidLeaveDays = 0.0;
    $earnedLeaveDays = 0.0;
    $unpaidLeaveDays = 0.0;
    $absentDays = 0.0;
    $halfDays = 0.0;
    $lateDays = 0.0;
    $weeklyOffDays = 0.0;
    $holidayDays = 0.0;
    $totalLateMinutes = 0;
    $totalHours = 0.0;

    if ($isEmployedThisMonth && $eligibleDays > 0) {
        $attStmt = $db->prepare("
            SELECT attendance_date, check_in, check_out, status, leave_type,
                   COALESCE(late_minutes, 0) as late_minutes,
                   COALESCE(working_hours_decimal, 0) as working_hours_decimal
            FROM attendance
            WHERE employee_id = ?
              AND attendance_date >= ?
              AND attendance_date <= ?
            ORDER BY attendance_date ASC
        ");
        $attStmt->execute([$employeeId, $effectiveStart, $effectiveEnd]);
        $attendanceLogs = $attStmt->fetchAll();

        foreach ($attendanceLogs as $att) {
            $st = strtolower((string)$att['status']);
            $lt = strtolower((string)($att['leave_type'] ?? ''));
            $lateMins = (int)$att['late_minutes'];
            $totalLateMinutes += $lateMins;
            $totalHours += (float)$att['working_hours_decimal'];

            if ($st === 'late') {
                $lateDays += 1;
            }

            if ($lt === 'weekly_off' || $lt === 'week_off' || $lt === 'weekoff' || $st === 'week-off' || $st === 'weekoff') {
                $weeklyOffDays += 1;
            } elseif ($lt === 'holiday' || $st === 'holiday') {
                $holidayDays += 1;
            } elseif ($lt === 'paid_leave' || $lt === 'casual leave' || $lt === 'sick leave') {
                $paidLeaveDays += 1;
            } elseif ($lt === 'earned_leave' || $lt === 'annual leave') {
                $earnedLeaveDays += 1;
            } elseif ($lt === 'unpaid_leave' || $lt === 'leave without pay' || $lt === 'lwp' || isUnpaidLeaveType($lt)) {
                $unpaidLeaveDays += 1;
            } elseif ($st === 'absent') {
                $absentDays += 1;
            } elseif ($st === 'half-day' || $st === 'half_day') {
                $halfDays += 1;
                $presentDays += 0.5;
            } elseif ($st === 'present' || $st === 'late') {
                $presentDays += 1;
            }
        }
    }

    // 4. Fetch salary deduction rules
    $salaryRules = ['half_day_deduction_percent' => 50, 'absent_deduction_percent' => 100];
    try {
        $rulesStmt = $db->query("SELECT * FROM salary_rules WHERE id = 1");
        $rulesRow = $rulesStmt->fetch();
        if ($rulesRow) {
            $salaryRules['half_day_deduction_percent'] = (float)$rulesRow['half_day_deduction_percent'];
            $salaryRules['absent_deduction_percent'] = (float)$rulesRow['absent_deduction_percent'];
        }
    } catch (Exception $e) {}

    $hdPercent = $salaryRules['half_day_deduction_percent'] / 100;
    $abPercent = $salaryRules['absent_deduction_percent'] / 100;

    // Calculate deductions
    $absentDeduction = round(($absentDays + $unpaidLeaveDays) * $dailyRate * $abPercent, 2);
    $halfDayDeduction = round($halfDays * $dailyRate * $hdPercent, 2);
    $lateDeduction = 0.00; // Lateness is logged for record, not docked as per company policy
    $attendanceDeduction = $absentDeduction + $halfDayDeduction + $lateDeduction;

    // 5. Fetch other ad-hoc deductions from salary_deductions
    $otherDeductions = 0.00;
    $dedStmt = $db->prepare("
        SELECT COALESCE(SUM(deduction_amount), 0) as total_ded
        FROM salary_deductions
        WHERE employee_id = ? AND DATE_FORMAT(deduction_date, '%Y-%m') = ?
    ");
    $dedStmt->execute([$employeeId, $month]);
    $otherDeductions = (float)$dedStmt->fetchColumn();

    // 6. Fetch existing processed payroll record if exists (to read manual bonuses/allowances/notes/lock_status)
    $existingProcStmt = $db->prepare("
        SELECT * FROM salary_processing
        WHERE employee_id = ? AND (month_year = ? OR payroll_month = ?)
        LIMIT 1
    ");
    $existingProcStmt->execute([$employeeId, $month, $month]);
    $existingProc = $existingProcStmt->fetch();

    $bonusAmount = (float)($existingProc['bonus_amount'] ?? 0);
    $incentiveAmount = (float)($existingProc['incentive_amount'] ?? 0);
    $overtimeAmount = (float)($existingProc['overtime_amount'] ?? 0);
    $allowances = (float)($existingProc['allowances'] ?? 0);
    $otherEarnings = (float)($existingProc['other_earnings'] ?? 0);
    $advanceDeduction = (float)($existingProc['advance_deduction'] ?? 0);
    $lockStatus = $existingProc['lock_status'] ?? 'generated';
    $notes = $existingProc['notes'] ?? '';

    $totalEarnings = round($bonusAmount + $incentiveAmount + $overtimeAmount + $allowances + $otherEarnings, 2);
    $totalDeductions = round($attendanceDeduction + $otherDeductions + $advanceDeduction, 2);

    // Current Month Net Salary (Earned for this month alone)
    $currentNetSalary = $isEmployedThisMonth 
        ? max(0, round($baseEarnedSalary + $totalEarnings - $totalDeductions, 2)) 
        : 0.00;

    // 7. Calculate Previous Due (Carried forward strictly from unpaid balances of prior months)
    $previousDue = calculatePreviousDueForEmployee($db, $employeeId, $month);

    // Total Payable = Current Month Net + Previous Due
    $totalPayable = round($currentNetSalary + $previousDue, 2);

    // 8. Fetch payments made for this month's payroll or allocated to this month
    $paidAmount = 0.00;
    if ($existingProc && !empty($existingProc['id'])) {
        $payStmt = $db->prepare("
            SELECT COALESCE(SUM(payment_amount), 0) as total_paid
            FROM salary_payments
            WHERE payroll_id = ?
        ");
        $payStmt->execute([$existingProc['id']]);
        $paidAmount = (float)$payStmt->fetchColumn();
    } else {
        // Check payments recorded by employee and month
        $payStmt = $db->prepare("
            SELECT COALESCE(SUM(sp.payment_amount), 0) as total_paid
            FROM salary_payments sp
            JOIN salary_processing spr ON spr.id = sp.payroll_id
            WHERE sp.employee_id = ? AND (spr.month_year = ? OR spr.payroll_month = ?)
        ");
        $payStmt->execute([$employeeId, $month, $month]);
        $paidAmount = (float)$payStmt->fetchColumn();
    }

    $remainingDue = max(0, round($totalPayable - $paidAmount, 2));

    // Determine payment status
    if ($totalPayable == 0 && $paidAmount == 0) {
        $paymentStatus = 'unpaid';
    } elseif ($paidAmount >= $totalPayable && $totalPayable > 0) {
        $paymentStatus = 'paid';
    } elseif ($paidAmount > 0) {
        $paymentStatus = 'partially_paid';
    } else {
        $paymentStatus = 'unpaid';
    }

    // 9. Running / Estimated Salary Till Today (if current ongoing month)
    $runningSalary = null;
    if ($isCurrentMonth && $isEmployedThisMonth) {
        $runningEnd = min($today, $effectiveEnd);
        $runningEligibleDays = max(0, (int)round((strtotime($runningEnd) - strtotime($effectiveStart)) / 86400) + 1);
        $runningBase = round($dailyRate * $runningEligibleDays, 2);
        $runningNet = max(0, round($runningBase - $attendanceDeduction - $otherDeductions, 2));
        $runningSalary = [
            'as_of_date' => $runningEnd,
            'eligible_days_till_today' => $runningEligibleDays,
            'estimated_earned_salary' => $runningNet,
            'label' => 'Estimated / Running Salary (Till ' . date('d M Y', strtotime($runningEnd)) . ')'
        ];
    }

    return [
        'success'                 => true,
        'employee'                => $employee,
        'payroll_month'           => $month,
        'month_name'              => date('F Y', strtotime($monthStart)),
        'total_days_in_month'     => $totalDaysInMonth,
        'is_employed_this_month'  => $isEmployedThisMonth,
        'not_employed_reason'     => $notEmployedReason,
        'joining_date'            => $joiningDate,
        'relieving_date'          => $relievingDate,
        'effective_start_date'    => $effectiveStart,
        'effective_end_date'      => $effectiveEnd,
        'eligible_days'           => $eligibleDays,
        'pre_joining_days'        => $preJoiningDays,
        'post_relieving_days'     => $postRelievingDays,
        'is_prorated'             => $isProrated,
        'monthly_salary'          => $monthlySalary,
        'daily_rate'              => $dailyRate,
        'base_earned_salary'      => $baseEarnedSalary,
        
        // Attendance Counts
        'present_days'            => $presentDays,
        'paid_leave_days'         => $paidLeaveDays,
        'earned_leave_days'       => $earnedLeaveDays,
        'unpaid_leave_days'       => $unpaidLeaveDays,
        'absent_days'             => $absentDays,
        'half_days'               => $halfDays,
        'late_days'               => $lateDays,
        'weekly_off_days'         => $weeklyOffDays,
        'holiday_days'            => $holidayDays,
        'total_late_minutes'      => $totalLateMinutes,
        'total_working_hours'     => $totalHours,

        // Deductions Breakdown
        'absent_deduction'        => $absentDeduction,
        'half_day_deduction'      => $halfDayDeduction,
        'late_deduction'          => $lateDeduction,
        'attendance_deduction'    => $attendanceDeduction,
        'other_deductions'        => $otherDeductions,
        'advance_deduction'       => $advanceDeduction,
        'total_deductions'        => $totalDeductions,

        // Earnings Breakdown
        'bonus_amount'            => $bonusAmount,
        'incentive_amount'        => $incentiveAmount,
        'overtime_amount'         => $overtimeAmount,
        'allowances'              => $allowances,
        'other_earnings'          => $otherEarnings,
        'total_earnings'          => $totalEarnings,

        // Financial Totals
        'current_net_salary'      => $currentNetSalary,
        'previous_due'            => $previousDue,
        'total_payable'           => $totalPayable,
        'paid_amount'             => $paidAmount,
        'remaining_due'           => $remainingDue,
        'payment_status'          => $paymentStatus,
        'lock_status'             => $lockStatus,
        'payroll_id'              => $existingProc['id'] ?? null,
        'notes'                   => $notes,
        'running_salary'          => $runningSalary,
    ];
}

/**
 * Calculates previous outstanding dues for an employee strictly from past finalized/generated months.
 * Guarantees zero duplication by calculating: (Sum of all past Net Salaries) - (Sum of all past Payments).
 */
function calculatePreviousDueForEmployee($db, $employeeId, $currentMonth) {
    $stmt = $db->prepare("
        SELECT id, month_year, payroll_month, current_net_salary, net_salary, paid_amount, remaining_due
        FROM salary_processing
        WHERE employee_id = ?
          AND (month_year < ? OR (payroll_month IS NOT NULL AND payroll_month < ?))
        ORDER BY month_year ASC
    ");
    $stmt->execute([$employeeId, $currentMonth, $currentMonth]);
    $pastPayrolls = $stmt->fetchAll();

    if (empty($pastPayrolls)) {
        return 0.00;
    }

    $totalPastDue = 0.00;
    foreach ($pastPayrolls as $p) {
        $rem = (float)($p['remaining_due'] ?? 0);
        // Double check against payment records
        $payStmt = $db->prepare("SELECT COALESCE(SUM(payment_amount), 0) FROM salary_payments WHERE payroll_id = ?");
        $payStmt->execute([$p['id']]);
        $actualPaid = (float)$payStmt->fetchColumn();

        $monthNet = (float)(!empty($p['current_net_salary']) ? $p['current_net_salary'] : $p['net_salary']);
        $due = max(0, round($monthNet - $actualPaid, 2));
        $totalPastDue += $due;
    }

    return round($totalPastDue, 2);
}

/**
 * Save / Upsert monthly payroll calculation into salary_processing.
 */
function saveSalaryPayrollRecord($db, $calcData, $userId = null) {
    $empId = $calcData['employee']['id'];
    $month = $calcData['payroll_month'];

    // Check if locked
    $chkStmt = $db->prepare("SELECT id, lock_status FROM salary_processing WHERE employee_id = ? AND (month_year = ? OR payroll_month = ?)");
    $chkStmt->execute([$empId, $month, $month]);
    $existing = $chkStmt->fetch();

    if ($existing && $existing['lock_status'] === 'finalized' && empty($calcData['force_override'])) {
        return ['success' => false, 'message' => 'Payroll is finalized and locked. Reopen first to recalculate.'];
    }

    $stmt = $db->prepare("
        INSERT INTO salary_processing (
            employee_id, month_year, payroll_month,
            joining_date_snapshot, relieving_date_snapshot,
            eligible_days, total_days_in_month, daily_rate,
            base_earned_salary, basic_salary, allowances, deductions,
            net_salary, current_net_salary, previous_due, total_payable,
            paid_amount, remaining_due,
            present_days, paid_leave_days, unpaid_leave_days,
            absent_days, late_days, half_days, weekly_off_days, holiday_days,
            attendance_deduction, bonus_amount, incentive_amount,
            overtime_hours, overtime_amount, other_earnings,
            advance_deduction, other_deductions,
            payment_status, lock_status, processed_by, notes
        ) VALUES (
            ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            payroll_month = VALUES(payroll_month),
            joining_date_snapshot = VALUES(joining_date_snapshot),
            relieving_date_snapshot = VALUES(relieving_date_snapshot),
            eligible_days = VALUES(eligible_days),
            total_days_in_month = VALUES(total_days_in_month),
            daily_rate = VALUES(daily_rate),
            base_earned_salary = VALUES(base_earned_salary),
            basic_salary = VALUES(basic_salary),
            allowances = VALUES(allowances),
            deductions = VALUES(deductions),
            net_salary = VALUES(net_salary),
            current_net_salary = VALUES(current_net_salary),
            previous_due = VALUES(previous_due),
            total_payable = VALUES(total_payable),
            paid_amount = VALUES(paid_amount),
            remaining_due = VALUES(remaining_due),
            present_days = VALUES(present_days),
            paid_leave_days = VALUES(paid_leave_days),
            unpaid_leave_days = VALUES(unpaid_leave_days),
            absent_days = VALUES(absent_days),
            late_days = VALUES(late_days),
            half_days = VALUES(half_days),
            weekly_off_days = VALUES(weekly_off_days),
            holiday_days = VALUES(holiday_days),
            attendance_deduction = VALUES(attendance_deduction),
            bonus_amount = VALUES(bonus_amount),
            incentive_amount = VALUES(incentive_amount),
            overtime_amount = VALUES(overtime_amount),
            other_earnings = VALUES(other_earnings),
            advance_deduction = VALUES(advance_deduction),
            other_deductions = VALUES(other_deductions),
            payment_status = VALUES(payment_status),
            lock_status = VALUES(lock_status),
            processed_by = VALUES(processed_by),
            notes = VALUES(notes)
    ");

    $stmt->execute([
        $empId, $month, $month,
        $calcData['joining_date'], $calcData['relieving_date'],
        $calcData['eligible_days'], $calcData['total_days_in_month'], $calcData['daily_rate'],
        $calcData['base_earned_salary'], $calcData['monthly_salary'], $calcData['allowances'], $calcData['total_deductions'],
        $calcData['current_net_salary'], $calcData['current_net_salary'], $calcData['previous_due'], $calcData['total_payable'],
        $calcData['paid_amount'], $calcData['remaining_due'],
        $calcData['present_days'], $calcData['paid_leave_days'], $calcData['unpaid_leave_days'],
        $calcData['absent_days'], $calcData['late_days'], $calcData['half_days'], $calcData['weekly_off_days'], $calcData['holiday_days'],
        $calcData['attendance_deduction'], $calcData['bonus_amount'], $calcData['incentive_amount'],
        0.00, $calcData['overtime_amount'], $calcData['other_earnings'],
        $calcData['advance_deduction'], $calcData['other_deductions'],
        $calcData['payment_status'], $calcData['lock_status'], $userId, $calcData['notes']
    ]);

    $payrollId = $existing ? $existing['id'] : $db->lastInsertId();

    // Log to audit trail
    logSalaryAudit($db, $payrollId, $empId, 'payroll_saved', null, json_encode($calcData), $userId);

    return ['success' => true, 'payroll_id' => $payrollId, 'message' => 'Payroll record saved successfully'];
}

/**
 * Record a salary payment with FIFO allocation across oldest pending months.
 *
 * @param PDO $db
 * @param int $employeeId
 * @param float $paymentAmount
 * @param string $paymentDate
 * @param string $paymentMethod
 * @param string $referenceNo
 * @param string $notes
 * @param int $userId
 * @param int|null $targetPayrollId If specified, payment is applied directly to this payroll
 * @return array
 */
function recordSalaryPayment($db, $employeeId, $paymentAmount, $paymentDate, $paymentMethod, $referenceNo, $notes, $userId, $targetPayrollId = null) {
    $paymentAmount = (float)$paymentAmount;
    if ($paymentAmount <= 0) {
        return ['success' => false, 'message' => 'Payment amount must be greater than zero.'];
    }

    try {
        $db->beginTransaction();

        $allocations = [];
        $remainingPayment = $paymentAmount;

        if ($targetPayrollId) {
            // Apply directly to target payroll
            $stmt = $db->prepare("SELECT * FROM salary_processing WHERE id = ? AND employee_id = ? FOR UPDATE");
            $stmt->execute([$targetPayrollId, $employeeId]);
            $payroll = $stmt->fetch();
            if (!$payroll) {
                throw new Exception('Selected payroll record not found.');
            }

            // Record payment transaction
            $insPay = $db->prepare("
                INSERT INTO salary_payments (payroll_id, employee_id, payment_amount, payment_date, payment_method, reference_no, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insPay->execute([$targetPayrollId, $employeeId, $paymentAmount, $paymentDate, $paymentMethod, $referenceNo, $notes, $userId]);
            $paymentId = $db->lastInsertId();

            // Refresh payroll totals
            refreshPayrollPaymentTotals($db, $targetPayrollId);

            $allocations[] = [
                'payroll_id' => $targetPayrollId,
                'month'      => $payroll['month_year'],
                'amount'     => $paymentAmount,
            ];
        } else {
            // FIFO Allocation: Fetch all pending/partially paid payrolls ordered by month_year ASC
            $stmt = $db->prepare("
                SELECT * FROM salary_processing
                WHERE employee_id = ? AND payment_status != 'paid'
                ORDER BY month_year ASC
                FOR UPDATE
            ");
            $stmt->execute([$employeeId]);
            $pendingPayrolls = $stmt->fetchAll();

            if (empty($pendingPayrolls)) {
                // If no unpaid payroll, find the latest payroll or record payment linked to employee
                $stmtLatest = $db->prepare("SELECT * FROM salary_processing WHERE employee_id = ? ORDER BY month_year DESC LIMIT 1");
                $stmtLatest->execute([$employeeId]);
                $latest = $stmtLatest->fetch();
                $targetId = $latest ? $latest['id'] : null;

                $insPay = $db->prepare("
                    INSERT INTO salary_payments (payroll_id, employee_id, payment_amount, payment_date, payment_method, reference_no, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insPay->execute([$targetId, $employeeId, $paymentAmount, $paymentDate, $paymentMethod, $referenceNo, $notes, $userId]);
                $paymentId = $db->lastInsertId();

                if ($targetId) {
                    refreshPayrollPaymentTotals($db, $targetId);
                }
                $allocations[] = [
                    'payroll_id' => $targetId,
                    'month'      => $latest ? $latest['month_year'] : 'General',
                    'amount'     => $paymentAmount
                ];
            } else {
                foreach ($pendingPayrolls as $p) {
                    if ($remainingPayment <= 0) break;

                    // Calculate remaining due for this specific payroll record
                    $payStmt = $db->prepare("SELECT COALESCE(SUM(payment_amount), 0) FROM salary_payments WHERE payroll_id = ?");
                    $payStmt->execute([$p['id']]);
                    $currentPaid = (float)$payStmt->fetchColumn();

                    $net = (float)(!empty($p['current_net_salary']) ? $p['current_net_salary'] : $p['net_salary']);
                    $monthDue = max(0, round($net - $currentPaid, 2));

                    if ($monthDue <= 0) continue;

                    $allocatedToThisMonth = min($remainingPayment, $monthDue);

                    $insPay = $db->prepare("
                        INSERT INTO salary_payments (payroll_id, employee_id, payment_amount, payment_date, payment_method, reference_no, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insPay->execute([$p['id'], $employeeId, $allocatedToThisMonth, $paymentDate, $paymentMethod, $referenceNo, $notes, $userId]);

                    refreshPayrollPaymentTotals($db, $p['id']);

                    $allocations[] = [
                        'payroll_id' => $p['id'],
                        'month'      => $p['month_year'],
                        'amount'     => $allocatedToThisMonth
                    ];

                    $remainingPayment -= $allocatedToThisMonth;
                }

                // If payment amount exceeded all pending months, allocate remainder to latest month
                if ($remainingPayment > 0) {
                    $lastP = end($pendingPayrolls);
                    $insPay = $db->prepare("
                        INSERT INTO salary_payments (payroll_id, employee_id, payment_amount, payment_date, payment_method, reference_no, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insPay->execute([$lastP['id'], $employeeId, $remainingPayment, $paymentDate, $paymentMethod, $referenceNo, 'Excess payment allocated', $userId]);
                    refreshPayrollPaymentTotals($db, $lastP['id']);
                    $allocations[] = [
                        'payroll_id' => $lastP['id'],
                        'month'      => $lastP['month_year'],
                        'amount'     => $remainingPayment
                    ];
                }
            }
        }

        // Log audit
        logSalaryAudit($db, $targetPayrollId, $employeeId, 'payment_added', null, json_encode([
            'amount' => $paymentAmount,
            'method' => $paymentMethod,
            'reference_no' => $referenceNo,
            'allocations' => $allocations
        ]), $userId);

        $db->commit();
        return ['success' => true, 'message' => 'Payment of ₹' . number_format($paymentAmount, 2) . ' recorded successfully.', 'allocations' => $allocations];
    } catch (Exception $e) {
        $db->rollBack();
        error_log('recordSalaryPayment Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Payment failed: ' . $e->getMessage()];
    }
}

/**
 * Recalculate paid amount and remaining due on a payroll record after a payment transaction.
 */
function refreshPayrollPaymentTotals($db, $payrollId) {
    if (!$payrollId) return;

    $stmt = $db->prepare("SELECT * FROM salary_processing WHERE id = ?");
    $stmt->execute([$payrollId]);
    $p = $stmt->fetch();
    if (!$p) return;

    $payStmt = $db->prepare("SELECT COALESCE(SUM(payment_amount), 0) FROM salary_payments WHERE payroll_id = ?");
    $payStmt->execute([$payrollId]);
    $totalPaid = (float)$payStmt->fetchColumn();

    $net = (float)(!empty($p['current_net_salary']) ? $p['current_net_salary'] : $p['net_salary']);
    $prevDue = (float)($p['previous_due'] ?? 0);
    $totalPayable = round($net + $prevDue, 2);
    $remDue = max(0, round($totalPayable - $totalPaid, 2));

    if ($totalPayable == 0 && $totalPaid == 0) {
        $status = 'unpaid';
    } elseif ($totalPaid >= $totalPayable && $totalPayable > 0) {
        $status = 'paid';
    } elseif ($totalPaid > 0) {
        $status = 'partially_paid';
    } else {
        $status = 'unpaid';
    }

    $upStmt = $db->prepare("
        UPDATE salary_processing
        SET paid_amount = ?, remaining_due = ?, payment_status = ?, total_payable = ?
        WHERE id = ?
    ");
    $upStmt->execute([$totalPaid, $remDue, $status, $totalPayable, $payrollId]);
}

/**
 * Log audit trail entry
 */
function logSalaryAudit($db, $payrollId, $employeeId, $action, $oldData, $newData, $userId) {
    try {
        $stmt = $db->prepare("
            INSERT INTO salary_audit_logs (payroll_id, employee_id, action, old_data, new_data, user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$payrollId, $employeeId, $action, $oldData, $newData, $userId]);
    } catch (Exception $e) {
        error_log('logSalaryAudit error: ' . $e->getMessage());
    }
}
