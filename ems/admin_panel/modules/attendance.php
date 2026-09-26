<?php
require_once __DIR__ . '/../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('attendance');

$message = '';
$date = filterDate($_GET['date'] ?? '');

/**
 * Finishes a POST by redirecting back to the list.
 *
 * The rows are read at the top of this file, before any handler runs, so
 * rendering straight after a save showed the table exactly as it was before
 * the save — the correction had been written, but you had to refresh to see
 * it. Redirecting re-enters the page cleanly, and has the side benefit that
 * refreshing afterwards no longer re-submits the correction.
 *
 * $showDate lands you on the day the record now belongs to, so a check-in
 * corrected onto another date does not simply vanish from the current view.
 */
function redirectToList($message, $type = 'success', $showDate = null) {
    setFlash($message, $type);
    $query = $_GET;
    if ($showDate) $query['date'] = $showDate;
    header('Location: attendance.php' . ($query ? '?' . http_build_query($query) : ''));
    exit;
}
$deptFilter = $_GET['department_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT a.*, e.first_name, e.last_name, e.employee_code, e.department_id, e.designation_id,
               d.name as department_name, des.name as designation_name
        FROM attendance a
        JOIN employees e ON e.id = a.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN designations des ON des.id = e.designation_id
        WHERE a.attendance_date = :date";
$params = [':date' => $date];

if ($deptFilter) { $sql .= " AND e.department_id = :dept"; $params[':dept'] = $deptFilter; }
if ($statusFilter) { $sql .= " AND a.status = :status"; $params[':status'] = $statusFilter; }
$sql .= " ORDER BY e.first_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$departments = $pdo->query("SELECT * FROM departments WHERE status = 1 ORDER BY name")->fetchAll();

// Helper function to format late minutes
function formatLateMinutes($minutes) {
    if (!$minutes || $minutes == 0) return '-';
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($mins == 0) {
        return $hours . 'h';
    }
    return $hours . 'h ' . $mins . 'm';
}

// Helper function to get full image URL
function getFullImageUrl($path) {
    if (empty($path)) {
        return null;
    }
    
    // If already full URL, return as is
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }
    
    // Remove leading slash if exists
    $path = ltrim($path, '/');
    
    // Check if path already contains 'uploads'
    if (strpos($path, 'uploads/') === 0) {
        return rtrim(BACKEND_URL, '/') . '/' . $path;
    }
    
    // Default: assume it's in uploads/attendance
    return rtrim(BACKEND_URL, '/') . '/uploads/attendance/' . $path;
}

// Manual attendance with leave_type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_employee_id'])) {
    $empId = (int)$_POST['manual_employee_id'];
    $attDate = $_POST['attendance_date'];
    $status = $_POST['manual_status'];
    $leaveType = $_POST['leave_type'] ?? 'present';
    $checkIn = $_POST['check_in'] ? $attDate . ' ' . $_POST['check_in'] : null;
    $checkOut = $_POST['check_out'] ? $attDate . ' ' . $_POST['check_out'] : null;

    // Same derivation as a correction, so a manually entered row is graded by
    // the identical rules as one created by the app.
    require_once __DIR__ . '/../../backend/helpers/attendance_rules.php';
    $d = attendanceDerivedFields(attendanceRules($pdo), $checkIn, $checkOut, $status);

    $stmt = $pdo->prepare("INSERT INTO attendance
        (employee_id, attendance_date, check_in, check_out, working_hours, working_hours_decimal,
         late_minutes, status, leave_type, remarks)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            status=VALUES(status),
            check_in=VALUES(check_in),
            check_out=VALUES(check_out),
            working_hours=VALUES(working_hours),
            working_hours_decimal=VALUES(working_hours_decimal),
            late_minutes=VALUES(late_minutes),
            leave_type=VALUES(leave_type),
            remarks='Manual entry'");
    try {
        $stmt->execute([
            $empId, $attDate, $checkIn, $checkOut, $d['working_hours'], $d['working_hours_decimal'],
            $d['late_minutes'], $d['status'], $leaveType, 'Manual entry',
        ]);
    } catch (Exception $e) {
        // Previously uncaught, so a rejected insert took the whole page down
        // with a blank 500 rather than telling the admin what was wrong.
        error_log('Manual attendance: ' . $e->getMessage());
        redirectToList('Could not save that entry: ' . $e->getMessage(), 'error');
    }

    // Land on the date just entered, so the new row is on screen rather than
    // hidden behind whichever day the filter happened to be showing.
    redirectToList('Manual attendance recorded for ' . date('d M Y', strtotime($attDate)) . '.', 'success', $attDate);
}

