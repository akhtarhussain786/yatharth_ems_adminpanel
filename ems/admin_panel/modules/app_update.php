<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('settings');

$message = '';
$messageType = 'info';

define('APK_DIR', __DIR__ . '/../../backend/uploads/apk/');
define('APK_REL', 'uploads/apk/');
define('APK_MAX_BYTES', 300 * 1024 * 1024); // 300 MB

/** Semantic comparison: 1.0.9 < 1.0.10 and 1.9.0 < 1.10.0. */
function versionCompare($a, $b) {
    $pa = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', (string) $a)));
    $pb = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', (string) $b)));
    $len = max(count($pa), count($pb));
    for ($i = 0; $i < $len; $i++) {
        $x = $pa[$i] ?? 0;
        $y = $pb[$i] ?? 0;
        if ($x !== $y) return $x <=> $y;
    }
    return 0;
}

function isValidVersion($v) {
    return (bool) preg_match('/^\d+(\.\d+){1,3}$/', trim((string) $v));
}

function humanSize($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '—';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/**
 * Confirms the upload really is an APK rather than something renamed.
 *
 * An APK is a ZIP, so the file must start with the ZIP magic bytes and contain
 * AndroidManifest.xml. Trusting the extension alone would let anything through.
 */
function validateApk($tmpPath, $originalName, &$error) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'apk') {
        $error = 'Only .apk files can be uploaded.';
        return false;
    }
    if (filesize($tmpPath) > APK_MAX_BYTES) {
        $error = 'That file is larger than the ' . (APK_MAX_BYTES / 1048576) . ' MB limit.';
        return false;
    }

    $handle = @fopen($tmpPath, 'rb');
    if (!$handle) {
        $error = 'The uploaded file could not be read.';
        return false;
    }
    $magic = fread($handle, 4);
    fclose($handle);

    if ($magic !== "PK\x03\x04") {
        $error = 'That file is not a valid APK (bad signature).';
        return false;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            $error = 'That file is not a valid APK (unreadable archive).';
            return false;
        }
        $hasManifest = $zip->locateName('AndroidManifest.xml') !== false;
        $zip->close();
        if (!$hasManifest) {
            $error = 'That archive contains no AndroidManifest.xml, so it is not an APK.';
            return false;
        }
    }

    return true;
}

/** Version-derived name; nothing from the client reaches the filesystem. */
function apkFileName($version) {
    $safe = preg_replace('/[^0-9.]/', '', $version);
    return 'yatharth-ems-v' . $safe . '-' . date('YmdHis') . '.apk';
}

function deleteApkFile($fileName) {
    // basename() strips any path, so a stored value can never traverse out.
    $name = basename((string) $fileName);
    if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'apk') return;
    $path = APK_DIR . $name;
    if (is_file($path)) @unlink($path);
}

