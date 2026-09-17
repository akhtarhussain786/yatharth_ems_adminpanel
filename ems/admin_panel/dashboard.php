<?php
require_once 'includes/config.php';

// ===== SESSION & REDIRECT HANDLING =====
if (!isLoggedIn()) {
    $rc = getRedirectCount();
    if ($rc > 3) {
        $_SESSION = [];
        session_destroy();
        header("Location: " . BASE_URL . "index.php?session_error=1");
        exit;
    }
    redirect(buildRedirectUrl('index.php', $rc));
    exit; // ✅ Added exit
}

// ===== INITIALIZE VARIABLES =====
$role = getAdminRoleName();
$roleId = getAdminRoleId();
$today = date('Y-m-d');
$currentHour = (int)date('H');
$greeting = $currentHour < 12 ? 'Good Morning' : ($currentHour < 17 ? 'Good Afternoon' : 'Good Evening');

// ===== ROLE-BASED STATS (Using Prepared Statements) =====
$totalLeads = 0;
$activeCampaigns = 0;
$wonLeads = 0;
$totalCalls = 0;
$pendingFollowUps = 0;
$pendingLeaves = 0;

switch ($role) {
    case 'digital_marketing_admin':
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE DATE(created_at) = CURDATE()");
        $stmt->execute();
        $totalLeads = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE status = 'active'");
        $stmt->execute();
        $activeCampaigns = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE status = 'won' AND DATE(updated_at) = CURDATE()");
        $stmt->execute();
        $wonLeads = $stmt->fetchColumn();
        break;
        
    case 'telecaller_admin':
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM call_reports WHERE call_date = CURDATE()");
        $stmt->execute();
        $totalCalls = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM follow_ups WHERE status = 'pending' AND follow_up_date <= CURDATE()");
        $stmt->execute();
        $pendingFollowUps = $stmt->fetchColumn();
        break;
        
    case 'hr_admin':
    case 'hr':
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'");
        $stmt->execute();
        $pendingLeaves = $stmt->fetchColumn();
        break;
        
    case 'accounts_admin':
        break;
}

// ===== MODULE ACCESS =====
$showAttendance = hasModuleAccess('attendance');
$showEmployees = hasModuleAccess('employees');

// ===== EMPLOYEE COUNT =====
$total = 0;
if ($showEmployees) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE status = 1");
    $stmt->execute();
    $total = $stmt->fetchColumn();
}

// ===== ATTENDANCE STATS =====
$todayStats = ['present' => 0, 'late' => 0, 'half_day' => 0, 'total_marked' => 0];
$absent = 0;
$activeEmployees = 0;

if ($showAttendance) {
    // "Present" means the person is at work today — anyone who has clocked in.
    // It used to count only status = 'present', so somebody who arrived late or
    // was graded a half day was left out of the figure entirely: they were at
    // their desk, and the dashboard said they were not present.
    //
    // Counting employees rather than attendance rows also keeps the figure
    // honest when a row exists for someone who has since been deactivated.
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT e.id) AS active_employees,
                COUNT(DISTINCT CASE WHEN a.check_in IS NOT NULL THEN e.id END) AS present,
                COUNT(DISTINCT CASE WHEN LOWER(a.status) = 'late' THEN e.id END) AS late,
                COUNT(DISTINCT CASE WHEN LOWER(a.status) = 'half-day' THEN e.id END) AS half_day,
                COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN e.id END) AS total_marked
            FROM employees e
            LEFT JOIN attendance a
                   ON a.employee_id = e.id AND a.attendance_date = ?
            WHERE e.status = 1
        ");
        $stmt->execute([$today]);
        $row = $stmt->fetch();
        if ($row) {
            $todayStats = [
                'present'      => (int) $row['present'],
                'late'         => (int) $row['late'],
                'half_day'     => (int) $row['half_day'],
                'total_marked' => (int) $row['total_marked'],
            ];
            $activeEmployees = (int) $row['active_employees'];
        }
    } catch (Exception $e) {
        error_log('Dashboard attendance counts: ' . $e->getMessage());
    }
}

// ===== DASHBOARD QUERIES (Optimized with Prepared Statements) =====
$totalDepartments = 0;
$pendingLeavesAll = 0;
$openTasks = 0;
$activeProjects = 0;
$pendingExpenses = 0;
$onLeave = 0;
$birthdays = [];
$todayBirthdays = [];
$upcomingHolidays = [];
$upcomingMeetings = [];
$recentActivity = [];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE status = 1");
    $stmt->execute();
    $totalDepartments = $stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'");
    $stmt->execute();
    $pendingLeavesAll = $stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE status NOT IN ('Completed','Cancelled')");
    $stmt->execute();
    $openTasks = $stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE status IN ('in_progress','pending')");
    $stmt->execute();
    $activeProjects = $stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE status = 'pending'");
    $stmt->execute();
    $pendingExpenses = $stmt->fetchColumn();
} catch (Exception $e) {}

try {
    // The table is leave_requests. This read `FROM leaves`, which does not
    // exist, inside a catch that discarded the error — so the figure was
    // always nought, and since absent is headcount minus present minus this,
    // everyone on approved leave was being reported as absent.
    //
    // start_date/end_date only: the older from_date/to_date pair is absent on
    // some databases, and naming it in a COALESCE made the whole query fail.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM leave_requests
        WHERE status = 'Approved'
        AND CURDATE() BETWEEN start_date AND end_date
    ");
    $stmt->execute();
    $onLeave = (int) $stmt->fetchColumn();
} catch (Exception $e) {
    error_log('dashboard on-leave count: ' . $e->getMessage());
}

// Absent is worked out here, once the approved-leave figure is known: somebody
// on approved leave is not absent, and was previously counted as though they
// had simply failed to turn up. Nobody is absent on a Sunday or a holiday.
if ($showAttendance) {
    $headcount = $activeEmployees > 0 ? $activeEmployees : $total;
    $isWeeklyOff = (int) date('N', strtotime($today)) === 7;   // Sunday

    $isHolidayToday = false;
    try {
        $h = $pdo->prepare("SELECT 1 FROM holidays WHERE holiday_date = ? AND status = 1 LIMIT 1");
        $h->execute([$today]);
        $isHolidayToday = (bool) $h->fetchColumn();
    } catch (Exception $e) {}

    $absent = ($isWeeklyOff || $isHolidayToday)
        ? 0
        : max(0, $headcount - $todayStats['present'] - (int) $onLeave);
}

