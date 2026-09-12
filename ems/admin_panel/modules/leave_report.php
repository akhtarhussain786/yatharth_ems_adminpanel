<?php
/**
 * One employee's leave, in full: entitlement, what is used, what is left, and
 * every request behind those figures.
 *
 * The leave requests page answers "what is waiting for me to approve"; this
 * answers "where does this person stand", which nothing did before.
 */
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('leave_requests');
require_once __DIR__ . '/../../backend/helpers/leave_balance.php';

$employeeId = (int) ($_GET['employee_id'] ?? 0);
$year       = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');

$employees = $pdo->query("
    SELECT e.id, e.employee_code, e.first_name, e.last_name, d.name AS department_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.status = 1
    ORDER BY e.first_name, e.last_name
")->fetchAll();

$employee = null;
$balances = [];
$requests = [];
$summary  = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'days_taken' => 0.0];
$monthly  = [];
$loadError = '';

if ($employeeId) {
    try {
        $stmt = $pdo->prepare("
            SELECT e.*, d.name AS department_name, g.name AS designation_name
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN designations g ON g.id = e.designation_id
            WHERE e.id = ?");
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();

        if ($employee) {
            $balances = computeLeaveBalances($pdo, $employeeId, $year);

            $rq = $pdo->prepare("
                SELECT * FROM leave_requests
                WHERE employee_id = ? AND (YEAR(start_date) = ? OR YEAR(created_at) = ?)
                ORDER BY start_date DESC");
            $rq->execute([$employeeId, $year, $year]);
            $requests = $rq->fetchAll();

            foreach ($requests as $r) {
                $st = strtolower((string) $r['status']);
                if (isset($summary[$st])) $summary[$st]++;
                if ($st === 'approved') {
                    $summary['days_taken'] += (float) ($r['total_days'] ?? 0);
                    $m = date('M', strtotime($r['start_date']));
                    $monthly[$m] = ($monthly[$m] ?? 0) + (float) ($r['total_days'] ?? 0);
                }
            }
        }
    } catch (Throwable $e) {
        // Say so rather than rendering an empty page that looks like "no leave".
        error_log('Leave report: ' . $e->getMessage());
        $loadError = 'Could not load this employee\'s leave. Please try again.';
    }
}

// Without a selection the page used to show nothing but a prompt, so the figures
// everybody actually wants — who has leave left, who is nearly out — took one
// page load per person to find. This builds the same numbers for everyone at
// once. computeLeaveBalances() is reused per employee rather than reimplemented
// as one big query: it is the single source of truth the app reads too, and a
// second copy of the arithmetic here is precisely how the two would drift.
$overview = [];
if (!$employeeId && !$loadError) {
    try {
        $pendingCounts = [];
        $pc = $pdo->prepare("SELECT employee_id, COUNT(*) AS c
                             FROM leave_requests
                             WHERE LOWER(status) = 'pending'
                               AND (YEAR(start_date) = ? OR YEAR(created_at) = ?)
                             GROUP BY employee_id");
        $pc->execute([$year, $year]);
        foreach ($pc->fetchAll() as $row) {
            $pendingCounts[(int) $row['employee_id']] = (int) $row['c'];
        }

        foreach ($employees as $e) {
            $eid = (int) $e['id'];
            $rows = computeLeaveBalances($pdo, $eid, $year);
            $allotted = 0.0; $used = 0.0;
            foreach ($rows as $b) {
                $allotted += (float) $b['allotted'];
                $used     += (float) $b['used'];
            }
            $overview[] = [
                'employee'  => $e,
                'allotted'  => $allotted,
                'used'      => $used,
                'remaining' => max(0.0, $allotted - $used),
                'pending'   => $pendingCounts[$eid] ?? 0,
                'rows'      => $rows,
            ];
        }
    } catch (Throwable $e) {
        error_log('Leave report overview: ' . $e->getMessage());
        $loadError = 'Could not load the leave summary. Please try again.';
    }
}

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="fw-bold mb-0"><i class="fas fa-chart-pie me-2"></i>Leave Report</h4>
    <a href="leave_requests.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-list me-1"></i>All Leave Requests
    </a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">Employee</label>
                <select name="employee_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Select an employee…</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?php echo $e['id']; ?>" <?php echo $e['id'] == $employeeId ? 'selected' : ''; ?>>
                            <?php echo sanitize(trim($e['first_name'] . ' ' . $e['last_name'])); ?>
                            (<?php echo sanitize($e['employee_code']); ?><?php echo $e['department_name'] ? ' — ' . sanitize($e['department_name']) : ''; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Year</label>
                <select name="year" class="form-select" onchange="this.form.submit()">
                    <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 4; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Show</button>
            </div>
        </form>
    </div>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger"><?php echo sanitize($loadError); ?></div>
<?php elseif (!$employeeId): ?>
    <?php if (!$overview): ?>
        <div class="card"><div class="card-body text-center text-muted py-5">
            <i class="fas fa-user-clock fa-2x mb-2 d-block"></i>
            No active employees to report on.
        </div></div>
    <?php else: ?>
    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-bold"><i class="fas fa-users me-2"></i>All Employees &mdash; <?php echo $year; ?></span>
            <span class="d-flex align-items-center gap-2">
                <input type="search" id="lrSearch" class="form-control form-control-sm"
                       style="max-width:220px" placeholder="Search name or ID…" autocomplete="off">
                <span class="text-muted small"><?php echo count($overview); ?> employees</span>
            </span>
        </div>
        <!-- Bounded so the list scrolls inside its own box and the filter above
             stays put, instead of the whole page growing with the headcount. -->
        <div class="card-body p-2" id="lrScroll" style="max-height:70vh; overflow-y:auto;">
            <div class="row g-2">
            <?php foreach ($overview as $ov):
                $e   = $ov['employee'];
                $name = trim($e['first_name'] . ' ' . $e['last_name']);
                $pct  = $ov['allotted'] > 0 ? min(100, ($ov['used'] / $ov['allotted']) * 100) : 0;
                $bar  = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                $link = 'leave_report.php?employee_id=' . (int) $e['id'] . '&year=' . $year;
            ?>
                <div class="col-12 col-md-6 col-xl-4 lr-item"
                     data-search="<?php echo sanitize(strtolower($name . ' ' . $e['employee_code'] . ' ' . ($e['department_name'] ?? ''))); ?>">
                    <div class="card h-100 border">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div class="min-w-0">
                                    <a href="<?php echo $link; ?>" class="fw-bold text-decoration-none d-block text-truncate">
                                        <?php echo sanitize($name); ?>
                                    </a>
                                    <div class="small text-muted text-truncate">
                                        <?php echo sanitize($e['employee_code']); ?><?php
                                            echo $e['department_name'] ? ' · ' . sanitize($e['department_name']) : ''; ?>
                                    </div>
                                </div>
                                <?php if ($ov['pending'] > 0): ?>
                                    <span class="badge bg-warning text-dark flex-shrink-0"><?php echo $ov['pending']; ?> pending</span>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Used <strong class="text-dark"><?php echo rtrim(rtrim(number_format($ov['used'], 1), '0'), '.'); ?></strong>
                                    of <?php echo rtrim(rtrim(number_format($ov['allotted'], 1), '0'), '.'); ?> days</span>
                                <span class="fw-semibold"><?php echo rtrim(rtrim(number_format($ov['remaining'], 1), '0'), '.'); ?> left</span>
                            </div>
                            <div class="progress" style="height:6px">
                                <div class="progress-bar <?php echo $bar; ?>" style="width:<?php echo (float) $pct; ?>%"></div>
                            </div>

                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <?php foreach ($ov['rows'] as $b):
                                    if ($b['allotted'] <= 0 && $b['used'] <= 0) continue; ?>
                                    <span class="badge bg-light text-dark border fw-normal">
                                        <?php echo sanitize($b['leave_type']); ?>:
                                        <?php echo rtrim(rtrim(number_format($b['remaining'], 1), '0'), '.'); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                            <a href="<?php echo $link; ?>" class="btn btn-sm btn-outline-primary w-100 mt-3">
                                <i class="fas fa-eye me-1"></i>Full report
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <div id="lrEmpty" class="text-center text-muted py-4 d-none">No employee matches that search.</div>
        </div>
    </div>
    <script>
    (function () {
        var box = document.getElementById('lrSearch');
        if (!box) return;
        var items = Array.prototype.slice.call(document.querySelectorAll('.lr-item'));
        var empty = document.getElementById('lrEmpty');
        box.addEventListener('input', function () {
            var q = box.value.trim().toLowerCase();
            var shown = 0;
            items.forEach(function (el) {
                var hit = !q || el.dataset.search.indexOf(q) !== -1;
                el.classList.toggle('d-none', !hit);
                if (hit) shown++;
            });
            empty.classList.toggle('d-none', shown !== 0);
        });
    })();
    </script>
    <?php endif; ?>
<?php elseif (!$employee): ?>
    <div class="alert alert-warning">That employee no longer exists.</div>
<?php else: ?>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                 style="width:52px;height:52px;font-size:1.25rem;font-weight:600;">
                <?php echo strtoupper(substr($employee['first_name'], 0, 1)); ?>
            </div>
            <div>
                <div class="fw-bold fs-5"><?php echo sanitize(trim($employee['first_name'] . ' ' . $employee['last_name'])); ?></div>
                <div class="text-muted small">
                    <?php echo sanitize($employee['employee_code']); ?>
                    <?php if (!empty($employee['department_name'])): ?> · <?php echo sanitize($employee['department_name']); ?><?php endif; ?>
                    <?php if (!empty($employee['designation_name'])): ?> · <?php echo sanitize($employee['designation_name']); ?><?php endif; ?>
                </div>
            </div>
            <div class="ms-auto text-end">
                <div class="text-muted small">Year</div>
                <div class="fw-bold fs-5"><?php echo $year; ?></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <?php
        $cards = [
            ['Days Taken', number_format($summary['days_taken'], 1), 'primary', 'fa-calendar-check'],
            ['Approved',   $summary['approved'],                     'success', 'fa-circle-check'],
            ['Pending',    $summary['pending'],                      'warning', 'fa-clock'],
            ['Rejected',   $summary['rejected'],                     'danger',  'fa-circle-xmark'],
        ];
        foreach ($cards as [$label, $value, $colour, $icon]): ?>
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-3 bg-<?php echo $colour; ?> bg-opacity-10 text-<?php echo $colour; ?> d-flex align-items-center justify-content-center"
                         style="width:44px;height:44px;">
                        <i class="fas <?php echo $icon; ?>"></i>
                    </div>
                    <div>
                        <div class="text-muted small"><?php echo $label; ?></div>
                        <div class="fw-bold fs-4"><?php echo $value; ?></div>
                    </div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-bold"><i class="fas fa-scale-balanced me-2"></i>Leave Balance</div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th>Leave Type</th><th class="text-end">Entitled</th><th class="text-end">Used</th><th class="text-end">Remaining</th><th style="width:30%">Usage</th></tr></thead>
                <tbody>
                <?php foreach ($balances as $b):
                    $allotted = (float) $b['allotted'];
                    $used     = (float) $b['used'];
                    $pct      = $allotted > 0 ? min(100, ($used / $allotted) * 100) : ($used > 0 ? 100 : 0);
                    $bar      = $pct >= 100 ? 'danger' : ($pct >= 75 ? 'warning' : 'success');
                ?>
                    <tr>
                        <td class="fw-semibold"><?php echo sanitize($b['leave_type']); ?></td>
                        <td class="text-end"><?php echo $allotted > 0 ? number_format($allotted, 1) : '<span class="text-muted">—</span>'; ?></td>
                        <td class="text-end"><?php echo number_format($used, 1); ?></td>
                        <td class="text-end fw-bold"><?php echo $allotted > 0 ? number_format((float) $b['remaining'], 1) : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <?php if ($allotted > 0): ?>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-<?php echo $bar; ?>" style="width:<?php echo $pct; ?>%"></div>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small">Not an entitlement</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($monthly): ?>
    <div class="card mb-3">
        <div class="card-header bg-white fw-bold"><i class="fas fa-calendar-days me-2"></i>Approved Days by Month</div>
        <div class="card-body">
            <div class="d-flex align-items-end gap-2" style="height:120px;">
                <?php
                $max = max($monthly);
                foreach (['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $m):
                    $v = $monthly[$m] ?? 0;
                    $h = $max > 0 ? max(4, ($v / $max) * 100) : 4;
                ?>
                    <div class="flex-fill text-center">
                        <div class="small text-muted mb-1"><?php echo $v > 0 ? number_format($v, 1) : ''; ?></div>
                        <div class="bg-primary rounded-top mx-auto" style="height:<?php echo $h; ?>px;width:70%;opacity:<?php echo $v > 0 ? 1 : 0.15; ?>"></div>
                        <div class="small text-muted mt-1"><?php echo $m; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-list-ul me-2"></i>Leave Requests
            <span class="badge bg-secondary ms-1"><?php echo count($requests); ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Type</th><th>From</th><th>To</th><th class="text-end">Days</th><th>Reason</th><th>Status</th><th>Decided</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No leave recorded for <?php echo $year; ?>.</td></tr>
                <?php else: foreach ($requests as $r):
                    $st = strtolower((string) $r['status']);
                    $badge = $st === 'approved' ? 'success' : ($st === 'rejected' ? 'danger' : ($st === 'cancelled' ? 'secondary' : 'warning text-dark'));
                ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border"><?php echo sanitize($r['leave_type']); ?></span></td>
                        <td><?php echo $r['start_date'] ? date('d M Y', strtotime($r['start_date'])) : '—'; ?></td>
                        <td><?php echo $r['end_date'] ? date('d M Y', strtotime($r['end_date'])) : '—'; ?></td>
                        <td class="text-end"><?php echo number_format((float) ($r['total_days'] ?? 0), 1); ?></td>
                        <td class="small"><?php echo sanitize($r['reason'] ?? '') ?: '<span class="text-muted">—</span>'; ?></td>
                        <td><span class="badge bg-<?php echo $badge; ?>"><?php echo sanitize(ucfirst($st)); ?></span></td>
                        <td class="small"><?php echo !empty($r['approved_at']) ? date('d M Y', strtotime($r['approved_at'])) : '<span class="text-muted">—</span>'; ?></td>
                        <td class="small"><?php echo sanitize($r['remarks'] ?? '') ?: '<span class="text-muted">—</span>'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
