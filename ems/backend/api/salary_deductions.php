<?php
function handleSalaryReportRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $rawInput = $GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_GET;
    $month = $data['month'] ?? date('Y-m');

    $role = $auth['role'];
    $isAdmin = in_array($role, ['super_admin', 'admin', 'hr', 'hr_admin', 'accounts_admin']);

    switch ($action) {
        case 'list':
            if ($isAdmin) {
                $res = getDeductionSummary($db, $month, '');
            } else {
                $res = getMyDeductionDetail($db, $auth, $month);
                if ($res['success']) {
                    $d = $res['data'];
                    $emp = $d['employee'];
                    $calc = $d['calculation'];
                    $single = [
                        'id' => $emp['id'],
                        'first_name' => $emp['first_name'] ?? '',
                        'last_name' => $emp['last_name'] ?? '',
                        'employee_code' => $emp['employee_code'] ?? '',
                        'salary' => $calc['basic_salary'],
                        'department_name' => $emp['department_name'] ?? '',
                        'designation_name' => $emp['designation_name'] ?? '',
                        'present_days' => $calc['present_days'],
                        'late_days' => $calc['late_days'],
                        'half_days' => $calc['half_days'],
                        'absent_days' => $calc['absent_days'],
                        'total_late_minutes' => 0,
                        'unpaid_leave_days' => $calc['unpaid_leave_days'] ?? 0,
                        'total_deduction' => $calc['other_deduction'],
                        'daily_rate' => $calc['daily_rate'],
                        'late_deduction' => $calc['late_deduction'],
                        'half_day_deduction' => $calc['half_day_deduction'],
                        'absent_deduction' => $calc['absent_deduction'],
                        'attendance_deduction' => $calc['attendance_deduction'],
                        'other_deduction' => $calc['other_deduction'],
                        'total_deduction' => $calc['total_deduction'],
                        'net_salary' => $calc['net_salary'],
                    ];
                    $res = ['success' => true, 'data' => [$single]];
                }
            }
            if (!$res['success']) return $res;
            $mapped = [];
            foreach ($res['data'] as $r) {
                $mapped[] = [
                    'employee' => [
                        'id' => $r['id'],
                        'name' => $r['first_name'] . ' ' . $r['last_name'],
                        'code' => $r['employee_code'],
                        'department' => $r['department_name'],
                        'designation' => $r['designation_name'] ?? '',
                    ],
                    'salary' => [
                        'basic_salary' => (float)$r['salary'],
                        'present_days' => (int)$r['present_days'],
                        'absent_days' => (int)$r['absent_days'],
                        'half_days' => (int)$r['half_days'],
                        'late_days' => (int)$r['late_days'],
                        'unpaid_leave_days' => (int)($r['unpaid_leave_days'] ?? 0),
                        'paid_leave_days' => (int)($r['paid_leave_days'] ?? 0),
                        'earned_leave_days' => 0,
                        'other_deductions' => (float)$r['other_deduction'],
                        'total_deductions' => $r['total_deduction'],
                        'total_earnings' => (float)$r['salary'],
                        'net_salary' => $r['net_salary'],
                    ],
                ];
            }
            return ['success' => true, 'data' => $mapped];
        case 'slip':
            $employeeId = $isAdmin ? intval($data['employee_id'] ?? $param) : $auth['employee_id'];
            $res = getEmployeeDeductionDetail($db, $employeeId, $month);
            if (!$res['success']) return $res;
            $d = $res['data'];
            $emp = $d['employee'];
            $calc = $d['calculation'];
            $name = ($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '');
            $dept = $emp['department_name'] ?? '';
            return [
                'success' => true,
                'data' => [
                    'employee' => [
                        'id' => $emp['id'],
                        'name' => trim($name),
                        'code' => $emp['employee_code'] ?? '',
                        'department' => $dept,
                        'designation' => $emp['designation_name'] ?? ($emp['desg_name'] ?? ''),
                    ],
                    'salary' => [
                        'basic_salary' => $calc['basic_salary'],
                        'present_days' => $calc['present_days'],
                        'late_days' => $calc['late_days'],
                        'half_days' => $calc['half_days'],
                        'absent_days' => $calc['absent_days'],
                        'unpaid_leave_days' => $calc['unpaid_leave_days'] ?? 0,
                        'paid_leave_days' => 0,
                        'earned_leave_days' => 0,
                        'other_deductions' => $calc['other_deduction'],
                        'total_deductions' => $calc['total_deduction'],
                        'total_earnings' => $calc['basic_salary'],
                        'net_salary' => $calc['net_salary'],
                    ],
                ],
            ];
        default:
            return ['success' => false, 'message' => 'Invalid salary action'];
    }
}

function handleSalaryDeductionRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();

    switch ($action) {
        case 'summary':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin', 'accounts_admin']);
            return getDeductionSummary($db, filterMonth($_GET['month'] ?? ''), $_GET['department_id'] ?? '');
        case 'employee':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin', 'accounts_admin']);
            return getEmployeeDeductionDetail($db, $param, filterMonth($_GET['month'] ?? ''));
        case 'my':
            return getMyDeductionDetail($db, $auth, filterMonth($_GET['month'] ?? ''));
        case 'daily':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin', 'accounts_admin']);
            return getDailyDeductions($db, filterDate($_GET['date'] ?? ''));
        default:
            return ['success' => false, 'message' => 'Invalid salary deduction action'];
    }
}

function getDeductionSummary($db, $month, $deptId) {
    $sql = "SELECT e.id, e.first_name, e.last_name, e.employee_code, e.salary, d.name as department_name,
                   des.name as designation_name,
                   COALESCE(att.present, 0) as present_days, COALESCE(att.late, 0) as late_days,
                   COALESCE(att.half_day, 0) as half_days, COALESCE(att.absent, 0) as absent_days,
                   COALESCE(att.total_late_minutes, 0) as total_late_minutes,
                   COALESCE(ded.amount, 0) as total_deduction
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN designations des ON des.id = e.designation_id
            LEFT JOIN (
                SELECT employee_id,
                       SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                       SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                       SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_day,
                       SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                       COALESCE(SUM(late_minutes), 0) as total_late_minutes
                FROM attendance WHERE DATE_FORMAT(attendance_date, '%Y-%m') = ? GROUP BY employee_id
            ) att ON att.employee_id = e.id
            LEFT JOIN (
                SELECT employee_id, SUM(deduction_amount) as amount
                FROM salary_deductions WHERE DATE_FORMAT(deduction_date, '%Y-%m') = ? GROUP BY employee_id
            ) ded ON ded.employee_id = e.id
            WHERE e.status = 1";
    $params = [$month, $month];
    if ($deptId) { $sql .= " AND e.department_id = ?"; $params[] = $deptId; }
    $sql .= " ORDER BY e.first_name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    foreach ($records as &$r) {
        $daily = $r['salary'] > 0 ? $r['salary'] / 30 : 0;
        // Lateness is not charged: half a day for a half day, a full day for an
        // absence, nothing for being late. This list used to take a quarter of
        // a day per late while the detail view below charged nothing, so the
        // same employee's pay differed by which screen asked for it.
        $lateDed = 0.0;
        $halfDed = $r['half_days'] * ($daily * 0.5);
        $absentDed = $r['absent_days'] * $daily;
        $attendanceDed = $lateDed + $halfDed + $absentDed;
        $otherDed = (float)$r['total_deduction'];
        $totalDeduction = $attendanceDed + $otherDed;
        $netSalary = $r['salary'] - $totalDeduction;

        $r['daily_rate'] = round($daily, 2);
        $r['late_deduction'] = round($lateDed, 2);
        $r['half_day_deduction'] = round($halfDed, 2);
        $r['absent_deduction'] = round($absentDed, 2);
        $r['attendance_deduction'] = round($attendanceDed, 2);
        $r['other_deduction'] = $otherDed;
        $r['total_deduction'] = round(max(0, $totalDeduction), 2);
        $r['net_salary'] = round(max(0, $netSalary), 2);
    }

    return ['success' => true, 'data' => $records, 'month' => $month];
}