// Polled by the dashboard every minute so a check-in shows up without the page
// being reloaded. It answers before the rest of this file's queries run, so the
// poll costs a fraction of a full dashboard load.
if (isset($_GET['stats'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'total'    => $activeEmployees > 0 ? $activeEmployees : $total,
        'present'  => (int) $todayStats['present'],
        'late'     => (int) $todayStats['late'],
        'half_day' => (int) $todayStats['half_day'],
        'absent'   => (int) $absent,
        'on_leave' => (int) $onLeave,
        'percent'  => $total > 0 ? (int) round($todayStats['present'] / $total * 100) : 0,
    ]);
    exit;
}

// ✅ FIXED: Birthday Query - Using date_of_birth
try {
    $stmt = $pdo->prepare("
        SELECT first_name, last_name, date_of_birth, department_id 
        FROM employees 
        WHERE MONTH(date_of_birth) = MONTH(CURDATE()) 
        AND DAY(date_of_birth) >= DAY(CURDATE()) 
        AND status = 1 
        ORDER BY DAY(date_of_birth) ASC 
        LIMIT 5
    ");
    $stmt->execute();
    $birthdays = $stmt->fetchAll();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT first_name, last_name, department_id 
        FROM employees 
        WHERE MONTH(date_of_birth) = MONTH(CURDATE()) 
        AND DAY(date_of_birth) = DAY(CURDATE()) 
        AND status = 1
    ");
    $stmt->execute();
    $todayBirthdays = $stmt->fetchAll();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT * FROM holidays 
        WHERE holiday_date >= CURDATE() 
        AND status = 1 
        ORDER BY holiday_date ASC 
        LIMIT 3
    ");
    $stmt->execute();
    $upcomingHolidays = $stmt->fetchAll();
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT * FROM meetings 
        WHERE meeting_date >= CURDATE() 
        AND status != 'completed' 
        ORDER BY meeting_date ASC 
        LIMIT 3
    ");
    $stmt->execute();
    $upcomingMeetings = $stmt->fetchAll();
} catch (Exception $e) {}

// ✅ FIXED: Recent Activity with better error handling
try {
    $stmt = $pdo->prepare("
        (SELECT 'attendance' as type, CONCAT(e.first_name, ' ', e.last_name) as actor, 
            CONCAT('Checked in at ', TIME_FORMAT(a.check_in, '%h:%i %p')) as action, 
            a.created_at 
        FROM attendance a 
        JOIN employees e ON e.id = a.employee_id 
        WHERE a.attendance_date = CURDATE() 
        ORDER BY a.created_at DESC 
        LIMIT 3)
        UNION ALL
        (SELECT 'leave' as type, CONCAT(e.first_name, ' ', e.last_name) as actor, 
            CONCAT(lr.status, ' leave request') as action, 
            lr.created_at 
        FROM leave_requests lr 
        JOIN employees e ON e.id = lr.employee_id 
        ORDER BY lr.created_at DESC 
        LIMIT 3)
        UNION ALL
        (SELECT 'task' as type, CONCAT(e.first_name, ' ', e.last_name) as actor, 
            CONCAT('Task: ', t.title) as action, 
            t.created_at 
        FROM tasks t 
        JOIN employees e ON e.id = t.assigned_to 
        ORDER BY t.created_at DESC 
        LIMIT 3)
        UNION ALL
        (SELECT 'lead' as type, CONCAT(e.first_name, ' ', e.last_name) as actor, 
            'Lead created' as action, 
            l.created_at 
        FROM leads l 
        JOIN employees e ON e.id = l.employee_id 
        ORDER BY l.created_at DESC 
        LIMIT 3)
        ORDER BY created_at DESC 
        LIMIT 8
    ");
    $stmt->execute();
    $recentActivity = $stmt->fetchAll();
} catch (Exception $e) {}

// ===== CHART DATA =====
$chartLabels = [];
$chartPresent = [];
$chartLate = [];

try {
    $stmt = $pdo->prepare("
        SELECT 
            attendance_date,
            COUNT(CASE WHEN LOWER(status) = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN LOWER(status) = 'late' THEN 1 END) as late
        FROM attendance 
        WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY attendance_date 
        ORDER BY attendance_date ASC
    ");
    $stmt->execute();
    $chartRows = $stmt->fetchAll();
    
    foreach ($chartRows as $d) {
        $chartLabels[] = date('d M', strtotime($d['attendance_date']));
        $chartPresent[] = (int)$d['present'];
        $chartLate[] = (int)$d['late'];
    }
} catch (Exception $e) {}

$weeklyLabels = [];
$weeklyPresent = [];
$weeklyAbsent = [];
$weeklyLate = [];

try {
    $stmt = $pdo->prepare("
        SELECT 
            DAYNAME(attendance_date) as dayname,
            COUNT(CASE WHEN LOWER(status) = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN LOWER(status) = 'late' THEN 1 END) as late,
            COUNT(*) as total
        FROM attendance 
        WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DAYOFWEEK(attendance_date), DAYNAME(attendance_date)
        ORDER BY DAYOFWEEK(attendance_date)
    ");
    $stmt->execute();
    $weeklyData = $stmt->fetchAll();
    
    foreach ($weeklyData as $d) {
        $weeklyLabels[] = $d['dayname'];
        $weeklyPresent[] = (int)$d['present'];
        $weeklyAbsent[] = max(0, (int)$total - (int)$d['total']);
        $weeklyLate[] = (int)$d['late'];
    }
} catch (Exception $e) {}

// ===== DEPARTMENT STATS =====
$deptStatsFull = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            d.name as department,
            COUNT(e.id) as total,
            COUNT(CASE WHEN LOWER(a.status) = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN LOWER(a.status) = 'late' THEN 1 END) as late
        FROM departments d
        LEFT JOIN employees e ON e.department_id = d.id AND e.status = 1
        LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date = CURDATE()
        WHERE d.status = 1
        GROUP BY d.id, d.name
        ORDER BY d.name
    ");
    $stmt->execute();
    $deptStatsFull = $stmt->fetchAll();
} catch (Exception $e) {}

$deptList = [];
$deptChartLabels = [];
$deptChartData = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, COUNT(e.id) as total
        FROM departments d
        LEFT JOIN employees e ON e.department_id = d.id AND e.status = 1
        WHERE d.status = 1
        GROUP BY d.id, d.name
        ORDER BY d.name
    ");
    $stmt->execute();
    $deptList = $stmt->fetchAll();
    foreach ($deptList as $dl) {
        $deptChartLabels[] = $dl['name'];
        $deptChartData[] = (int)$dl['total'];
    }
} catch (Exception $e) {}

// Encode complete chart dataset for JS
$chartDataForJs = [
    'labels'        => $chartLabels,
    'present'       => $chartPresent,
    'late'          => $chartLate,
    'presentCount'  => (int)($todayStats['present'] ?? 0),
    'lateCount'     => (int)($todayStats['late'] ?? 0),
    'absentCount'   => (int)($absent ?? 0),
    'deptLabels'    => $deptChartLabels,
    'deptData'      => $deptChartData,
    'weeklyLabels'  => $weeklyLabels,
    'weeklyPresent' => $weeklyPresent,
    'weeklyAbsent'  => $weeklyAbsent,
    'weeklyLate'    => $weeklyLate
];
$chartDataJson = json_encode($chartDataForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// ===== TODAY'S ATTENDANCE =====
$todayAttendance = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
        FROM attendance a
        JOIN employees e ON e.id = a.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE a.attendance_date = CURDATE()
        ORDER BY a.check_in DESC
        LIMIT 10
    ");
    $stmt->execute();
    $todayAttendance = $stmt->fetchAll();
} catch (Exception $e) {}

// ===== PREPARE CHART DATA FOR JAVASCRIPT =====
$chartDataJson = json_encode([
    'labels' => $chartLabels,
    'present' => $chartPresent,
    'late' => $chartLate,
    'presentCount' => (int)($todayStats['present'] ?? 0),
    'lateCount' => (int)($todayStats['late'] ?? 0),
    'absentCount' => (int)$absent,
    'deptLabels' => array_column($deptStatsFull, 'department'),
    'deptData' => array_column($deptStatsFull, 'total'),
    'weeklyLabels' => $weeklyLabels,
    'weeklyPresent' => $weeklyPresent,
    'weeklyAbsent' => $weeklyAbsent,
    'weeklyLate' => $weeklyLate
]);

require_once 'includes/header.php';
?>

<!-- ===== STYLES ===== -->
<style>
/* ===== BASE ===== */
:root {
    --primary: #2563eb;
    --primary-light: #dbeafe;
    --success: #16a34a;
    --success-light: #dcfce7;
    --warning: #f59e0b;
    --warning-light: #fef3c7;
    --danger: #dc2626;
    --danger-light: #fee2e2;
    --info: #0ea5e9;
    --info-light: #e0f2fe;
    --purple: #7c3aed;
    --purple-light: #ede9fe;
    --pink: #ec4899;
    --pink-light: #fce7f3;
    --orange: #f97316;
    --orange-light: #ffedd5;
    --gray: #6b7280;
    --gray-light: #f3f4f6;
    --text-muted: #9ca3af;
    --card-shadow: 0 1px 3px rgba(0,0,0,0.08);
    --border-radius: 12px;
    --transition: all 0.3s ease;
}

body {
    background: #f0f4f9;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

/* ===== GREETING SECTION ===== */
.greeting-section {
    background: linear-gradient(135deg, #1a2a44 0%, #0f1a2e 100%);
    border-radius: var(--border-radius);
    padding: 24px 30px;
    margin-bottom: 24px;
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    overflow: hidden;
}

.greeting-section h2 {
    font-size: 24px;
    font-weight: 700;
    margin: 0;
}

.greeting-section p {
    opacity: 0.7;
    font-size: 14px;
    margin: 4px 0 0;
}

.greeting-icon {
    font-size: 48px;
    opacity: 0.2;
    position: absolute;
    right: 30px;
    top: 50%;
    transform: translateY(-50%);
}

/* ===== STAT CARDS ===== */
.stat-card {
    background: #fff;
    border-radius: var(--border-radius);
    padding: 20px 24px;
    box-shadow: var(--card-shadow);
    transition: var(--transition);
    border: 1px solid rgba(0,0,0,0.04);
    height: 100%;
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
}

.stat-body {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.stat-icon-circle {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--gray);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #1a2332;
    line-height: 1.2;
}

.stat-footer {
    font-size: 11px;
    color: var(--gray);
    margin-top: 4px;
}

.trend-up { color: var(--success); }
.trend-down { color: var(--danger); }
.trend-neutral { color: var(--gray); }

/* ===== CARDS ===== */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--card-shadow);
    background: #fff;
    overflow: hidden;
}

.card-header {
    padding: 16px 24px;
    background: #fff;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
    font-size: 14px;
    color: #1a2332;
}

.card-body {
    padding: 20px 24px;
}

.card-actions .btn {
    padding: 4px 12px;
    font-size: 11px;
    border-radius: 6px;
}

/* ===== BADGES ===== */
.badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 500;
}

.badge-success { background: var(--success-light); color: var(--success); }
.badge-warning { background: var(--warning-light); color: var(--warning); }
.badge-danger { background: var(--danger-light); color: var(--danger); }
.badge-present { background: var(--success-light); color: var(--success); }
.badge-late { background: var(--warning-light); color: var(--warning); }
.badge-absent { background: var(--danger-light); color: var(--danger); }

/* ===== CHARTS ===== */
.chart-container {
    position: relative;
    height: 250px;
}

/* ===== DEPARTMENT CARDS ===== */
.dept-card {
    background: var(--gray-light);
    border-radius: 10px;
    padding: 16px 18px;
    transition: var(--transition);
}

.dept-card:hover {
    background: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.dept-name {
    font-weight: 600;
    font-size: 14px;
    color: #1a2332;
}

.dept-stats {
    display: flex;
    gap: 16px;
    margin: 8px 0 10px;
}

.dept-stat {
    text-align: center;
}

.dept-stat .num {
    font-weight: 700;
    font-size: 18px;
}

.dept-stat .label {
    font-size: 10px;
    color: var(--gray);
    text-transform: uppercase;
}

.dept-progress {
    height: 4px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.dept-progress .bar {
    height: 100%;
    border-radius: 4px;
    transition: width 1s ease;
}

/* ===== QUICK ACTIONS ===== */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 10px;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border-radius: 10px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: var(--transition);
    background: var(--gray-light);
    color: #1a2332;
}

.quick-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    text-decoration: none;
}

.quick-action-btn i { font-size: 16px; }

.quick-action-btn.blue { background: var(--primary-light); color: var(--primary); }
.quick-action-btn.green { background: var(--success-light); color: var(--success); }
.quick-action-btn.purple { background: var(--purple-light); color: var(--purple); }
.quick-action-btn.info { background: var(--info-light); color: var(--info); }
.quick-action-btn.orange { background: var(--orange-light); color: var(--orange); }
.quick-action-btn.pink { background: var(--pink-light); color: var(--pink); }
.quick-action-btn.red { background: var(--danger-light); color: var(--danger); }

/* ===== TIMELINE ===== */
.timeline {
    max-height: 300px;
    overflow-y: auto;
}

.timeline-item {
    padding: 10px 0 10px 20px;
    border-left: 2px solid #e5e7eb;
    position: relative;
    margin-left: 8px;
}

.timeline-item:last-child { border-left-color: transparent; }

.timeline-dot {
    position: absolute;
    left: -6px;
    top: 14px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #e5e7eb;
}

.timeline-dot.blue { background: var(--primary); }
.timeline-dot.orange { background: var(--orange); }
.timeline-dot.purple { background: var(--purple); }
.timeline-dot.green { background: var(--success); }

.timeline-time {
    font-size: 10px;
    color: var(--text-muted);
    margin-bottom: 1px;
}

.timeline-title {
    font-weight: 600;
    font-size: 13px;
    color: #1a2332;
}

.timeline-desc {
    font-size: 12px;
    color: var(--gray);
}

/* ===== RIGHT SIDEBAR ===== */
.right-sidebar {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.right-widget {
    background: #fff;
    border-radius: var(--border-radius);
    padding: 18px 20px;
    box-shadow: var(--card-shadow);
}

.widget-title {
    font-weight: 600;
    font-size: 14px;
    color: #1a2332;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ===== BIRTHDAY WIDGET ===== */
.widget-birthday {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
}

.widget-birthday:last-child { border-bottom: none; }

.bday-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
}

.bday-info .name {
    font-weight: 500;
    font-size: 13px;
}

.bday-info .dept {
    font-size: 11px;
    color: var(--gray);
}

/* ===== HOLIDAY WIDGET ===== */
.widget-holiday {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
}

.widget-holiday:last-child { border-bottom: none; }

.holiday-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--warning-light);
    color: var(--warning);
    display: flex;
    align-items: center;
    justify-content: center;
}

