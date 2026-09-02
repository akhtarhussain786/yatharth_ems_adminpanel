<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $title = sanitize($_POST['title']);
    $category_id = $_POST['category_id'] ? (int)$_POST['category_id'] : null;
    $description = sanitize($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 1;

    try {
        if ($action == 'add_category') {
            $name = sanitize($_POST['name']);
            $stmt = $pdo->prepare("INSERT INTO download_categories (name) VALUES (?)");
            $stmt->execute([$name]);
            $message = 'Category added successfully';
        } elseif ($action == 'add_download' || $action == 'edit_download') {
            if ($action == 'add_download') {
                $stmt = $pdo->prepare("INSERT INTO downloads (title, category_id, description, file_path, file_type, status) VALUES (?,?,?,'','',?)");
                $stmt->execute([$title, $category_id, $description, $status]);
                $message = 'Download added successfully';
            } else {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE downloads SET title=?, category_id=?, description=?, status=? WHERE id=?");
                $stmt->execute([$title, $category_id, $description, $status, $id]);
                $message = 'Download updated successfully';
            }
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM downloads WHERE id = ?")->execute([$id]);
    $message = 'Download deleted';
}

if (isset($_GET['delete_cat'])) {
    $id = (int)$_GET['delete_cat'];
    $pdo->prepare("DELETE FROM download_categories WHERE id = ?")->execute([$id]);
    $message = 'Category deleted';
}

$downloads = $pdo->query("
    SELECT d.*, dc.name as category_name
    FROM downloads d
    LEFT JOIN download_categories dc ON dc.id = d.category_id
    ORDER BY d.created_at DESC
")->fetchAll();

$categories = $pdo->query("SELECT * FROM download_categories ORDER BY name")->fetchAll();

require_once '../includes/header.php';
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#downloadsTab">Downloads</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#categoriesTab">Categories</a></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="downloadsTab">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold"><i class="fas fa-download me-2"></i>Download Files</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#downloadModal">
                <i class="fas fa-plus me-1"></i>Add Download
            </button>
        </div>
        <?php if ($message): ?>
        <div class="alert alert-info alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover datatable mb-0">
                        <thead>
                            <tr><th>Title</th><th>Category</th><th>File Type</th><th>Downloads</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($downloads as $d): ?>
                            <tr>
                                <td><?php echo sanitize($d['title']); ?></td>
                                <td><?php echo sanitize($d['category_name'] ?? '-'); ?></td>
                                <td><?php echo sanitize($d['file_type'] ?: '-'); ?></td>
                                <td><?php echo $d['download_count']; ?></td>
                                <td><span class="badge bg-<?php echo $d['status'] ? 'success' : 'secondary'; ?>"><?php echo $d['status'] ? 'Active' : 'Inactive'; ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editDownload(<?php echo htmlspecialchars(json_encode($d)); ?>)"><i class="fas fa-edit"></i></button>
                                    <a href="?delete=<?php echo $d['id']; ?>" class="btn btn-sm btn-danger btn-delete"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="categoriesTab">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold"><i class="fas fa-folder me-2"></i>Categories</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#catModal">
                <i class="fas fa-plus me-1"></i>Add Category
            </button>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr><th>Name</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $c): ?>
                            <tr>
                                <td><?php echo sanitize($c['name']); ?></td>
                                <td><a href="?delete_cat=<?php echo $c['id']; ?>" class="btn btn-sm btn-danger btn-delete"><i class="fas fa-trash"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Download Modal -->
<div class="modal fade" id="downloadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Download</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add_download">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Title *</label>
                            <input type="text" name="title" id="f_title" class="form-control" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Category</label>
                            <select name="category_id" id="f_category" class="form-select">
                                <option value="">Select</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="f_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Status</label>
                            <select name="status" id="f_status" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
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

<!-- Add Category Modal -->
<div class="modal fade" id="catModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_category">
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" name="name" class="form-control" required>
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
function editDownload(d) {
    document.getElementById('modalTitle').textContent = 'Edit Download';
    document.getElementById('formAction').value = 'edit_download';
    document.getElementById('formId').value = d.id;
    document.getElementById('f_title').value = d.title;
    document.getElementById('f_category').value = d.category_id || '';
    document.getElementById('f_description').value = d.description || '';
    document.getElementById('f_status').value = d.status;
    new bootstrap.Modal(document.getElementById('downloadModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
