<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('settings');

$message = '';
$error = '';

// Handle Branch Actions (Add / Edit / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['branch_action'])) {
    $action = $_POST['branch_action'];
    $branch_name = sanitize($_POST['branch_name'] ?? '');
    $branch_code = strtoupper(trim(sanitize($_POST['branch_code'] ?? '')));
    $latitude = isset($_POST['latitude']) && trim($_POST['latitude']) !== '' ? (float)$_POST['latitude'] : null;
    $longitude = isset($_POST['longitude']) && trim($_POST['longitude']) !== '' ? (float)$_POST['longitude'] : null;
    $attendance_radius = !empty($_POST['attendance_radius']) ? (int)$_POST['attendance_radius'] : 200;
    $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    if (empty($branch_name)) {
        $error = 'Branch name is required.';
    } else {
        if (empty($branch_code)) {
            $clean = preg_replace('/[^A-Za-z]/', '', $branch_name);
            $branch_code = strlen($clean) >= 3 ? strtoupper(substr($clean, 0, 3)) : str_pad(strtoupper($clean), 3, 'X');
        }

        try {
            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO branches (branch_name, branch_code, latitude, longitude, attendance_radius, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$branch_name, $branch_code, $latitude, $longitude, $attendance_radius, $status]);
                $message = 'Branch "' . htmlspecialchars($branch_name) . '" added successfully!';
            } elseif ($action === 'edit') {
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('Invalid branch ID.');

                $stmt = $pdo->prepare("UPDATE branches SET branch_name=?, branch_code=?, latitude=?, longitude=?, attendance_radius=?, status=? WHERE id=?");
                $stmt->execute([$branch_name, $branch_code, $latitude, $longitude, $attendance_radius, $status, $id]);
                $message = 'Branch "' . htmlspecialchars($branch_name) . '" updated successfully!';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['delete_branch'])) {
    $id = (int)$_GET['delete_branch'];
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE branch_id = ?");
        $chk->execute([$id]);
        $empCount = (int)$chk->fetchColumn();

        if ($empCount > 0) {
            $error = "Cannot delete branch: $empCount employee(s) are currently assigned to it. Please reassign them first or set branch status to Inactive.";
        } else {
            $bStmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ?");
            $bStmt->execute([$id]);
            $bName = $bStmt->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Branch "' . htmlspecialchars($bName ?: "ID #$id") . '" deleted successfully!';
        }
    } catch (Exception $e) {
        $error = 'Error deleting branch: ' . $e->getMessage();
    }
}