.holiday-info .name {
    font-weight: 500;
    font-size: 13px;
}

.holiday-info .date {
    font-size: 11px;
    color: var(--gray);
}

/* ===== WEATHER WIDGET ===== */
.weather-widget {
    text-align: center;
    background: linear-gradient(135deg, #1e3a5f 0%, #0f1a2e 100%);
    color: #fff;
}

.weather-icon { font-size: 48px; margin-bottom: 4px; }
.weather-temp { font-size: 32px; font-weight: 700; }
.weather-desc { opacity: 0.8; font-size: 14px; }
.weather-details {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-top: 8px;
    font-size: 12px;
    opacity: 0.7;
}

/* ===== QUOTE WIDGET ===== */
.quote-widget {
    background: linear-gradient(135deg, #f8fafc 0%, #eef2f6 100%);
    text-align: center;
}

.quote-icon {
    font-size: 24px;
    color: var(--primary);
    opacity: 0.3;
    margin-bottom: 6px;
}

.quote-text {
    font-style: italic;
    font-size: 14px;
    color: #1a2332;
    line-height: 1.6;
}

.quote-author {
    font-size: 12px;
    color: var(--gray);
    margin-top: 4px;
}

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center;
    padding: 30px 20px;
    color: var(--gray);
}

.empty-state i {
    font-size: 36px;
    opacity: 0.3;
    margin-bottom: 10px;
}

.empty-state h6 {
    font-weight: 600;
    color: #1a2332;
    margin: 0;
}

.empty-state p {
    font-size: 13px;
    margin: 4px 0 0;
}

/* ===== TABLE ===== */
.table-wrapper {
    overflow-x: auto;
}

.table {
    font-size: 13px;
    margin-bottom: 0;
}

.table thead th {
    background: var(--gray-light);
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray);
    border-bottom: 1px solid #e5e7eb;
    padding: 12px 16px;
}

