<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('reports');

$reportType = $_GET['type'] ?? 'working';
$month = filterMonth($_GET['month'] ?? '');
$date = filterDate($_GET['date'] ?? '');
$deptId = $_GET['department_id'] ?? '';
$empId = $_GET['employee_id'] ?? '';
$designationId = $_GET['designation_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$searchEmp = trim($_GET['search_emp'] ?? '');
$preset = $_GET['preset'] ?? '';

// Handle quick presets for working report
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$thisWeekStart = date('Y-m-d', strtotime('monday this week'));
$thisWeekEnd = date('Y-m-d', strtotime('sunday this week'));
$thisMonthStart = date('Y-m-01');
$thisMonthEnd = date('Y-m-t');

if ($preset === 'today') {
    $startDate = $today;
    $endDate = $today;
} elseif ($preset === 'yesterday') {
    $startDate = $yesterday;
    $endDate = $yesterday;
} elseif ($preset === 'this_week') {
    $startDate = $thisWeekStart;
    $endDate = $thisWeekEnd;
} elseif ($preset === 'this_month') {
    $startDate = $thisMonthStart;
    $endDate = $thisMonthEnd;
} else {
    $startDate = filterDate($_GET['start_date'] ?? '', $today);
    $endDate = filterDate($_GET['end_date'] ?? '', $today);
}

$depts = $pdo->query("SELECT id, name FROM departments WHERE status = 1 ORDER BY name")->fetchAll();
$designations = $pdo->query("SELECT id, name FROM designations WHERE status = 1 ORDER BY name")->fetchAll();
$allEmployees = $pdo->query("SELECT id, first_name, last_name, employee_code, department_id, designation_id FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
?>

<style>
.stat-card-clean {
    border-radius: 10px;
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: transform 0.15s ease;
}
.stat-card-clean:hover {
    transform: translateY(-2px);
}
.stat-card-clean .stat-val {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.2;
}
.stat-card-clean .stat-lbl {
    font-size: 0.78rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.btn-preset {
    font-size: 0.8rem;
    padding: 0.25rem 0.65rem;
    border-radius: 20px;
}
.pulse-badge {
    animation: pulse 1.8s infinite;
}
@keyframes pulse {
    0% { transform: scale(0.98); opacity: 0.85; }
    50% { transform: scale(1.03); opacity: 1; }
    100% { transform: scale(0.98); opacity: 0.85; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-file-invoice me-2 text-primary"></i>Reports & Analytics</h4>
        <small class="text-muted">Comprehensive Employee Working, Attendance, Performance & Departmental Reports</small>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-2">
        <ul class="nav nav-pills gap-1">
            <li class="nav-item">
                <a class="nav-link <?php echo $reportType == 'working' ? 'active' : ''; ?>" href="?type=working">
                    <i class="fas fa-user-clock me-1"></i> Employee Working Report
                </a>
            </li>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'daily' ? 'active' : ''; ?>" href="?type=daily"><i class="fas fa-calendar-day me-1"></i> Daily Attendance</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'monthly' ? 'active' : ''; ?>" href="?type=monthly"><i class="fas fa-calendar-alt me-1"></i> Monthly Summary</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'late' ? 'active' : ''; ?>" href="?type=late"><i class="fas fa-clock me-1"></i> Late Report</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'absent' ? 'active' : ''; ?>" href="?type=absent"><i class="fas fa-user-slash me-1"></i> Absent</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'salary' ? 'active' : ''; ?>" href="?type=salary"><i class="fas fa-money-bill-wave me-1"></i> Salary Report</a></li>
            <?php if (hasModuleAccess('daily_work_reports')): ?>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'work' ? 'active' : ''; ?>" href="?type=work"><i class="fas fa-tasks me-1"></i> Work Reports</a></li>
            <?php endif; ?>
            <?php if (hasModuleAccess('tasks')): ?>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'task' ? 'active' : ''; ?>" href="?type=task"><i class="fas fa-check-double me-1"></i> Task Report</a></li>
            <?php endif; ?>
            <?php if (hasModuleAccess('leads')): ?>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'lead' ? 'active' : ''; ?>" href="?type=lead"><i class="fas fa-funnel-dollar me-1"></i> Lead Report</a></li>
            <?php endif; ?>
            <?php if (hasModuleAccess('call_reports')): ?>
            <li class="nav-item"><a class="nav-link <?php echo $reportType == 'call' ? 'active' : ''; ?>" href="?type=call"><i class="fas fa-phone-volume me-1"></i> Call Report</a></li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<?php if ($reportType == 'working'): ?>
    <?php
    // Fetch all active employees
    $empSql = "SELECT e.*, d.name as department_name, des.name as designation_name 
               FROM employees e 
               LEFT JOIN departments d ON d.id = e.department_id 
               LEFT JOIN designations des ON des.id = e.designation_id 
               WHERE e.status = 1";
    $empParams = [];

    if ($deptId) { $empSql .= " AND e.department_id = ?"; $empParams[] = $deptId; }
    if ($designationId) { $empSql .= " AND e.designation_id = ?"; $empParams[] = $designationId; }
    if ($empId) { $empSql .= " AND e.id = ?"; $empParams[] = $empId; }
    if ($searchEmp) { 
        $empSql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ? OR e.email LIKE ? OR e.mobile LIKE ?)"; 
        $sTerm = "%$searchEmp%";
        $empParams = array_merge($empParams, [$sTerm, $sTerm, $sTerm, $sTerm, $sTerm]);
    }
    $empSql .= " ORDER BY e.first_name ASC";
    $eStmt = $pdo->prepare($empSql);
    $eStmt->execute($empParams);
    $employeesList = $eStmt->fetchAll();

    // Fetch Attendance records in date range
    $attStmt = $pdo->prepare("SELECT a.*, e.first_name, e.last_name, e.employee_code FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.attendance_date BETWEEN ? AND ?");
    $attStmt->execute([$startDate, $endDate]);
    $attendanceData = $attStmt->fetchAll();

    // Group attendance by date and employee
    $attMap = [];
    foreach ($attendanceData as $row) {
        $attMap[$row['attendance_date']][$row['employee_id']] = $row;
    }

    // Fetch Approved Leaves
    $leaveStmt = $pdo->prepare("SELECT * FROM leave_requests WHERE status = 'approved' AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?) OR (start_date <= ? AND end_date >= ?))");
    $leaveStmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
    $leaveRows = $leaveStmt->fetchAll();
    $leaveMap = [];
    foreach ($leaveRows as $lr) {
        $curr = max($startDate, $lr['start_date']);
        $last = min($endDate, $lr['end_date']);
        while ($curr <= $last) {
            $leaveMap[$curr][$lr['employee_id']] = $lr['leave_type'] ?? 'Leave';
            $curr = date('Y-m-d', strtotime($curr . ' +1 day'));
        }
    }

    // Fetch Holidays
    // holidays has a single holiday_date; it has no start_date/end_date pair,
    // so naming them made the whole report page fail with "Unknown column
    // 'start_date'". The loop below already treats a holiday as one day.
    $holStmt = $pdo->prepare("SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    $holStmt->execute([$startDate, $endDate]);
    $holRows = $holStmt->fetchAll();
    $holidayMap = [];
    foreach ($holRows as $hr) {
        $hStart = $hr['holiday_date'] ?? $hr['start_date'] ?? null;
        $hEnd = $hr['end_date'] ?? $hStart;
        if ($hStart) {
            $curr = max($startDate, $hStart);
            $last = min($endDate, $hEnd);
            while ($curr <= $last) {
                $holidayMap[$curr] = $hr['name'] ?? $hr['title'] ?? 'Holiday';
                $curr = date('Y-m-d', strtotime($curr . ' +1 day'));
            }
        }
    }

    // Build Working Report Rows and Summary Stats
    $reportRows = [];
    $totalEmployeesCount = count($allEmployees);
    $presentTodayCount = 0;
    $absentCount = 0;
    $onLeaveCount = 0;
    $lateCount = 0;
    $currentlyWorkingCount = 0;
    $totalWorkedMinutes = 0;
    $totalOvertimeMinutes = 0;

    $currDate = $startDate;
    while ($currDate <= $endDate) {
        $dayOfWeek = date('l', strtotime($currDate));
        $dayNum = date('N', strtotime($currDate));
        $isSunday = ($dayNum == 7);
        $isSaturday = ($dayNum == 6);
        $isFuture = ($currDate > $today);
        $requiredMinutes = $isSunday ? 0 : ($isSaturday ? 270 : 540); // 4.5h Saturday, 9h Weekday

        foreach ($employeesList as $emp) {
            $eId = $emp['id'];
            $att = $attMap[$currDate][$eId] ?? null;

            $status = 'Absent';
            $statusBadge = 'danger';
            $checkInDisplay = '-';
            $checkOutDisplay = '-';
            $workedDisplay = '-';
            $lateMinutes = 0;
            $overtimeMinutes = 0;
            $dayWorkedMins = 0;
            $isWorkingNow = false;

            if ($att) {
                $rawStatus = strtolower($att['status'] ?? 'present');
                $checkIn = $att['check_in'] ?? null;
                $checkOut = $att['check_out'] ?? null;

                if ($checkIn) {
                    $checkInDisplay = date('h:i A', strtotime($checkIn));
                }

                if ($checkOut) {
                    $checkOutDisplay = date('h:i A', strtotime($checkOut));
                } elseif ($currDate === $today && $checkIn) {
                    $checkOutDisplay = '<span class="badge bg-success-subtle text-success border border-success pulse-badge"><i class="fas fa-spinner fa-spin me-1"></i>Currently Working</span>';
                    $isWorkingNow = true;
                    $currentlyWorkingCount++;
                } elseif ($checkIn && !$checkOut && !$isFuture) {
                    $checkOutDisplay = '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Missing Checkout</span>';
                }

                // Calculate working duration
                if ($checkIn && $checkOut) {
                    $secs = strtotime($checkOut) - strtotime($checkIn);
                    if ($secs > 0) $dayWorkedMins = round($secs / 60);
                } elseif ($currDate === $today && $checkIn) {
                    $secs = time() - strtotime($checkIn);
                    if ($secs > 0) $dayWorkedMins = round($secs / 60);
                }

                $totalWorkedMinutes += $dayWorkedMins;

                if ($dayWorkedMins > $requiredMinutes && $requiredMinutes > 0) {
                    $overtimeMinutes = $dayWorkedMins - $requiredMinutes;
                    $totalOvertimeMinutes += $overtimeMinutes;
                }

                $lateMinutes = intval($att['late_minutes'] ?? 0);

                if ($rawStatus === 'half-day') {
                    $status = 'Half Day';
                    $statusBadge = 'warning';
                } elseif ($rawStatus === 'late' || $lateMinutes > 0) {
                    $status = 'Late';
                    $statusBadge = 'warning';
                    $lateCount++;
                    $presentTodayCount++;
                } elseif ($rawStatus === 'leave') {
                    $status = 'Leave';
                    $statusBadge = 'info';
                    $onLeaveCount++;
                } else {
                    $status = 'Present';
                    $statusBadge = 'success';
                    $presentTodayCount++;
                }

                if ($dayWorkedMins > 0) {
                    $h = intdiv($dayWorkedMins, 60);
                    $m = $dayWorkedMins % 60;
                    $workedDisplay = "<strong>{$h}h {$m}m</strong>";
                    if ($isWorkingNow) $workedDisplay .= ' <span class="text-success small">(Running)</span>';
                }
            } elseif (isset($leaveMap[$currDate][$eId])) {
                $status = 'Leave (' . $leaveMap[$currDate][$eId] . ')';
                $statusBadge = 'info';
                if (!$isFuture) $onLeaveCount++;
            } elseif (isset($holidayMap[$currDate])) {
                $status = 'Holiday (' . $holidayMap[$currDate] . ')';
                $statusBadge = 'secondary';
            } elseif ($isSunday) {
                $status = 'Weekly Off';
                $statusBadge = 'secondary';
            } elseif ($isFuture) {
                $status = 'Upcoming';
                $statusBadge = 'light text-dark';
            } else {
                $status = 'Absent';
                $statusBadge = 'danger';
                $absentCount++;
            }

            // Filter by status if requested
            if ($statusFilter) {
                if ($statusFilter === 'currently_working' && !$isWorkingNow) continue;
                if ($statusFilter === 'present' && !in_array($status, ['Present', 'Late'])) continue;
                if ($statusFilter === 'late' && $status !== 'Late') continue;
                if ($statusFilter === 'half-day' && $status !== 'Half Day') continue;
                if ($statusFilter === 'absent' && $status !== 'Absent') continue;
                if ($statusFilter === 'leave' && strpos($status, 'Leave') === false) continue;
            }

            $reportRows[] = [
                'employee' => $emp,
                'date' => $currDate,
                'day' => $dayOfWeek,
                'status' => $status,
                'status_badge' => $statusBadge,
                'check_in' => $checkInDisplay,
                'check_out' => $checkOutDisplay,
                'worked_hours' => $workedDisplay,
                'required_hours' => $requiredMinutes > 0 ? (intdiv($requiredMinutes, 60) . 'h ' . ($requiredMinutes % 60) . 'm') : '-',
                'late_minutes' => $lateMinutes > 0 ? "<span class='badge bg-warning text-dark'>{$lateMinutes}m</span>" : '-',
                'overtime' => $overtimeMinutes > 0 ? "<span class='badge bg-success text-white'>+{$overtimeMinutes}m</span>" : '-',
            ];
        }
        $currDate = date('Y-m-d', strtotime($currDate . ' +1 day'));
    }
    ?>

    <!-- Top Working Summary Cards -->
    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card-clean bg-white p-3 border-start border-primary border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-lbl">Total Employees</div>
                        <div class="stat-val text-primary mt-1"><?php echo $totalEmployeesCount; ?></div>
                    </div>
                    <div class="bg-primary-subtle text-primary p-3 rounded-circle">
                        <i class="fas fa-users fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card-clean bg-white p-3 border-start border-success border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-lbl">Present / Active</div>
                        <div class="stat-val text-success mt-1"><?php echo $presentTodayCount; ?></div>
                    </div>
                    <div class="bg-success-subtle text-success p-3 rounded-circle">
                        <i class="fas fa-user-check fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card-clean bg-white p-3 border-start border-danger border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-lbl">Absent</div>
                        <div class="stat-val text-danger mt-1"><?php echo $absentCount; ?></div>
                    </div>
                    <div class="bg-danger-subtle text-danger p-3 rounded-circle">
                        <i class="fas fa-user-times fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card-clean bg-white p-3 border-start border-info border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-lbl">Currently Working</div>
                        <div class="stat-val text-info mt-1"><?php echo $currentlyWorkingCount; ?></div>
                    </div>
                    <div class="bg-info-subtle text-info p-3 rounded-circle">
                        <i class="fas fa-business-time fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card-clean bg-white p-3 border-start border-warning border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-lbl">Late Arrivals</div>
                        <div class="stat-val text-warning mt-1"><?php echo $lateCount; ?></div>
                    </div>
                    <div class="bg-warning-subtle text-warning p-3 rounded-circle">
                        <i class="fas fa-clock fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card-clean bg-white p-3 border-start border-secondary border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-lbl">On Leave</div>
                        <div class="stat-val text-secondary mt-1"><?php echo $onLeaveCount; ?></div>
                    </div>
                    <div class="bg-secondary-subtle text-secondary p-3 rounded-circle">
                        <i class="fas fa-envelope-open-text fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card-clean bg-white p-3 border-start border-dark border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-lbl">Total Working Hours</div>
                        <div class="stat-val text-dark mt-1"><?php echo formatMinutes($totalWorkedMinutes); ?></div>
                    </div>
                    <div class="bg-light text-dark p-3 rounded-circle border">
                        <i class="fas fa-hourglass-half fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card-clean bg-white p-3 border-start border-success border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-lbl">Total Overtime Hours</div>
                        <div class="stat-val text-success mt-1"><?php echo formatMinutes($totalOvertimeMinutes); ?></div>
                    </div>
                    <div class="bg-success-subtle text-success p-3 rounded-circle">
                        <i class="fas fa-chart-line fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and Quick Presets Card -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-2 border-bottom">
                <div class="d-flex align-items-center gap-1 flex-wrap">
                    <span class="text-muted small fw-bold me-2"><i class="fas fa-bolt text-warning me-1"></i>Quick Ranges:</span>
                    <a href="?type=working&preset=today" class="btn btn-sm btn-preset <?php echo ($preset === 'today' || ($startDate == $today && $endDate == $today && !$preset)) ? 'btn-primary' : 'btn-outline-secondary'; ?>">Today</a>
                    <a href="?type=working&preset=yesterday" class="btn btn-sm btn-preset <?php echo $preset === 'yesterday' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Yesterday</a>
                    <a href="?type=working&preset=this_week" class="btn btn-sm btn-preset <?php echo $preset === 'this_week' ? 'btn-primary' : 'btn-outline-secondary'; ?>">This Week</a>
                    <a href="?type=working&preset=this_month" class="btn btn-sm btn-preset <?php echo $preset === 'this_month' ? 'btn-primary' : 'btn-outline-secondary'; ?>">This Month</a>
                </div>
                <div class="text-muted small">
                    <i class="far fa-calendar me-1"></i>Range: <strong><?php echo date('d M Y', strtotime($startDate)); ?></strong> to <strong><?php echo date('d M Y', strtotime($endDate)); ?></strong>
                </div>
            </div>

            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="working">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Search Employee</label>
                    <input type="text" name="search_emp" class="form-control form-control-sm" placeholder="Name, Code, Phone..." value="<?php echo sanitize($searchEmp); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Department</label>
                    <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Designation</label>
                    <select name="designation_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Designations</option>
                        <?php foreach ($designations as $des): ?>
                        <option value="<?php echo $des['id']; ?>" <?php echo $designationId == $des['id'] ? 'selected' : ''; ?>><?php echo sanitize($des['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="present" <?php echo $statusFilter == 'present' ? 'selected' : ''; ?>>Present</option>
                        <option value="currently_working" <?php echo $statusFilter == 'currently_working' ? 'selected' : ''; ?>>Currently Working</option>
                        <option value="late" <?php echo $statusFilter == 'late' ? 'selected' : ''; ?>>Late</option>
                        <option value="half-day" <?php echo $statusFilter == 'half-day' ? 'selected' : ''; ?>>Half Day</option>
                        <option value="leave" <?php echo $statusFilter == 'leave' ? 'selected' : ''; ?>>On Leave</option>
                        <option value="absent" <?php echo $statusFilter == 'absent' ? 'selected' : ''; ?>>Absent</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="row g-1">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-muted mb-1">From</label>
                            <input type="date" name="start_date" value="<?php echo $startDate; ?>" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-muted mb-1">To</label>
                            <input type="date" name="end_date" value="<?php echo $endDate; ?>" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
                <div class="col-12 text-end mt-2">
                    <button type="submit" class="btn btn-sm btn-primary px-3"><i class="fas fa-filter me-1"></i> Apply Filter</button>
                    <a href="?type=working" class="btn btn-sm btn-outline-secondary px-3"><i class="fas fa-undo me-1"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Employee Working Report Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold"><i class="fas fa-table me-2 text-primary"></i>Employee Working Report Records (<?php echo count($reportRows); ?>)</h6>
            <small class="text-muted">Click "Monthly View" on any employee to inspect day-by-day monthly records</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0" style="font-size:0.875rem">
                    <thead class="table-light">
                        <tr>
                            <th>Employee</th>
                            <th>Code</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Working Hours</th>
                            <th>Required</th>
                            <th>Late</th>
                            <th>Overtime</th>
                            <th>Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportRows as $row): 
                            $e = $row['employee'];
                            $empName = trim($e['first_name'] . ' ' . $e['last_name']);
                            $avatarInitial = strtoupper(substr($e['first_name'], 0, 1));
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-primary-subtle text-primary fw-bold d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:0.85rem">
                                        <?php echo $avatarInitial; ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo sanitize($empName); ?></div>
                                        <small class="text-muted"><?php echo sanitize($e['mobile'] ?? ''); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo sanitize($e['employee_code']); ?></span></td>
                            <td><?php echo sanitize($e['department_name'] ?? '-'); ?></td>
                            <td><small class="text-muted"><?php echo sanitize($e['designation_name'] ?? 'Staff'); ?></small></td>
                            <td><?php echo date('d M Y', strtotime($row['date'])); ?></td>
                            <td><span class="badge bg-light text-secondary"><?php echo $row['day']; ?></span></td>
                            <td><?php echo $row['check_in']; ?></td>
                            <td><?php echo $row['check_out']; ?></td>
                            <td><?php echo $row['worked_hours']; ?></td>
                            <td><small class="text-muted"><?php echo $row['required_hours']; ?></small></td>
                            <td><?php echo $row['late_minutes']; ?></td>
                            <td><?php echo $row['overtime']; ?></td>
                            <td><span class="badge bg-<?php echo $row['status_badge']; ?>"><?php echo $row['status']; ?></span></td>
                            <td class="text-center">
                                <button type="button" class="btn btn-xs btn-outline-primary" onclick="openMonthlyWorkModal(<?php echo $e['id']; ?>, '<?php echo substr($row['date'], 0, 7); ?>')">
                                    <i class="fas fa-calendar-alt me-1"></i>Monthly View
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reportRows)): ?>
                        <tr><td colspan="14" class="text-center py-4 text-muted"><i class="fas fa-folder-open fa-2x d-block mb-2"></i>No working records found for selected criteria</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Monthly Work Report Modal -->
    <div class="modal fade" id="monthlyWorkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-light">
                    <div>
                        <h5 class="modal-title fw-bold" id="mModalEmpName">Employee Monthly Work Report</h5>
                        <div class="text-muted small" id="mModalEmpSub">Loading details...</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <input type="month" id="mModalMonthPicker" class="form-control form-control-sm w-auto" onchange="reloadMonthlyModal()">
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-4" id="mModalBody">
                    <div class="text-center py-5">
                        <i class="fas fa-circle-notch fa-spin fa-2x text-primary mb-2"></i>
                        <p class="text-muted">Loading employee monthly work data...</p>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    var currentModalEmpId = null;

    function openMonthlyWorkModal(empId, month) {
        currentModalEmpId = empId;
        var monthVal = month || '<?php echo date('Y-m'); ?>';
        $('#mModalMonthPicker').val(monthVal);
        var modal = new bootstrap.Modal(document.getElementById('monthlyWorkModal'));
        modal.show();
        loadMonthlyWorkData(empId, monthVal);
    }

    function reloadMonthlyModal() {
        if (currentModalEmpId) {
            loadMonthlyWorkData(currentModalEmpId, $('#mModalMonthPicker').val());
        }
    }

    function loadMonthlyWorkData(empId, month) {
        $('#mModalBody').html('<div class="text-center py-5"><i class="fas fa-circle-notch fa-spin fa-2x text-primary mb-2"></i><p class="text-muted">Loading employee monthly work data...</p></div>');
        
        $.getJSON('../ajax/employee_monthly_work.php', { employee_id: empId, month: month }, function(res) {
            if (!res.success) {
                $('#mModalBody').html('<div class="alert alert-danger">' + (res.message || 'Error loading data') + '</div>');
                return;
            }
            var d = res.data;
            $('#mModalEmpName').text(d.employee.name + ' (' + d.employee.code + ')');
            $('#mModalEmpSub').text(d.employee.department + ' • ' + d.employee.designation + ' • Month: ' + d.month_name);

            var s = d.summary;
            var html = '';
            
            // Summary Cards
            html += '<div class="row g-2 mb-4">';
            html += '<div class="col-md-2 col-4"><div class="p-2 border rounded text-center bg-light"><div class="fw-bold fs-5 text-primary">' + s.working_days + '</div><div class="small text-muted">Working Days</div></div></div>';
            html += '<div class="col-md-2 col-4"><div class="p-2 border rounded text-center bg-light"><div class="fw-bold fs-5 text-success">' + s.present_days + '</div><div class="small text-muted">Present Days</div></div></div>';
            html += '<div class="col-md-2 col-4"><div class="p-2 border rounded text-center bg-light"><div class="fw-bold fs-5 text-warning">' + s.late_days + '</div><div class="small text-muted">Late Days</div></div></div>';
            html += '<div class="col-md-2 col-4"><div class="p-2 border rounded text-center bg-light"><div class="fw-bold fs-5 text-info">' + s.half_days + '</div><div class="small text-muted">Half Days</div></div></div>';
            html += '<div class="col-md-2 col-4"><div class="p-2 border rounded text-center bg-light"><div class="fw-bold fs-5 text-secondary">' + s.leave_days + '</div><div class="small text-muted">Leave Days</div></div></div>';
            html += '<div class="col-md-2 col-4"><div class="p-2 border rounded text-center bg-light"><div class="fw-bold fs-5 text-danger">' + s.absent_days + '</div><div class="small text-muted">Absent Days</div></div></div>';
            html += '</div>';

            // Additional Hours Metrics
            html += '<div class="row g-2 mb-4">';
            html += '<div class="col-md-3"><div class="p-3 border rounded bg-white"><span class="text-muted small">Total Hours Worked:</span><div class="fw-bold fs-6 text-dark">' + s.total_working_hours + '</div></div></div>';
            html += '<div class="col-md-3"><div class="p-3 border rounded bg-white"><span class="text-muted small">Expected Hours:</span><div class="fw-bold fs-6 text-secondary">' + s.expected_working_hours + '</div></div></div>';
            html += '<div class="col-md-3"><div class="p-3 border rounded bg-white"><span class="text-muted small">Overtime Hours:</span><div class="fw-bold fs-6 text-success">' + s.total_overtime_hours + '</div></div></div>';
            html += '<div class="col-md-3"><div class="p-3 border rounded bg-white"><span class="text-muted small">Avg Daily Hours:</span><div class="fw-bold fs-6 text-primary">' + s.avg_daily_hours + '</div></div></div>';
            html += '</div>';

            // Daily Records Table
            html += '<h6 class="fw-bold mb-2"><i class="fas fa-calendar-day me-1"></i> Daily Breakdown (' + d.month_name + ')</h6>';
            html += '<div class="table-responsive"><table class="table table-sm table-hover border align-middle mb-0" style="font-size:0.83rem">';
            html += '<thead class="table-light"><tr><th>Date</th><th>Day</th><th>Status</th><th>Check In</th><th>Check Out</th><th>Working Hours</th><th>Required</th><th>Late</th><th>Overtime</th></tr></thead><tbody>';

            d.daily_records.forEach(function(r) {
                html += '<tr>';
                html += '<td><strong>' + r.date_formatted + '</strong></td>';
                html += '<td><span class="text-muted">' + r.day + '</span></td>';
                html += '<td><span class="badge bg-' + r.status_class + '">' + r.status + '</span></td>';
                html += '<td>' + r.check_in + '</td>';
                html += '<td>' + r.check_out + '</td>';
                html += '<td><strong>' + r.working_hours + '</strong></td>';
                html += '<td><small class="text-muted">' + r.required_hours + '</small></td>';
                html += '<td>' + r.late_minutes + '</td>';
                html += '<td>' + r.overtime + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';
            $('#mModalBody').html(html);
        }).fail(function() {
            $('#mModalBody').html('<div class="alert alert-danger">Failed to communicate with server.</div>');
        });
    }
    </script>

<?php elseif ($reportType == 'daily'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT a.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM attendance a JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE a.attendance_date = ? ORDER BY e.first_name ASC");
    $stmt->execute([$date]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <span class="fw-bold"><i class="fas fa-calendar-day me-2 text-primary"></i>Daily Attendance - <?php echo date('d F Y', strtotime($date)); ?></span>
            <form method="GET" class="d-inline">
                <input type="hidden" name="type" value="daily">
                <input type="date" name="date" value="<?php echo $date; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()">
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0">
                    <thead class="table-light"><tr><th>Employee</th><th>Code</th><th>Dept</th><th>Check In</th><th>Check Out</th><th>Working Hrs</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?php echo sanitize($r['employee_code']); ?></span></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td><?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '-'; ?></td>
                            <td><?php echo $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '-'; ?></td>
                            <td><?php echo formatWorkedHours($r['working_hours'] ?? null) ?? '-'; ?></td>
                            <td><span class="badge bg-<?php echo $r['status'] == 'present' ? 'success' : ($r['status'] == 'late' ? 'warning' : 'danger'); ?>"><?php echo ucfirst($r['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($records)): ?>
                        <tr><td colspan="7" class="text-center py-3 text-muted">No records found for this date</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($reportType == 'monthly'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT e.id, e.first_name, e.last_name, e.employee_code, d.name as department_name, COUNT(a.id) as total_days, SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late, SUM(CASE WHEN a.status = 'half-day' THEN 1 ELSE 0 END) as half_day, SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent, COALESCE(SUM(a.late_minutes), 0) as total_late_minutes FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN attendance a ON a.employee_id = e.id AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ? WHERE e.status = 1 GROUP BY e.id, d.name ORDER BY e.first_name ASC");
    $stmt->execute([$month]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <span class="fw-bold"><i class="fas fa-calendar-alt me-2 text-primary"></i>Monthly Summary - <?php echo date('F Y', strtotime($month . '-01')); ?></span>
            <form method="GET" class="d-inline">
                <input type="hidden" name="type" value="monthly">
                <input type="month" name="month" value="<?php echo $month; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()">
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0">
                    <thead class="table-light"><tr><th>Employee</th><th>Code</th><th>Dept</th><th>Present</th><th>Late</th><th>Half Day</th><th>Absent</th><th>Late Mins</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?php echo sanitize($r['employee_code']); ?></span></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td><span class="text-success fw-bold"><?php echo $r['present']; ?></span></td>
                            <td><span class="text-warning fw-bold"><?php echo $r['late']; ?></span></td>
                            <td><span class="text-danger fw-bold"><?php echo $r['half_day']; ?></span></td>
                            <td><span class="text-secondary fw-bold"><?php echo $r['absent']; ?></span></td>
                            <td><?php echo $r['total_late_minutes']; ?> min</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($reportType == 'late'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT a.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM attendance a JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE a.status = 'late' AND a.attendance_date = ? ORDER BY a.late_minutes DESC");
    $stmt->execute([$date]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <span class="fw-bold"><i class="fas fa-clock me-2 text-warning"></i>Late Report - <?php echo date('d F Y', strtotime($date)); ?></span>
            <form method="GET" class="d-inline">
                <input type="hidden" name="type" value="late">
                <input type="date" name="date" value="<?php echo $date; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()">
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0">
                    <thead class="table-light"><tr><th>Employee</th><th>Code</th><th>Dept</th><th>Check In</th><th>Late Minutes</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?php echo sanitize($r['employee_code']); ?></span></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td><?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '-'; ?></td>
                            <td><span class="badge bg-warning text-dark"><?php echo $r['late_minutes']; ?> min</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($records)): ?>
                        <tr><td colspan="5" class="text-center py-3 text-muted">No late arrivals for this date</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($reportType == 'absent'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT e.id, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.status = 1 AND e.id NOT IN (SELECT employee_id FROM attendance WHERE attendance_date = ?) ORDER BY e.first_name ASC");
    $stmt->execute([$date]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <span class="fw-bold"><i class="fas fa-user-slash me-2 text-danger"></i>Absent Report - <?php echo date('d F Y', strtotime($date)); ?></span>
            <form method="GET" class="d-inline">
                <input type="hidden" name="type" value="absent">
                <input type="date" name="date" value="<?php echo $date; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()">
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0">
                    <thead class="table-light"><tr><th>Employee</th><th>Code</th><th>Dept</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?php echo sanitize($r['employee_code']); ?></span></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($records)): ?>
                        <tr><td colspan="3" class="text-center py-3 text-muted">No absent employees today</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($reportType == 'salary'): ?>
    <?php
    $stmt = $pdo->prepare("SELECT e.id, e.first_name, e.last_name, e.employee_code, e.salary, d.name as department_name, COALESCE(att.present, 0) as present_days, COALESCE(att.late, 0) as late_days, COALESCE(att.half_day, 0) as half_days, COALESCE(att.absent, 0) as absent_days, COALESCE(ded.total_deduction, 0) as total_deduction FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN (SELECT employee_id, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late, SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_day, SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent FROM attendance WHERE DATE_FORMAT(attendance_date, '%Y-%m') = ? GROUP BY employee_id) att ON att.employee_id = e.id LEFT JOIN (SELECT employee_id, SUM(deduction_amount) as total_deduction FROM salary_deductions WHERE DATE_FORMAT(deduction_date, '%Y-%m') = ? GROUP BY employee_id) ded ON ded.employee_id = e.id WHERE e.status = 1 ORDER BY e.first_name ASC");
    $stmt->execute([$month, $month]);
    $records = $stmt->fetchAll();
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <span class="fw-bold"><i class="fas fa-money-bill-wave me-2 text-success"></i>Salary Report - <?php echo date('F Y', strtotime($month . '-01')); ?></span>
            <form method="GET" class="d-inline">
                <input type="hidden" name="type" value="salary">
                <input type="month" name="month" value="<?php echo $month; ?>" class="form-control form-control-sm d-inline w-auto" onchange="this.form.submit()">
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0">
                    <thead class="table-light"><tr><th>Employee</th><th>Dept</th><th>Salary</th><th>Present</th><th>Late</th><th>Half</th><th>Absent</th><th>Deduction</th><th>Net</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): 
                            $daily = $r['salary'] > 0 ? $r['salary'] / 30 : 0; 
                            $ded = $r['half_days'] * ($daily * 0.5) + $r['absent_days'] * $daily; 
                            $net = $r['salary'] - $ded; 
                        ?>
                        <tr>
                            <td><strong><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></strong></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td>₹<?php echo number_format($r['salary'], 2); ?></td>
                            <td><?php echo $r['present_days']; ?></td>
                            <td><?php echo $r['late_days']; ?></td>
                            <td><?php echo $r['half_days']; ?></td>
                            <td><?php echo $r['absent_days']; ?></td>
                            <td class="text-danger">₹<?php echo number_format($ded, 2); ?></td>
                            <td class="fw-bold text-success">₹<?php echo number_format($net, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="work">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Department</label>
                    <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Employee</label>
                    <select name="employee_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($allEmployees as $e): ?>
                        <option value="<?php echo $e['id']; ?>" <?php echo $empId == $e['id'] ? 'selected' : ''; ?>><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">From</label>
                    <input type="date" name="start_date" value="<?php echo $startDate; ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">To</label>
                    <input type="date" name="end_date" value="<?php echo $endDate; ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><span>Work Reports (<?php echo count($records); ?>)</span></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0">
                    <thead class="table-light"><tr><th>Employee</th><th>Dept</th><th>Date</th><th>Title</th><th>Hours</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></strong></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td><?php echo $r['report_date']; ?></td>
                            <td><?php echo sanitize($r['title']); ?></td>
                            <td><?php echo $r['hours_worked']; ?></td>
                            <td><span class="badge bg-<?php echo $r['status'] == 'approved' ? 'success' : ($r['status'] == 'rejected' ? 'danger' : 'primary'); ?>"><?php echo ucfirst($r['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($records)): ?>
                        <tr><td colspan="6" class="text-center py-3 text-muted">No reports found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="task">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Department</label>
                    <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Employee</label>
                    <select name="employee_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($allEmployees as $e): ?>
                        <option value="<?php echo $e['id']; ?>" <?php echo $empId == $e['id'] ? 'selected' : ''; ?>><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="Pending" <?php echo $statusFilter=='Pending'?'selected':''; ?>>Pending</option>
                        <option value="In Progress" <?php echo $statusFilter=='In Progress'?'selected':''; ?>>In Progress</option>
                        <option value="Completed" <?php echo $statusFilter=='Completed'?'selected':''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo $statusFilter=='Cancelled'?'selected':''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><span>Task Reports (<?php echo count($records); ?>)</span></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0">
                    <thead class="table-light"><tr><th>Employee</th><th>Dept</th><th>Title</th><th>Priority</th><th>Due</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></strong></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td><?php echo sanitize($r['title']); ?></td>
                            <td><span class="badge bg-<?php echo $r['priority'] == 'Urgent' ? 'danger' : ($r['priority'] == 'High' ? 'warning' : ($r['priority'] == 'Medium' ? 'info' : 'secondary')); ?>"><?php echo $r['priority']; ?></span></td>
                            <td><?php echo $r['due_date'] ?? '-'; ?></td>
                            <td><span class="badge bg-<?php echo $r['status'] == 'Completed' ? 'success' : ($r['status'] == 'In Progress' ? 'primary' : ($r['status'] == 'Cancelled' ? 'secondary' : 'warning')); ?>"><?php echo $r['status']; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($records)): ?>
                        <tr><td colspan="6" class="text-center py-3 text-muted">No tasks found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($reportType == 'lead'): ?>
    <?php
    $sql = "SELECT l.*, e.first_name, e.last_name, e.employee_code, d.name as department_name FROM leads l JOIN employees e ON e.id = COALESCE(l.created_by, l.employee_id) LEFT JOIN departments d ON d.id = e.department_id WHERE DATE(l.created_at) BETWEEN ? AND ?";
    $params = [$startDate, $endDate];
    if ($deptId) { $sql .= " AND e.department_id = ?"; $params[] = $deptId; }
    if ($empId) { $sql .= " AND (l.employee_id = ? OR l.created_by = ?)"; $params[] = $empId; $params[] = $empId; }
    if ($statusFilter) { $sql .= " AND l.status = ?"; $params[] = $statusFilter; }
    $sql .= " ORDER BY l.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="lead">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Department</label>
                    <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Created By</label>
                    <select name="employee_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($allEmployees as $e): ?>
                        <option value="<?php echo $e['id']; ?>" <?php echo $empId == $e['id'] ? 'selected' : ''; ?>><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">From</label>
                    <input type="date" name="start_date" value="<?php echo $startDate; ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">To</label>
                    <input type="date" name="end_date" value="<?php echo $endDate; ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><span>Lead Reports (<?php echo count($records); ?>)</span></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0">
                    <thead class="table-light"><tr><th>Customer</th><th>Phone</th><th>Created By</th><th>Dept</th><th>Source</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?php echo sanitize($r['customer_name']); ?></strong></td>
                            <td><?php echo sanitize($r['customer_phone'] ?: ($r['mobile'] ?? '-')); ?></td>
                            <td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td><?php echo sanitize($r['source'] ?: ($r['lead_source'] ?? '-')); ?></td>
                            <td><span class="badge bg-<?php echo $r['status'] == 'won' ? 'success' : ($r['status'] == 'lost' ? 'danger' : ($r['status'] == 'new' ? 'info' : 'warning')); ?>"><?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?></span></td>
                            <td><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($records)): ?>
                        <tr><td colspan="7" class="text-center py-3 text-muted">No leads found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="call">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Department</label>
                    <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Employee</label>
                    <select name="employee_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($allEmployees as $e): ?>
                        <option value="<?php echo $e['id']; ?>" <?php echo $empId == $e['id'] ? 'selected' : ''; ?>><?php echo sanitize($e['first_name'] . ' ' . $e['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">From</label>
                    <input type="date" name="start_date" value="<?php echo $startDate; ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">To</label>
                    <input type="date" name="end_date" value="<?php echo $endDate; ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><span>Call Reports (<?php echo count($records); ?>)</span></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle mb-0">
                    <thead class="table-light"><tr><th>Customer</th><th>Employee</th><th>Dept</th><th>Duration</th><th>Type</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><strong><?php echo sanitize($r['customer_name']); ?></strong></td>
                            <td><?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?></td>
                            <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                            <td><?php echo gmdate('i:s', $r['call_duration']); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $r['call_type'])); ?></td>
                            <td><span class="badge bg-<?php echo $r['status'] == 'completed' ? 'success' : ($r['status'] == 'callback' ? 'warning' : 'danger'); ?>"><?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?></span></td>
                            <td><?php echo $r['call_date']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($records)): ?>
                        <tr><td colspan="7" class="text-center py-3 text-muted">No call reports found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
