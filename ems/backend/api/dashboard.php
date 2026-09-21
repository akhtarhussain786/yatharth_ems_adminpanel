<?php
function handleDashboardRequest($action) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();

    switch ($action) {
        case 'summary':
            return getDashboardSummary($db, $auth);
        case 'employee':
            return getEmployeeDashboard($db, $auth);
        default:
            return ['success' => false, 'message' => 'Invalid dashboard action'];
    }
}

function getDashboardSummary($db, $auth) {
    AuthMiddleware::checkRole(['super_admin', 'hr']);

    $today = date('Y-m-d');

    // Total employees
    $total = $db->query("SELECT COUNT(*) as count FROM employees WHERE status = 1")->fetch()['count'];

    // Today's attendance stats
    $stmt = $db->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN status = 'late' THEN 1 END) as late,
            COUNT(CASE WHEN status = 'half-day' THEN 1 END) as half_day,
            COUNT(*) as total_marked
        FROM attendance WHERE attendance_date = ?
    ");
    $stmt->execute([$today]);
    $todayStats = $stmt->fetch();

    $absent = $total - $todayStats['total_marked'];

    // Monthly chart data
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $stmt = $db->prepare("
        SELECT attendance_date, 
               COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
               COUNT(CASE WHEN status = 'late' THEN 1 END) as late,
               COUNT(CASE WHEN status = 'half-day' THEN 1 END) as half_day,
               COUNT(*) as total
        FROM attendance 
        WHERE attendance_date BETWEEN ? AND ?
        GROUP BY attendance_date
        ORDER BY attendance_date ASC
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $chartData = $stmt->fetchAll();

    // Recent attendance
    $stmt = $db->query("
        SELECT a.*, e.first_name, e.last_name, e.employee_code, e.department_id, e.designation_id,
               d.name as department_name
        FROM attendance a
        JOIN employees e ON e.id = a.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE a.attendance_date = CURDATE()
        ORDER BY a.check_in DESC
        LIMIT 10
    ");
    $recent = $stmt->fetchAll();

    // Department wise attendance
    $stmt = $db->query("
        SELECT d.name as department, 
               COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
               COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late,
               COUNT(CASE WHEN a.status = 'half-day' THEN 1 END) as half_day,
               COUNT(CASE WHEN a.id IS NULL THEN 1 END) as absent
        FROM departments d
        LEFT JOIN employees e ON e.department_id = d.id
        LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date = CURDATE()
        GROUP BY d.id, d.name
    ");
    $deptStats = $stmt->fetchAll();

    return [
        'success' => true,
        'data' => [
            'total_employees' => (int)$total,
            'present' => (int)($todayStats['present'] ?? 0),
            'absent' => max(0, $absent),
            'late' => (int)($todayStats['late'] ?? 0),
            'half_day' => (int)($todayStats['half_day'] ?? 0),
            'chart_data' => $chartData,
            'recent_attendance' => $recent,
            'department_stats' => $deptStats,
            'date' => $today,
        ],
    ];
}

function getEmployeeDashboard($db, $auth) {
    $employeeId = $auth['employee_id'];

    // Get employee info
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

    // Today's attendance
    $stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = CURDATE()");
    $stmt->execute([$employeeId]);
    $todayAttendance = $stmt->fetch();

    // Monthly stats
    $monthStart = date('Y-m-01');
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_days,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(late_minutes) as total_late_minutes
        FROM attendance 
        WHERE employee_id = ? AND attendance_date >= ? AND attendance_date <= CURDATE()
    ");
    $stmt->execute([$employeeId, $monthStart]);
    $monthlyStats = $stmt->fetch();

    $role = strtolower(trim($auth['role'] ?? ''));
    $roleSpecific = [];

    if (in_array($role, ['super_admin', 'admin'], true)) {
        $totalLeads = $db->query("SELECT COUNT(*) FROM leads");
        $todayLeads = $db->query("SELECT COUNT(*) FROM leads WHERE DATE(created_at) = CURDATE()");
        $monthLeads = $db->query("SELECT COUNT(*) FROM leads WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
        $wonLeads = $db->query("SELECT COUNT(*) FROM leads WHERE status IN ('won', 'converted')");
        $roleSpecific = [
            'total_leads' => (int)$totalLeads->fetchColumn(),
            'today_leads' => (int)$todayLeads->fetchColumn(),
            'monthly_leads' => (int)$monthLeads->fetchColumn(),
            'converted_leads' => (int)$wonLeads->fetchColumn(),
        ];
    } elseif (in_array($role, ['digital_marketing_admin', 'digital_marketing', 'marketing', 'marketing_executive'], true)) {
        $totalLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (employee_id = ? OR created_by = ?)");
        $totalLeads->execute([$employeeId, $employeeId]);
        $todayLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (employee_id = ? OR created_by = ?) AND DATE(created_at) = CURDATE()");
        $todayLeads->execute([$employeeId, $employeeId]);
        $monthLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (employee_id = ? OR created_by = ?) AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
        $monthLeads->execute([$employeeId, $employeeId]);
        $wonLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (employee_id = ? OR created_by = ?) AND status IN ('won', 'converted')");
        $wonLeads->execute([$employeeId, $employeeId]);
        $roleSpecific = [
            'total_leads' => (int)$totalLeads->fetchColumn(),
            'today_leads' => (int)$todayLeads->fetchColumn(),
            'monthly_leads' => (int)$monthLeads->fetchColumn(),
            'converted_leads' => (int)$wonLeads->fetchColumn(),
        ];
    } elseif (in_array($role, ['telecaller', 'telecallers', 'telecaller_admin', 'counselor', 'counselors', 'sales', 'sales_admin', 'sales_executive'], true)) {
        $assignedLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (assigned_to = ? OR employee_id = ? OR created_by = ?)");
        $assignedLeads->execute([$employeeId, $employeeId, $employeeId]);
        $todayCalls = $db->prepare("SELECT COUNT(*) FROM call_reports WHERE employee_id = ? AND DATE(call_date) = CURDATE()");
        $todayCalls->execute([$employeeId]);
        $pendingFollowUps = $db->prepare("SELECT COUNT(*) FROM follow_ups WHERE employee_id = ? AND status = 'pending' AND DATE(follow_up_date) <= CURDATE()");
        $pendingFollowUps->execute([$employeeId]);
        $converted = $db->prepare("SELECT COUNT(*) FROM leads WHERE (assigned_to = ? OR employee_id = ? OR created_by = ?) AND status IN ('won', 'converted', 'qualified')");
        $converted->execute([$employeeId, $employeeId, $employeeId]);
        $roleSpecific = [
            'assigned_leads' => (int)$assignedLeads->fetchColumn(),
            'today_calls' => (int)$todayCalls->fetchColumn(),
            'pending_followups' => (int)$pendingFollowUps->fetchColumn(),
            'converted_leads' => (int)$converted->fetchColumn(),
        ];
    } elseif (in_array($role, ['hr_admin', 'hr'], true)) {
        $pendingLeaves = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE LOWER(status) = 'pending'");
        $pendingLeaves->execute();
        $roleSpecific = ['pending_leave_requests' => (int)$pendingLeaves->fetchColumn()];
    } elseif ($role === 'manager') {
        $teamTasks = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_by = (SELECT id FROM employees WHERE user_id = ?)");
        $teamTasks->execute([$auth['user_id']]);
        $roleSpecific = ['team_tasks' => (int)$teamTasks->fetchColumn()];
    }

    $leavePending = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'Pending'");
    $leavePending->execute([$employeeId]);
    $leaveApproved = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'Approved' AND YEAR(created_at) = YEAR(CURDATE())");
    $leaveApproved->execute([$employeeId]);
    $leaveRejected = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'Rejected' AND YEAR(created_at) = YEAR(CURDATE())");
    $leaveRejected->execute([$employeeId]);
    $lastLeave = $db->prepare("SELECT leave_type, status, start_date, end_date, total_days FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1");
    $lastLeave->execute([$employeeId]);

    return [
        'success' => true,
        'data' => array_merge([
            'employee' => $employee,
            'today_attendance' => $todayAttendance ?: null,
            'monthly_stats' => $monthlyStats,
            'leave_pending' => (int)$leavePending->fetchColumn(),
            'leave_approved' => (int)$leaveApproved->fetchColumn(),
            'leave_rejected' => (int)$leaveRejected->fetchColumn(),
            'last_leave' => $lastLeave->fetch() ?: null,
        ], $roleSpecific),
    ];
}