.table td {
    padding: 12px 16px;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 12px;
}

/* ===== ANIMATIONS ===== */
.animate-fade-up {
    opacity: 0;
    transform: translateY(20px);
    animation: fadeUp 0.5s ease forwards;
}

.stagger-1 { animation-delay: 0.05s; }
.stagger-2 { animation-delay: 0.10s; }
.stagger-3 { animation-delay: 0.15s; }
.stagger-4 { animation-delay: 0.20s; }
.stagger-5 { animation-delay: 0.25s; }
.stagger-6 { animation-delay: 0.30s; }

@keyframes fadeUp {
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.counter-animate {
    animation: countUp 0.8s ease forwards;
}

@keyframes countUp {
    0% { opacity: 0; transform: scale(0.8); }
    100% { opacity: 1; transform: scale(1); }
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .greeting-section {
        padding: 16px 20px;
        flex-direction: column;
        align-items: flex-start;
    }
    
    .greeting-section h2 { font-size: 18px; }
    .greeting-icon { display: none; }
    
    .stat-card { padding: 14px 16px; }
    .stat-value { font-size: 20px; }
    .stat-icon-circle { width: 36px; height: 36px; font-size: 14px; }
    
    .quick-actions {
        grid-template-columns: repeat(auto-fill, minmax(100%, 1fr));
    }
    
    .card-header { padding: 12px 16px; font-size: 13px; }
    .card-body { padding: 14px 16px; }
    
    .chart-container { height: 180px; }
}

@media (max-width: 576px) {
    .greeting-section h2 { font-size: 16px; }
    .stat-value { font-size: 18px; }
    .dept-stats { gap: 10px; }
    .dept-stat .num { font-size: 15px; }
    .right-widget { padding: 14px 16px; }
}

/* ===== SCROLLBAR ===== */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: #f0f0f0; border-radius: 10px; }
::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
</style>

<!-- ===== GREETING SECTION ===== -->
<div class="greeting-section animate-fade-up">
    <div>
        <h2><?php echo $greeting; ?>, <?php echo sanitize($_SESSION['admin_name'] ?? 'Admin'); ?>! 🎉</h2>
        <p>Here is your company summary for <?php echo date('l, d F Y'); ?></p>
    </div>
    <div class="greeting-icon"><i class="fas fa-sun"></i></div>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="row g-4">
<div class="col-lg-8">

<?php if ($role === 'digital_marketing_admin'): ?>
<!-- Digital Marketing Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-6 animate-fade-up stagger-1">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--primary-light);color:var(--primary);"><i class="fas fa-users"></i></div>
                <div class="stat-label">Today's Leads</div>
                <div class="stat-value counter-animate"><?php echo $totalLeads; ?></div>
                <div class="stat-footer"><span class="trend-up"><i class="fas fa-arrow-up"></i> 12%</span> vs yesterday</div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6 animate-fade-up stagger-2">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--success-light);color:var(--success);"><i class="fas fa-bullhorn"></i></div>
                <div class="stat-label">Active Campaigns</div>
                <div class="stat-value counter-animate"><?php echo $activeCampaigns; ?></div>
                <div class="stat-footer"><span class="trend-up"><i class="fas fa-arrow-up"></i> 8%</span> vs last week</div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6 animate-fade-up stagger-3">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--warning-light);color:var(--warning);"><i class="fas fa-trophy"></i></div>
                <div class="stat-label">Won Today</div>
                <div class="stat-value counter-animate"><?php echo $wonLeads; ?></div>
                <div class="stat-footer"><span class="trend-neutral"><i class="fas fa-minus"></i> Same</span> as yesterday</div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($role === 'telecaller_admin'): ?>
<!-- Telecaller Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-6 animate-fade-up stagger-1">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--primary-light);color:var(--primary);"><i class="fas fa-phone"></i></div>
                <div class="stat-label">Today's Calls</div>
                <div class="stat-value counter-animate"><?php echo $totalCalls; ?></div>
                <div class="stat-footer"><span class="trend-up"><i class="fas fa-arrow-up"></i> 5%</span> vs yesterday</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-6 animate-fade-up stagger-2">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--warning-light);color:var(--warning);"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Pending Follow Ups</div>
                <div class="stat-value counter-animate"><?php echo $pendingFollowUps; ?></div>
                <div class="stat-footer"><span class="trend-down"><i class="fas fa-arrow-down"></i> 3%</span> vs yesterday</div>
            </div>
        </div>
    </div>
</div>

<?php elseif (in_array($role, ['hr_admin', 'hr'])): ?>
<!-- HR Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6 animate-fade-up stagger-1">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--primary-light);color:var(--primary);"><i class="fas fa-users"></i></div>
                <div class="stat-label">Total Employees</div>
                <div class="stat-value counter-animate"><?php echo $total; ?></div>
                <div class="stat-footer"><span class="trend-neutral"><i class="fas fa-building"></i> Active</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 animate-fade-up stagger-2">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--warning-light);color:var(--warning);"><i class="fas fa-envelope-open-text"></i></div>
                <div class="stat-label">Pending Leaves</div>
                <div class="stat-value counter-animate"><?php echo $pendingLeaves; ?></div>
                <div class="stat-footer"><span class="trend-down"><i class="fas fa-clock"></i> Needs action</span></div>
            </div>
        </div>
    </div>
    <?php if ($showAttendance): ?>
    <div class="col-md-3 col-6 animate-fade-up stagger-3">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--success-light);color:var(--success);"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Present Today</div>
                <div class="stat-value counter-animate" data-stat="present"><?php echo $todayStats['present'] ?? 0; ?></div>
                <div class="stat-footer"><span class="trend-up"><i class="fas fa-arrow-up"></i> Active</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 animate-fade-up stagger-4">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--danger-light);color:var(--danger);"><i class="fas fa-user-slash"></i></div>
                <div class="stat-label">Absent Today</div>
                <div class="stat-value counter-animate" data-stat="absent"><?php echo max(0, $absent); ?></div>
                <div class="stat-footer"><span class="trend-down"><i class="fas fa-exclamation-circle"></i> Needs review</span></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- General Stats -->