function getEmployeeDeductionDetail($db, $employeeId, $month) {
    if (!$employeeId) return ['success' => false, 'message' => 'Employee ID required'];

    // Employee info
    $stmt = $db->prepare("SELECT e.*, d.name as department_name, des.name as designation_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN designations des ON des.id = e.designation_id WHERE e.id = ?");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    if (!$employee) return ['success' => false, 'message' => 'Employee not found'];

    // Daily attendance breakdown. `remarks` is the real column — this used to
    // read sd.reason, which does not exist, so the whole query errored.
    $stmt = $db->prepare("SELECT attendance_date, check_in, check_out, status, COALESCE(late_minutes, 0) as late_minutes, working_hours,
                                 COALESCE(sd.deduction_amount, 0) as deduction_amount, sd.remarks as deduction_remarks
                          FROM attendance
                          LEFT JOIN salary_deductions sd ON sd.employee_id = attendance.employee_id AND sd.deduction_date = attendance.attendance_date
                          WHERE attendance.employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
                          ORDER BY attendance_date ASC");
    $stmt->execute([$employeeId, $month]);
    $dailyRecords = $stmt->fetchAll();

    // Each day is graded from its own clock times by the shared rule, so an
    // admin correcting a check-in immediately changes what that day is worth,
    // and rows stored before these rules existed are still graded correctly.
    $rules = attendanceRules($db);
    $present = 0; $lateDays = 0; $halfDays = 0; $absentDays = 0; $totalLateMinutes = 0;
    $unpaidLeaveDays = 0;
    $payableDays = 0.0;

    foreach ($dailyRecords as &$record) {
        $status = attendanceStatusFor($rules, $record['check_in'], $record['check_out']);
        $stored = strtolower((string) $record['status']);

        // Leave and holidays are not judged on clock times — but which leave it
        // is decides whether it is paid. Leave Without Pay costs a full day;
        // treating every 'leave' row as paid meant LWP was being paid in full.
        // A holiday or weekly off is recognised by either field. Check-in stores
        // status='present' on those days and puts the marker in leave_type, so
        // matching on status alone sent a worked Sunday down the clock-time path
        // below: leaving before 17:00 graded it a half day and docked half a
        // day's pay. Staying at home cost nothing, so turning up to work on a
        // rest day was the more expensive choice.
        $restDay = in_array(strtolower((string) ($record['leave_type'] ?? '')),
                            ['holiday', 'weekly_off', 'week-off', 'weekoff'], true);

        if ($restDay || in_array($stored, ['leave', 'holiday', 'week-off', 'weekoff'], true)) {
            $unpaid = isUnpaidLeaveType($record['leave_type'] ?? '');
            $payableDays += $unpaid ? 0.0 : 1.0;
            $record['pay_fraction'] = $unpaid ? 0.0 : 1.0;
            $record['graded_status'] = $unpaid ? 'unpaid-leave' : $stored;
            if ($unpaid) $unpaidLeaveDays++;
            continue;
        }

        // An admin can mark a day absent on the correction form; that stands
        // regardless of any clock times left on the row.
        if ($stored === 'absent') {
            $absentDays++;
            $record['pay_fraction'] = 0.0;
            $record['graded_status'] = 'absent';
            continue;
        }

        // With no check-in there are no times to grade, so a stored half-day —
        // a half-day leave, or an admin marking one — has to be honoured.
        if (empty($record['check_in'])) {
            $status = ($stored === 'half-day') ? 'half-day' : $status;
        }

        $fraction = $status === 'half-day' ? 0.5 : 1.0;
        $payableDays += $fraction;
        $record['pay_fraction'] = $fraction;
        $record['graded_status'] = $status;

        if ($status === 'half-day') $halfDays++;
        elseif ($status === 'late') { $lateDays++; $present++; }
        else $present++;

        $totalLateMinutes += (int) $record['late_minutes'];
    }
    unset($record);

    $summary = [
        'total_days'         => count($dailyRecords),
        'present'            => $present,
        'late'               => $lateDays,
        'half_day'           => $halfDays,
        'absent'             => $absentDays,
        'unpaid_leave'       => $unpaidLeaveDays,
        'total_late_minutes' => $totalLateMinutes,
    ];

    // Other deductions (not linked to attendance)
    $stmt = $db->prepare("SELECT * FROM salary_deductions WHERE employee_id = ? AND DATE_FORMAT(deduction_date, '%Y-%m') = ? ORDER BY deduction_date DESC");
    $stmt->execute([$employeeId, $month]);
    $otherDeductions = $stmt->fetchAll();

    // A day is always the monthly salary divided by 30, in every month.
    $daily = $employee['salary'] > 0 ? $employee['salary'] / 30 : 0;

    // Lateness on its own no longer costs anything — it is recorded for
    // reporting only. A day is either full, half (late in past half_day_time or
    // out before half_day_checkout_time), or absent.
    $lateDed = 0.0;
    $halfDed = $halfDays * ($daily * 0.5);
    $absentDed = $absentDays * $daily;
    // Leave Without Pay costs the same as an absence, but is reported separately
    // so it reads as approved leave rather than absenteeism.
    $unpaidLeaveDed = $unpaidLeaveDays * $daily;
    $attendanceDed = $halfDed + $absentDed + $unpaidLeaveDed;

    $otherDedTotal = 0;
    foreach ($otherDeductions as $d) $otherDedTotal += (float)$d['deduction_amount'];

    $totalDeduction = $attendanceDed + $otherDedTotal;
    $netSalary = $employee['salary'] - $totalDeduction;

    return [
        'success' => true,
        'data' => [
            'employee' => $employee,
            'summary' => $summary,
            'daily_records' => $dailyRecords,
            'other_deductions' => $otherDeductions,
            'calculation' => [
                'month' => $month,
                'basic_salary' => (float)$employee['salary'],
                'daily_rate' => round($daily, 2),
                'present_days' => (int)$summary['present'],
                'late_days' => (int)$summary['late'],
                'half_days' => (int)$summary['half_day'],
                'absent_days' => (int)$summary['absent'],
                'payable_days' => round($payableDays, 2),
                'half_day_after' => $rules['half_day_time'],
                'early_checkout_before' => $rules['half_day_checkout_time'],
                'late_deduction' => round($lateDed, 2), // always 0; late is recorded, not charged
                'half_day_deduction' => round($halfDed, 2),
                'absent_deduction' => round($absentDed, 2),
                'unpaid_leave_days' => $unpaidLeaveDays,
                'unpaid_leave_deduction' => round($unpaidLeaveDed, 2),
                'attendance_deduction' => round($attendanceDed, 2),
                'other_deduction' => round($otherDedTotal, 2),
                'total_deduction' => round(max(0, $totalDeduction), 2),
                'net_salary' => round(max(0, $netSalary), 2),
            ],
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
