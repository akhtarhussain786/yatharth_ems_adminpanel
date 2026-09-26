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

    // 2. Ensure August 2026 processing record exists
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
                15000.00, 15000.00, 0.00, 15000.00, 0.00, 15000.00,
                'unpaid', 'August 2026 Salary Pending', 'generated', NOW()
            )
        ");
        $stmtInsAug->execute([$empId]);
        $augPayrollId = (int)$db->lastInsertId();
        echo "Created August 2026 Salary Record (ID: $augPayrollId) with status = UNPAID (Due: Rs. 15,000)\n";
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
                paid_amount = 0.00,
                remaining_due = 15000.00,
                payment_status = 'unpaid',
                lock_status = 'generated',
                notes = 'August 2026 Salary Pending'
            WHERE id = ?
        ");
        $stmtUpdAug->execute([$augPayrollId]);
        echo "Updated August 2026 Salary Record (ID: $augPayrollId) -> UNPAID Rs. 15,000\n";
    }
    // Clean any duplicates for August
    $db->prepare("DELETE FROM salary_processing WHERE employee_id = ? AND (month_year = '2026-08' OR payroll_month = '2026-08') AND id != ?")->execute([$empId, $augPayrollId]);

    // 3. Reset September 2026 processing record to unpaid (with previous due of 15,000 from August)
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
                previous_due = 15000.00,
                total_payable = 30000.00,
                paid_amount = 0.00,
                remaining_due = 30000.00,
                payment_status = 'unpaid',
                lock_status = 'generated',
                notes = 'September 2026 Ongoing (August Due: Rs. 15k, Total Due: Rs. 30k)'
            WHERE id = ?
        ");
        $stmtUpdSep->execute([$sepPayrollId]);
        echo "Updated September 2026 Salary Record (ID: $sepPayrollId) -> Total Payable: Rs. 30k (Paid: Rs. 0, Total Due: Rs. 30k)\n";
    }
    // Clean any duplicates for September
    $db->prepare("DELETE FROM salary_processing WHERE employee_id = ? AND (month_year = '2026-09' OR payroll_month = '2026-09') AND id != ?")->execute([$empId, $sepPayrollId]);

    // 4. Fix salary_payments table: link payment of Rs. 15,000 to July 2026
    $stmtPayCheck = $db->prepare("SELECT id, payment_date, payroll_id FROM salary_payments WHERE employee_id = ?");
    $stmtPayCheck->execute([$empId]);
    $payments = $stmtPayCheck->fetchAll();

    if (empty($payments)) {
        $stmtInsPay = $db->prepare("
            INSERT INTO salary_payments (
                employee_id, payroll_id, amount, payment_date, payment_method, reference_no, notes, created_by, created_at
            ) VALUES (
                ?, ?, 15000.00, '2026-08-13', 'bank_transfer', 'JULY-SALARY-PAID', 'July 2026 salary disbursed on 13 Aug', 1, NOW()
            )
        ");
        $stmtInsPay->execute([$empId, $julPayrollId]);
        echo "Inserted payment record of Rs. 15,000 linked to July 2026 payroll (ID: $julPayrollId)\n";
    } else {
        foreach ($payments as $p) {
            $stmtUpdPay = $db->prepare("
                UPDATE salary_payments SET
                    payroll_id = ?,
                    payment_date = '2026-08-13',
                    payment_method = 'bank_transfer',
                    notes = 'July 2026 salary disbursed on 13 Aug'
                WHERE id = ?
            ");
            $stmtUpdPay->execute([$julPayrollId, (int)$p['id']]);
            echo "Updated payment record ID {$p['id']} -> Pointed to July 2026 (Payroll ID: $julPayrollId)\n";
        }
    }

    echo "\n=== ALL SALARY RECORDS FIXED SUCCESSFULLY ===";
    echo "\nJuly 2026:      Net Rs. 15,000 | Paid Rs. 15,000 (PAID on 13 Aug 2026) | Due: Rs. 0";
    echo "\nAugust 2026:    Net Rs. 15,000 | Paid Rs. 0      (UNPAID)              | Due: Rs. 15,000";
    echo "\nSeptember 2026: Net Rs. 15,000 | Paid Rs. 0      (CURRENT)             | Total Due: Rs. 30,000 (Aug + Sep)\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