<?php if ($showAttendance): ?>
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6 animate-fade-up stagger-1">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--primary-light);color:var(--primary);"><i class="fas fa-users"></i></div>
                <div class="stat-label">Total Employees</div>
                <div class="stat-value counter-animate"><?php echo $total; ?></div>
                <div class="stat-footer"><span class="trend-neutral"><i class="fas fa-building"></i> All departments</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 animate-fade-up stagger-2">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--success-light);color:var(--success);"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Present Today</div>
                <div class="stat-value counter-animate" data-stat="present"><?php echo $todayStats['present'] ?? 0; ?></div>
                <div class="stat-footer">
                    <span class="trend-up"><i class="fas fa-arrow-up"></i> 
                    <span data-stat="percent"><?php echo $total > 0 ? round(($todayStats['present'] ?? 0) / $total * 100) : 0; ?></span>%
                    </span> of total
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 animate-fade-up stagger-3">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--warning-light);color:var(--warning);"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Late Today</div>
                <div class="stat-value counter-animate" data-stat="late"><?php echo $todayStats['late'] ?? 0; ?></div>
                <div class="stat-footer"><span class="trend-down"><i class="fas fa-exclamation-triangle"></i> Needs attention</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 animate-fade-up stagger-4">
        <div class="stat-card">
            <div class="stat-body">
                <div class="stat-icon-circle" style="background:var(--danger-light);color:var(--danger);"><i class="fas fa-user-slash"></i></div>
                <div class="stat-label">Absent Today</div>
                <div class="stat-value counter-animate" data-stat="absent"><?php echo max(0, $absent); ?></div>
                <div class="stat-footer"><span class="trend-down"><i class="fas fa-user-clock"></i> On leave: <span data-stat="on_leave"><?php echo $onLeave; ?></span></span></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ===== MINI STATS ROW ===== -->
<?php if (!in_array($role, ['digital_marketing_admin', 'telecaller_admin'])): ?>
<div class="row g-3 mb-4">
    <div class="col-4 col-md-2 animate-fade-up stagger-1">
        <div class="stat-card" style="padding:14px;">
            <div class="stat-body text-center">
                <div class="stat-icon-circle" style="width:36px;height:36px;margin:0 auto 8px;font-size:14px;background:var(--purple-light);color:var(--purple);"><i class="fas fa-building"></i></div>
                <div class="stat-label" style="font-size:10px;">Departments</div>
                <div class="stat-value" style="font-size:18px;"><?php echo $totalDepartments; ?></div>
            </div>
        </div>
    </div>
    <div class="col-4 col-md-2 animate-fade-up stagger-2">
        <div class="stat-card" style="padding:14px;">
            <div class="stat-body text-center">
                <div class="stat-icon-circle" style="width:36px;height:36px;margin:0 auto 8px;font-size:14px;background:var(--warning-light);color:var(--warning);"><i class="fas fa-envelope"></i></div>
                <div class="stat-label" style="font-size:10px;">Pending Leaves</div>
                <div class="stat-value" style="font-size:18px;"><?php echo $pendingLeavesAll; ?></div>
            </div>
        </div>
    </div>
    <div class="col-4 col-md-2 animate-fade-up stagger-3">
        <div class="stat-card" style="padding:14px;">
            <div class="stat-body text-center">
                <div class="stat-icon-circle" style="width:36px;height:36px;margin:0 auto 8px;font-size:14px;background:var(--info-light);color:var(--info);"><i class="fas fa-tasks"></i></div>
                <div class="stat-label" style="font-size:10px;">Open Tasks</div>
                <div class="stat-value" style="font-size:18px;"><?php echo $openTasks; ?></div>
            </div>
        </div>
    </div>
    <div class="col-4 col-md-2 animate-fade-up stagger-4">
        <div class="stat-card" style="padding:14px;">
            <div class="stat-body text-center">
                <div class="stat-icon-circle" style="width:36px;height:36px;margin:0 auto 8px;font-size:14px;background:var(--success-light);color:var(--success);"><i class="fas fa-rocket"></i></div>
                <div class="stat-label" style="font-size:10px;">Active Projects</div>
                <div class="stat-value" style="font-size:18px;"><?php echo $activeProjects; ?></div>
            </div>
        </div>
    </div>
    <div class="col-4 col-md-2 animate-fade-up stagger-5">
        <div class="stat-card" style="padding:14px;">
            <div class="stat-body text-center">
                <div class="stat-icon-circle" style="width:36px;height:36px;margin:0 auto 8px;font-size:14px;background:var(--danger-light);color:var(--danger);"><i class="fas fa-receipt"></i></div>
                <div class="stat-label" style="font-size:10px;">Pending Expenses</div>
                <div class="stat-value" style="font-size:18px;"><?php echo $pendingExpenses; ?></div>
            </div>
        </div>
    </div>
    <div class="col-4 col-md-2 animate-fade-up stagger-6">
        <div class="stat-card" style="padding:14px;">
            <div class="stat-body text-center">
                <div class="stat-icon-circle" style="width:36px;height:36px;margin:0 auto 8px;font-size:14px;background:var(--pink-light);color:var(--pink);"><i class="fas fa-user-clock"></i></div>
                <div class="stat-label" style="font-size:10px;">On Leave</div>
                <div class="stat-value" style="font-size:18px;"><?php echo $onLeave; ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($showAttendance): ?>

