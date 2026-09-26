<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('settings');

$message = '';

// Update settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['office_name'])) {
        try {
            $stmt = $pdo->prepare("UPDATE office_locations SET office_name=?, latitude=?, longitude=?, radius=?, address=? WHERE id=1");
            $stmt->execute([
                sanitize($_POST['office_name']),
                $_POST['office_latitude'],
                $_POST['office_longitude'],
                (int)$_POST['office_radius'],
                sanitize($_POST['office_address'] ?? '')
            ]);

            if ($stmt->rowCount() == 0) {
                $pdo->prepare("INSERT INTO office_locations (office_name, latitude, longitude, radius, address) VALUES (?,?,?,?,?)")
                    ->execute([sanitize($_POST['office_name']), $_POST['office_latitude'], $_POST['office_longitude'], (int)$_POST['office_radius'], sanitize($_POST['office_address'] ?? '')]);
            }
            $message = 'Office settings saved';
        } catch (Exception $e) {
            $message = 'Error saving office settings';
        }
    }

    if (isset($_POST['office_start_time'])) {
        try {
            $settingsList = ['office_start_time', 'late_start_time', 'half_day_time', 'half_day_checkout_time', 'office_end_time', 'saturday_end_time', 'attendance_window_start', 'attendance_window_end'];
            foreach ($settingsList as $key) {
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

    // Face verification policy. Super admin only
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

try { $office = $pdo->query("SELECT * FROM office_locations WHERE id = 1")->fetch(); } catch (Exception $e) { $office = null; }
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) { $settings[$row['setting_key']] = $row['setting_value']; }
} catch (Exception $e) {}
try { $rules = $pdo->query("SELECT * FROM salary_rules WHERE id = 1")->fetch(); } catch (Exception $e) { $rules = null; }

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-cog me-2"></i>Settings</h4>
</div>

<?php if ($message): ?>
<div class="alert alert-info alert-dismissible"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
    <!-- Office Location -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-map-marker-alt me-1"></i>Office Location</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3"><label class="form-label">Office Name</label><input type="text" name="office_name" class="form-control" value="<?php echo sanitize($office['office_name'] ?? 'Head Office'); ?>"></div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><label class="form-label">Latitude</label><input type="text" name="office_latitude" class="form-control" value="<?php echo $office['latitude'] ?? '22.804566'; ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Longitude</label><input type="text" name="office_longitude" class="form-control" value="<?php echo $office['longitude'] ?? '86.202875'; ?>" required></div>
                    </div>
                    <div class="mb-3"><label class="form-label">Radius (meters)</label><input type="number" name="office_radius" class="form-control" value="<?php echo $office['radius'] ?? 100; ?>"></div>
                    <div class="mb-3"><label class="form-label">Address</label><textarea name="office_address" class="form-control" rows="2"><?php echo sanitize($office['address'] ?? ''); ?></textarea></div>
                    <button type="submit" class="btn btn-primary">Save Office Settings</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Time Settings -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-clock me-1"></i>Time Settings</div>
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
                        <div class="col-md-6"><label class="form-label">Saturday End Time</label><input type="time" name="saturday_end_time" class="form-control" value="<?php echo $settings['saturday_end_time'] ?? '14:30'; ?>"><div class="form-text">Saturday is a short day: leaving before this is a half day, instead of the weekday time above.</div></div>
                        <div class="col-md-6"><label class="form-label">Half Day Checkout Before</label><input type="time" name="half_day_checkout_time" class="form-control" value="<?php echo $settings['half_day_checkout_time'] ?? '17:00'; ?>"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-12"><small class="text-muted">
                            Check-in after <strong>Late Start Time</strong> is recorded as late but is not deducted.
                            Check-in after <strong>Half Day Time</strong>, or check-out before <strong>Half Day Checkout Before</strong>,
                            makes the day a half day. Breaking both still costs only half a day.
                        </small></div>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Time Settings</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Salary Rules -->
    <div class="col-md-12">
        <div class="card">
            <div class="card-header"><i class="fas fa-calculator me-1"></i>Salary Deduction Rules</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Rule Name</label>
                            <input type="text" name="rule_name" class="form-control" value="<?php echo sanitize($rules['rule_name'] ?? 'Default Rules'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Half Day Deduction (%)</label>
                            <input type="number" step="0.01" name="half_day_deduction_percent" class="form-control" value="<?php echo $rules['half_day_deduction_percent'] ?? 50; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Absent Deduction (%)</label>
                            <input type="number" step="0.01" name="absent_deduction_percent" class="form-control" value="<?php echo $rules['absent_deduction_percent'] ?? 100; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Overtime Rate (per hour)</label>
                            <input type="number" step="0.01" name="overtime_rate" class="form-control" value="<?php echo $rules['overtime_rate'] ?? 0; ?>">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Save Rules</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Face Verification -->
    <div class="col-md-12">
        <div class="card">
            <div class="card-header"><i class="fas fa-user-shield me-1"></i>Face Verification</div>
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
                        <div class="col-12"><small class="text-muted">
                            Start on <strong>Allow, but record it</strong> for a week and review the log below, then switch to
                            <strong>Block</strong> once the scores confirm the threshold suits your staff and phones.
                            Raising the strictness rejects more genuine employees; lowering it lets more impostors through.
                            Employees who have not registered a face are unaffected and can still clock in.
                        </small></div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Save Face Settings</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
