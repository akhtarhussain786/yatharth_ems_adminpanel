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
    $employeeId = $auth['employee_id'] ?? null;
    $userId = $auth['user_id'] ?? null;
    $role = strtolower(trim($auth['role'] ?? ''));

    // If employee_id is not in auth token, lookup by user_id
    if (!$employeeId && $userId) {
        $st = $db->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $employeeId = $st->fetchColumn() ?: null;
    }

    $employee = null;
    if ($employeeId) {
        $stmt = $db->prepare("
            SELECT e.*, d.name as department_name, des.name as designation_name
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN designations des ON des.id = e.designation_id
            WHERE e.id = ?
        ");
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
    }

    // Fallback for Admin/Super Admin without employee row
    if (!$employee) {
        $uStmt = $db->prepare("SELECT u.username, u.role_id, r.name as role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch();
        $employee = [
            'id' => $employeeId ?: 0,
            'first_name' => $uRow['username'] ?? 'Administrator',
            'last_name' => '',
            'employee_code' => 'ADMIN',
            'department_name' => 'Management',
            'designation_name' => ucfirst($uRow['role_name'] ?? $role),
            'profile_photo' => null,
        ];
    }

    // Today's attendance
    $todayAttendance = null;
    if ($employeeId) {
        $stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = CURDATE() LIMIT 1");
        $stmt->execute([$employeeId]);
        $todayAttendance = $stmt->fetch() ?: null;
    }

    // Monthly attendance stats
    $monthlyStats = [
        'total_days' => 0,
        'present_days' => 0,
        'late_days' => 0,
        'half_days' => 0,
        'absent_days' => 0,
        'total_late_minutes' => 0,
    ];
    if ($employeeId) {
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
        $res = $stmt->fetch();
        if ($res) {
            $monthlyStats = [
                'total_days' => (int)($res['total_days'] ?? 0),
                'present_days' => (int)($res['present_days'] ?? 0),
                'late_days' => (int)($res['late_days'] ?? 0),
                'half_days' => (int)($res['half_days'] ?? 0),
                'absent_days' => (int)($res['absent_days'] ?? 0),
                'total_late_minutes' => (int)($res['total_late_minutes'] ?? 0),
            ];
        }
    }

    $roleSpecific = [];

    // Check if role is admin or super admin
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
        $eid = $employeeId ?: 0;
        $totalLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (employee_id = ? OR created_by = ?)");
        $totalLeads->execute([$eid, $eid]);
        $todayLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (employee_id = ? OR created_by = ?) AND DATE(created_at) = CURDATE()");
        $todayLeads->execute([$eid, $eid]);
        $monthLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (employee_id = ? OR created_by = ?) AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
        $monthLeads->execute([$eid, $eid]);
        $wonLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (employee_id = ? OR created_by = ?) AND status IN ('won', 'converted')");
        $wonLeads->execute([$eid, $eid]);
        $roleSpecific = [
            'total_leads' => (int)$totalLeads->fetchColumn(),
            'today_leads' => (int)$todayLeads->fetchColumn(),
            'monthly_leads' => (int)$monthLeads->fetchColumn(),
            'converted_leads' => (int)$wonLeads->fetchColumn(),
        ];
    } elseif (in_array($role, ['telecaller', 'telecallers', 'telecaller_admin', 'counselor', 'counselors', 'sales', 'sales_admin', 'sales_executive'], true)) {
        $eid = $employeeId ?: 0;
        $assignedLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (assigned_to = ? OR employee_id = ? OR created_by = ?)");
        $assignedLeads->execute([$eid, $eid, $eid]);
        $todayCalls = $db->prepare("SELECT COUNT(*) FROM call_reports WHERE employee_id = ? AND (DATE(created_at) = CURDATE() OR DATE(call_date) = CURDATE())");
        $todayCalls->execute([$eid]);
        $pendingFollowUps = $db->prepare("SELECT COUNT(*) FROM follow_ups WHERE employee_id = ? AND status = 'pending' AND follow_up_date <= CURDATE()");
        $pendingFollowUps->execute([$eid]);
        $converted = $db->prepare("SELECT COUNT(*) FROM leads WHERE (assigned_to = ? OR employee_id = ? OR created_by = ?) AND (status = 'won' OR status = 'converted' OR status = 'qualified')");
        $converted->execute([$eid, $eid, $eid]);
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
        $teamTasks = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_by = ? OR assigned_by = (SELECT id FROM employees WHERE user_id = ? LIMIT 1)");
        $teamTasks->execute([$employeeId ?: 0, $userId ?: 0]);
        $roleSpecific = ['team_tasks' => (int)$teamTasks->fetchColumn()];
    }

    // Default fallback stats if roleSpecific is still empty
    if (empty($roleSpecific)) {
        $eid = $employeeId ?: 0;
        $assignedLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE (assigned_to = ? OR employee_id = ? OR created_by = ?)");
        $assignedLeads->execute([$eid, $eid, $eid]);
        $leadCount = (int)$assignedLeads->fetchColumn();
        if ($leadCount > 0) {
            $todayCalls = $db->prepare("SELECT COUNT(*) FROM call_reports WHERE employee_id = ? AND (DATE(created_at) = CURDATE() OR DATE(call_date) = CURDATE())");
            $todayCalls->execute([$eid]);
            $pendingFollowUps = $db->prepare("SELECT COUNT(*) FROM follow_ups WHERE employee_id = ? AND status = 'pending' AND follow_up_date <= CURDATE()");
            $pendingFollowUps->execute([$eid]);
            $converted = $db->prepare("SELECT COUNT(*) FROM leads WHERE (assigned_to = ? OR employee_id = ? OR created_by = ?) AND (status = 'won' OR status = 'converted' OR status = 'qualified')");
            $converted->execute([$eid, $eid, $eid]);
            $roleSpecific = [
                'assigned_leads' => $leadCount,
                'today_calls' => (int)$todayCalls->fetchColumn(),
                'pending_followups' => (int)$pendingFollowUps->fetchColumn(),
                'converted_leads' => (int)$converted->fetchColumn(),
            ];
        } else {
            $roleSpecific = [
                'total_leads' => 0,
                'today_leads' => 0,
                'monthly_leads' => 0,
                'converted_leads' => 0,
            ];
        }
    }

    // Leaves stats
    $eid = $employeeId ?: 0;
    $leavePending = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND LOWER(status) = 'pending'");
    $leavePending->execute([$eid]);
    $leaveApproved = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND LOWER(status) = 'approved' AND YEAR(created_at) = YEAR(CURDATE())");
    $leaveApproved->execute([$eid]);
    $leaveRejected = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND LOWER(status) = 'rejected' AND YEAR(created_at) = YEAR(CURDATE())");
    $leaveRejected->execute([$eid]);
    $lastLeave = $db->prepare("SELECT leave_type, status, start_date, end_date, total_days FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1");
    $lastLeave->execute([$eid]);

    return [
        'success' => true,
        'data' => array_merge([
            'employee' => $employee,
            'today_attendance' => $todayAttendance,
            'monthly_stats' => $monthlyStats,
            'leave_pending' => (int)$leavePending->fetchColumn(),
            'leave_approved' => (int)$leaveApproved->fetchColumn(),
            'leave_rejected' => (int)$leaveRejected->fetchColumn(),
            'last_leave' => $lastLeave->fetch() ?: null,
        ], $roleSpecific),
    ];
}