<!-- ===== CHARTS ROW ===== -->
<div class="row g-3 mb-4">
    <div class="col-lg-8 animate-fade-up">
        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-chart-line me-2" style="color:var(--primary);"></i>Attendance Trends (Last 30 Days)</span>
                <div class="card-actions">
                    <button class="btn btn-sm btn-outline-secondary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="attendanceChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 animate-fade-up stagger-2">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="fas fa-chart-pie me-2" style="color:var(--success);"></i>Today's Attendance</span>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <div class="chart-container" style="max-width:240px;height:240px;">
                    <canvas id="attendancePieChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== DEPARTMENT & WEEKLY CHARTS ===== -->
<div class="row g-3 mb-4">
    <div class="col-lg-6 animate-fade-up stagger-1">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="fas fa-chart-bar me-2" style="color:var(--purple);"></i>Department Wise Employees</span>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6 animate-fade-up stagger-2">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="fas fa-calendar-week me-2" style="color:var(--info);"></i>Weekly Attendance</span>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== DEPARTMENT OVERVIEW ===== -->
<div class="card mb-4 animate-fade-up">
    <div class="card-header">
        <span><i class="fas fa-building me-2" style="color:var(--primary);"></i>Department Overview</span>
        <a href="modules/departments.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php if (!empty($deptStatsFull)): ?>
                <?php foreach ($deptStatsFull as $d):
                    $absentCount = max(0, $d['total'] - $d['present'] - $d['late']);
                    $attPct = $d['total'] > 0 ? round(($d['present'] / $d['total']) * 100) : 0;
                    $barColor = $attPct >= 80 ? 'var(--success)' : ($attPct >= 50 ? 'var(--warning)' : 'var(--danger)');
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="dept-card">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="dept-name"><?php echo sanitize($d['department']); ?></div>
                            <span class="badge badge-<?php echo $attPct >= 80 ? 'success' : ($attPct >= 50 ? 'warning' : 'danger'); ?>">
                                <?php echo $attPct; ?>%
                            </span>
                        </div>
                        <div class="dept-stats">
                            <div class="dept-stat"><div class="num"><?php echo $d['total']; ?></div><div class="label">Total</div></div>
                            <div class="dept-stat"><div class="num" style="color:var(--success);"><?php echo $d['present']; ?></div><div class="label">Present</div></div>
                            <div class="dept-stat"><div class="num" style="color:var(--warning);"><?php echo $d['late']; ?></div><div class="label">Late</div></div>
                            <div class="dept-stat"><div class="num" style="color:var(--danger);"><?php echo $absentCount; ?></div><div class="label">Absent</div></div>
                        </div>
                        <div class="dept-progress">
                            <div class="bar" style="width:<?php echo $attPct; ?>%;background:<?php echo $barColor; ?>;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        <h6>No departments found</h6>
                        <p>Add departments to see overview here</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== TODAY'S ATTENDANCE TABLE ===== -->