// Correction with leave_type
/**
 * datetime-local posts "2026-08-14T10:20"; MySQL wants "2026-08-14 10:20:00".
 * Returns null for anything unusable rather than a half-formed date.
 */
function normalizeDateTimeLocal($value) {
    $value = trim((string) $value);
    if ($value === '') return null;
    $value = str_replace('T', ' ', $value);
    if (strlen($value) === 16) $value .= ':00';        // add seconds
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['correction_id'])) {
    require_once __DIR__ . '/../../backend/helpers/attendance_rules.php';

    $id        = (int) $_POST['correction_id'];
    $checkIn   = normalizeDateTimeLocal($_POST['corr_check_in'] ?? '');
    $checkOut  = normalizeDateTimeLocal($_POST['corr_check_out'] ?? '');
    $status    = $_POST['corr_status'] ?? 'present';
    $leaveType = $_POST['corr_leave_type'] ?? 'present';

    try {
        $cur = $pdo->prepare("SELECT id, employee_id, attendance_date, check_in, check_out FROM attendance WHERE id = ?");
        $cur->execute([$id]);
        $existing = $cur->fetch();
        if (!$existing) throw new Exception('That attendance record no longer exists.');

        $marked = in_array(strtolower($status), ['leave', 'absent', 'holiday'], true);

        // A worked day needs a check-in. Previously an empty field silently
        // wrote NULL over the real time, wiping the record.
        if (!$checkIn && !$marked) {
            throw new Exception('Enter a check-in time, or set the status to Leave or Absent.');
        }
        if ($checkIn && $checkOut && strtotime($checkOut) <= strtotime($checkIn)) {
            throw new Exception('Check-out must be later than check-in.');
        }

        // The day a record belongs to follows its check-in, so correcting a
        // clock-in that was logged on the wrong date moves the whole row.
        $newDate = $checkIn ? date('Y-m-d', strtotime($checkIn)) : $existing['attendance_date'];

        if ($newDate !== $existing['attendance_date']) {
            $clash = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ? AND id <> ?");
            $clash->execute([$existing['employee_id'], $newDate, $id]);
            if ($clash->fetch()) {
                throw new Exception('This employee already has a record on ' . date('d M Y', strtotime($newDate)) . '. Edit or delete that one first.');
            }
        }

        // Status, lateness and hours all re-derive from the corrected times, so
        // the row, the salary report and the employee app agree immediately. A
        // deliberate leave/absent marking by the admin is preserved.
        $d = attendanceDerivedFields(attendanceRules($pdo), $checkIn, $checkOut, $status);

        $pdo->prepare("UPDATE attendance SET
            attendance_date=?, check_in=?, check_out=?, working_hours=?,
            working_hours_decimal=?, late_minutes=?, status=?, leave_type=?
            WHERE id=?")
            ->execute([
                $newDate, $checkIn, $checkOut, $d['working_hours'],
                $d['working_hours_decimal'], $d['late_minutes'], $d['status'],
                $leaveType, $id,
            ]);

        $moved = $newDate !== $existing['attendance_date'];
        redirectToList(
            'Attendance corrected — status, lateness, hours and salary now reflect '
            . ($checkIn ? date('d M Y g:i A', strtotime($checkIn)) : 'the new status') . '.'
            . ($moved ? ' The record moved to ' . date('d M Y', strtotime($newDate)) . '.' : ''),
            'success',
            $moved ? $newDate : null
        );
    } catch (Exception $e) {
        error_log('Attendance correction: ' . $e->getMessage());
        redirectToList($e->getMessage(), 'error');
    }
}

$employees = $pdo->query("SELECT id, employee_code, first_name, last_name FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
?>

<style>
.photo-thumb {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 50%;
    cursor: pointer;
    border: 2px solid #ddd;
    transition: all 0.3s;
}
.photo-thumb:hover {
    border-color: #1E3A5F;
    transform: scale(1.1);
}
.photo-thumb-error {
    border-color: #dc3545 !important;
    opacity: 0.6;
}
.photo-container {
    position: relative;
    display: inline-block;
}
.photo-container .no-photo {
    width: 40px;
    height: 40px;
    background: #f8f9fa;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
    font-size: 16px;
    border: 2px solid #ddd;
}
.photo-container .no-photo i {
    font-size: 16px;
}
.badge-leave {
    background-color: #6c757d;
}
.badge-paid-leave {
    background-color: #0d6efd;
}
.badge-earned-leave {
    background-color: #198754;
}
.badge-unpaid-leave {
    background-color: #ffc107;
    color: #000;
}
.table td {
    vertical-align: middle;
}
.late-minutes {
    font-weight: 500;
}
.late-minutes.high {
    color: #dc3545;
}
.late-minutes.medium {
    color: #fd7e14;
}
.late-minutes.low {
    color: #ffc107;
}
.table th {
    white-space: nowrap;
}
.photo-col {
    min-width: 50px;
    text-align: center;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-calendar-check me-2"></i>Attendance Management</h4>
    <div>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#manualModal">
            <i class="fas fa-plus me-1"></i>Manual Entry
        </button>
    </div>
</div>

<?php displayFlash(); ?>
<?php if (!empty($message)): ?>
<div class="alert alert-info alert-dismissible fade show">
    <?php echo $message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" name="date" class="form-control" value="<?php echo $date; ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-3">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-select" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo $deptFilter == $d['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($d['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="present" <?php echo $statusFilter == 'present' ? 'selected' : ''; ?>>Present</option>
                    <option value="late" <?php echo $statusFilter == 'late' ? 'selected' : ''; ?>>Late</option>
                    <option value="half-day" <?php echo $statusFilter == 'half-day' ? 'selected' : ''; ?>>Half Day</option>
                    <option value="absent" <?php echo $statusFilter == 'absent' ? 'selected' : ''; ?>>Absent</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
            </div>
            <div class="col-md-2">
                <a href="?date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary w-100"><i class="fas fa-calendar me-1"></i>Today</a>
            </div>
        </form>
    </div>
</div>

<!-- Attendance Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <span>Attendance - <?php echo date('d F Y', strtotime($date)); ?></span>
        <span class="text-muted"><?php echo count($records); ?> records</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="attendanceTable">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Code</th>
                        <th>Department</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Working Hrs</th>
                        <th>Late</th>
                        <th>Status</th>
                        <th>Leave Type</th>
                        <th class="photo-col">Check-in Photo</th>
                        <th class="photo-col">Check-out Photo</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                    <?php 
                    $leaveType = $r['leave_type'] ?? 'present';
                    $leaveLabels = [
                        'present' => '✅ Present',
                        'paid_leave' => '✅ Paid Leave',
                        'earned_leave' => '✅ Earned Leave',
                        'unpaid_leave' => '❌ Unpaid Leave',
                        'absent' => '❌ Absent',
                        'weekly_off' => '🔵 Weekly Off',
                        'holiday' => '🎉 Holiday'
                    ];
                    $leaveColors = [
                        'present' => 'success',
                        'paid_leave' => 'info',
                        'earned_leave' => 'primary',
                        'unpaid_leave' => 'warning',
                        'absent' => 'danger',
                        'weekly_off' => 'secondary',
                        'holiday' => 'purple'
                    ];
                    $isHoliday = !empty($r['is_holiday']);
                    $holidayTitle = $r['holiday_title'] ?? '';
                    
                    // Format late minutes
                    $lateMinutes = $r['late_minutes'] ?? 0;
                    $formattedLate = formatLateMinutes($lateMinutes);
                    
                    // Determine late class based on minutes
                    $lateClass = '';
                    if ($lateMinutes > 0) {
                        if ($lateMinutes >= 60) {
                            $lateClass = 'high';
                        } elseif ($lateMinutes >= 30) {
                            $lateClass = 'medium';
                        } else {
                            $lateClass = 'low';
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?>
                            <?php if ($isHoliday && $holidayTitle): ?>
                            <br><small class="text-muted"><?php echo sanitize($holidayTitle); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo sanitize($r['employee_code']); ?></td>
                        <td><?php echo sanitize($r['department_name'] ?? '-'); ?></td>
                        <td><?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '-'; ?></td>
                        <td><?php echo $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '-'; ?></td>
                        <td><?php
                            $wh = formatWorkedHours($r['working_hours'] ?? null);
                            if (!$wh && $r['check_in'] && $r['check_out']) {
                                $in = new DateTime($r['check_in']);
                                $out = new DateTime($r['check_out']);
                                $diff = $in->diff($out);
                                $wh = $diff->h . 'h ' . $diff->i . 'm';
                            }
                            echo $wh ?? '-';
                            ?></td>
                        <td>
                            <?php if ($lateMinutes > 0): ?>
                            <span class="late-minutes <?php echo $lateClass; ?>">
                                <i class="fas fa-clock me-1"></i><?php echo $formattedLate; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $r['status'] == 'present' ? 'success' : ($r['status'] == 'late' ? 'warning' : ($r['status'] == 'half-day' ? 'danger' : 'secondary')); ?>">
                                <?php echo ucfirst($r['status']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $leaveColors[$leaveType] ?? 'secondary'; ?>">
                                <?php echo $leaveLabels[$leaveType] ?? ucfirst($leaveType); ?>
                            </span>
                            <?php if ($isHoliday && $holidayTitle): ?>
                            <br><small class="text-muted"><?php echo sanitize($holidayTitle); ?></small>
                            <?php endif; ?>
                        </td>
                        <!-- Check-in Photo Column -->
                        <td class="photo-col">
                            <div class="photo-container">
                                <?php if (!empty($r['check_in_photo'])): ?>
                                <?php 
                                $photoUrl = getFullImageUrl($r['check_in_photo']);
                                ?>
                                <a href="#" onclick="viewPhoto('<?php echo $photoUrl; ?>', 'Check-in Photo - <?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?>')" title="Check-in photo">
                                    <img src="<?php echo $photoUrl; ?>" class="photo-thumb" alt="Check-in photo" 
                                         onerror="this.style.display='none'; this.parentElement.innerHTML='<div class=\'no-photo\'><i class=\'fas fa-sign-in-alt\'></i></div>'">
                                </a>
                                <?php else: ?>
                                <div class="no-photo" title="No check-in photo">
                                    <i class="fas fa-sign-in-alt"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <!-- Check-out Photo Column -->
                        <td class="photo-col">
                            <div class="photo-container">
                                <?php if (!empty($r['check_out_photo'])): ?>
                                <?php 
                                $photoUrl = getFullImageUrl($r['check_out_photo']);
                                ?>
                                <a href="#" onclick="viewPhoto('<?php echo $photoUrl; ?>', 'Check-out Photo - <?php echo sanitize($r['first_name'] . ' ' . $r['last_name']); ?>')" title="Check-out photo">
                                    <img src="<?php echo $photoUrl; ?>" class="photo-thumb" alt="Check-out photo"
                                         onerror="this.style.display='none'; this.parentElement.innerHTML='<div class=\'no-photo\'><i class=\'fas fa-sign-out-alt\'></i></div>'">
                                </a>
                                <?php else: ?>
                                <div class="no-photo" title="No check-out photo">
                                    <i class="fas fa-sign-out-alt"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editAttendance(<?php echo htmlspecialchars(json_encode($r)); ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="12" class="text-center py-4 text-muted">No attendance records found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Manual Entry Modal with Leave Type -->
<div class="modal fade" id="manualModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <div class="modal-header">
                <h5 class="modal-title">Manual Attendance Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Employee *</label>
                    <select name="manual_employee_id" class="form-select" required>
                        <option value="">Select Employee</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?php echo $e['id']; ?>">
                            <?php echo sanitize($e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['employee_code'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Date *</label>
                    <input type="date" name="attendance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Check In</label>
                        <input type="time" name="check_in" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Check Out</label>
                        <input type="time" name="check_out" class="form-control">
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Status *</label>
                        <select name="manual_status" class="form-select" required>
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="half-day">Half Day</option>
                            <option value="absent">Absent</option>
                            <option value="leave">Leave</option>
                        </select>
                        <small class="text-muted">Present, Late and Half Day are re-derived from the times below.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Leave Type *</label>
                        <select name="leave_type" class="form-select" required>
                            <option value="present">✅ Present (Salary Add)</option>
                            <option value="paid_leave">✅ Paid Leave (Salary Add)</option>
                            <option value="earned_leave">✅ Earned Leave (Salary Add)</option>
                            <option value="unpaid_leave">❌ Unpaid Leave (Salary Deduction)</option>
                            <option value="absent">❌ Absent (Salary Deduction)</option>
                            <option value="weekly_off">🔵 Weekly Off</option>
                            <option value="holiday">🎉 Holiday</option>
                        </select>
                        <small class="text-muted">Select leave type for salary calculation</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Save</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Correction Modal with Leave Type -->
<div class="modal fade" id="correctionModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <div class="modal-header">
                <h5 class="modal-title">Attendance Correction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="correction_id" id="corrId">
                <div class="mb-3">
                    <label class="form-label">Date</label>
                    <input type="text" id="corrDate" class="form-control" readonly>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Check In</label>
                        <input type="datetime-local" name="corr_check_in" id="corrIn" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Check Out</label>
                        <input type="datetime-local" name="corr_check_out" id="corrOut" class="form-control">
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="corr_status" id="corrStatus" class="form-select">
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="half-day">Half Day</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Leave Type</label>
                        <select name="corr_leave_type" id="corrLeaveType" class="form-select">
                            <option value="present">✅ Present (Salary Add)</option>
                            <option value="paid_leave">✅ Paid Leave (Salary Add)</option>
                            <option value="earned_leave">✅ Earned Leave (Salary Add)</option>
                            <option value="unpaid_leave">❌ Unpaid Leave (Salary Deduction)</option>
                            <option value="absent">❌ Absent (Salary Deduction)</option>
                            <option value="weekly_off">🔵 Weekly Off</option>
                            <option value="holiday">🎉 Holiday</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Photo View Modal -->
<div class="modal fade" id="photoModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="photoModalTitle">Attendance Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="photoModalImg" src="" alt="Attendance Photo" style="max-width:100%; max-height:400px; border-radius:8px;">
                <div id="photoError" class="text-danger mt-2" style="display:none;">
                    <i class="fas fa-exclamation-triangle"></i> Failed to load image
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="photoModalDownload" href="#" download="attendance_photo.jpg" class="btn btn-primary">
                    <i class="fas fa-download"></i> Download
                </a>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    if ($.fn.DataTable.isDataTable('#attendanceTable')) {
        $('#attendanceTable').DataTable().destroy();
    }
    $('#attendanceTable').DataTable({
        "pageLength": 25,
        "order": [[0, 'asc']],
        "columns": [
            { "orderable": true, "searchable": true },
            { "orderable": true, "searchable": true },
            { "orderable": true, "searchable": true },
            { "orderable": true, "searchable": true },
            { "orderable": true, "searchable": true },
            { "orderable": true, "searchable": true },
            { "orderable": true, "searchable": true },
            { "orderable": true, "searchable": true },
            { "orderable": true, "searchable": true },
            { "orderable": false, "searchable": false }, // Check-in photo
            { "orderable": false, "searchable": false }, // Check-out photo
            { "orderable": false, "searchable": false }  // Actions
        ],
        "language": {
            "emptyTable": "No attendance records found",
            "info": "Showing _START_ to _END_ of _TOTAL_ records",
            "infoEmpty": "Showing 0 to 0 of 0 records",
            "infoFiltered": "(filtered from _MAX_ total records)",
            "search": "Search:",
            "lengthMenu": "Show _MENU_ entries",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            }
        }
    });
});

function editAttendance(r) {
    document.getElementById('corrId').value = r.id;
    document.getElementById('corrDate').value = r.attendance_date;
    // "2026-08-14 10:20:00" -> "2026-08-14T10:20", the only shape the input accepts
    const forInput = (v) => v ? String(v).replace(' ', 'T').substring(0, 16) : '';
    document.getElementById('corrIn').value = forInput(r.check_in);
    document.getElementById('corrOut').value = forInput(r.check_out);
    document.getElementById('corrStatus').value = r.status;
    document.getElementById('corrLeaveType').value = r.leave_type || 'present';
    new bootstrap.Modal(document.getElementById('correctionModal')).show();
}

function viewPhoto(src, title) {
    // Update modal title
    document.getElementById('photoModalTitle').textContent = title || 'Attendance Photo';
    
    // Show loading state
    var img = document.getElementById('photoModalImg');
    var errorDiv = document.getElementById('photoError');
    errorDiv.style.display = 'none';
    img.style.display = 'block';
    img.src = src;
    
    // Handle image load error
    img.onerror = function() {
        this.style.display = 'none';
        errorDiv.style.display = 'block';
        errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Failed to load image. The photo may not exist or the URL is incorrect.';
    };
    
    // Set download link
    document.getElementById('photoModalDownload').href = src;
    
    new bootstrap.Modal(document.getElementById('photoModal')).show();
}

// Handle image loading errors for thumbnails
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.photo-thumb').forEach(function(img) {
        img.onerror = function() {
            this.style.display = 'none';
            var container = this.closest('.photo-container');
            if (container) {
                var noPhoto = document.createElement('div');
                noPhoto.className = 'no-photo';
                // Check if it's check-in or check-out based on alt text
                var alt = this.getAttribute('alt') || '';
                if (alt.toLowerCase().includes('check-in')) {
                    noPhoto.innerHTML = '<i class="fas fa-sign-in-alt"></i>';
                } else if (alt.toLowerCase().includes('check-out')) {
                    noPhoto.innerHTML = '<i class="fas fa-sign-out-alt"></i>';
                } else {
                    noPhoto.innerHTML = '<i class="fas fa-user-slash"></i>';
                }
                container.appendChild(noPhoto);
            }
        };
    });
});

// Debug - Log photo URLs to console
console.log('Attendance records loaded:', <?php echo json_encode(array_map(function($r) {
    return [
        'employee' => $r['first_name'] . ' ' . $r['last_name'],
        'check_in_photo' => $r['check_in_photo'] ?? null,
        'check_out_photo' => $r['check_out_photo'] ?? null,
        'check_in_url' => !empty($r['check_in_photo']) ? getFullImageUrl($r['check_in_photo']) : null,
        'check_out_url' => !empty($r['check_out_photo']) ? getFullImageUrl($r['check_out_photo']) : null
    ];
}, $records)); ?>);
</script>

<?php require_once '../includes/footer.php'; ?>