// Update other settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['office_name'])) {
        try {
            $existing = $pdo->query("SELECT id FROM office_locations WHERE status = 1 ORDER BY id LIMIT 1")->fetchColumn();

            $values = [
                sanitize($_POST['office_name']),
                $_POST['office_latitude'],
                $_POST['office_longitude'],
                (int)$_POST['office_radius'],
                sanitize($_POST['office_address'] ?? '')
            ];

            if ($existing) {
                $pdo->prepare("UPDATE office_locations SET office_name=?, latitude=?, longitude=?, radius=?, address=? WHERE id=?")
                    ->execute(array_merge($values, [$existing]));
            } else {
                $pdo->prepare("INSERT INTO office_locations (office_name, latitude, longitude, radius, address) VALUES (?,?,?,?,?)")
                    ->execute($values);
            }
            $message = 'Office settings saved';
        } catch (Exception $e) {
            $message = 'Error saving office settings';
        }
    }

    if (isset($_POST['office_start_time'])) {
        try {
            $timeSettings = ['office_start_time', 'late_start_time', 'half_day_time', 'half_day_checkout_time', 'office_end_time', 'saturday_end_time', 'attendance_window_start', 'attendance_window_end'];
            foreach ($timeSettings as $key) {
                if (isset($_POST[$key])) {
                    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                        ->execute([$key, $_POST[$key], $_POST[$key]]);
                }
            }
            $message = 'Time settings saved';
        } catch (Exception $e) {
            $message = 'Error saving time settings';
        }
    }

    if (isset($_POST['face_verification_mode'])) {
        if (getAdminRoleName() !== 'super_admin') {
            $message = 'Only a super admin can change face verification settings';
        } else {
            try {
                $mode = $_POST['face_verification_mode'];
                if (!in_array($mode, ['block', 'flag', 'log', 'off'], true)) $mode = 'block';

                $threshold = (float) ($_POST['face_match_threshold'] ?? 0.65);
                if ($threshold < 0.30 || $threshold > 0.99) $threshold = 0.65;

                $upsert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                                         ON DUPLICATE KEY UPDATE setting_value = ?");
                $upsert->execute(['face_verification_mode', $mode, $mode]);
                $upsert->execute(['face_match_threshold', (string) $threshold, (string) $threshold]);
                $message = 'Face verification settings saved';
            } catch (Exception $e) {
                $message = 'Error saving face verification settings';
            }
        }
    }

    if (isset($_POST['rule_name'])) {
        try {
            $pdo->prepare("UPDATE salary_rules SET rule_name=?, half_day_deduction_percent=?, absent_deduction_percent=?, overtime_rate=? WHERE id=1")
                ->execute([
                    sanitize($_POST['rule_name']),
                    $_POST['half_day_deduction_percent'],
                    $_POST['absent_deduction_percent'],
                    $_POST['overtime_rate'] ?? 0,
                ]);
            $message = 'Salary rules saved';
        } catch (Exception $e) {
            $message = 'Error saving salary rules';
        }
    }
}

