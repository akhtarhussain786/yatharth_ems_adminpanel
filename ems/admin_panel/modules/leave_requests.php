<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('leave_requests');
require_once __DIR__ . '/../../backend/helpers/fcm_helper.php';

// Handle approve/reject via POST (with remarks)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireModuleAccess('leave_requests', 'can_edit');
    $id = intval($_POST['id']);
    $remarks = sanitize($_POST['remarks'] ?? '');
    $action = $_POST['action'];

    // Approving does five writes: the request status, one attendance row per
    // day, the leave-balance deduction, a notification, and the push. None of
    // it was wrapped, so a failure part-way left the request marked Approved
    // with the attendance and balance never touched — and because the success
    // message is set on the last line, the admin was told nothing at all.
    try {
        $pdo->beginTransaction();

    if ($action === 'approve') {
        // Whether it was already approved decides if the balance should move.
        // The deduction below adds to `used`, so approving twice — a double
        // click, or a second admin looking at a stale list — would take the
        // days off the employee's entitlement twice over.
        $wasApproved = false;
        $priorStmt = $pdo->prepare("SELECT status FROM leave_requests WHERE id = ?");
        $priorStmt->execute([$id]);
        $priorRow = $priorStmt->fetch();
        if ($priorRow) {
            $wasApproved = strcasecmp((string) $priorRow['status'], 'Approved') === 0;
        }

        $pdo->prepare("UPDATE leave_requests SET status='Approved', approved_by=?, approved_at=NOW(), remarks=? WHERE id=?")->execute([$_SESSION['admin_id'], $remarks ?: null, $id]);
        
        $lr = $pdo->query("SELECT l.*, e.user_id, e.id as emp_id FROM leave_requests l JOIN employees e ON e.id = l.employee_id WHERE l.id = $id")->fetch();
        if ($lr) {
            $from = $lr['start_date']; $to = $lr['end_date'];
            $period = new DatePeriod(new DateTime($from), new DateInterval('P1D'), (new DateTime($to))->modify('+1 day'));
            foreach ($period as $d) {
                $dt = $d->format('Y-m-d');
                $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status, leave_type) VALUES (?,?,'Leave',?) ON DUPLICATE KEY UPDATE status='Leave', leave_type=?")->execute([$lr['emp_id'], $dt, $lr['leave_type'], $lr['leave_type']]);
            }
            
            // Deduct leave balance — once only. See $wasApproved above.
            $currentYear = date('Y');
            if (!$wasApproved) {
                $pdo->prepare("INSERT INTO leave_balances (employee_id, leave_type, used, year) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE used = used + VALUES(used)")
                    ->execute([$lr['emp_id'], $lr['leave_type'], $lr['total_days'] ?? 1.0, $currentYear]);
            }

            // Notifications
            if ($lr['user_id']) {
                $title = "Leave Approved";
                $msg = "Your " . $lr['leave_type'] . " request (" . $from . " to " . $to . ") has been approved.";
                if ($remarks) $msg .= " Remarks: " . $remarks;

                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'leave')")->execute([$lr['user_id'], $title, $msg]);
                FCMHelper::sendToUsers($pdo, [$lr['user_id']], $title, $msg, ['type' => 'leave_approved', 'leave_id' => $id]);
            }
        }
        $_SESSION['flash'] = 'Leave request approved successfully';
    } elseif ($action === 'reject') {
        // Read the request BEFORE changing it: if it was already approved, the
        // days were deducted from the employee's balance and attendance rows
        // were written, and both have to be undone. Rejecting an approved leave
        // used to leave the deduction in place permanently — the employee lost
        // those days from their entitlement with nothing to show for it.
        $prior = $pdo->prepare("SELECT l.*, e.user_id, e.id AS emp_id
                                FROM leave_requests l
                                JOIN employees e ON e.id = l.employee_id
                                WHERE l.id = ?");
        $prior->execute([$id]);
        $before = $prior->fetch();

        $pdo->prepare("UPDATE leave_requests SET status='Rejected', approved_by=?, approved_at=NOW(), remarks=? WHERE id=?")->execute([$_SESSION['admin_id'], $remarks ?: null, $id]);

        if ($before && strcasecmp((string) $before['status'], 'Approved') === 0) {
            // Only rows still carrying this leave type are removed, so a later
            // correction or a real check-in on that day is left alone.
            $pdo->prepare("DELETE FROM attendance
                           WHERE employee_id = ?
                             AND attendance_date BETWEEN ? AND ?
                             AND leave_type = ?
                             AND check_in IS NULL")
                ->execute([$before['emp_id'], $before['start_date'], $before['end_date'], $before['leave_type']]);

            // Give the days back, never dropping below zero.
            $pdo->prepare("UPDATE leave_balances
                           SET used = GREATEST(used - ?, 0)
                           WHERE employee_id = ? AND leave_type = ? AND year = ?")
                ->execute([
                    $before['total_days'] ?? 1.0,
                    $before['emp_id'],
                    $before['leave_type'],
                    (int) date('Y', strtotime($before['start_date'])),
                ]);
        }

        $lr = $pdo->query("SELECT l.*, e.user_id FROM leave_requests l JOIN employees e ON e.id = l.employee_id WHERE l.id = $id")->fetch();
        if ($lr && $lr['user_id']) {
            $title = "Leave Rejected";
            $reasonText = $remarks ? " Reason: $remarks" : '';
            $msg = "Your " . $lr['leave_type'] . " request (" . $lr['start_date'] . " to " . $lr['end_date'] . ") has been rejected." . $reasonText;

            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'leave')")->execute([$lr['user_id'], $title, $msg]);
            FCMHelper::sendToUsers($pdo, [$lr['user_id']], $title, $msg, ['type' => 'leave_rejected', 'leave_id' => $id]);
        }
        $_SESSION['flash'] = 'Leave request rejected';
    }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Leave ' . $action . ' failed for #' . $id . ': ' . $e->getMessage());
        // Nothing was applied, so say so rather than leaving the admin to
        // guess from a page that looks unchanged.
        $_SESSION['flash_error'] = 'Could not ' . ($action === 'approve' ? 'approve' : 'reject')
            . ' that leave request. Nothing was changed — please try again.';
    }
    redirect('leave_requests.php');
}