// =============================================
// Publish a release
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save') {
    requireModuleAccess('settings', 'can_edit');

    $latest   = trim($_POST['latest_version'] ?? '');
    $minimum  = trim($_POST['minimum_version'] ?? '1.0.0');
    $title    = sanitize($_POST['update_title'] ?? 'New Update Available');
    $msg      = sanitize($_POST['update_message'] ?? '');
    $force    = !empty($_POST['force_update']) ? 1 : 0;
    $active   = !empty($_POST['is_active']) ? 1 : 0;
    $editId   = (int) ($_POST['id'] ?? 0);

    try {
        if (!isValidVersion($latest))  throw new Exception('Enter a valid version, for example 1.2.0');
        if (!isValidVersion($minimum)) throw new Exception('Enter a valid minimum version, for example 1.0.0');
        if (versionCompare($minimum, $latest) > 0) {
            throw new Exception('The minimum version cannot be higher than the latest version.');
        }

        $existing = null;
        if ($editId) {
            $st = $pdo->prepare("SELECT * FROM app_updates WHERE id = ?");
            $st->execute([$editId]);
            $existing = $st->fetch();
        }

        $fileName = $existing['apk_file_name'] ?? '';
        $fileSize = (int) ($existing['apk_file_size'] ?? 0);
        $sha256   = $existing['sha256'] ?? null;
        $apkUrl   = $existing['apk_url'] ?? '';

        $hasUpload = isset($_FILES['apk_file']) && $_FILES['apk_file']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($hasUpload) {
            $f = $_FILES['apk_file'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $codes = [
                    UPLOAD_ERR_INI_SIZE   => 'The APK exceeds the server upload limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'The APK exceeds the form upload limit.',
                    UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server has no temporary folder for uploads.',
                    UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
                ];
                throw new Exception($codes[$f['error']] ?? 'Upload failed (code ' . $f['error'] . ').');
            }

            $err = '';
            if (!validateApk($f['tmp_name'], $f['name'], $err)) throw new Exception($err);

            if (!is_dir(APK_DIR) && !mkdir(APK_DIR, 0755, true) && !is_dir(APK_DIR)) {
                throw new Exception('Could not create the APK directory on the server.');
            }

            $newName = apkFileName($latest);
            if (!move_uploaded_file($f['tmp_name'], APK_DIR . $newName)) {
                throw new Exception('Could not save the APK to the server.');
            }

            $oldName  = $fileName;
            $fileName = $newName;
            $fileSize = filesize(APK_DIR . $newName);
            $sha256   = hash_file('sha256', APK_DIR . $newName);
            $apkUrl   = APK_REL . $newName;

            if ($oldName && $oldName !== $newName) deleteApkFile($oldName);
        }

        if (!$fileName) throw new Exception('Upload an APK before publishing a release.');

        if ($editId && $existing) {
            $pdo->prepare("UPDATE app_updates SET latest_version=?, minimum_version=?, apk_url=?,
                    apk_file_name=?, apk_file_size=?, sha256=?, update_title=?, update_message=?,
                    force_update=?, is_active=? WHERE id=?")
                ->execute([$latest, $minimum, $apkUrl, $fileName, $fileSize, $sha256,
                           $title, $msg, $force, $active, $editId]);
            $message = 'Release updated.';
        } else {
            $pdo->prepare("INSERT INTO app_updates (latest_version, minimum_version, apk_url,
                    apk_file_name, apk_file_size, sha256, update_title, update_message,
                    force_update, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$latest, $minimum, $apkUrl, $fileName, $fileSize, $sha256,
                           $title, $msg, $force, $active]);
            $message = 'Release published. Users will be prompted on next launch.';
        }

        // Only one release may be live at a time.
        if ($active) {
            $keep = $editId ?: (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE app_updates SET is_active = 0 WHERE id <> ?")->execute([$keep]);
        }
        $messageType = 'success';
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
        error_log('App update publish: ' . $e->getMessage());
    }
}

// =============================================
// Activate / delete
// =============================================
if (isset($_GET['activate'])) {
    requireModuleAccess('settings', 'can_edit');
    $id = (int) $_GET['activate'];
    $pdo->prepare("UPDATE app_updates SET is_active = 1 WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE app_updates SET is_active = 0 WHERE id <> ?")->execute([$id]);
    $message = 'Release activated.';
    $messageType = 'success';
}

if (isset($_GET['delete'])) {
    requireModuleAccess('settings', 'can_delete');
    $id = (int) $_GET['delete'];
    $st = $pdo->prepare("SELECT apk_file_name FROM app_updates WHERE id = ?");
    $st->execute([$id]);
    if ($row = $st->fetch()) {
        deleteApkFile($row['apk_file_name']);
        $pdo->prepare("DELETE FROM app_updates WHERE id = ?")->execute([$id]);
        $message = 'Release and its APK were deleted.';
        $messageType = 'success';
    }
}

