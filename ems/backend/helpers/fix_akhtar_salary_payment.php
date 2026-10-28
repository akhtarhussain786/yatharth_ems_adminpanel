<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = (new Database())->getConnection();
    if (!$db) {
        die("DB connection failed.\n");
    }

    echo "== Checking Akhtar Hussain Employee Record ==\n";
    $stmt = $db->prepare("SELECT id, first_name, last_name, employee_code, salary, joining_date FROM employees WHERE employee_code = 'YGI001' OR first_name LIKE '%Akhtar%' LIMIT 1");
    $stmt->execute();
    $emp = $stmt->fetch();

    if (!$emp) {
        die("Employee Akhtar Hussain not found.\n");
    }

    $empId = (int)$emp['id'];
    echo "Found Employee: {$emp['first_name']} {$emp['last_name']} (ID: $empId, Code: {$emp['employee_code']})\n";

    // 1. Ensure July 2026 processing record exists
    $stmtJul = $db->prepare("SELECT id FROM salary_processing WHERE employee_id = ? AND (month_year = '2026-07' OR payroll_month = '2026-07')");
    $stmtJul->execute([$empId]);
    $julRow = $stmtJul->fetch();
    $julPayrollId = $julRow ? (int)$julRow['id'] : 0;

    if (!$julPayrollId) {
        $stmtIns = $db->prepare("
            INSERT INTO salary_processing (
                employee_id, month_year, payroll_month, total_days_in_month, eligible_days, daily_rate,
                present_days, absent_days, half_days, late_days,
                paid_leave_days, unpaid_leave_days, earned_leave_days, weekly_off_days, holiday_days,
                basic_salary, base_earned_salary, allowances, deductions,
                net_salary, current_net_salary, previous_due, total_payable, paid_amount, remaining_due,
                payment_status, notes, lock_status, created_at
            ) VALUES (
                ?, '2026-07', '2026-07', 31, 24, 500.00,
                0, 0, 0, 0,
                0, 0, 0, 0, 0,
                15000.00, 15000.00, 0.00, 0.00,
                15000.00, 15000.00, 0.00, 15000.00, 15000.00, 0.00,
                'paid', 'July 2026 Salary paid on 13 Aug 2026', 'finalized', NOW()
            )
        ");
        $stmtIns->execute([$empId]);
        $julPayrollId = (int)$db->lastInsertId();
        echo "Created July 2026 Salary Record (ID: $julPayrollId) with status = PAID (Rs. 15,000)\n";
    } else {
        $stmtUpdJul = $db->prepare("
            UPDATE salary_processing SET
                basic_salary = 15000.00,
                base_earned_salary = 15000.00,
                current_net_salary = 15000.00,
                net_salary = 15000.00,
                previous_due = 0.00,
                total_payable = 15000.00,
                paid_amount = 15000.00,
                remaining_due = 0.00,
                payment_status = 'paid',
                lock_status = 'finalized',
                notes = 'July 2026 Salary paid on 13 Aug 2026'
            WHERE id = ?
        ");
        $stmtUpdJul = $db->prepare("
            UPDATE salary_processing SET
                month_year = '2026-07',
                payroll_month = '2026-07',
                basic_salary = 15000.00,
                base_earned_salary = 15000.00,
                current_net_salary = 15000.00,
                net_salary = 15000.00,
                previous_due = 0.00,
                total_payable = 15000.00,
                paid_amount = 15000.00,
                remaining_due = 0.00,
                payment_status = 'paid',
                lock_status = 'finalized',
                notes = 'July 2026 Salary paid on 13 Aug 2026'
            WHERE id = ?
        ");
        $stmtUpdJul->execute([$julPayrollId]);
        echo "Updated July 2026 Salary Record (ID: $julPayrollId) -> PAID Rs. 15,000 (Remaining Due: Rs. 0)\n";
    }
    // Clean any duplicates for July
    $db->prepare("DELETE FROM salary_processing WHERE employee_id = ? AND (month_year = '2026-07' OR payroll_month = '2026-07') AND id != ?")->execute([$empId, $julPayrollId]);

    // 2. Ensure August 2026 processing record exists (Paid: 10,000, Due: 5,000)
    $stmtAug = $db->prepare("SELECT id FROM salary_processing WHERE employee_id = ? AND (month_year = '2026-08' OR payroll_month = '2026-08') ORDER BY id ASC");
    $stmtAug->execute([$empId]);
    $augRow = $stmtAug->fetch();
    $augPayrollId = $augRow ? (int)$augRow['id'] : 0;

    if (!$augPayrollId) {
        $stmtInsAug = $db->prepare("
            INSERT INTO salary_processing (
                employee_id, month_year, payroll_month, total_days_in_month, eligible_days, daily_rate,
                present_days, absent_days, half_days, late_days,
                paid_leave_days, unpaid_leave_days, earned_leave_days, weekly_off_days, holiday_days,
                basic_salary, base_earned_salary, allowances, deductions,
                net_salary, current_net_salary, previous_due, total_payable, paid_amount, remaining_due,
                payment_status, notes, lock_status, created_at
            ) VALUES (
                ?, '2026-08', '2026-08', 31, 31, 500.00,
                0, 0, 0, 0,
                0, 0, 0, 0, 0,
                15000.00, 15000.00, 0.00, 0.00,
                15000.00, 15000.00, 0.00, 15000.00, 10000.00, 5000.00,
                'partially_paid', 'August 2026 Salary (Rs. 10,000 paid via UPI, Rs. 5,000 due)', 'generated', NOW()
            )
        ");
        $stmtInsAug->execute([$empId]);
        $augPayrollId = (int)$db->lastInsertId();
        echo "Created August 2026 Salary Record (ID: $augPayrollId) with status = PARTIALLY PAID (Paid: Rs. 10,000, Due: Rs. 5,000)\n";
    } else {
        $stmtUpdAug = $db->prepare("
            UPDATE salary_processing SET
                month_year = '2026-08',
                payroll_month = '2026-08',
                basic_salary = 15000.00,
                base_earned_salary = 15000.00,
                current_net_salary = 15000.00,
                net_salary = 15000.00,
                previous_due = 0.00,
                total_payable = 15000.00,
                paid_amount = 10000.00,
                remaining_due = 5000.00,
                payment_status = 'partially_paid',
                lock_status = 'generated',
                notes = 'August 2026 Salary (Rs. 10,000 paid via UPI, Rs. 5,000 due)'
            WHERE id = ?
        ");
        $stmtUpdAug->execute([$augPayrollId]);
        echo "Updated August 2026 Salary Record (ID: $augPayrollId) -> PARTIALLY PAID (Paid: Rs. 10,000, Due: Rs. 5,000)\n";
    }
    // Clean any duplicates for August
    $db->prepare("DELETE FROM salary_processing WHERE employee_id = ? AND (month_year = '2026-08' OR payroll_month = '2026-08') AND id != ?")->execute([$empId, $augPayrollId]);

    // 3. Update September 2026 processing record (with previous due of 5,000 from August)
    $stmtSep = $db->prepare("SELECT id FROM salary_processing WHERE employee_id = ? AND (month_year = '2026-09' OR payroll_month = '2026-09') ORDER BY id ASC");
    $stmtSep->execute([$empId]);
    $sepRow = $stmtSep->fetch();
    $sepPayrollId = $sepRow ? (int)$sepRow['id'] : 0;

    if ($sepPayrollId) {
        $stmtUpdSep = $db->prepare("
            UPDATE salary_processing SET
                month_year = '2026-09',
                payroll_month = '2026-09',
                basic_salary = 15000.00,
                base_earned_salary = 15000.00,
                current_net_salary = 15000.00,
                net_salary = 15000.00,
                previous_due = 5000.00,
                total_payable = 20000.00,
                paid_amount = 0.00,
                remaining_due = 20000.00,
                payment_status = 'unpaid',
                lock_status = 'generated',
                notes = 'September 2026 (August Due: Rs. 5k, Total Due: Rs. 20k)'
            WHERE id = ?
        ");
        $stmtUpdSep->execute([$sepPayrollId]);
        echo "Updated September 2026 Salary Record (ID: $sepPayrollId) -> Total Payable: Rs. 20k (Paid: Rs. 0, Total Due: Rs. 20k)\n";
    }
    // Clean any duplicates for September
    $db->prepare("DELETE FROM salary_processing WHERE employee_id = ? AND (month_year = '2026-09' OR payroll_month = '2026-09') AND id != ?")->execute([$empId, $sepPayrollId]);

    // 4. Update October 2026 if exists
    $stmtOct = $db->prepare("SELECT id FROM salary_processing WHERE employee_id = ? AND (month_year = '2026-10' OR payroll_month = '2026-10') ORDER BY id ASC");
    $stmtOct->execute([$empId]);
    $octRow = $stmtOct->fetch();
    if ($octRow) {
        $db->prepare("
            UPDATE salary_processing SET
                previous_due = 20000.00,
                total_payable = 35000.00,
                remaining_due = 35000.00
            WHERE id = ?
        ")->execute([(int)$octRow['id']]);
    }

    // 5. Fix salary_payments table: link 15k to July and 10k to August
    $stmtPayCheck = $db->prepare("SELECT id, payment_date, payroll_id, COALESCE(payment_amount, amount, 0) as amt FROM salary_payments WHERE employee_id = ? ORDER BY id ASC");
    $stmtPayCheck->execute([$empId]);
    $payments = $stmtPayCheck->fetchAll();

    foreach ($payments as $p) {
        $amt = (float)$p['amt'];
        $pid = (int)$p['id'];
        if (round($amt, 2) == 15000.00 && $julPayrollId) {
            $db->prepare("
                UPDATE salary_payments SET
                    payroll_id = ?,
                    payment_amount = 15000.00,
                    payment_date = '2026-08-13',
                    payment_method = 'bank_transfer',
                    reference_no = 'NEFT8456345432676223',
                    notes = 'July 2026 Salary payment received on 13 Aug 2026'
                WHERE id = ?
            ")->execute([$julPayrollId, $pid]);
            echo "Updated payment ID $pid -> Linked to July 2026 (Rs. 15,000)\n";
        } elseif (round($amt, 2) == 10000.00 && $augPayrollId) {
            $db->prepare("
                UPDATE salary_payments SET
                    payroll_id = ?,
                    payment_amount = 10000.00,
                    payment_date = '2026-10-27',
                    payment_method = 'upi',
                    reference_no = 'UTR-76562002603',
                    notes = 'August 2026 salary payment via UPI'
                WHERE id = ?
            ")->execute([$augPayrollId, $pid]);
            echo "Updated payment ID $pid -> Linked to August 2026 (Rs. 10,000)\n";
        }
    }

    echo "\n=== ALL SALARY RECORDS FIXED SUCCESSFULLY ===";
    echo "\nJuly 2026:      Net Rs. 15,000 | Paid Rs. 15,000 (PAID on 13 Aug 2026) | Due: Rs. 0";
    echo "\nAugust 2026:    Net Rs. 15,000 | Paid Rs. 10,000 (PARTIALLY PAID)      | Due: Rs. 5,000";
    echo "\nSeptember 2026: Net Rs. 15,000 | Paid Rs. 0      (UNPAID)              | Total Due: Rs. 20,000 (Aug 5k + Sep 15k)";
    echo "\nOctober 2026:   Net Rs. 15,000 | Paid Rs. 0      (RUNNING)             | Total Due: Rs. 35,000\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