// Calculate Dashboard Metrics
$totalRequests  = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests")->fetchColumn();
$pendingCount   = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='Pending'")->fetchColumn();
$approvedCount  = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='Approved'")->fetchColumn();
$rejectedCount  = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='Rejected'")->fetchColumn();
$totalApprovedDays = (float)$pdo->query("SELECT COALESCE(SUM(total_days), 0) FROM leave_requests WHERE status='Approved'")->fetchColumn();
$currentMonthLeaves = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='Approved' AND MONTH(start_date) = MONTH(CURRENT_DATE()) AND YEAR(start_date) = YEAR(CURRENT_DATE())")->fetchColumn();

// Entitlement in days across the workforce for this year. Half Day and Leave
// Without Pay carry no allotment, so only CL, SL and EL count against it.
$perEmployeeEntitlement = 8 + 8 + 15; // Casual + Sick + Earned
$activeEmployees = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 1")->fetchColumn();
$totalLeaveDays = $activeEmployees * $perEmployeeEntitlement;
$usedLeaveDays = (float)$pdo->query("SELECT COALESCE(SUM(total_days), 0) FROM leave_requests
    WHERE status = 'Approved' AND YEAR(start_date) = YEAR(CURRENT_DATE())
      AND leave_type IN ('Casual Leave','Sick Leave','Earned Leave')")->fetchColumn();
$remainingLeaveDays = max(0, $totalLeaveDays - $usedLeaveDays);

// Filters
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$deptFilter = $_GET['department'] ?? '';

$sql = "SELECT l.*, e.first_name, e.last_name, e.employee_code, d.name as department_name 
        FROM leave_requests l 
        JOIN employees e ON e.id = l.employee_id 
        LEFT JOIN departments d ON d.id = e.department_id 
        WHERE 1=1";
$params = [];

if ($deptFilter) { $sql .= " AND e.department_id = ?"; $params[] = $deptFilter; }
if ($statusFilter) { $sql .= " AND l.status = ?"; $params[] = $statusFilter; }
if ($search) { $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ?)"; $s = "%$search%"; $params = array_merge($params, [$s, $s, $s]); }
$sql .= " ORDER BY FIELD(l.status,'Pending','Approved','Rejected'), l.created_at DESC";

$leave_requests = $pdo->prepare($sql);
$leave_requests->execute($params);
$leave_requests = $leave_requests->fetchAll();

// Get departments for filter
$depts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

require_once '../includes/header.php';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$flashError = $_SESSION['flash_error'] ?? ''; unset($_SESSION['flash_error']);
?>
<style>
.metric-card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); transition: transform 0.2s; }
.metric-card:hover { transform: translateY(-2px); }
.metric-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
.modal-body dl dt { color: #6c757d; font-weight: 500; font-size: 0.85rem; }
.modal-body dl dd { font-weight: 600; margin-bottom: 0.5rem; }
.status-badge { font-size: 0.8rem; padding: 0.25rem 0.6rem; border-radius: 20px; }
table th { background: #f8f9fa; position: sticky; top: 0; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-calendar-check text-primary me-2"></i>Leave Management Dashboard</h4>
        <small class="text-muted">Overview of employee leave applications, balances, and approvals</small>
    </div>
    <a href="leave_calendar.php" class="btn btn-primary"><i class="fas fa-calendar-alt me-1"></i>Leave Calendar</a>
</div>

<?php if ($flash): ?>
<div class="alert alert-success py-2 alert-dismissible fade show"><?php echo sanitize($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-danger py-2 alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?php echo sanitize($flashError); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- 📊 LEAVE DASHBOARD CARDS -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card metric-card bg-white p-3">
            <div class="d-flex align-items-center">
                <div class="metric-icon bg-primary bg-opacity-10 text-primary me-3">
                    <i class="fas fa-list"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small">Total Requests</h6>
                    <h4 class="fw-bold mb-0"><?php echo $totalRequests; ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card metric-card bg-white p-3">
            <div class="d-flex align-items-center">
                <div class="metric-icon bg-warning bg-opacity-10 text-warning me-3">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small">Pending Requests</h6>
                    <h4 class="fw-bold mb-0 text-warning"><?php echo $pendingCount; ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card metric-card bg-white p-3">
            <div class="d-flex align-items-center">
                <div class="metric-icon bg-success bg-opacity-10 text-success me-3">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small">Approved Leaves</h6>
                    <h4 class="fw-bold mb-0 text-success"><?php echo $approvedCount; ?> <small class="text-muted fs-6">(<?php echo $totalApprovedDays; ?> Days)</small></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card metric-card bg-white p-3">
            <div class="d-flex align-items-center">
                <div class="metric-icon bg-danger bg-opacity-10 text-danger me-3">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small">Rejected Requests</h6>
                    <h4 class="fw-bold mb-0 text-danger"><?php echo $rejectedCount; ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card metric-card bg-white p-3">
            <div class="d-flex align-items-center">
                <div class="metric-icon bg-info bg-opacity-10 text-info me-3">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small">Total Leave (<?php echo date('Y'); ?>)</h6>
                    <h4 class="fw-bold mb-0"><?php echo $totalLeaveDays; ?> <small class="text-muted fs-6">days</small></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card metric-card bg-white p-3">
            <div class="d-flex align-items-center">
                <div class="metric-icon bg-warning bg-opacity-10 text-warning me-3">
                    <i class="fas fa-calendar-minus"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small">Used Leave</h6>
                    <h4 class="fw-bold mb-0"><?php echo rtrim(rtrim(number_format($usedLeaveDays, 1), '0'), '.'); ?> <small class="text-muted fs-6">days</small></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card metric-card bg-white p-3">
            <div class="d-flex align-items-center">
                <div class="metric-icon bg-success bg-opacity-10 text-success me-3">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small">Remaining Leave</h6>
                    <h4 class="fw-bold mb-0 text-success"><?php echo rtrim(rtrim(number_format($remainingLeaveDays, 1), '0'), '.'); ?> <small class="text-muted fs-6">days</small></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card metric-card bg-white p-3">
            <div class="d-flex align-items-center">
                <div class="metric-icon bg-primary bg-opacity-10 text-primary me-3">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small">This Month</h6>
                    <h4 class="fw-bold mb-0"><?php echo $currentMonthLeaves; ?></h4>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3 border-0 shadow-sm">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Search Employee</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Name or Employee Code" value="<?php echo sanitize($search); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Department</label>
                <select name="department" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo $deptFilter == $d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="Pending" <?php echo $statusFilter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Approved" <?php echo $statusFilter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Rejected" <?php echo $statusFilter == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                <a href="leave_requests.php" class="btn btn-sm btn-outline-secondary w-100"><i class="fas fa-undo me-1"></i>Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Leave Requests Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="leaveRequestsTable">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Dept</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Attachment</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leave_requests)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No leave requests found</td></tr>
                    <?php else: ?>
                    <?php foreach ($leave_requests as $l): ?>
                    <tr>
                        <td>
                            <strong><?php echo sanitize($l['first_name'] . ' ' . $l['last_name']); ?></strong>
                            <br><small class="text-muted"><?php echo sanitize($l['employee_code']); ?></small>
                        </td>
                        <td><small class="badge bg-light text-dark border"><?php echo sanitize($l['department_name'] ?? 'N/A'); ?></small></td>
                        <td><span class="badge bg-secondary"><?php echo sanitize($l['leave_type']); ?></span></td>
                        <td><small><?php echo date('d M Y', strtotime($l['start_date'])); ?></small></td>
                        <td><small><?php echo date('d M Y', strtotime($l['end_date'])); ?></small></td>
                        <td class="text-center"><strong><?php echo $l['total_days'] ?? 1; ?></strong></td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php echo sanitize(substr($l['reason'] ?? '', 0, 50)); ?>
                        </td>
                        <td>
                            <?php if (!empty($l['attachment'])): ?>
                            <a href="../../<?php echo sanitize($l['attachment']); ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.75rem;">
                                <i class="fas fa-paperclip me-1"></i>View
                            </a>
                            <?php else: ?>
                            <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['status'] == 'Pending'): ?>
                            <span class="badge bg-warning text-dark status-badge"><i class="fas fa-clock me-1"></i>Pending</span>
                            <?php elseif ($l['status'] == 'Approved'): ?>
                            <span class="badge bg-success status-badge"><i class="fas fa-check me-1"></i>Approved</span>
                            <?php else: ?>
                            <span class="badge bg-danger status-badge"><i class="fas fa-times me-1"></i>Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-info me-1" onclick="showDetails(<?php echo htmlspecialchars(json_encode($l)); ?>)" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if ($l['status'] == 'Pending' && hasModuleAccess('leave_requests', 'can_edit')): ?>
                            <button class="btn btn-sm btn-success me-1" onclick="showActionModal(<?php echo $l['id']; ?>, 'approve', '<?php echo sanitize(addslashes($l['first_name'] . ' ' . $l['last_name'])); ?>')" title="Approve Leave">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="showActionModal(<?php echo $l['id']; ?>, 'reject', '<?php echo sanitize(addslashes($l['first_name'] . ' ' . $l['last_name'])); ?>')" title="Reject Leave">
                                <i class="fas fa-times"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-info-circle text-info me-2"></i>Leave Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Action Modal (Approve / Reject) -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="id" id="actionId">
                <input type="hidden" name="action" id="actionType">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="actionTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="actionText" class="fw-semibold">Are you sure?</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold small mb-1" id="remarksLabel">Admin Remarks / Reject Reason</label>
                        <textarea name="remarks" id="actionRemarks" class="form-control" rows="3" placeholder="Enter reason or notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="actionBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    if ($.fn.DataTable.isDataTable('#leaveRequestsTable')) {
        $('#leaveRequestsTable').DataTable().destroy();
    }
    $('#leaveRequestsTable').DataTable({
        "pageLength": 15,
        "ordering": true,
        "searching": true,
        "responsive": true,
        "columnDefs": [
            { "orderable": false, "targets": [7, 9] }
        ]
    });
});