<div class="card animate-fade-up">
    <div class="card-header">
        <span><i class="fas fa-clipboard-list me-2" style="color:var(--primary);"></i>Today's Attendance</span>
        <div class="card-actions">
            <a href="modules/attendance.php" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-wrapper">
            <div class="table-responsive">
            <table class="table table-hover mb-0" id="attendanceTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Code</th>
                        <th>Department</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($todayAttendance)): ?>
                        <?php $counter = 1; ?>
                        <?php foreach ($todayAttendance as $r): 
                            $statusClass = in_array($r['status'], ['present', 'Present']) ? 'present' : 
                                          (in_array($r['status'], ['late', 'Late']) ? 'late' : 'absent');
                        ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="user-avatar" style="background:var(--primary-light);color:var(--primary);">
                                        <?php echo strtoupper(substr($r['first_name'], 0, 1) . substr($r['last_name'], 0, 1)); ?>
                                    </div>
                                    <span class="fw-medium"><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></span>
                                </div>
                            </td>
                            <td><span class="text-muted"><?php echo sanitize($r['employee_code']); ?></span></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td><?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '-'; ?></td>
                            <td><?php echo $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '<span class="text-warning">Working</span>'; ?></td>
                            <td><span class="badge badge-<?php echo $statusClass; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-inbox me-2"></i>No attendance records for today
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== QUICK ACTIONS + RECENT ACTIVITY ===== -->
<?php if (!in_array($role, ['digital_marketing_admin', 'telecaller_admin'])): ?>
<div class="row g-3 mt-4">
    <div class="col-lg-7 animate-fade-up">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="fas fa-bolt me-2" style="color:var(--warning);"></i>Quick Actions</span>
            </div>
            <div class="card-body">
                <div class="quick-actions">
                    <a href="modules/employees.php" class="quick-action-btn blue"><i class="fas fa-user-plus"></i><span>Add Employee</span></a>
                    <a href="modules/attendance.php" class="quick-action-btn green"><i class="fas fa-calendar-check"></i><span>Mark Attendance</span></a>
                    <a href="modules/salary.php" class="quick-action-btn purple"><i class="fas fa-money-bill-wave"></i><span>Generate Salary</span></a>
                    <a href="modules/tasks.php" class="quick-action-btn info"><i class="fas fa-tasks"></i><span>Create Task</span></a>
                    <a href="modules/leads.php" class="quick-action-btn orange"><i class="fas fa-users-cog"></i><span>Add Lead</span></a>
                    <a href="modules/notices.php" class="quick-action-btn pink"><i class="fas fa-bullhorn"></i><span>Create Notice</span></a>
                    <a href="modules/leaves.php" class="quick-action-btn red"><i class="fas fa-envelope-open-text"></i><span>Approve Leave</span></a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5 animate-fade-up stagger-2">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="fas fa-clock me-2" style="color:var(--info);"></i>Recent Activity</span>
            </div>
            <div class="card-body">
                <?php if (!empty($recentActivity)): ?>
                <div class="timeline">
                    <?php foreach ($recentActivity as $act):
                        $dotColor = $act['type'] == 'attendance' ? 'blue' : ($act['type'] == 'leave' ? 'orange' : ($act['type'] == 'task' ? 'purple' : 'green'));
                        $icon = $act['type'] == 'attendance' ? 'fa-calendar-check' : ($act['type'] == 'leave' ? 'fa-envelope' : ($act['type'] == 'task' ? 'fa-tasks' : 'fa-users-cog'));
                        $timeAgo = '';
                        if ($act['created_at']) {
                            $ts = strtotime($act['created_at']);
                            $diff = time() - $ts;
                            if ($diff < 60) $timeAgo = 'Just now';
                            elseif ($diff < 3600) $timeAgo = floor($diff/60) . 'm ago';
                            elseif ($diff < 86400) $timeAgo = floor($diff/3600) . 'h ago';
                            else $timeAgo = date('d M', $ts);
                        }
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-dot <?php echo $dotColor; ?>"></div>
                        <div class="timeline-time"><?php echo $timeAgo; ?></div>
                        <div class="timeline-title"><i class="fas <?php echo $icon; ?> me-1" style="font-size:11px;"></i><?php echo sanitize($act['actor']); ?></div>
                        <div class="timeline-desc"><?php echo sanitize($act['action']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h6>No recent activity</h6>
                    <p>Activity will appear here as things happen</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</div> <!-- End col-lg-8 -->

<!-- ===== RIGHT SIDEBAR ===== -->
<div class="col-lg-4">
    <div class="right-sidebar">

        <!-- Logo Widget -->
        <div class="right-widget animate-fade-up">
            <div class="d-flex justify-content-center align-items-center">
                <img src="https://iili.io/CEx2XpI.png" alt="YATHARTH GROUP OF INSTITUTION" style="height:50px;width:auto;object-fit:contain;">
            </div>
            <div class="text-center mt-2" style="font-size:12px;color:var(--gray);">
                <i class="fas fa-calendar-alt me-1"></i> <?php echo date('d M Y'); ?>
            </div>
        </div>

        <!-- Calendar Widget -->
        <div class="right-widget animate-fade-up stagger-1">
            <div class="widget-title"><i class="fas fa-calendar-alt" style="color:var(--primary);"></i> Calendar</div>
            <div id="miniCalendar"></div>
        </div>

        <!-- Birthdays -->
        <div class="right-widget animate-fade-up stagger-2">
            <div class="widget-title"><i class="fas fa-birthday-cake" style="color:var(--pink);"></i> Birthdays</div>
            <?php if (!empty($todayBirthdays)): ?>
                <?php foreach ($todayBirthdays as $b): ?>
                <div class="widget-birthday">
                    <div class="bday-avatar"><?php echo strtoupper(substr($b['first_name'], 0, 1)); ?></div>
                    <div class="bday-info">
                        <div class="name"><?php echo sanitize($b['first_name'] . ' ' . $b['last_name']); ?> 🎂</div>
                        <div class="dept">Birthday Today!</div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php elseif (!empty($birthdays)): ?>
                <?php foreach ($birthdays as $b): ?>
                <div class="widget-birthday">
                    <div class="bday-avatar"><?php echo strtoupper(substr($b['first_name'], 0, 1)); ?></div>
                    <div class="bday-info">
                        <div class="name"><?php echo sanitize($b['first_name'] . ' ' . $b['last_name']); ?></div>
                        <div class="dept"><?php echo date('d M', strtotime($b['date_of_birth'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-muted" style="font-size:13px;">No upcoming birthdays</div>
            <?php endif; ?>
        </div>

        <!-- Holidays -->
        <div class="right-widget animate-fade-up stagger-3">
            <div class="widget-title"><i class="fas fa-star" style="color:var(--orange);"></i> Upcoming Holidays</div>
            <?php if (!empty($upcomingHolidays)): ?>
                <?php foreach ($upcomingHolidays as $h): ?>
                <div class="widget-holiday">
                    <div class="holiday-icon"><i class="fas fa-calendar-day"></i></div>
                    <div class="holiday-info">
                        <div class="name"><?php echo sanitize($h['title']); ?></div>
                        <div class="date"><?php echo date('d M Y', strtotime($h['holiday_date'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-muted" style="font-size:13px;">No upcoming holidays</div>
            <?php endif; ?>
        </div>

        <!-- Meetings -->
        <div class="right-widget animate-fade-up stagger-4">
            <div class="widget-title"><i class="fas fa-handshake" style="color:var(--purple);"></i> Upcoming Meetings</div>
            <?php if (!empty($upcomingMeetings)): ?>
                <?php foreach ($upcomingMeetings as $m): ?>
                <div class="widget-holiday">
                    <div class="holiday-icon" style="background:var(--purple-light);color:var(--purple);"><i class="fas fa-video"></i></div>
                    <div class="holiday-info">
                        <div class="name"><?php echo sanitize($m['title']); ?></div>
                        <div class="date"><?php echo date('d M Y', strtotime($m['meeting_date'])); ?> <?php echo $m['start_time'] ? date('h:i A', strtotime($m['start_time'])) : ''; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-muted" style="font-size:13px;">No upcoming meetings</div>
            <?php endif; ?>
        </div>

        <!-- Weather Widget -->
        <div class="right-widget weather-widget animate-fade-up stagger-5">
            <div class="weather-icon">☀️</div>
            <div class="weather-temp" id="weatherTemp">32°C</div>
            <div class="weather-desc" id="weatherDesc">Sunny - New Delhi</div>
            <div class="weather-details">
                <span><i class="fas fa-tint"></i> <span id="weatherHumidity">45%</span></span>
                <span><i class="fas fa-wind"></i> <span id="weatherWind">12 km/h</span></span>
            </div>
        </div>

        <!-- Quote Widget -->
        <div class="right-widget quote-widget animate-fade-up stagger-6">
            <div class="quote-icon"><i class="fas fa-quote-left"></i></div>
            <div class="quote-text">"Success is not final, failure is not fatal: it is the courage to continue that counts."</div>
            <div class="quote-author">— Winston Churchill</div>
        </div>

    </div>
</div>

</div> <!-- End row -->

<!-- ===== SCRIPTS ===== -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">

<script>
// ===== CHART DATA FROM PHP =====
const chartData = <?php echo $chartDataJson; ?>;

// ===== ATTENDANCE CHART =====
function createAttendanceChart() {
    const ctx = document.getElementById('attendanceChart');
    if (!ctx) return;
    
    if (chartData.labels && chartData.labels.length > 0) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Present',
                        data: chartData.present,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22,163,74,0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Late',
                        data: chartData.late,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245,158,11,0.1)',
                        fill: true,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    } else {
        ctx.parentElement.innerHTML = '<div class="text-muted text-center py-4"><i class="fas fa-chart-line me-2"></i>No data available</div>';
    }
}

// ===== ATTENDANCE PIE CHART =====
function createAttendancePieChart() {
    const ctx = document.getElementById('attendancePieChart');
    if (!ctx) return;
    
    const present = chartData.presentCount || 0;
    const late = chartData.lateCount || 0;
    const absent = chartData.absentCount || 0;
    const total = present + late + absent;
    
    if (total > 0) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Present', 'Late', 'Absent'],
                datasets: [{
                    data: [present, late, absent],
                    backgroundColor: ['#16a34a', '#f59e0b', '#dc2626'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 10, usePointStyle: true } }
                },
                cutout: '65%'
            }
        });
    } else {
        ctx.parentElement.innerHTML = '<div class="text-muted text-center py-4"><i class="fas fa-chart-pie me-2"></i>No data</div>';
    }
}

// ===== DEPARTMENT CHART =====
function createDepartmentChart() {
    const ctx = document.getElementById('departmentChart');
    if (!ctx) return;
    
    if (chartData.deptLabels && chartData.deptLabels.length > 0) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.deptLabels,
                datasets: [{
                    label: 'Employees',
                    data: chartData.deptData,
                    backgroundColor: 'rgba(124,58,237,0.7)',
                    borderColor: '#7c3aed',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    } else {
        ctx.parentElement.innerHTML = '<div class="text-muted text-center py-4"><i class="fas fa-chart-bar me-2"></i>No departments</div>';
    }
}

// ===== WEEKLY CHART =====
function createWeeklyChart() {
    const ctx = document.getElementById('weeklyChart');
    if (!ctx) return;
    
    if (chartData.weeklyLabels && chartData.weeklyLabels.length > 0) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.weeklyLabels,
                datasets: [
                    { label: 'Present', data: chartData.weeklyPresent, backgroundColor: 'rgba(22,163,74,0.7)' },
                    { label: 'Absent', data: chartData.weeklyAbsent, backgroundColor: 'rgba(220,38,38,0.7)' },
                    { label: 'Late', data: chartData.weeklyLate, backgroundColor: 'rgba(245,158,11,0.7)' }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true } }
                },
                scales: {
                    x: { stacked: false },
                    y: { beginAtZero: true }
                }
            }
        });
    } else {
        ctx.parentElement.innerHTML = '<div class="text-muted text-center py-4"><i class="fas fa-calendar-week me-2"></i>No weekly data</div>';
    }
}

