<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('reports');

$reportType = $_GET['type'] ?? 'daily';
$month = filterMonth($_GET['month'] ?? '');
$date = filterDate($_GET['date'] ?? '');
$deptId = $_GET['department_id'] ?? '';
$empId = $_GET['employee_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$startDate = filterDate($_GET['start_date'] ?? '', date('Y-m-01'));
$endDate = filterDate($_GET['end_date'] ?? '', date('Y-m-t'));

$depts = $pdo->query("SELECT id, name FROM departments WHERE status = 1 ORDER BY name")->fetchAll();

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-file-alt me-2"></i>Reports</h4>
</div>

<div class="card mb-3">
    <div class="card-body">
        <ul class="nav nav-tabs">
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'daily' ? 'active' : ''; ?>" href="?type=daily">Daily</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'monthly' ? 'active' : ''; ?>" href="?type=monthly">Monthly</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'late' ? 'active' : ''; ?>" href="?type=late">Late</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'absent' ? 'active' : ''; ?>" href="?type=absent">Absent</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'salary' ? 'active' : ''; ?>" href="?type=salary">Salary</a></li>
            <?php if (hasModuleAccess('daily_work_reports')): ?>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'work' ? 'active' : ''; ?>" href="?type=work">Work Report</a></li>
            <?php endif; ?>
            <?php if (hasModuleAccess('tasks')): ?>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'task' ? 'active' : ''; ?>" href="?type=task">Task Report</a></li>
            <?php endif; ?>
            <?php if (hasModuleAccess('leads')): ?>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'lead' ? 'active' : ''; ?>" href="?type=lead">Lead Report</a></li>
            <?php endif; ?>
            <?php if (hasModuleAccess('call_reports')): ?>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'call' ? 'active' : ''; ?>" href="?type=call">Call Report</a></li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<?php if ($reportType == 'daily'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT a.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM attendance a JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE a.attendance_date = ? ORDER BY e.first_name ASC");
    $stmt->execute([$date]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between"><span>Daily Attendance - <?php echo date('d F Y', strtotime($date)); ?></span><form method="GET" class="d-inline"><input type="hidden" name="type" value="daily"><input type="date" name="date" value="<?php echo $date; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()"></form></div>
        <div class="card-body p-0">
            <div class="table-responsive"><table class="table table-hover datatable mb-0"><thead><tr><th>Employee</th><th>Code</th><th>Dept</th><th>Check In</th><th>Check Out</th><th>Working Hrs</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($records as $r): ?><tr><td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo sanitize($r['employee_code']); ?></td><td><?php echo sanitize($r['department_name'] ?? '-'); ?></td><td><?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '-'; ?></td><td><?php echo $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '-'; ?></td><td><?php echo formatWorkedHours($r['working_hours'] ?? null) ?? '-'; ?></td><td><span class="badge bg-<?php echo $r['status'] == 'present' ? 'success' : ($r['status'] == 'late' ? 'warning' : 'danger'); ?>"><?php echo ucfirst($r['status']); ?></span></td></tr><?php endforeach; ?><?php if (empty($records)): ?><tr><td colspan="7" class="text-center py-3 text-muted">No records</td></tr><?php endif; ?></tbody></table></div></div></div>

<?php elseif ($reportType == 'monthly'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT e.id, e.first_name, e.last_name, e.employee_code, d.name as department_name, COUNT(a.id) as total_days, SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late, SUM(CASE WHEN a.status = 'half-day' THEN 1 ELSE 0 END) as half_day, SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent, COALESCE(SUM(a.late_minutes), 0) as total_late_minutes FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN attendance a ON a.employee_id = e.id AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ? WHERE e.status = 1 GROUP BY e.id, d.name ORDER BY e.first_name ASC");
    $stmt->execute([$month]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between"><span>Monthly Report - <?php echo date('F Y', strtotime($month . '-01')); ?></span><form method="GET" class="d-inline"><input type="hidden" name="type" value="monthly"><input type="month" name="month" value="<?php echo $month; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()"></form></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead><tr><th>Employee</th><th>Code</th><th>Dept</th><th>Present</th><th>Late</th><th>Half Day</th><th>Absent</th><th>Late Mins</th></tr></thead>
            <tbody><?php foreach ($records as $r): ?><tr><td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo sanitize($r['employee_code']); ?></td><td><?php echo sanitize($r['department_name'] ?? '-'); ?></td><td><span class="text-success fw-bold"><?php echo $r['present']; ?></span></td><td><span class="text-warning fw-bold"><?php echo $r['late']; ?></span></td><td><span class="text-danger fw-bold"><?php echo $r['half_day']; ?></span></td><td><span class="text-secondary fw-bold"><?php echo $r['absent']; ?></span></td><td><?php echo $r['total_late_minutes']; ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>

<?php elseif ($reportType == 'late'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT a.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM attendance a JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE a.status = 'late' AND a.attendance_date = ? ORDER BY a.late_minutes DESC");
    $stmt->execute([$date]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between"><span>Late Report - <?php echo date('d F Y', strtotime($date)); ?></span><form method="GET" class="d-inline"><input type="hidden" name="type" value="late"><input type="date" name="date" value="<?php echo $date; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()"></form></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead><tr><th>Employee</th><th>Code</th><th>Dept</th><th>Check In</th><th>Late Minutes</th></tr></thead>
            <tbody><?php foreach ($records as $r): ?><tr><td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo sanitize($r['employee_code']); ?></td><td><?php echo sanitize($r['department_name'] ?? '-'); ?></td><td><?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '-'; ?></td><td><span class="badge bg-warning"><?php echo $r['late_minutes']; ?> min</span></td></tr><?php endforeach; ?><?php if (empty($records)): ?><tr><td colspan="5" class="text-center py-3 text-muted">No late arrivals today</td></tr><?php endif; ?></tbody></table></div></div></div>

<?php elseif ($reportType == 'absent'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT e.id, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.status = 1 AND e.id NOT IN (SELECT employee_id FROM attendance WHERE attendance_date = ?) ORDER BY e.first_name ASC");
    $stmt->execute([$date]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card">
        <div class="card-header"><span>Absent Report - <?php echo date('d F Y', strtotime($date)); ?></span></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead><tr><th>Employee</th><th>Code</th><th>Dept</th></tr></thead>
            <tbody><?php foreach ($records as $r): ?><tr><td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo sanitize($r['employee_code']); ?></td><td><?php echo sanitize($r['department_name'] ?? '-'); ?></td></tr><?php endforeach; ?><?php if (empty($records)): ?><tr><td colspan="3" class="text-center py-3 text-muted">No absent employees today</td></tr><?php endif; ?></tbody></table></div></div></div>

<?php elseif ($reportType == 'salary'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT e.id, e.first_name, e.last_name, e.employee_code, e.salary, d.name as department_name, COALESCE(att.present, 0) as present_days, COALESCE(att.late, 0) as late_days, COALESCE(att.half_day, 0) as half_days, COALESCE(att.absent, 0) as absent_days, COALESCE(ded.total_deduction, 0) as total_deduction FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN (SELECT employee_id, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late, SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_day, SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent FROM attendance WHERE DATE_FORMAT(attendance_date, '%Y-%m') = ? GROUP BY employee_id) att ON att.employee_id = e.id LEFT JOIN (SELECT employee_id, SUM(deduction_amount) as total_deduction FROM salary_deductions WHERE DATE_FORMAT(deduction_date, '%Y-%m') = ? GROUP BY employee_id) ded ON ded.employee_id = e.id WHERE e.status = 1 ORDER BY e.first_name ASC");
    $stmt->execute([$month, $month]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between"><span>Salary Report - <?php echo date('F Y', strtotime($month . '-01')); ?></span><form method="GET" class="d-inline"><input type="hidden" name="type" value="salary"><input type="month" name="month" value="<?php echo $month; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()"></form></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead><tr><th>Employee</th><th>Dept</th><th>Salary</th><th>Present</th><th>Late</th><th>Half</th><th>Absent</th><th>Deduction</th><th>Net</th></tr></thead>
            <tbody><?php foreach ($records as $r): $daily = $r['salary'] > 0 ? $r['salary'] / 30 : 0; $ded = $r['half_days'] * ($daily * 0.5) + $r['absent_days'] * $daily; $net = $r['salary'] - $ded; ?><tr><td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo sanitize($r['department_name'] ?? '-'); ?></td><td>₹<?php echo number_format($r['salary'], 2); ?></td><td><?php echo $r['present_days']; ?></td><td><?php echo $r['late_days']; ?></td><td><?php echo $r['half_days']; ?></td><td><?php echo $r['absent_days']; ?></td><td class="text-danger">₹<?php echo number_format($ded, 2); ?></td><td class="fw-bold">₹<?php echo number_format($net, 2); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>

<?php elseif ($reportType == 'work'): ?>
    <?php
    $sql = "SELECT w.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM daily_work_reports w JOIN employees e ON e.id = w.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE w.report_date BETWEEN ? AND ?";
    $params = [$startDate, $endDate];
    if ($deptId) { $sql .= " AND e.department_id = ?"; $params[] = $deptId; }
    if ($empId) { $sql .= " AND w.employee_id = ?"; $params[] = $empId; }
    if ($statusFilter) { $sql .= " AND w.status = ?"; $params[] = $statusFilter; }
    $sql .= " ORDER BY w.report_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    $emps = $deptId ? $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE status = 1 AND department_id = ? ORDER BY first_name") : $pdo->query("SELECT id, first_name, last_name FROM employees WHERE status = 1 ORDER BY first_name");
    if ($deptId) $emps->execute([$deptId]);
    $emps = $emps->fetchAll();
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="work">
                <div class="col-auto"><label class="form-label small">Department</label><select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All</option><?php foreach ($depts as $d): ?><option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-auto"><label class="form-label small">Employee</label><select name="employee_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>" <?php echo $empId == $e['id'] ? 'selected' : ''; ?>><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-auto"><label class="form-label small">From</label><input type="date" name="start_date" value="<?php echo $startDate; ?>" class="form-control form-control-sm"></div>
                <div class="col-auto"><label class="form-label small">To</label><input type="date" name="end_date" value="<?php echo $endDate; ?>" class="form-control form-control-sm"></div>
                <div class="col-auto"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="submitted" <?php echo $statusFilter=='submitted'?'selected':''; ?>>Submitted</option><option value="approved" <?php echo $statusFilter=='approved'?'selected':''; ?>>Approved</option><option value="rejected" <?php echo $statusFilter=='rejected'?'selected':''; ?>>Rejected</option></select></div>
                <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary">Filter</button></div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><span>Work Reports (<?php echo count($records); ?>)</span></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead><tr><th>Employee</th><th>Dept</th><th>Date</th><th>Title</th><th>Hours</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($records as $r): ?><tr><td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo sanitize($r['department_name'] ?? '-'); ?></td><td><?php echo $r['report_date']; ?></td><td><?php echo sanitize($r['title']); ?></td><td><?php echo $r['hours_worked']; ?></td><td><span class="badge bg-<?php echo $r['status'] == 'approved' ? 'success' : ($r['status'] == 'rejected' ? 'danger' : 'primary'); ?>"><?php echo ucfirst($r['status']); ?></span></td></tr><?php endforeach; ?><?php if (empty($records)): ?><tr><td colspan="6" class="text-center py-3 text-muted">No reports found</td></tr><?php endif; ?></tbody></table></div></div></div>

<?php elseif ($reportType == 'task'): ?>
    <?php
    $sql = "SELECT t.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM tasks t JOIN employees e ON e.id = t.assigned_to LEFT JOIN departments d ON d.id = e.department_id WHERE 1=1";
    $params = [];
    if ($deptId) { $sql .= " AND e.department_id = ?"; $params[] = $deptId; }
    if ($empId) { $sql .= " AND t.assigned_to = ?"; $params[] = $empId; }
    if ($statusFilter) { $sql .= " AND t.status = ?"; $params[] = $statusFilter; }
    $sql .= " ORDER BY t.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    $emps = $deptId ? $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE status = 1 AND department_id = ? ORDER BY first_name") : $pdo->query("SELECT id, first_name, last_name FROM employees WHERE status = 1 ORDER BY first_name");
    if ($deptId) $emps->execute([$deptId]);
    $emps = $emps->fetchAll();
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="task">
                <div class="col-auto"><label class="form-label small">Department</label><select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All</option><?php foreach ($depts as $d): ?><option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-auto"><label class="form-label small">Employee</label><select name="employee_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>" <?php echo $empId == $e['id'] ? 'selected' : ''; ?>><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-auto"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="Pending" <?php echo $statusFilter=='Pending'?'selected':''; ?>>Pending</option><option value="In Progress" <?php echo $statusFilter=='In Progress'?'selected':''; ?>>In Progress</option><option value="Completed" <?php echo $statusFilter=='Completed'?'selected':''; ?>>Completed</option><option value="Cancelled" <?php echo $statusFilter=='Cancelled'?'selected':''; ?>>Cancelled</option></select></div>
                <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary">Filter</button></div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><span>Task Reports (<?php echo count($records); ?>)</span></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead><tr><th>Employee</th><th>Dept</th><th>Title</th><th>Priority</th><th>Due</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($records as $r): ?><tr><td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo sanitize($r['department_name'] ?? '-'); ?></td><td><?php echo sanitize($r['title']); ?></td><td><span class="badge bg-<?php echo $r['priority'] == 'Urgent' ? 'danger' : ($r['priority'] == 'High' ? 'warning' : ($r['priority'] == 'Medium' ? 'info' : 'secondary')); ?>"><?php echo $r['priority']; ?></span></td><td><?php echo $r['due_date'] ?? '-'; ?></td><td><span class="badge bg-<?php echo $r['status'] == 'Completed' ? 'success' : ($r['status'] == 'In Progress' ? 'primary' : ($r['status'] == 'Cancelled' ? 'secondary' : 'warning')); ?>"><?php echo $r['status']; ?></span></td></tr><?php endforeach; ?><?php if (empty($records)): ?><tr><td colspan="6" class="text-center py-3 text-muted">No tasks found</td></tr><?php endif; ?></tbody></table></div></div></div>

<?php elseif ($reportType == 'lead'): ?>
    <?php
    $sql = "SELECT l.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM leads l JOIN employees e ON e.id = l.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE DATE(l.created_at) BETWEEN ? AND ?";
    $params = [$startDate, $endDate];
    if ($deptId) { $sql .= " AND e.department_id = ?"; $params[] = $deptId; }
    if ($empId) { $sql .= " AND l.employee_id = ?"; $params[] = $empId; }
    if ($statusFilter) { $sql .= " AND l.status = ?"; $params[] = $statusFilter; }
    $sql .= " ORDER BY l.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    $emps = $deptId ? $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE status = 1 AND department_id = ? ORDER BY first_name") : $pdo->query("SELECT id, first_name, last_name FROM employees WHERE status = 1 ORDER BY first_name");
    if ($deptId) $emps->execute([$deptId]);
    $emps = $emps->fetchAll();
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="lead">
                <div class="col-auto"><label class="form-label small">Department</label><select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All</option><?php foreach ($depts as $d): ?><option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-auto"><label class="form-label small">Employee</label><select name="employee_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>" <?php echo $empId == $e['id'] ? 'selected' : ''; ?>><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-auto"><label class="form-label small">From</label><input type="date" name="start_date" value="<?php echo $startDate; ?>" class="form-control form-control-sm"></div>
                <div class="col-auto"><label class="form-label small">To</label><input type="date" name="end_date" value="<?php echo $endDate; ?>" class="form-control form-control-sm"></div>
                <div class="col-auto"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="new" <?php echo $statusFilter=='new'?'selected':''; ?>>New</option><option value="contacted" <?php echo $statusFilter=='contacted'?'selected':''; ?>>Contacted</option><option value="qualified" <?php echo $statusFilter=='qualified'?'selected':''; ?>>Qualified</option><option value="won" <?php echo $statusFilter=='won'?'selected':''; ?>>Won</option><option value="lost" <?php echo $statusFilter=='lost'?'selected':''; ?>>Lost</option></select></div>
                <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary">Filter</button></div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><span>Lead Reports (<?php echo count($records); ?>)</span></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead><tr><th>Customer</th><th>Phone</th><th>Employee</th><th>Dept</th><th>Source</th><th>Status</th><th>Date</th></tr></thead>
            <tbody><?php foreach ($records as $r): ?><tr><td><?php echo sanitize($r['customer_name']); ?></td><td><?php echo sanitize($r['customer_phone']); ?></td><td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo sanitize($r['department_name'] ?? '-'); ?></td><td><?php echo sanitize($r['source']); ?></td><td><span class="badge bg-<?php echo $r['status'] == 'won' ? 'success' : ($r['status'] == 'lost' ? 'danger' : ($r['status'] == 'new' ? 'info' : 'warning')); ?>"><?php echo ucfirst($r['status']); ?></span></td><td><?php echo date('d M Y', strtotime($r['created_at'])); ?></td></tr><?php endforeach; ?><?php if (empty($records)): ?><tr><td colspan="7" class="text-center py-3 text-muted">No leads found</td></tr><?php endif; ?></tbody></table></div></div></div>

<?php elseif ($reportType == 'call'): ?>
    <?php
    $sql = "SELECT c.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM call_reports c JOIN employees e ON e.id = c.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE c.call_date BETWEEN ? AND ?";
    $params = [$startDate, $endDate];
    if ($deptId) { $sql .= " AND e.department_id = ?"; $params[] = $deptId; }
    if ($empId) { $sql .= " AND c.employee_id = ?"; $params[] = $empId; }
    $sql .= " ORDER BY c.call_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    $emps = $deptId ? $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE status = 1 AND department_id = ? ORDER BY first_name") : $pdo->query("SELECT id, first_name, last_name FROM employees WHERE status = 1 ORDER BY first_name");
    if ($deptId) $emps->execute([$deptId]);
    $emps = $emps->fetchAll();
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="call">
                <div class="col-auto"><label class="form-label small">Department</label><select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All</option><?php foreach ($depts as $d): ?><option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-auto"><label class="form-label small">Employee</label><select name="employee_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($emps as $e): ?><option value="<?php echo $e['id']; ?>" <?php echo $empId == $e['id'] ? 'selected' : ''; ?>><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-auto"><label class="form-label small">From</label><input type="date" name="start_date" value="<?php echo $startDate; ?>" class="form-control form-control-sm"></div>
                <div class="col-auto"><label class="form-label small">To</label><input type="date" name="end_date" value="<?php echo $endDate; ?>" class="form-control form-control-sm"></div>
                <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary">Filter</button></div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><span>Call Reports (<?php echo count($records); ?>)</span></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover datatable mb-0"><thead><tr><th>Customer</th><th>Employee</th><th>Dept</th><th>Duration</th><th>Type</th><th>Status</th><th>Date</th></tr></thead>
            <tbody><?php foreach ($records as $r): ?><tr><td><?php echo sanitize($r['customer_name']); ?></td><td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo sanitize($r['department_name'] ?? '-'); ?></td><td><?php echo gmdate('i:s', $r['call_duration']); ?></td><td><?php echo ucfirst(str_replace('_', ' ', $r['call_type'])); ?></td><td><span class="badge bg-<?php echo $r['status'] == 'completed' ? 'success' : ($r['status'] == 'callback' ? 'warning' : 'danger'); ?>"><?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?></span></td><td><?php echo $r['call_date']; ?></td></tr><?php endforeach; ?><?php if (empty($records)): ?><tr><td colspan="7" class="text-center py-3 text-muted">No call reports found</td></tr><?php endif; ?></tbody></table></div></div></div>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
