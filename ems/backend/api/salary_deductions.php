<?php
require_once __DIR__ . '/../helpers/payroll_helper.php';

function handleSalaryReportRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $rawInput = $GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_GET;
    $month = filterMonth($data['month'] ?? ($data['month_year'] ?? date('Y-m')));

    $role = $auth['role'];
    $isAdmin = in_array($role, ['super_admin', 'admin', 'hr', 'hr_admin', 'accounts_admin']);

    switch ($action) {
        case 'list':
            $deptId = $data['department_id'] ?? '';
            $status = $data['status'] ?? '';

            if ($isAdmin) {
                $sql = "SELECT id FROM employees WHERE status = 1";
                $params = [];
                if ($deptId) {
                    $sql .= " AND department_id = ?";
                    $params[] = $deptId;
                }
                $sql .= " ORDER BY first_name ASC";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $empRows = $stmt->fetchAll();

                $mapped = [];
                foreach ($empRows as $er) {
                    $calc = calculateEmployeeSalary($db, (int)$er['id'], $month);
                    if ($calc['success']) {
                        $emp = $calc['employee'];
                        $mapped[] = [
                            'employee' => [
                                'id'           => (int)$emp['id'],
                                'name'         => trim($emp['first_name'] . ' ' . ($emp['last_name'] ?? '')),
                                'code'         => $emp['employee_code'] ?? '',
                                'department'   => $emp['department_name'] ?? '',
                                'designation'  => $emp['designation_name'] ?? '',
                                'joining_date' => $emp['joining_date'] ?? null,
                            ],
                            'salary' => [
                                'monthly_salary'       => (float)$calc['monthly_salary'],
                                'basic_salary'         => (float)$calc['monthly_salary'],
                                'eligible_days'        => (int)$calc['eligible_days'],
                                'total_days_in_month'  => (int)$calc['total_days_in_month'],
                                'daily_rate'           => (float)$calc['daily_rate'],
                                'base_earned_salary'   => (float)$calc['base_earned_salary'],
                                'present_days'         => (float)$calc['present_days'],
                                'absent_days'          => (float)$calc['absent_days'],
                                'half_days'            => (float)$calc['half_days'],
                                'late_days'            => (float)$calc['late_days'],
                                'unpaid_leave_days'    => (float)$calc['unpaid_leave_days'],
                                'paid_leave_days'      => (float)$calc['paid_leave_days'],
                                'earned_leave_days'    => (float)$calc['earned_leave_days'],
                                'weekly_off_days'      => (float)$calc['weekly_off_days'],
                                'holiday_days'         => (float)$calc['holiday_days'],
                                'attendance_deduction' => (float)$calc['attendance_deduction'],
                                'other_deductions'     => (float)$calc['other_deductions'],
                                'total_deductions'     => (float)$calc['total_deductions'],
                                'bonus_amount'         => (float)$calc['bonus_amount'],
                                'total_earnings'       => (float)$calc['total_earnings'],
                                'current_net_salary'   => (float)$calc['current_net_salary'],
                                'net_salary'           => (float)$calc['current_net_salary'],
                                'previous_due'         => (float)$calc['previous_due'],
                                'total_payable'        => (float)$calc['total_payable'],
                                'paid_amount'          => (float)$calc['paid_amount'],
                                'remaining_due'        => (float)$calc['remaining_due'],
                                'payment_status'       => $calc['payment_status'],
                                'lock_status'          => $calc['lock_status'],
                                'payroll_id'           => $calc['payroll_id'],
                            ],
                            'running_salary' => $calc['running_salary'],
                        ];
                    }
                }
                return ['success' => true, 'data' => $mapped, 'month' => $month];
            } else {
                $calc = calculateEmployeeSalary($db, (int)$auth['employee_id'], $month);
                if (!$calc['success']) return $calc;
                $emp = $calc['employee'];
                $single = [
                    'employee' => [
                        'id'           => (int)$emp['id'],
                        'name'         => trim($emp['first_name'] . ' ' . ($emp['last_name'] ?? '')),
                        'code'         => $emp['employee_code'] ?? '',
                        'department'   => $emp['department_name'] ?? '',
                        'designation'  => $emp['designation_name'] ?? '',
                        'joining_date' => $emp['joining_date'] ?? null,
                    ],
                    'salary' => [
                        'monthly_salary'       => (float)$calc['monthly_salary'],
                        'basic_salary'         => (float)$calc['monthly_salary'],
                        'eligible_days'        => (int)$calc['eligible_days'],
                        'total_days_in_month'  => (int)$calc['total_days_in_month'],
                        'daily_rate'           => (float)$calc['daily_rate'],
                        'base_earned_salary'   => (float)$calc['base_earned_salary'],
                        'present_days'         => (float)$calc['present_days'],
                        'absent_days'          => (float)$calc['absent_days'],
                        'half_days'            => (float)$calc['half_days'],
                        'late_days'            => (float)$calc['late_days'],
                        'unpaid_leave_days'    => (float)$calc['unpaid_leave_days'],
                        'paid_leave_days'      => (float)$calc['paid_leave_days'],
                        'earned_leave_days'    => (float)$calc['earned_leave_days'],
                        'attendance_deduction' => (float)$calc['attendance_deduction'],
                        'other_deductions'     => (float)$calc['other_deductions'],
                        'total_deductions'     => (float)$calc['total_deductions'],
                        'total_earnings'       => (float)$calc['total_earnings'],
                        'current_net_salary'   => (float)$calc['current_net_salary'],
                        'net_salary'           => (float)$calc['current_net_salary'],
                        'previous_due'         => (float)$calc['previous_due'],
                        'total_payable'        => (float)$calc['total_payable'],
                        'paid_amount'          => (float)$calc['paid_amount'],
                        'remaining_due'        => (float)$calc['remaining_due'],
                        'payment_status'       => $calc['payment_status'],
                        'lock_status'          => $calc['lock_status'],
                    ],
                    'running_salary' => $calc['running_salary'],
                ];
                return ['success' => true, 'data' => [$single], 'month' => $month];
            }

        case 'slip':
            $employeeId = $isAdmin ? intval($data['employee_id'] ?? $param) : (int)$auth['employee_id'];
            $calc = calculateEmployeeSalary($db, $employeeId, $month);
            if (!$calc['success']) return $calc;

            $emp = $calc['employee'];
            $name = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
            return [
                'success' => true,
                'data' => [
                    'employee' => [
                        'id'           => (int)$emp['id'],
                        'name'         => $name,
                        'code'         => $emp['employee_code'] ?? '',
                        'department'   => $emp['department_name'] ?? '',
                        'designation'  => $emp['designation_name'] ?? ($emp['desg_name'] ?? ''),
                        'joining_date' => $emp['joining_date'] ?? null,
                    ],
                    'salary' => [
                        'monthly_salary'       => (float)$calc['monthly_salary'],
                        'basic_salary'         => (float)$calc['monthly_salary'],
                        'eligible_days'        => (int)$calc['eligible_days'],
                        'total_days_in_month'  => (int)$calc['total_days_in_month'],
                        'daily_rate'           => (float)$calc['daily_rate'],
                        'base_earned_salary'   => (float)$calc['base_earned_salary'],
                        'present_days'         => (float)$calc['present_days'],
                        'late_days'            => (float)$calc['late_days'],
                        'half_days'            => (float)$calc['half_days'],
                        'absent_days'          => (float)$calc['absent_days'],
                        'unpaid_leave_days'    => (float)$calc['unpaid_leave_days'],
                        'paid_leave_days'      => (float)$calc['paid_leave_days'],
                        'earned_leave_days'    => (float)$calc['earned_leave_days'],
                        'weekly_off_days'      => (float)$calc['weekly_off_days'],
                        'holiday_days'         => (float)$calc['holiday_days'],
                        'attendance_deduction' => (float)$calc['attendance_deduction'],
                        'other_deductions'     => (float)$calc['other_deductions'],
                        'total_deductions'     => (float)$calc['total_deductions'],
                        'bonus_amount'         => (float)$calc['bonus_amount'],
                        'total_earnings'       => (float)$calc['total_earnings'],
                        'current_net_salary'   => (float)$calc['current_net_salary'],
                        'net_salary'           => (float)$calc['current_net_salary'],
                        'previous_due'         => (float)$calc['previous_due'],
                        'total_payable'        => (float)$calc['total_payable'],
                        'paid_amount'          => (float)$calc['paid_amount'],
                        'remaining_due'        => (float)$calc['remaining_due'],
                        'payment_status'       => $calc['payment_status'],
                        'lock_status'          => $calc['lock_status'],
                        'payroll_id'           => $calc['payroll_id'],
                    ],
                    'running_salary' => $calc['running_salary'],
                ],
            ];

        case 'history':
            $employeeId = $isAdmin ? intval($data['employee_id'] ?? $param) : (int)$auth['employee_id'];
            if (!$employeeId) return ['success' => false, 'message' => 'Employee ID required'];

            $stmt = $db->prepare("
                SELECT * FROM salary_processing
                WHERE employee_id = ?
                ORDER BY month_year DESC
            ");
            $stmt->execute([$employeeId]);
            $history = $stmt->fetchAll();
            return ['success' => true, 'data' => $history];

        case 'payments':
            $employeeId = $isAdmin ? intval($data['employee_id'] ?? $param) : (int)$auth['employee_id'];
            if (!$employeeId) return ['success' => false, 'message' => 'Employee ID required'];

            $payrollId = !empty($data['payroll_id']) ? (int)$data['payroll_id'] : null;
            $sql = "
                SELECT sp.*, u.username as created_by_name
                FROM salary_payments sp
                LEFT JOIN users u ON u.id = sp.created_by
                WHERE sp.employee_id = ?
            ";
            $params = [$employeeId];
            if ($payrollId) {
                $sql .= " AND sp.payroll_id = ?";
                $params[] = $payrollId;
            }
            $sql .= " ORDER BY sp.payment_date DESC, sp.id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return ['success' => true, 'data' => $stmt->fetchAll()];

        case 'pay':
            AuthMiddleware::checkRole(['super_admin', 'admin', 'hr', 'hr_admin', 'accounts_admin']);
            $employeeId = (int)($data['employee_id'] ?? 0);
            $amount = (float)($data['amount'] ?? 0);
            $paymentDate = filterDate($data['payment_date'] ?? date('Y-m-d'));
            $paymentMethod = $data['payment_method'] ?? 'bank_transfer';
            $referenceNo = $data['reference_no'] ?? '';
            $notes = $data['notes'] ?? '';
            $targetPayrollId = !empty($data['payroll_id']) ? (int)$data['payroll_id'] : null;

            if (!$employeeId || $amount <= 0) {
                return ['success' => false, 'message' => 'Employee ID and valid payment amount are required.'];
            }

            return recordSalaryPayment($db, $employeeId, $amount, $paymentDate, $paymentMethod, $referenceNo, $notes, $auth['user_id'], $targetPayrollId);

        case 'running':
            $employeeId = $isAdmin ? intval($data['employee_id'] ?? $param) : (int)$auth['employee_id'];
            $calc = calculateEmployeeSalary($db, $employeeId, date('Y-m'));
            return ['success' => true, 'data' => $calc['running_salary'] ?? null];

        case 'lock':
            AuthMiddleware::checkRole(['super_admin', 'admin', 'hr', 'hr_admin', 'accounts_admin']);
            $payrollId = (int)($data['payroll_id'] ?? $param);
            if (!$payrollId) return ['success' => false, 'message' => 'Payroll ID required'];

            $stmt = $db->prepare("UPDATE salary_processing SET lock_status = 'finalized', finalized_by = ?, finalized_at = NOW() WHERE id = ?");
            $stmt->execute([$auth['user_id'], $payrollId]);
            logSalaryAudit($db, $payrollId, 0, 'payroll_finalized', null, null, $auth['user_id']);
            return ['success' => true, 'message' => 'Payroll record finalized and locked successfully.'];

        case 'reopen':
            AuthMiddleware::checkRole(['super_admin', 'admin', 'hr', 'hr_admin', 'accounts_admin']);
            $payrollId = (int)($data['payroll_id'] ?? $param);
            if (!$payrollId) return ['success' => false, 'message' => 'Payroll ID required'];

            $stmt = $db->prepare("UPDATE salary_processing SET lock_status = 'generated', finalized_by = NULL, finalized_at = NULL WHERE id = ?");
            $stmt->execute([$payrollId]);
            logSalaryAudit($db, $payrollId, 0, 'payroll_reopened', null, json_encode(['reason' => $data['reason'] ?? 'Admin reopen']), $auth['user_id']);
            return ['success' => true, 'message' => 'Payroll record reopened for editing.'];

        default:
            return ['success' => false, 'message' => 'Invalid salary action'];
    }
}

function handleSalaryDeductionRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();

    switch ($action) {
        case 'summary':
            AuthMiddleware::checkRole(['super_admin', 'admin', 'hr', 'hr_admin', 'accounts_admin']);
            return getDeductionSummary($db, filterMonth($_GET['month'] ?? ''), $_GET['department_id'] ?? '');
        case 'employee':
            AuthMiddleware::checkRole(['super_admin', 'admin', 'hr', 'hr_admin', 'accounts_admin']);
            return getEmployeeDeductionDetail($db, $param, filterMonth($_GET['month'] ?? ''));
        case 'my':
            return getMyDeductionDetail($db, $auth, filterMonth($_GET['month'] ?? ''));
        case 'daily':
            AuthMiddleware::checkRole(['super_admin', 'admin', 'hr', 'hr_admin', 'accounts_admin']);
            return getDailyDeductions($db, filterDate($_GET['date'] ?? ''));
        default:
            return ['success' => false, 'message' => 'Invalid salary deduction action'];
    }
}

function getDeductionSummary($db, $month, $deptId) {
    $sql = "SELECT id FROM employees WHERE status = 1";
    $params = [];
    if ($deptId) {
        $sql .= " AND department_id = ?";
        $params[] = $deptId;
    }
    $sql .= " ORDER BY first_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $empRows = $stmt->fetchAll();

    $records = [];
    foreach ($empRows as $er) {
        $calc = calculateEmployeeSalary($db, (int)$er['id'], $month);
        if ($calc['success']) {
            $emp = $calc['employee'];
            $records[] = [
                'id'                   => $emp['id'],
                'first_name'           => $emp['first_name'],
                'last_name'            => $emp['last_name'],
                'employee_code'        => $emp['employee_code'],
                'salary'               => $calc['monthly_salary'],
                'department_name'      => $emp['department_name'],
                'designation_name'     => $emp['designation_name'],
                'present_days'         => $calc['present_days'],
                'late_days'            => $calc['late_days'],
                'half_days'            => $calc['half_days'],
                'absent_days'          => $calc['absent_days'],
                'unpaid_leave_days'    => $calc['unpaid_leave_days'],
                'paid_leave_days'      => $calc['paid_leave_days'],
                'total_late_minutes'   => $calc['total_late_minutes'],
                'daily_rate'           => $calc['daily_rate'],
                'late_deduction'       => $calc['late_deduction'],
                'half_day_deduction'   => $calc['half_day_deduction'],
                'absent_deduction'     => $calc['absent_deduction'],
                'attendance_deduction' => $calc['attendance_deduction'],
                'other_deduction'      => $calc['other_deductions'],
                'total_deduction'      => $calc['total_deductions'],
                'current_net_salary'   => $calc['current_net_salary'],
                'net_salary'           => $calc['current_net_salary'],
                'previous_due'         => $calc['previous_due'],
                'total_payable'        => $calc['total_payable'],
                'paid_amount'          => $calc['paid_amount'],
                'remaining_due'        => $calc['remaining_due'],
                'payment_status'       => $calc['payment_status'],
            ];
        }
    }

    return ['success' => true, 'data' => $records, 'month' => $month];
}