// Fetch branches with employee counts
try {
    $branches = $pdo->query("
        SELECT b.*, 
               (SELECT COUNT(*) FROM employees e WHERE e.branch_id = b.id) as employee_count
        FROM branches b 
        ORDER BY b.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $branches = [];
}

try { $office = $pdo->query("SELECT * FROM office_locations WHERE status = 1 ORDER BY id LIMIT 1")->fetch(); } catch (Exception $e) { $office = null; }
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) { $settings[$row['setting_key']] = $row['setting_value']; }
} catch (Exception $e) {}
try { $rules = $pdo->query("SELECT * FROM salary_rules WHERE id = 1")->fetch(); } catch (Exception $e) { $rules = null; }

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-cog me-2 text-primary"></i>System & Branch Settings</h4>
        <p class="text-muted small mb-0">Configure branches, GPS attendance geofencing, office hours, and salary rules.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#branchModal" onclick="resetBranchModal()">
        <i class="fas fa-plus me-1"></i> Add Branch
    </button>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Branch Management Section -->
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark"><i class="fas fa-building text-primary me-2"></i>Branches & GPS Geofences (<?php echo count($branches); ?>)</span>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#branchModal" onclick="resetBranchModal()">
                    <i class="fas fa-plus me-1"></i> Add Branch
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Branch Name</th>
                                <th>Code / Prefix</th>
                                <th>GPS Coordinates (Latitude, Longitude)</th>
                                <th>Radius</th>
                                <th>Employees</th>
                                <th>Status</th>
                                <th style="width: 130px;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($branches)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fas fa-building fa-2x mb-2 text-secondary"></i><br>
                                    No branches created yet. Click "Add Branch" to create one.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($branches as $b): ?>
                                <tr>
                                    <td><strong><?php echo $b['id']; ?></strong></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo sanitize($b['branch_name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary px-2 py-1 font-monospace">
                                            <?php echo sanitize($b['branch_code'] ?: strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $b['branch_name']), 0, 3))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($b['latitude'] !== null && $b['longitude'] !== null && (float)$b['latitude'] != 0): ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="small font-monospace text-secondary">
                                                    <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                                    <?php echo round($b['latitude'], 6); ?>, <?php echo round($b['longitude'], 6); ?>
                                                </span>
                                                <a href="https://www.google.com/maps?q=<?php echo $b['latitude']; ?>,<?php echo $b['longitude']; ?>" 
                                                   target="_blank" class="btn btn-sm btn-outline-info py-0 px-2" title="Open Map">
                                                    <i class="fas fa-external-link-alt" style="font-size: 0.75rem;"></i> Map
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="fas fa-exclamation-circle text-warning me-1"></i>Not configured</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo $b['attendance_radius'] ?? 200; ?>m</span>
                                    </td>
                                    <td>
                                        <a href="<?php echo BASE_URL; ?>modules/employees?branch_id=<?php echo $b['id']; ?>" class="badge bg-light text-primary border text-decoration-none py-1 px-2">
                                            <i class="fas fa-users me-1"></i><?php echo (int)$b['employee_count']; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ((int)$b['status'] === 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary me-1" 
                                                onclick="editBranch(<?php echo htmlspecialchars(json_encode($b)); ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="confirmDeleteBranch(<?php echo $b['id']; ?>, '<?php echo addslashes(sanitize($b['branch_name'])); ?>', <?php echo (int)$b['employee_count']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Time Settings -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white"><i class="fas fa-clock text-primary me-2"></i>Time & Attendance Window Settings</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><label class="form-label">Office Start Time</label><input type="time" name="office_start_time" class="form-control" value="<?php echo $settings['office_start_time'] ?? '09:30'; ?>"></div>
                        <div class="col-md-6"><label class="form-label">Late Start Time</label><input type="time" name="late_start_time" class="form-control" value="<?php echo $settings['late_start_time'] ?? '10:00'; ?>"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><label class="form-label">Half Day Time</label><input type="time" name="half_day_time" class="form-control" value="<?php echo $settings['half_day_time'] ?? '10:10'; ?>"></div>
                        <div class="col-md-6"><label class="form-label">Office End Time</label><input type="time" name="office_end_time" class="form-control" value="<?php echo $settings['office_end_time'] ?? '18:30'; ?>"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><label class="form-label">Window Start</label><input type="time" name="attendance_window_start" class="form-control" value="<?php echo $settings['attendance_window_start'] ?? '09:00'; ?>"></div>
                        <div class="col-md-6"><label class="form-label">Window End</label><input type="time" name="attendance_window_end" class="form-control" value="<?php echo $settings['attendance_window_end'] ?? '09:30'; ?>"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><label class="form-label">Saturday End Time</label><input type="time" name="saturday_end_time" class="form-control" value="<?php echo $settings['saturday_end_time'] ?? '14:30'; ?>"><div class="form-text">Short day rule.</div></div>
                        <div class="col-md-6"><label class="form-label">Half Day Checkout Before</label><input type="time" name="half_day_checkout_time" class="form-control" value="<?php echo $settings['half_day_checkout_time'] ?? '17:00'; ?>"></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Time Settings</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Salary Rules -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white"><i class="fas fa-calculator text-primary me-2"></i>Salary Deduction Rules</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Rule Name</label>
                            <input type="text" name="rule_name" class="form-control" value="<?php echo sanitize($rules['rule_name'] ?? 'Default Rules'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Half Day Deduction (%)</label>
                            <input type="number" step="0.01" name="half_day_deduction_percent" class="form-control" value="<?php echo $rules['half_day_deduction_percent'] ?? 50; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Absent Deduction (%)</label>
                            <input type="number" step="0.01" name="absent_deduction_percent" class="form-control" value="<?php echo $rules['absent_deduction_percent'] ?? 100; ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Overtime Rate (per hour)</label>
                            <input type="number" step="0.01" name="overtime_rate" class="form-control" value="<?php echo $rules['overtime_rate'] ?? 0; ?>">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Rules</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Face Verification -->
    <div class="col-md-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><i class="fas fa-user-shield text-primary me-2"></i>Face Verification Settings</div>
            <div class="card-body">
                <?php if (getAdminRoleName() !== 'super_admin'): ?>
                <div class="alert alert-light border mb-0">
                    <i class="fas fa-lock me-1"></i>
                    Only a super admin can change these settings.
                    Current policy: <strong><?php echo sanitize($settings['face_verification_mode'] ?? 'block'); ?></strong>.
                </div>
                <?php else: ?>
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">When a face does not match</label>
                            <?php $mode = $settings['face_verification_mode'] ?? 'block'; ?>
                            <select name="face_verification_mode" class="form-select">
                                <option value="block" <?php echo $mode === 'block' ? 'selected' : ''; ?>>Block the check-in</option>
                                <option value="flag"  <?php echo $mode === 'flag'  ? 'selected' : ''; ?>>Allow, but record it</option>
                                <option value="log"   <?php echo $mode === 'log'   ? 'selected' : ''; ?>>Allow, log only</option>
                                <option value="off"   <?php echo $mode === 'off'   ? 'selected' : ''; ?>>Turn face verification off</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Match strictness (0.30 - 0.99)</label>
                            <input type="number" step="0.01" min="0.30" max="0.99" name="face_match_threshold"
                                   class="form-control" value="<?php echo sanitize($settings['face_match_threshold'] ?? '0.65'); ?>">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Face Settings</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Branch Modal (Add / Edit) -->
<div class="modal fade" id="branchModal" tabindex="-1" aria-labelledby="branchModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST" id="branchForm">
                <input type="hidden" name="branch_action" id="branchFormAction" value="add">
                <input type="hidden" name="id" id="branchId" value="">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="branchModalTitle">
                        <i class="fas fa-building text-primary me-2"></i>Add New Branch
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Branch Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-building"></i></span>
                                <input type="text" name="branch_name" id="branchNameInput" class="form-control" 
                                       placeholder="e.g. Siwan Branch, Patna City Branch" required oninput="autoSuggestCode()">
                            </div>
                            <div class="form-text">Used to generate dynamic employee code prefix (first 3 letters uppercase).</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Branch Code / Prefix <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-tag"></i></span>
                                <input type="text" name="branch_code" id="branchCodeInput" class="form-control font-monospace text-uppercase" 
                                       maxlength="10" placeholder="e.g. SIW, PAT" required>
                            </div>
                            <div class="form-text">e.g. <code>SIW</code> for SIW001, SIW002.</div>
                        </div>

                        <div class="col-12 mt-3">
                            <div class="card bg-light border-0 p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                    <span class="fw-bold text-dark">
                                        <i class="fas fa-map-marked-alt text-danger me-1"></i> GPS Coordinates (Geofence)
                                    </span>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnDetectLocation" onclick="detectGPSLocation()">
                                        <i class="fas fa-crosshairs me-1"></i> Detect Current Location
                                    </button>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold">Latitude</label>
                                        <input type="number" step="any" name="latitude" id="branchLatInput" class="form-control font-monospace" placeholder="e.g. 25.778135">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold">Longitude</label>
                                        <input type="number" step="any" name="longitude" id="branchLngInput" class="form-control font-monospace" placeholder="e.g. 84.349683">
                                    </div>
                                </div>
                                <div class="small text-muted mt-2" id="gpsStatusMessage">
                                    <i class="fas fa-info-circle me-1"></i> Staff check-in will be verified against these coordinates.
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Attendance Geofence Radius (Meters)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-bullseye"></i></span>
                                <input type="number" name="attendance_radius" id="branchRadiusInput" class="form-control" value="200" min="10" max="5000">
                                <span class="input-group-text">meters</span>
                            </div>
                            <div class="form-text">Staff must be within this radius to mark attendance. Default: 200m.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Branch Status</label>
                            <select name="status" id="branchStatusInput" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                            <div class="form-text">Only active branches can be assigned to new employees.</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSaveBranch">
                        <i class="fas fa-save me-1"></i> Save Branch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function autoSuggestCode() {
    var mode = document.getElementById('branchFormAction').value;
    if (mode === 'add') {
        var name = document.getElementById('branchNameInput').value;
        var clean = name.replace(/[^a-zA-Z]/g, '').toUpperCase();
        if (clean.length >= 3) {
            document.getElementById('branchCodeInput').value = clean.substring(0, 3);
        } else if (clean.length > 0) {
            document.getElementById('branchCodeInput').value = clean;
        }
    }
}

function resetBranchModal() {
    document.getElementById('branchFormAction').value = 'add';
    document.getElementById('branchId').value = '';
    document.getElementById('branchModalTitle').innerHTML = '<i class="fas fa-building text-primary me-2"></i>Add New Branch';
    document.getElementById('btnSaveBranch').innerHTML = '<i class="fas fa-save me-1"></i> Save Branch';
    document.getElementById('branchNameInput').value = '';
    document.getElementById('branchCodeInput').value = '';
    document.getElementById('branchLatInput').value = '';
    document.getElementById('branchLngInput').value = '';
    document.getElementById('branchRadiusInput').value = '200';
    document.getElementById('branchStatusInput').value = '1';
    document.getElementById('gpsStatusMessage').innerHTML = '<i class="fas fa-info-circle me-1"></i> Staff check-in will be verified against these coordinates.';
}

function editBranch(b) {
    document.getElementById('branchFormAction').value = 'edit';
    document.getElementById('branchId').value = b.id;
    document.getElementById('branchModalTitle').innerHTML = '<i class="fas fa-edit text-primary me-2"></i>Edit Branch: ' + b.branch_name;
    document.getElementById('btnSaveBranch').innerHTML = '<i class="fas fa-save me-1"></i> Update Branch';
    document.getElementById('branchNameInput').value = b.branch_name || '';
    document.getElementById('branchCodeInput').value = b.branch_code || '';
    document.getElementById('branchLatInput').value = b.latitude || '';
    document.getElementById('branchLngInput').value = b.longitude || '';
    document.getElementById('branchRadiusInput').value = b.attendance_radius || '200';
    document.getElementById('branchStatusInput').value = b.status !== undefined ? b.status : '1';
    document.getElementById('gpsStatusMessage').innerHTML = '<i class="fas fa-info-circle me-1"></i> Staff check-in will be verified against these coordinates.';

    var modal = new bootstrap.Modal(document.getElementById('branchModal'));
    modal.show();
}

function confirmDeleteBranch(id, name, empCount) {
    if (empCount > 0) {
        alert("Cannot delete branch '" + name + "': " + empCount + " employee(s) are currently assigned to this branch.\n\nPlease reassign those employees to another branch first, or set this branch status to Inactive.");
        return;
    }
    if (confirm("Are you sure you want to delete branch '" + name + "'? This action cannot be undone.")) {
        window.location.href = '?delete_branch=' + id;
    }
}

function detectGPSLocation() {
    var msg = document.getElementById('gpsStatusMessage');
    var btn = document.getElementById('btnDetectLocation');
    if (!navigator.geolocation) {
        msg.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i> Geolocation is not supported by your browser.</span>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Detecting...';
    msg.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i> Fetching precise GPS location from device...</span>';

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-crosshairs me-1"></i> Detect Current Location';
            var lat = pos.coords.latitude.toFixed(6);
            var lng = pos.coords.longitude.toFixed(6);
            document.getElementById('branchLatInput').value = lat;
            document.getElementById('branchLngInput').value = lng;
            msg.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i> Location detected: ' + lat + ', ' + lng + ' (Accuracy: ~' + Math.round(pos.coords.accuracy) + 'm)</span>';
        },
        function(err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-crosshairs me-1"></i> Detect Current Location';
            msg.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i> GPS error: ' + err.message + '. Please enter coordinates manually.</span>';
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}
</script>

<?php require_once '../includes/footer.php'; ?>
