<?php
function handleReportRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'monthly':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin']);
            return monthlyReport($db, $param);
        case 'employee':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin']);
            return employeeReport($db, $param);
        case 'late':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin']);
            return lateReport($db);
        case 'absent':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin']);
            return absentReport($db);
        case 'salary':
            AuthMiddleware::checkRole(['super_admin', 'hr', 'hr_admin', 'accounts_admin']);
            return salaryReport($db, $param);
        case 'work':
            PermissionHelper::checkPermission($db, $auth, 'daily_work_reports', 'can_view');
            return workReport($db, $data);
        case 'task':
            PermissionHelper::checkPermission($db, $auth, 'tasks', 'can_view');
            return taskReport($db, $data);
        case 'lead':
            PermissionHelper::checkPermission($db, $auth, 'leads', 'can_view');
            return leadReport($db, $data);
        case 'call':
            PermissionHelper::checkPermission($db, $auth, 'call_reports', 'can_view');
            return callReport($db, $data);
        case 'employee_list':
            return getEmployeesForReport($db, $data);
        case 'export_data':
            return exportReportData($db, $auth, $data);
        default:
            return ['success' => false, 'message' => 'Invalid report action'];
    }
}

function monthlyReport($db, $month) {
    $month = $month ?: date('Y-m');
    $stmt = $db->prepare("
        SELECT a.*, e.first_name, e.last_name, e.employee_code, e.department_id, e.designation_id,
               d.name as department_name, des.name as designation_name
        FROM attendance a
        JOIN employees e ON e.id = a.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN designations des ON des.id = e.designation_id
        WHERE DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
        ORDER BY a.attendance_date DESC, e.first_name ASC
    ");
    $stmt->execute([$month]);
    return ['success' => true, 'data' => $stmt->fetchAll(), 'month' => $month];
}

function employeeReport($db, $employeeId) {
    if (!$employeeId) return ['success' => false, 'message' => 'Employee ID required'];
    $month = filterMonth($_GET['month'] ?? '');
    $stmt = $db->prepare("SELECT a.*, e.first_name, e.last_name, e.employee_code, e.salary FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.employee_id = ? AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ? ORDER BY a.attendance_date DESC");
    $stmt->execute([$employeeId, $month]);
    $records = $stmt->fetchAll();
    $stmt = $db->prepare("SELECT COUNT(*) as total_days, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late, SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_day, SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent, COALESCE(SUM(late_minutes), 0) as total_late_minutes FROM attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?");
    $stmt->execute([$employeeId, $month]);
    $summary = $stmt->fetch();
    $stmt = $db->prepare("SELECT COALESCE(SUM(deduction_amount), 0) as total_deduction FROM salary_deductions WHERE employee_id = ? AND DATE_FORMAT(deduction_date, '%Y-%m') = ?");
    $stmt->execute([$employeeId, $month]);
    return ['success' => true, 'data' => ['records' => $records, 'summary' => $summary, 'total_deduction' => $stmt->fetch()['total_deduction']]];
}

function lateReport($db) {
    $date = filterDate($_GET['date'] ?? '');
    $stmt = $db->prepare("SELECT a.*, e.first_name, e.last_name, e.employee_code, e.department_id, e.designation_id, d.name as department_name FROM attendance a JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE a.status = 'late' AND a.attendance_date = ? ORDER BY a.late_minutes DESC");
    $stmt->execute([$date]);
    return ['success' => true, 'data' => $stmt->fetchAll(), 'date' => $date];
}

function absentReport($db) {
    $date = filterDate($_GET['date'] ?? '');
    $stmt = $db->prepare("SELECT e.id, e.first_name, e.last_name, e.employee_code, e.department_id, e.designation_id, d.name as department_name, des.name as designation_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN designations des ON des.id = e.designation_id WHERE e.status = 1 AND e.id NOT IN (SELECT employee_id FROM attendance WHERE attendance_date = ?) ORDER BY e.first_name ASC");
    $stmt->execute([$date]);
    return ['success' => true, 'data' => $stmt->fetchAll(), 'date' => $date];
}

function salaryReport($db, $month) {
    $month = $month ?: date('Y-m');
    $stmt = $db->prepare("SELECT e.id, e.first_name, e.last_name, e.employee_code, e.salary, d.name as department_name, COALESCE(att.present, 0) as present_days, COALESCE(att.late, 0) as late_days, COALESCE(att.half_day, 0) as half_days, COALESCE(att.absent, 0) as absent_days, COALESCE(ded.total_deduction, 0) as total_deduction FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN (SELECT employee_id, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late, SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_day, SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent FROM attendance WHERE DATE_FORMAT(attendance_date, '%Y-%m') = ? GROUP BY employee_id) att ON att.employee_id = e.id LEFT JOIN (SELECT employee_id, SUM(deduction_amount) as total_deduction FROM salary_deductions WHERE DATE_FORMAT(deduction_date, '%Y-%m') = ? GROUP BY employee_id) ded ON ded.employee_id = e.id WHERE e.status = 1 ORDER BY e.first_name ASC");
    $stmt->execute([$month, $month]);
    return ['success' => true, 'data' => $stmt->fetchAll(), 'month' => $month];
}

function workReport($db, $data) {
    $dept = $data['department_id'] ?? '';
    $emp = $data['employee_id'] ?? '';
    $start = $data['start_date'] ?? date('Y-m-01');
    $end = $data['end_date'] ?? date('Y-m-t');
    $status = $data['status'] ?? '';

    $sql = "SELECT w.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM daily_work_reports w JOIN employees e ON e.id = w.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE w.report_date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($dept) { $sql .= " AND e.department_id = ?"; $params[] = $dept; }
    if ($emp) { $sql .= " AND w.employee_id = ?"; $params[] = $emp; }
    if ($status) { $sql .= " AND w.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY w.report_date DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function taskReport($db, $data) {
    $dept = $data['department_id'] ?? '';
    $emp = $data['employee_id'] ?? '';
    $status = $data['status'] ?? '';

    $sql = "SELECT t.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM tasks t JOIN employees e ON e.id = t.assigned_to LEFT JOIN departments d ON d.id = e.department_id WHERE 1=1";
    $params = [];
    if ($dept) { $sql .= " AND e.department_id = ?"; $params[] = $dept; }
    if ($emp) { $sql .= " AND t.assigned_to = ?"; $params[] = $emp; }
    if ($status) { $sql .= " AND t.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY t.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function leadReport($db, $data) {
    $emp = $data['employee_id'] ?? '';
    $status = $data['status'] ?? '';
    $start = $data['start_date'] ?? date('Y-m-01');
    $end = $data['end_date'] ?? date('Y-m-t');

    $sql = "SELECT l.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM leads l JOIN employees e ON e.id = l.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE DATE(l.created_at) BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($emp) { $sql .= " AND l.employee_id = ?"; $params[] = $emp; }
    if ($status) { $sql .= " AND l.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY l.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function callReport($db, $data) {
    $emp = $data['employee_id'] ?? '';
    $start = $data['start_date'] ?? date('Y-m-01');
    $end = $data['end_date'] ?? date('Y-m-t');

    $sql = "SELECT c.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM call_reports c JOIN employees e ON e.id = c.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE c.call_date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($emp) { $sql .= " AND c.employee_id = ?"; $params[] = $emp; }
    $sql .= " ORDER BY c.call_date DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getEmployeesForReport($db, $data) {
    $deptId = $data['department_id'] ?? '';
    $sql = "SELECT e.id, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.status = 1";
    $params = [];
    if ($deptId) { $sql .= " AND e.department_id = ?"; $params[] = $deptId; }
    $sql .= " ORDER BY e.first_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function exportReportData($db, $auth, $data) {
    $type = $data['report_type'] ?? 'leads';
    $startDate = $data['start_date'] ?? date('Y-m-01');
    $endDate = $data['end_date'] ?? date('Y-m-d');
    $status = $data['status'] ?? '';
    $employeeId = $data['employee_id'] ?? '';
    $role = $auth['role'];
    $authEid = $auth['employee_id'];
    $isAdmin = in_array($role, ['super_admin', 'admin', 'hr_admin', 'sales_admin', 'telecaller_admin', 'digital_marketing_admin', 'accounts_admin'], true);

    $headers = [];
    $rows = [];
    $groups = [];

    switch ($type) {
        case 'leads':
            $headers = ['ID', 'Customer Name', 'Phone', 'Email', 'Company', 'City', 'Source', 'Campaign', 'Requirement', 'Budget (INR)', 'Priority', 'Status', 'Created By', 'Assigned Telecaller', 'Assigned Sales', 'Created Date'];
            
            // Streamlined headers for creator-specific pages (omits redundant "Created By")
            $groupHeaders = ['ID', 'Customer Name', 'Phone', 'City', 'Source', 'Requirement', 'Priority', 'Status', 'Assigned Staff', 'Created Date'];

            $sql = "SELECT l.*, 
                           cr.id as cr_id, cr.first_name as cr_first, cr.last_name as cr_last, cr.employee_code as cr_code,
                           a.first_name as a_first, a.last_name as a_last,
                           s.first_name as s_first, s.last_name as s_last
                    FROM leads l
                    LEFT JOIN employees cr ON cr.id = COALESCE(l.created_by, l.employee_id)
                    LEFT JOIN employees a ON a.id = l.assigned_to
                    LEFT JOIN employees s ON s.id = l.assigned_sales
                    WHERE DATE(l.created_at) BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
            if ($status !== '') { $sql .= " AND l.status = ?"; $params[] = $status; }
            if (!$isAdmin) {
                $sql .= " AND (l.employee_id = ? OR l.created_by = ? OR l.assigned_to = ? OR l.assigned_sales = ?)";
                $params[] = $authEid; $params[] = $authEid; $params[] = $authEid; $params[] = $authEid;
            }
            $sql .= " ORDER BY cr.first_name ASC, cr.last_name ASC, l.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $groupedByCreator = [];
            foreach ($stmt->fetchAll() as $r) {
                $creatorName = trim(($r['cr_first'] ?? '') . ' ' . ($r['cr_last'] ?? ''));
                if (empty($creatorName)) {
                    $creatorName = 'Direct / Unassigned';
                }
                $creatorCode = $r['cr_code'] ?? '';
                $creatorKey = !empty($r['cr_id']) ? (string)$r['cr_id'] : ('unknown_' . $creatorName);

                if (!isset($groupedByCreator[$creatorKey])) {
                    $groupedByCreator[$creatorKey] = [
                        'creator_id' => $r['cr_id'] ?? null,
                        'creator_name' => $creatorName,
                        'employee_code' => $creatorCode,
                        'headers' => $groupHeaders,
                        'rows' => [],
                    ];
                }

                $assignedStaff = trim(($r['a_first'] ?? '') . ' ' . ($r['a_last'] ?? ''));
                if (empty($assignedStaff) && !empty($r['s_first'])) {
                    $assignedStaff = trim(($r['s_first'] ?? '') . ' ' . ($r['s_last'] ?? '')) . ' (Sales)';
                }

                // Row for Creator-dedicated PDF Page
                $groupedByCreator[$creatorKey]['rows'][] = [
                    $r['id'],
                    $r['customer_name'] ?? $r['first_name'] ?? '',
                    $r['customer_phone'] ?? $r['mobile'] ?? '',
                    $r['city'] ?? '--',
                    $r['lead_source'] ?? $r['source'] ?? 'Direct',
                    $r['requirement'] ?? '--',
                    ucfirst($r['priority'] ?? 'Medium'),
                    strtoupper($r['status'] ?? 'New'),
                    $assignedStaff ?: '--',
                    !empty($r['created_at']) ? date('d M Y', strtotime($r['created_at'])) : '',
                ];

                // Flat Row for Excel/CSV
                $rows[] = [
                    $r['id'],
                    $r['customer_name'] ?? $r['first_name'] ?? '',
                    $r['customer_phone'] ?? $r['mobile'] ?? '',
                    $r['customer_email'] ?? $r['email'] ?? '',
                    $r['company_name'] ?? '',
                    $r['city'] ?? '',
                    $r['lead_source'] ?? $r['source'] ?? '',
                    $r['campaign_name'] ?? '',
                    $r['requirement'] ?? '',
                    $r['budget'] ?? '0',
                    ucfirst($r['priority'] ?? 'Medium'),
                    strtoupper($r['status'] ?? 'New'),
                    $creatorName,
                    trim(($r['a_first'] ?? '') . ' ' . ($r['a_last'] ?? '')),
                    trim(($r['s_first'] ?? '') . ' ' . ($r['s_last'] ?? '')),
                    $r['created_at'] ?? '',
                ];
            }

            $groups = array_values($groupedByCreator);
            break;

        case 'attendance':
            $headers = ['ID', 'Employee Code', 'Employee Name', 'Department', 'Designation', 'Date', 'Check In', 'Check Out', 'Working Hours', 'Late Minutes', 'Status', 'Distance (m)', 'Remarks'];
            $sql = "SELECT a.*, e.first_name, e.last_name, e.employee_code, d.name as dept_name, des.name as desig_name
                    FROM attendance a
                    JOIN employees e ON e.id = a.employee_id
                    LEFT JOIN departments d ON d.id = e.department_id
                    LEFT JOIN designations des ON des.id = e.designation_id
                    WHERE a.attendance_date BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
            if ($status !== '') { $sql .= " AND a.status = ?"; $params[] = $status; }
            if ($employeeId !== '') { $sql .= " AND a.employee_id = ?"; $params[] = intval($employeeId); }
            elseif (!$isAdmin) { $sql .= " AND a.employee_id = ?"; $params[] = $authEid; }
            $sql .= " ORDER BY a.attendance_date DESC, e.first_name ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['id'],
                    $r['employee_code'] ?? '',
                    trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                    $r['dept_name'] ?? '',
                    $r['desig_name'] ?? '',
                    $r['attendance_date'] ?? '',
                    $r['check_in'] ?? '--',
                    $r['check_out'] ?? '--',
                    $r['working_hours'] ?? '00:00:00',
                    $r['late_minutes'] ?? 0,
                    strtoupper($r['status'] ?? 'PRESENT'),
                    $r['distance'] ?? '0',
                    $r['remarks'] ?? '',
                ];
            }
            break;

        case 'call_reports':
            $headers = ['ID', 'Customer Name', 'Customer Phone', 'Telecaller', 'Call Status', 'Notes', 'Follow-up Date', 'Call Date & Time'];
            $sql = "SELECT c.*, l.customer_name, l.customer_phone, l.phone, e.first_name as tc_first, e.last_name as tc_last
                    FROM call_reports c
                    LEFT JOIN leads l ON l.id = c.lead_id
                    LEFT JOIN employees e ON e.id = c.employee_id
                    WHERE (DATE(c.created_at) BETWEEN ? AND ? OR c.call_date BETWEEN ? AND ?)";
            $params = [$startDate, $endDate, $startDate, $endDate];
            if ($status !== '') { $sql .= " AND c.call_status = ?"; $params[] = $status; }
            if (!$isAdmin) { $sql .= " AND c.employee_id = ?"; $params[] = $authEid; }
            $sql .= " ORDER BY c.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['id'],
                    $r['customer_name'] ?? 'Lead #' . ($r['lead_id'] ?? ''),
                    $r['customer_phone'] ?? $r['phone'] ?? '',
                    trim(($r['tc_first'] ?? '') . ' ' . ($r['tc_last'] ?? '')),
                    strtoupper($r['call_status'] ?? 'No Answer'),
                    $r['notes'] ?? '',
                    $r['follow_up_date'] ?? '--',
                    $r['created_at'] ?? $r['call_date'] ?? '',
                ];
            }
            break;

        case 'work_reports':
            $headers = ['ID', 'Employee Code', 'Employee Name', 'Department', 'Report Date', 'Work Description', 'Completed Tasks', 'Pending Tasks', 'Status', 'Manager Remarks'];
            $sql = "SELECT w.*, e.first_name, e.last_name, e.employee_code, d.name as dept_name
                    FROM daily_work_reports w
                    JOIN employees e ON e.id = w.employee_id
                    LEFT JOIN departments d ON d.id = e.department_id
                    WHERE w.report_date BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
            if ($status !== '') { $sql .= " AND w.status = ?"; $params[] = $status; }
            if ($employeeId !== '') { $sql .= " AND w.employee_id = ?"; $params[] = intval($employeeId); }
            elseif (!$isAdmin) { $sql .= " AND w.employee_id = ?"; $params[] = $authEid; }
            $sql .= " ORDER BY w.report_date DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['id'],
                    $r['employee_code'] ?? '',
                    trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                    $r['dept_name'] ?? '',
                    $r['report_date'] ?? '',
                    $r['work_description'] ?? $r['description'] ?? '',
                    $r['completed_tasks'] ?? '',
                    $r['pending_tasks'] ?? '',
                    strtoupper($r['status'] ?? 'SUBMITTED'),
                    $r['manager_remarks'] ?? $r['remarks'] ?? '',
                ];
            }
            break;

        case 'salary':
            $headers = ['Employee Code', 'Employee Name', 'Department', 'Base Salary (INR)', 'Present Days', 'Late Days', 'Half Days', 'Absent Days', 'Total Deduction (INR)', 'Net Payable (INR)'];
            $sql = "SELECT e.id, e.first_name, e.last_name, e.employee_code, e.salary, d.name as dept_name,
                           COALESCE(att.present, 0) as present_days,
                           COALESCE(att.late, 0) as late_days,
                           COALESCE(att.half_day, 0) as half_days,
                           COALESCE(att.absent, 0) as absent_days,
                           COALESCE(ded.total_deduction, 0) as total_deduction
                    FROM employees e
                    LEFT JOIN departments d ON d.id = e.department_id
                    LEFT JOIN (
                        SELECT employee_id,
                               SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                               SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                               SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_day,
                               SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
                        FROM attendance
                        WHERE attendance_date BETWEEN ? AND ?
                        GROUP BY employee_id
                    ) att ON att.employee_id = e.id
                    LEFT JOIN (
                        SELECT employee_id, SUM(deduction_amount) as total_deduction
                        FROM salary_deductions
                        WHERE deduction_date BETWEEN ? AND ?
                        GROUP BY employee_id
                    ) ded ON ded.employee_id = e.id
                    WHERE e.status = 1";
            $params = [$startDate, $endDate, $startDate, $endDate];
            if (!$isAdmin) { $sql .= " AND e.id = ?"; $params[] = $authEid; }
            $sql .= " ORDER BY e.first_name ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $baseSalary = floatval($r['salary'] ?? 0);
                $deduction = floatval($r['total_deduction'] ?? 0);
                $net = max(0, $baseSalary - $deduction);
                $rows[] = [
                    $r['employee_code'] ?? '',
                    trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                    $r['dept_name'] ?? '',
                    number_format($baseSalary, 2, '.', ''),
                    $r['present_days'] ?? 0,
                    $r['late_days'] ?? 0,
                    $r['half_days'] ?? 0,
                    $r['absent_days'] ?? 0,
                    number_format($deduction, 2, '.', ''),
                    number_format($net, 2, '.', ''),
                ];
            }
            break;

        case 'expenses':
            $headers = ['ID', 'Employee Name', 'Category', 'Amount (INR)', 'Expense Date', 'Description', 'Status', 'Remarks'];
            $sql = "SELECT exp.*, e.first_name, e.last_name, ec.name as cat_name
                    FROM expenses exp
                    JOIN employees e ON e.id = exp.employee_id
                    LEFT JOIN expense_categories ec ON ec.id = exp.expense_category_id
                    WHERE exp.expense_date BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
            if ($status !== '') { $sql .= " AND exp.status = ?"; $params[] = $status; }
            if (!$isAdmin) { $sql .= " AND exp.employee_id = ?"; $params[] = $authEid; }
            $sql .= " ORDER BY exp.expense_date DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['id'],
                    trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                    $r['cat_name'] ?? 'General',
                    number_format(floatval($r['amount'] ?? 0), 2, '.', ''),
                    $r['expense_date'] ?? '',
                    $r['description'] ?? '',
                    strtoupper($r['status'] ?? 'PENDING'),
                    $r['remarks'] ?? '',
                ];
            }
            break;

        case 'travel':
            $headers = ['ID', 'Employee Name', 'Travel Date', 'Purpose', 'Start KM', 'End KM', 'Total KM', 'Allowance (INR)', 'Start Location', 'End Location', 'Status'];
            $sql = "SELECT t.*, e.first_name, e.last_name
                    FROM travel_requests t
                    JOIN employees e ON e.id = t.employee_id
                    WHERE t.travel_date BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
            if ($status !== '') { $sql .= " AND t.status = ?"; $params[] = $status; }
            if (!$isAdmin) { $sql .= " AND t.employee_id = ?"; $params[] = $authEid; }
            $sql .= " ORDER BY t.travel_date DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['id'],
                    trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                    $r['travel_date'] ?? '',
                    $r['purpose'] ?? '',
                    $r['start_km'] ?? 0,
                    $r['end_km'] ?? 0,
                    $r['total_km'] ?? (($r['end_km'] ?? 0) - ($r['start_km'] ?? 0)),
                    number_format(floatval($r['travel_allowance'] ?? 0), 2, '.', ''),
                    $r['start_location'] ?? '',
                    $r['end_location'] ?? '',
                    strtoupper($r['status'] ?? 'PENDING'),
                ];
            }
            break;

        case 'campaigns':
            $headers = ['ID', 'Campaign Name', 'Platform', 'Budget (INR)', 'Spent (INR)', 'Start Date', 'End Date', 'Leads Generated', 'Status'];
            $sql = "SELECT c.* FROM campaigns c WHERE (c.start_date BETWEEN ? AND ? OR c.end_date BETWEEN ? AND ?)";
            $params = [$startDate, $endDate, $startDate, $endDate];
            if ($status !== '') { $sql .= " AND c.status = ?"; $params[] = $status; }
            $sql .= " ORDER BY c.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    $r['id'],
                    $r['name'] ?? $r['campaign_name'] ?? '',
                    $r['platform'] ?? $r['channel'] ?? 'Digital',
                    number_format(floatval($r['budget'] ?? 0), 2, '.', ''),
                    number_format(floatval($r['spent'] ?? 0), 2, '.', ''),
                    $r['start_date'] ?? '',
                    $r['end_date'] ?? '',
                    $r['leads_count'] ?? 0,
                    strtoupper($r['status'] ?? 'ACTIVE'),
                ];
            }
            break;

        default:
            return ['success' => false, 'message' => 'Unsupported report type: ' . $type];
    }

    return [
        'success' => true,
        'report_type' => $type,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'headers' => $headers,
        'rows' => $rows,
        'groups' => $groups,
        'total_count' => count($rows),
    ];
}