function showDetails(l) {
    l = typeof l === 'string' ? JSON.parse(l) : l;
    const statusBadge = l.status == 'Approved' ? 'bg-success' : l.status == 'Rejected' ? 'bg-danger' : 'bg-warning text-dark';
    const attachmentHtml = l.attachment ? `<a href="../../${esc(l.attachment)}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-download me-1"></i>View Attachment</a>` : 'None';
    
    const html = `
        <dl class="row mb-0">
            <dt class="col-sm-4">Employee</dt>
            <dd class="col-sm-8">${esc(l.first_name)} ${esc(l.last_name)} (${esc(l.employee_code)})</dd>
            <dt class="col-sm-4">Department</dt>
            <dd class="col-sm-8">${esc(l.department_name || 'N/A')}</dd>
            <dt class="col-sm-4">Leave Type</dt>
            <dd class="col-sm-8"><span class="badge bg-secondary">${esc(l.leave_type)}</span></dd>
            <dt class="col-sm-4">Duration</dt>
            <dd class="col-sm-8">${l.start_date} to ${l.end_date} (${l.total_days || 1} day/s)</dd>
            <dt class="col-sm-4">Day Type</dt>
            <dd class="col-sm-8">${esc(l.day_type || 'Full Day')}</dd>
            <dt class="col-sm-4">Reason</dt>
            <dd class="col-sm-8">${esc(l.reason || '-')}</dd>
            <dt class="col-sm-4">Attachment</dt>
            <dd class="col-sm-8">${attachmentHtml}</dd>
            <dt class="col-sm-4">Status</dt>
            <dd class="col-sm-8"><span class="badge ${statusBadge}">${l.status}</span></dd>
            ${l.remarks ? `<dt class="col-sm-4">Admin Remarks / Reject Reason</dt><dd class="col-sm-8 text-danger">${esc(l.remarks)}</dd>` : ''}
            ${l.approved_at ? `<dt class="col-sm-4">Reviewed On</dt><dd class="col-sm-8">${l.approved_at}</dd>` : ''}
        </dl>
    `;
    document.getElementById('detailsBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('detailsModal')).show();
}

function showActionModal(id, action, name) {
    document.getElementById('actionId').value = id;
    document.getElementById('actionType').value = action;
    const btn = document.getElementById('actionBtn');
    const remarksLabel = document.getElementById('remarksLabel');
    const remarksArea = document.getElementById('actionRemarks');
    
    if (action === 'approve') {
        document.getElementById('actionTitle').textContent = 'Approve Leave Request';
        document.getElementById('actionText').textContent = `Are you sure you want to APPROVE the leave request for ${name}?`;
        remarksLabel.textContent = 'Admin Remarks (Optional)';
        remarksArea.required = false;
        btn.className = 'btn btn-success';
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Approve Leave';
    } else {
        document.getElementById('actionTitle').textContent = 'Reject Leave Request';
        document.getElementById('actionText').textContent = `Are you sure you want to REJECT the leave request for ${name}?`;
        remarksLabel.textContent = 'Reject Reason (Mandatory)';
        remarksArea.required = true;
        btn.className = 'btn btn-danger';
        btn.innerHTML = '<i class="fas fa-times me-1"></i>Reject Leave';
    }
    new bootstrap.Modal(document.getElementById('actionModal')).show();
}

function esc(s) { 
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>

<?php require_once '../includes/footer.php'; ?>