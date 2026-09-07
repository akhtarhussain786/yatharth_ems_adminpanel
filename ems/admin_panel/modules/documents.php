<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('documents');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $title = sanitize($_POST['title']);
    $category_id = $_POST['category_id'] ? (int)$_POST['category_id'] : null;
    $employee_id = $_POST['employee_id'] ? (int)$_POST['employee_id'] : null;
    $expiry_date = $_POST['expiry_date'] ?: null;
    $is_public = $_POST['is_public'] ?? 0;

    try {
        if ($action == 'add') {
            $stmt = $pdo->prepare("INSERT INTO documents (title, category_id, employee_id, expiry_date, is_public, file_path, file_type) VALUES (?,?,?,?,?,'','')");
            $stmt->execute([$title, $category_id, $employee_id, $expiry_date, $is_public]);
            $message = 'Document added successfully';
        } elseif ($action == 'edit') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE documents SET title=?, category_id=?, employee_id=?, expiry_date=?, is_public=? WHERE id=?");
            $stmt->execute([$title, $category_id, $employee_id, $expiry_date, $is_public, $id]);
            $message = 'Document updated successfully';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);
    $message = 'Document deleted';
}

$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$employee_filter = isset($_GET['employee']) ? (int)$_GET['employee'] : 0;
$view = ($_GET['view'] ?? 'table') === 'employee' ? 'employee' : 'table';

$sql = "SELECT d.*, dc.name as category_name, e.first_name, e.last_name
        FROM documents d
        LEFT JOIN document_categories dc ON dc.id = d.category_id
        LEFT JOIN employees e ON e.id = d.employee_id";
$where = [];
$params = [];
if ($category_filter > 0) { $where[] = 'd.category_id = ?'; $params[] = $category_filter; }
if ($employee_filter > 0) { $where[] = 'd.employee_id = ?'; $params[] = $employee_filter; }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY e.first_name ASC, d.uploaded_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

/** Keeps the current filters on delete links and view switches. */
function docQuery(array $extra = []) {
    $base = array_filter([
        'category' => $_GET['category'] ?? null,
        'employee' => $_GET['employee'] ?? null,
        'view'     => $_GET['view'] ?? null,
    ], fn($v) => $v !== null && $v !== '' && $v !== '0');
    return http_build_query(array_merge($base, $extra));
}

$employees = $pdo->query("SELECT id, first_name, last_name FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();
$categories = $pdo->query("SELECT * FROM document_categories WHERE status = 1 ORDER BY name")->fetchAll();

// Group documents by employee
$grouped = [];
foreach ($documents as $doc) {
    $key = $doc['employee_id'] ? $doc['employee_id'] : 'unassigned';
    $grouped[$key][] = $doc;
}

function docFileUrl($filePath) {
    if (!$filePath) return '';
    return '/ems/backend/' . ltrim($filePath, '/');
}

/** Icon and colour for a file extension, so non-images still read at a glance. */
function docFileIcon($ext) {
    $map = [
        'pdf'  => ['fa-file-pdf', '#dc3545'],
        'doc'  => ['fa-file-word', '#2b579a'],  'docx' => ['fa-file-word', '#2b579a'],
        'xls'  => ['fa-file-excel', '#217346'], 'xlsx' => ['fa-file-excel', '#217346'],
        'csv'  => ['fa-file-csv', '#217346'],
        'ppt'  => ['fa-file-powerpoint', '#d24726'], 'pptx' => ['fa-file-powerpoint', '#d24726'],
        'zip'  => ['fa-file-zipper', '#6c757d'], 'rar' => ['fa-file-zipper', '#6c757d'],
        'txt'  => ['fa-file-lines', '#6c757d'],
    ];
    return $map[$ext] ?? ['fa-file', '#6c757d'];
}

/**
 * Preview tile. Images show the file; anything else — and any image that fails
 * to load — falls back to a typed icon rather than the blank box the old
 * onerror-hide left behind.
 */
function docThumbnail($doc, $size = 44) {
    $url = docFileUrl($doc['file_path']);
    $ext = strtolower(pathinfo($doc['file_path'] ?? '', PATHINFO_EXTENSION));
    [$icon, $colour] = docFileIcon($ext);

    $box = "width:{$size}px;height:{$size}px;border-radius:8px;";

    if (!$url) {
        return '<div class="d-flex align-items-center justify-content-center bg-light border text-muted" style="' . $box . '"><i class="fas fa-ban"></i></div>';
    }

    $fallback = '<div class="d-none align-items-center justify-content-center bg-light border" style="' . $box . '"><i class="fas ' . $icon . '" style="color:' . $colour . '"></i></div>';

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return '<a href="' . $url . '" target="_blank" class="d-inline-block">'
             . '<img src="' . $url . '" alt="" style="' . $box . 'object-fit:cover;border:1px solid #dee2e6;"'
             . ' onerror="this.style.display=\'none\';this.nextElementSibling.classList.replace(\'d-none\',\'d-flex\')">'
             . $fallback . '</a>';
    }

    return '<a href="' . $url . '" target="_blank" class="d-flex align-items-center justify-content-center bg-light border text-decoration-none" style="' . $box . '">'
         . '<i class="fas ' . $icon . '" style="color:' . $colour . '"></i></a>';
}

