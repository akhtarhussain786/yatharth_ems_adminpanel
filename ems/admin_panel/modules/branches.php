<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('settings');

$message = '';
$error = '';

// Handle POST actions (Add / Edit)
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
        // Auto-generate branch code from first 3 letters if empty
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

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        // Check if employees are assigned to this branch
        $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE branch_id = ?");
        $chk->execute([$id]);
        $empCount = (int)$chk->fetchColumn();

        if ($empCount > 0) {
            $error = "Cannot delete branch: $empCount employee(s) are assigned to it. Please reassign the employees first or mark the branch as Inactive.";
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

// Fetch all branches with assigned employee counts
$query = "
    SELECT b.*, 
           (SELECT COUNT(*) FROM employees e WHERE e.branch_id = b.id) as employee_count
    FROM branches b 
    ORDER BY b.id ASC
";
$branches = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-building me-2 text-primary"></i>Branch Management & Geofencing</h4>
        <p class="text-muted small mb-0">Manage office branches, employee prefixes, GPS coordinates, and attendance radii.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>modules/settings" class="btn btn-outline-secondary">
            <i class="fas fa-cog me-1"></i> General Settings
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#branchModal" onclick="resetBranchModal()">
            <i class="fas fa-plus me-1"></i> Add New Branch
        </button>
    </div>
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

<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i>All Branches (<?php echo count($branches); ?>)</h6>
        <span class="badge bg-light text-dark border">Dynamic Code Generation & GPS Geofence</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="branchesTable">
                <thead class="table-light">
                    <tr>
                        <th style="width: 60px;">#</th>
                        <th>Branch Name</th>
                        <th>Code / Prefix</th>
                        <th>GPS Coordinates (Lat / Long)</th>
                        <th>Attendance Radius</th>
                        <th>Employees</th>
                        <th>Status</th>
                        <th style="width: 140px;" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($branches)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="fas fa-building fa-2x mb-2 text-secondary"></i><br>
                            No branches created yet. Click "Add New Branch" to create one.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($branches as $idx => $b): ?>
                        <tr>
                            <td><strong><?php echo $b['id']; ?></strong></td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo sanitize($b['branch_name']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-primary px-2 py-1 font-monospace" style="font-size: 0.85rem;">
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
                                           target="_blank" class="btn btn-sm btn-outline-info py-0 px-2" title="View on Google Maps">
                                            <i class="fas fa-external-link-alt" style="font-size: 0.75rem;"></i> Map
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small"><i class="fas fa-exclamation-circle text-warning me-1"></i>Not configured</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-bullseye me-1"></i><?php echo $b['attendance_radius'] ?? 200; ?> meters
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>modules/employees?branch_id=<?php echo $b['id']; ?>" class="badge bg-light text-primary border text-decoration-none py-1 px-2" title="View Employees in this Branch">
                                    <i class="fas fa-users me-1"></i><?php echo (int)$b['employee_count']; ?> staff
                                </a>
                            </td>
                            <td>
                                <?php if ((int)$b['status'] === 1): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary me-1" 
                                        onclick="editBranch(<?php echo htmlspecialchars(json_encode($b)); ?>)" title="Edit Branch">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                        onclick="confirmDeleteBranch(<?php echo $b['id']; ?>, '<?php echo addslashes(sanitize($b['branch_name'])); ?>', <?php echo (int)$b['employee_count']; ?>)" title="Delete Branch">
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
                            <div class="form-text">Used to dynamically generate employee code prefix (first 3 letters uppercase).</div>
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
                                        <i class="fas fa-map-marked-alt text-danger me-1"></i> GPS Geofence Coordinates
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
    // Auto suggest only when adding new branch or when code is empty
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
        window.location.href = '?delete=' + id;
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