$releases = $pdo->query("SELECT * FROM app_updates ORDER BY is_active DESC, id DESC")->fetchAll();
$active = null;
foreach ($releases as $r) { if ($r['is_active']) { $active = $r; break; } }

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-mobile-alt me-2"></i>App Update</h4>
        <small class="text-muted">Publish a new APK for the Android app, distributed outside the Play Store</small>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#releaseModal" onclick="newRelease()">
        <i class="fas fa-upload me-1"></i>Publish Release
    </button>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show py-2">
    <?php echo sanitize($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($active): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <span class="badge bg-success-subtle text-success border border-success-subtle mb-2">
                    <i class="fas fa-circle-check me-1"></i>Live
                </span>
                <h5 class="fw-bold mb-1">Version <?php echo sanitize($active['latest_version']); ?></h5>
                <div class="text-muted small">
                    Minimum supported <strong><?php echo sanitize($active['minimum_version']); ?></strong>
                    &middot; <?php echo humanSize($active['apk_file_size']); ?>
                    <?php if ($active['force_update']): ?>
                    &middot; <span class="text-danger fw-semibold">Force update on</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-end">
                <a href="<?php echo BACKEND_URL . sanitize($active['apk_url']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                    <i class="fas fa-download me-1"></i>Download APK
                </a>
                <button class="btn btn-sm btn-outline-primary" onclick='editRelease(<?php echo htmlspecialchars(json_encode($active), ENT_QUOTES); ?>)'>
                    <i class="fas fa-pen me-1"></i>Edit
                </button>
            </div>
        </div>
        <?php if (!empty($active['update_message'])): ?>
        <hr class="my-3">
        <div class="small"><strong><?php echo sanitize($active['update_title']); ?></strong><br>
            <span class="text-muted"><?php echo nl2br(sanitize($active['update_message'])); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($active['sha256'])): ?>
        <div class="small text-muted mt-2">
            <i class="fas fa-shield-halved me-1"></i>SHA-256
            <code style="font-size:.72rem;word-break:break-all;"><?php echo sanitize($active['sha256']); ?></code>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body text-center py-5">
        <i class="fas fa-mobile-alt fa-2x text-muted mb-3 d-block"></i>
        <h6 class="fw-bold mb-1">No release published</h6>
        <p class="text-muted small mb-0">Upload an APK to start prompting users to update.</p>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-2"><strong class="small">Release history</strong></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Version</th><th>Minimum</th><th>Size</th><th>Force</th>
                        <th>Status</th><th>Published</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$releases): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No releases yet</td></tr>
                    <?php endif; ?>
                    <?php foreach ($releases as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo sanitize($r['latest_version']); ?></td>
                        <td><?php echo sanitize($r['minimum_version']); ?></td>
                        <td><?php echo humanSize($r['apk_file_size']); ?></td>
                        <td>
                            <?php if ($r['force_update']): ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Forced</span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['is_active']): ?>
                            <span class="badge bg-success">Live</span>
                            <?php else: ?><span class="badge bg-light text-muted border">Inactive</span><?php endif; ?>
                        </td>
                        <td class="small text-muted"><?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?></td>
                        <td class="text-end text-nowrap">
                            <?php if (!$r['is_active']): ?>
                            <a href="?activate=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-success" title="Make live"><i class="fas fa-check"></i></a>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-primary" title="Edit"
                                    onclick='editRelease(<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES); ?>)'><i class="fas fa-pen"></i></button>
                            <a href="?delete=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete release and APK"
                               onclick="return confirm('Delete this release and its APK file?')"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Publish / edit -->
<div class="modal fade" id="releaseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="?action=save" enctype="multipart/form-data">
            <input type="hidden" name="id" id="r_id" value="">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="r_title">Publish Release</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label">Latest Version *</label>
                            <input type="text" name="latest_version" id="r_latest" class="form-control" placeholder="1.2.0" required>
                            <small class="text-muted">Must match the version in the APK.</small>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label">Minimum Supported Version *</label>
                            <input type="text" name="minimum_version" id="r_minimum" class="form-control" value="1.0.0" required>
                            <small class="text-muted">Anyone below this is forced to update.</small>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">APK File</label>
                        <input type="file" name="apk_file" id="r_file" class="form-control" accept=".apk,application/vnd.android.package-archive">
                        <small class="text-muted" id="r_fileHint">Size and SHA-256 are calculated on upload. Replacing the APK deletes the previous file.</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Update Title</label>
                        <input type="text" name="update_title" id="r_updateTitle" class="form-control" value="New Update Available">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Update Message</label>
                        <textarea name="update_message" id="r_message" class="form-control" rows="3">A new version of the app is available. Please update to get the latest features and improvements.</textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="force_update" id="r_force" value="1">
                                <label class="form-check-label" for="r_force">Force update — users cannot dismiss</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="r_active" value="1" checked>
                                <label class="form-check-label" for="r_active">Live — serve this release to the app</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save Release</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function newRelease() {
    document.getElementById('r_title').textContent = 'Publish Release';
    document.getElementById('r_id').value = '';
    document.getElementById('r_latest').value = '';
    document.getElementById('r_minimum').value = '1.0.0';
    document.getElementById('r_updateTitle').value = 'New Update Available';
    document.getElementById('r_message').value = 'A new version of the app is available. Please update to get the latest features and improvements.';
    document.getElementById('r_force').checked = false;
    document.getElementById('r_active').checked = true;
    document.getElementById('r_file').required = true;
    document.getElementById('r_fileHint').textContent = 'Size and SHA-256 are calculated on upload.';
}

function editRelease(r) {
    document.getElementById('r_title').textContent = 'Edit Release ' + (r.latest_version || '');
    document.getElementById('r_id').value = r.id;
    document.getElementById('r_latest').value = r.latest_version || '';
    document.getElementById('r_minimum').value = r.minimum_version || '1.0.0';
    document.getElementById('r_updateTitle').value = r.update_title || 'New Update Available';
    document.getElementById('r_message').value = r.update_message || '';
    document.getElementById('r_force').checked = r.force_update == 1;
    document.getElementById('r_active').checked = r.is_active == 1;
    // Editing metadata alone must not require re-uploading the APK.
    document.getElementById('r_file').required = false;
    document.getElementById('r_fileHint').textContent =
        'Currently ' + (r.apk_file_name || 'no file') + '. Leave empty to keep it.';
    new bootstrap.Modal(document.getElementById('releaseModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