// ===== MINI CALENDAR =====
function renderMiniCalendar() {
    const container = document.getElementById('miniCalendar');
    if (!container) return;
    
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth();
    const today = now.getDate();
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    
    let html = `
        <div style="text-align:center;font-weight:600;font-size:14px;margin-bottom:8px;">
            ${now.toLocaleString('default', { month: 'long' })} ${year}
        </div>
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;font-size:11px;color:var(--gray);margin-bottom:4px;">
            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;font-size:12px;">
    `;
    
    for (let i = 0; i < firstDay; i++) {
        html += `<span></span>`;
    }
    
    for (let day = 1; day <= daysInMonth; day++) {
        const isToday = day === today;
        const bg = isToday ? 'var(--primary)' : 'transparent';
        const color = isToday ? '#fff' : 'var(--text-color)';
        html += `<span style="padding:4px 0;border-radius:50%;background:${bg};color:${color};font-weight:${isToday?'700':'400'};">${day}</span>`;
    }
    
    html += `</div>`;
    container.innerHTML = html;
}

// ===== WEATHER WIDGET (Mock - Replace with real API) =====
function updateWeather() {
    // You can replace this with actual OpenWeatherMap API call
    document.getElementById('weatherTemp').textContent = '32°C';
    document.getElementById('weatherDesc').textContent = '☀️ Sunny - New Delhi';
    document.getElementById('weatherHumidity').textContent = '45%';
    document.getElementById('weatherWind').textContent = '12 km/h';
}

// ===== INIT DATATABLE =====
$(document).ready(function() {
    // Check if DataTable exists and destroy it
    if ($.fn.DataTable.isDataTable('#attendanceTable')) {
        $('#attendanceTable').DataTable().destroy();
    }
    
    // ✅ FIXED: DataTable with correct column count
    $('#attendanceTable').DataTable({
        pageLength: 10,
        ordering: true,
        searching: true,
        responsive: false,
        columnDefs: [
            { orderable: false, targets: [0] }, // # column
            { searchable: true, targets: [1, 2, 3, 4, 5, 6] }
        ],
        language: {
            emptyTable: "No attendance records found",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "Showing 0 to 0 of 0 entries",
            infoFiltered: "(filtered from _MAX_ total entries)",
            search: "Search:",
            lengthMenu: "Show _MENU_ entries",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        }
    });
});

// ===== INIT ALL CHARTS =====
document.addEventListener('DOMContentLoaded', function() {
    renderMiniCalendar();
    updateWeather();
    
    // Initialize charts with delay for better rendering
    setTimeout(() => {
        createAttendanceChart();
        createAttendancePieChart();
        createDepartmentChart();
        createWeeklyChart();
    }, 100);
});

// ===== LIVE ATTENDANCE FIGURES =====
// Refreshes only the four numbers, once a minute, so somebody clocking in shows
// up without the page jumping. A full window.location.reload() used to live
// here (commented out): it re-ran every query on the page and threw away the
// admin's scroll position to update four integers.
(function liveAttendanceCounts() {
    var FIELDS = ['present', 'late', 'absent', 'on_leave', 'percent'];

    function paint(data) {
        FIELDS.forEach(function (key) {
            if (data[key] === undefined || data[key] === null) return;
            document.querySelectorAll('[data-stat="' + key + '"]').forEach(function (el) {
                var next = String(data[key]);
                if (el.textContent.trim() === next) return;   // nothing to do
                el.textContent = next;
                // A brief highlight, so a change is noticed rather than just
                // silently appearing between glances at the screen.
                el.style.transition = 'color .2s';
                el.style.color = 'var(--primary, #1E3A5F)';
                setTimeout(function () { el.style.color = ''; }, 1200);
            });
        });
    }

    function poll() {
        // Never let a failed poll surface as an error: the figures on screen
        // are still valid, they are simply a minute old.
        fetch('dashboard.php?stats=1', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) paint(d); })
            .catch(function () {});
    }

    // Pause while the tab is in the background; resume on return.
    setInterval(function () {
        if (!document.hidden) poll();
    }, 60000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll();
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>