/** Human-readable size, or an em dash when it was never recorded. */
function docFileSize($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '—';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/** Expiry rendered as a state, which is the only reason to have the column. */
function docExpiry($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '<span class="text-muted">—</span>';
    }
    $days = (int) floor((strtotime($date) - strtotime('today')) / 86400);
    $shown = date('d M Y', strtotime($date));

    if ($days < 0)  return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">Expired · ' . $shown . '</span>';
    if ($days <= 30) return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">' . $days . 'd left · ' . $shown . '</span>';
    return '<span class="text-body">' . $shown . '</span>';
}

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-file-alt me-2"></i>Document Management</h4>
        <small class="text-muted"><?php echo count($documents); ?> document<?php echo count($documents) === 1 ? '' : 's'; ?><?php echo ($category_filter || $employee_filter) ? ' matching your filters' : ' on file'; ?></small>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#docModal">
        <i class="fas fa-plus me-1"></i>Add Document
    </button>
</div>

<?php if ($message): ?>
<div class="alert alert-info alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php
// Headline figures, counted from the rows already loaded.
$statPeople = count(array_unique(array_filter(array_column($documents, 'employee_id'))));
$statExpiring = 0; $statExpired = 0; $statUnassigned = 0;
foreach ($documents as $d) {
    if (empty($d['employee_id'])) $statUnassigned++;
    if (!empty($d['expiry_date']) && $d['expiry_date'] !== '0000-00-00') {
        $days = (int) floor((strtotime($d['expiry_date']) - strtotime('today')) / 86400);
        if ($days < 0) $statExpired++;
        elseif ($days <= 30) $statExpiring++;
    }
}
$stats = [
    ['Documents',  count($documents), 'fa-file-lines',          'text-primary'],
    ['Employees',  $statPeople,       'fa-users',               'text-info'],
    ['Expiring',   $statExpiring,     'fa-hourglass-half',      'text-warning'],
    ['Expired',    $statExpired,      'fa-triangle-exclamation','text-danger'],
    ['Unassigned', $statUnassigned,   'fa-link-slash',          'text-secondary'],
];
?>
<div class="row g-2 mb-3">
    <?php foreach ($stats as [$label, $value, $icon, $tone]): ?>
    <div class="col-6 col-md">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body py-2 px-3 d-flex align-items-center">
                <i class="fas <?php echo $icon; ?> <?php echo $tone; ?> me-2"></i>
                <div>
                    <div class="fw-bold lh-1"><?php echo $value; ?></div>
                    <small class="text-muted"><?php echo $label; ?></small>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card mb-3 border-0 shadow-sm">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="view" value="<?php echo $view; ?>">
            <div class="col-md-3">
                <label class="form-label mb-1 small text-muted">Category</label>
                <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>><?php echo sanitize($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 small text-muted">Employee</label>
                <select name="employee" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">All employees</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>" <?php echo $employee_filter == $emp['id'] ? 'selected' : ''; ?>><?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <?php if ($category_filter || $employee_filter): ?>
                <a href="documents.php?view=<?php echo $view; ?>" class="btn btn-sm btn-outline-secondary">Clear filters</a>
                <?php endif; ?>
            </div>
            <div class="col-md-3 text-md-end">
                <div class="btn-group btn-group-sm" role="group">
                    <a href="?<?php echo docQuery(['view' => 'table']); ?>" class="btn btn-outline-secondary <?php echo $view === 'table' ? 'active' : ''; ?>"><i class="fas fa-list me-1"></i>Table</a>
                    <a href="?<?php echo docQuery(['view' => 'employee']); ?>" class="btn btn-outline-secondary <?php echo $view === 'employee' ? 'active' : ''; ?>"><i class="fas fa-users me-1"></i>By employee</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!$documents): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="fas fa-folder-open fa-2x text-muted mb-3 d-block"></i>
        <h6 class="fw-bold mb-1">No documents found</h6>
        <p class="text-muted small mb-3"><?php echo ($category_filter || $employee_filter) ? 'No document matches the current filters.' : 'Add the first document to get started.'; ?></p>
        <?php if ($category_filter || $employee_filter): ?>
        <a href="documents.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($view === 'table'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle datatable mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;"></th>
                        <th>Document</th>
                        <th>Category</th>
                        <th>Employee</th>
                        <th>Expiry</th>
                        <th>Visibility</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                    <?php $ext = strtolower(pathinfo($doc['file_path'] ?? '', PATHINFO_EXTENSION)); ?>
                    <tr>
                        <td><?php echo docThumbnail($doc); ?></td>
                        <td>
                            <div class="fw-semibold text-truncate" style="max-width:260px;" title="<?php echo sanitize($doc['title']); ?>"><?php echo sanitize($doc['title']); ?></div>
                            <small class="text-muted">
                                <?php echo $ext ? strtoupper($ext) : 'FILE'; ?> · <?php echo docFileSize($doc['file_size']); ?>
                                <?php if (!empty($doc['uploaded_at'])): ?> · <?php echo date('d M Y', strtotime($doc['uploaded_at'])); ?><?php endif; ?>
                            </small>
                        </td>
                        <td><?php echo $doc['category_name'] ? '<span class="badge bg-light text-dark border">' . sanitize($doc['category_name']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <?php if (trim($doc['first_name'] . $doc['last_name']) !== ''): ?>
                                <?php echo sanitize(trim($doc['first_name'] . ' ' . $doc['last_name'])); ?>
                            <?php else: ?>
                                <span class="text-muted fst-italic">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo docExpiry($doc['expiry_date']); ?></td>
                        <td>
                            <?php if ($doc['is_public']): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-globe me-1"></i>Public</span>
                            <?php else: ?>
                            <span class="badge bg-light text-muted border"><i class="fas fa-lock me-1"></i>Private</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (docFileUrl($doc['file_path'])): ?>
                            <a href="<?php echo docFileUrl($doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Open"><i class="fas fa-arrow-up-right-from-square"></i></a>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-primary" title="Edit" onclick="editDoc(<?php echo htmlspecialchars(json_encode($doc)); ?>)"><i class="fas fa-pen"></i></button>
                            <a href="?<?php echo docQuery(['delete' => $doc['id']]); ?>" class="btn btn-sm btn-outline-danger btn-delete" title="Delete"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<?php foreach ($grouped as $empId => $empDocs): ?>
<?php $empName = trim(($empDocs[0]['first_name'] ?? '') . ' ' . ($empDocs[0]['last_name'] ?? '')); ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <div class="d-flex align-items-center">
            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light border text-secondary fw-bold me-2" style="width:32px;height:32px;">
                <?php echo $empName !== '' ? strtoupper(substr($empName, 0, 1)) : '?'; ?>
            </span>
            <span class="fw-semibold"><?php echo $empName !== '' ? sanitize($empName) : 'Unassigned'; ?></span>
        </div>
        <span class="badge bg-light text-muted border"><?php echo count($empDocs); ?> document<?php echo count($empDocs) === 1 ? '' : 's'; ?></span>
    </div>
    <div class="card-body pt-3">
        <div class="row g-2">
            <?php foreach ($empDocs as $doc): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <div class="border rounded p-2 h-100 d-flex align-items-start">
                    <?php echo docThumbnail($doc, 38); ?>
                    <div class="ms-2 flex-grow-1 min-w-0">
                        <div class="small fw-semibold text-truncate" title="<?php echo sanitize($doc['title']); ?>"><?php echo sanitize($doc['title']); ?></div>
                        <div class="text-muted" style="font-size:.75rem;"><?php echo sanitize($doc['category_name'] ?? '—'); ?></div>
                        <div class="mt-1">
                            <?php if (docFileUrl($doc['file_path'])): ?>
                            <a href="<?php echo docFileUrl($doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-link p-0 me-2" title="Open"><i class="fas fa-arrow-up-right-from-square"></i></a>
                            <?php endif; ?>
                            <a href="?<?php echo docQuery(['delete' => $doc['id']]); ?>" class="btn btn-sm btn-link text-danger p-0 btn-delete" title="Delete"><i class="fas fa-trash"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="docModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Title *</label>
                            <input type="text" name="title" id="f_title" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select name="category_id" id="f_category" class="form-select">
                                <option value="">Select</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo sanitize($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" id="f_employee" class="form-select">
                                <option value="">Select</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" name="expiry_date" id="f_expiry" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Public</label>
                            <select name="is_public" id="f_public" class="form-select">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editDoc(doc) {
    document.getElementById('modalTitle').textContent = 'Edit Document';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = doc.id;
    document.getElementById('f_title').value = doc.title;
    document.getElementById('f_category').value = doc.category_id || '';
    document.getElementById('f_employee').value = doc.employee_id || '';
    document.getElementById('f_expiry').value = doc.expiry_date || '';
    document.getElementById('f_public').value = doc.is_public;
    new bootstrap.Modal(document.getElementById('docModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