function getEmployeeDeductionDetail($db, $employeeId, $month) {
    if (!$employeeId) return ['success' => false, 'message' => 'Employee ID required'];

    $calc = calculateEmployeeSalary($db, (int)$employeeId, $month);
    if (!$calc['success']) return $calc;

    $emp = $calc['employee'];

    // Daily attendance breakdown within eligible window
    $stmt = $db->prepare("
        SELECT attendance_date, check_in, check_out, status, leave_type,
               COALESCE(late_minutes, 0) as late_minutes, working_hours,
               COALESCE(sd.deduction_amount, 0) as deduction_amount, sd.remarks as deduction_remarks
        FROM attendance
        LEFT JOIN salary_deductions sd ON sd.employee_id = attendance.employee_id AND sd.deduction_date = attendance.attendance_date
        WHERE attendance.employee_id = ?
          AND attendance_date >= ? AND attendance_date <= ?
        ORDER BY attendance_date ASC
    ");
    $stmt->execute([$employeeId, $calc['effective_start_date'], $calc['effective_end_date']]);
    $dailyRecords = $stmt->fetchAll();

    $stmtDed = $db->prepare("SELECT * FROM salary_deductions WHERE employee_id = ? AND DATE_FORMAT(deduction_date, '%Y-%m') = ? ORDER BY deduction_date DESC");
    $stmtDed->execute([$employeeId, $month]);
    $otherDeductions = $stmtDed->fetchAll();

    return [
        'success' => true,
        'data' => [
            'employee' => $emp,
            'summary' => [
                'total_days'         => count($dailyRecords),
                'present'            => $calc['present_days'],
                'late'               => $calc['late_days'],
                'half_day'           => $calc['half_days'],
                'absent'             => $calc['absent_days'],
                'unpaid_leave'       => $calc['unpaid_leave_days'],
                'total_late_minutes' => $calc['total_late_minutes'],
            ],
            'daily_records' => $dailyRecords,
            'other_deductions' => $otherDeductions,
            'calculation' => [
                'month'                  => $month,
                'monthly_salary'         => $calc['monthly_salary'],
                'basic_salary'           => $calc['monthly_salary'],
                'eligible_days'          => $calc['eligible_days'],
                'daily_rate'             => $calc['daily_rate'],
                'base_earned_salary'     => $calc['base_earned_salary'],
                'present_days'           => $calc['present_days'],
                'late_days'              => $calc['late_days'],
                'half_days'              => $calc['half_days'],
                'absent_days'            => $calc['absent_days'],
                'unpaid_leave_days'      => $calc['unpaid_leave_days'],
                'late_deduction'         => $calc['late_deduction'],
                'half_day_deduction'     => $calc['half_day_deduction'],
                'absent_deduction'       => $calc['absent_deduction'],
                'attendance_deduction'   => $calc['attendance_deduction'],
                'other_deduction'        => $calc['other_deductions'],
                'total_deduction'        => $calc['total_deductions'],
                'current_net_salary'     => $calc['current_net_salary'],
                'net_salary'             => $calc['current_net_salary'],
                'previous_due'           => $calc['previous_due'],
                'total_payable'          => $calc['total_payable'],
                'paid_amount'            => $calc['paid_amount'],
                'remaining_due'          => $calc['remaining_due'],
                'payment_status'         => $calc['payment_status'],
            ],
            'running_salary' => $calc['running_salary'],
        ],
    ];
}

function getMyDeductionDetail($db, $auth, $month) {
    return getEmployeeDeductionDetail($db, $auth['employee_id'], $month);
}

function getDailyDeductions($db, $date) {
    $sql = "SELECT sd.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
            FROM salary_deductions sd
            JOIN employees e ON e.id = sd.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE sd.deduction_date = ?
            ORDER BY e.first_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$date]);
    return ['success' => true, 'data' => $stmt->fetchAll(), 'date' => $date];
}
