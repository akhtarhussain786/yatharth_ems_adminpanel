<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/payroll_helper.php';

try {
    $db = (new Database())->getConnection();
    echo "=== DATABASE SALARY AUDIT ===\n";
    
    // 1. Check Akhtar Hussain Employee & User
    $stEmp = $db->query("SELECT e.id, e.user_id, e.first_name, e.last_name, e.employee_code, e.salary, e.joining_date, e.status, u.role_id, r.name as role_name 
                         FROM employees e 
                         LEFT JOIN users u ON u.id = e.user_id 
                         LEFT JOIN roles r ON r.id = u.role_id 
                         WHERE e.employee_code = 'YGI001' OR e.first_name LIKE '%Akhtar%'");
    $emp = $stEmp->fetch(PDO::FETCH_ASSOC);
    echo "Employee Data:\n";
    print_r($emp);
    
    $empId = $emp['id'] ?? 12;

    // 2. Check salary_processing records for Akhtar Hussain
    echo "\n=== SALARY PROCESSING TABLE RECORDS ===\n";
    $stProc = $db->prepare("SELECT id, employee_id, month_year, payroll_month, base_earned_salary, basic_salary, current_net_salary, net_salary, previous_due, total_payable, paid_amount, remaining_due, payment_status, lock_status, notes FROM salary_processing WHERE employee_id = ? ORDER BY month_year ASC");
    $stProc->execute([$empId]);
    $procRows = $stProc->fetchAll(PDO::FETCH_ASSOC);
    print_r($procRows);

    // 3. Check salary_payments table
    echo "\n=== SALARY PAYMENTS TABLE RECORDS ===\n";
    $stPay = $db->prepare("SELECT * FROM salary_payments WHERE employee_id = ?");
    $stPay->execute([$empId]);
    $payRows = $stPay->fetchAll(PDO::FETCH_ASSOC);
    print_r($payRows);

    // 4. Test calculateEmployeeSalary for 2026-07, 2026-08, 2026-09
    foreach (['2026-07', '2026-08', '2026-09'] as $m) {
        echo "\n=== calculateEmployeeSalary for $m ===\n";
        $calc = calculateEmployeeSalary($db, $empId, $m);
        echo "Success: " . ($calc['success'] ? 'true' : 'false') . "\n";
        if (!$calc['success']) {
            echo "Error: " . ($calc['message'] ?? 'Unknown error') . "\n";
        } else {
            echo "Employed: " . ($calc['is_employed_this_month'] ? 'Yes' : 'No (' . ($calc['not_employed_reason'] ?? '') . ')') . "\n";
            echo "Base Earned: " . ($calc['base_earned_salary'] ?? 0) . "\n";
            echo "Deductions: " . ($calc['total_deductions'] ?? 0) . "\n";
            echo "Current Net: " . ($calc['current_net_salary'] ?? 0) . "\n";
            echo "Previous Due: " . ($calc['previous_due'] ?? 0) . "\n";
            echo "Total Payable: " . ($calc['total_payable'] ?? 0) . "\n";
            echo "Paid Amount: " . ($calc['paid_amount'] ?? 0) . "\n";
            echo "Remaining Due: " . ($calc['remaining_due'] ?? 0) . "\n";
            echo "Payment Status: " . ($calc['payment_status'] ?? '') . "\n";
        }
    }

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
