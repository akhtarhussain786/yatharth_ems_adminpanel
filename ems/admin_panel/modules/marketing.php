<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('marketing');

$message = '';

// total_leads on the row itself is never written by anything — leads are
// created through the leads endpoint, which knows nothing about duty logs, so
// the stored column has always read 0. Counting them here by employee and time
// window gives the number without a schema change or a migration: a lead
// belongs to the duty that was running when it was created.
//
// An open duty counts leads up to now; a completed one stops at its end time.
$duty_logs = [];
try {
    $duty_logs = $pdo->query("
        SELECT dl.*, e.first_name, e.last_name, e.employee_code,
               (SELECT COUNT(*) FROM leads l
                 WHERE l.employee_id = dl.employee_id
                   AND l.created_at >= dl.start_time
                   AND l.created_at <= COALESCE(dl.end_time, NOW())
               ) AS leads_in_duty
        FROM duty_logs dl
        LEFT JOIN employees e ON e.id = dl.employee_id
        ORDER BY dl.duty_date DESC
    ")->fetchAll();
} catch (Exception $e) {
    // leads.created_at or employee_id missing on an older database: fall back
    // to the plain list rather than losing the whole page.
    error_log('Duty lead count unavailable: ' . $e->getMessage());
    $duty_logs = $pdo->query("
        SELECT dl.*, e.first_name, e.last_name, e.employee_code
        FROM duty_logs dl
        LEFT JOIN employees e ON e.id = dl.employee_id
        ORDER BY dl.duty_date DESC
    ")->fetchAll();
}

$field_visits = $pdo->query("
    SELECT fv.*, e.first_name, e.last_name
    FROM field_visits fv
    LEFT JOIN employees e ON e.id = fv.employee_id
    ORDER BY fv.visit_date DESC
")->fetchAll();

$leads = $pdo->query("
    SELECT l.*, e.first_name as assigned_to_name, e.last_name as assigned_to_last
    FROM leads l
    LEFT JOIN employees e ON e.id = l.assigned_to
    ORDER BY l.created_at DESC LIMIT 200
")->fetchAll();

$employees = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#dutyLogsTab">Duty Logs</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#fieldVisitsTab">Field Visits</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#leadsTab">Leads</a></li>
</ul>
<?php if ($message): ?>
<div class="alert alert-info alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<div class="tab-content">
    <div class="tab-pane fade show active" id="dutyLogsTab">
        <h5 class="fw-bold mb-3"><i class="fas fa-clipboard-list me-2"></i>Duty Logs</h5>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Employee</th><th>Date</th><th>Start Time</th><th>End Time</th><th>Start KM</th><th>End KM</th><th>Selfies</th><th>Odometer</th><th>Total Visits</th><th>Total Leads</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($duty_logs as $d): ?>
                            <tr>
                                <td><?php echo sanitize($d['first_name'] . ' ' . $d['last_name']); ?></td>
                                <td><?php echo sanitize($d['duty_date']); ?></td>
                                <td><?php echo $d['start_time'] ? sanitize($d['start_time']) : '-'; ?></td>
                                <td><?php echo $d['end_time'] ? sanitize($d['end_time']) : '-'; ?></td>
                                <td><?php echo $d['start_km']; ?></td>
                                <td><?php echo $d['end_km']; ?></td>
                                <?php $who = sanitize($d['first_name'] . ' ' . $d['last_name']); ?>
                                <td>
                                    <div class="photo-cell">
                                        <?php photoThumb($d['start_selfie'] ?? null, "Duty start — $who", 'fa-right-to-bracket'); ?>
                                        <?php photoThumb($d['end_selfie'] ?? null, "Duty end — $who", 'fa-right-from-bracket'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="photo-cell">
                                        <?php photoThumb($d['odometer_start_photo'] ?? null, "Odometer at start — $who", 'fa-gauge'); ?>
                                        <?php photoThumb($d['odometer_end_photo'] ?? null, "Odometer at end — $who", 'fa-gauge-high'); ?>
                                    </div>
                                </td>
                                <td><?php echo $d['total_visits']; ?></td>
                                <td><?php echo $d['leads_in_duty'] ?? $d['total_leads'] ?? 0; ?></td>
                                <td><span class="badge bg-<?php echo $d['status'] == 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($d['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="fieldVisitsTab">
        <h5 class="fw-bold mb-3"><i class="fas fa-map-marker-alt me-2"></i>Field Visits</h5>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Employee</th><th>Date</th><th>Time</th><th>Institute</th><th>Contact Person</th><th>Visit Type</th><th>Teachers Met</th><th>Students Met</th><th>Photo</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($field_visits as $fv): ?>
                            <tr>
                                <td><?php echo sanitize($fv['first_name'] . ' ' . $fv['last_name']); ?></td>
                                <td><?php echo sanitize($fv['visit_date']); ?></td>
                                <td><?php echo sanitize($fv['visit_time']); ?></td>
                                <td><?php echo sanitize($fv['institute_name']); ?></td>
                                <td><?php echo sanitize($fv['contact_person']); ?></td>
                                <td><?php echo ucfirst($fv['visit_type']); ?></td>
                                <td><?php echo $fv['teacher_met']; ?></td>
                                <td><?php echo $fv['student_met']; ?></td>
                                <td>
                                    <div class="photo-cell">
                                        <?php
                                        $visitWho = sanitize(($fv['institute_name'] ?: 'Visit') . ' — ' . $fv['first_name'] . ' ' . $fv['last_name']);
                                        // photo_stamped carries the date/location overlay when the app
                                        // produced one; the plain photo is the fallback.
                                        photoThumb(($fv['photo_stamped'] ?? null) ?: ($fv['photo'] ?? null), $visitWho, 'fa-camera');
                                        ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="leadsTab">
        <h5 class="fw-bold mb-3"><i class="fas fa-users-cog me-2"></i>Leads (Marketing)</h5>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Name</th><th>Mobile</th><th>Email</th><th>Source</th><th>Assigned To</th><th>Status</th><th>Priority</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leads as $l): ?>
                            <tr>
                                <td><?php echo sanitize($l['first_name'] . ' ' . ($l['last_name'] ?? '')); ?></td>
                                <td><?php echo sanitize($l['mobile']); ?></td>
                                <td><?php echo sanitize($l['email'] ?: '-'); ?></td>
                                <td><?php echo sanitize($l['lead_source'] ?: '-'); ?></td>
                                <td><?php echo sanitize(($l['assigned_to_name'] ?? '') . ' ' . ($l['assigned_to_last'] ?? '')); ?></td>
                                <td><span class="badge bg-<?php echo $l['status'] == 'new' ? 'primary' : ($l['status'] == 'contacted' ? 'info' : ($l['status'] == 'interested' ? 'success' : ($l['status'] == 'not_interested' ? 'danger' : ($l['status'] == 'follow_up' ? 'warning text-dark' : ($l['status'] == 'admitted' ? 'success' : 'secondary'))))); ?>"><?php echo str_replace('_', ' ', ucfirst($l['status'])); ?></span></td>
                                <td><span class="badge bg-<?php echo $l['priority'] == 'high' ? 'danger' : ($l['priority'] == 'medium' ? 'warning text-dark' : 'info'); ?>"><?php echo ucfirst($l['priority']); ?></span></td>
                                <td><?php echo sanitize($l['created_at']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/photo_modal.php'; ?>
<?php require_once '../includes/footer.php'; ?>
