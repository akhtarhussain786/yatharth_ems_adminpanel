<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
if (!isset($_GET['action']) || $_GET['action'] !== 'ajax') {
    requireModuleAccess('notices');
}

// AJAX: Return notifications JSON for the dropdown
if (isset($_GET['action']) && $_GET['action'] === 'ajax') {
    header('Content-Type: application/json');
    // Delete old junk notifications (test data)
    $pdo->exec("DELETE FROM notifications WHERE title IN ('hello EMP','HY EMP','HAPPY EID ALL EMP','Office Holiday Notice') OR message LIKE 'HELLSAFDDSH%'");
    // Only show relevant system notifications
    $notifs = $pdo->query("
        SELECT n.id, n.title, n.message, n.type, n.created_at,
               COALESCE(n.is_read, 0) as is_read,
               CONCAT(e.first_name, ' ', e.last_name) as employee_name
        FROM notifications n
        LEFT JOIN employees e ON e.user_id = n.user_id
        WHERE n.type IN ('leave','attendance','system','announcement')
        ORDER BY n.created_at DESC LIMIT 20
    ")->fetchAll();

    // Flag what arrived since the admin's previous sign-in. The flag is
    // cleared the moment they open the bell (see the notif_seen action
    // below), so a notification is only ever announced as new once.
    $since = $_SESSION['notif_since'] ?? null;
    $alreadySeen = !empty($_SESSION['notif_seen']);
    $sinceTs = $since ? strtotime($since) : null;

    foreach ($notifs as $i => $n) {
        $isNew = false;
        if (!$alreadySeen && empty($n['is_read'])) {
            $createdTs = strtotime((string) $n['created_at']);
            // No previous sign-in recorded means everything unread is new.
            $isNew = ($sinceTs === null) || ($createdTs !== false && $createdTs > $sinceTs);
        }
        $notifs[$i]['is_new'] = $isNew ? 1 : 0;
    }

    echo json_encode($notifs);
    exit;
}

// AJAX: the admin has opened the bell, so stop announcing this batch as new.
if (isset($_GET['action']) && $_GET['action'] === 'notif_seen') {
    header('Content-Type: application/json');
    $_SESSION['notif_seen'] = true;
    echo json_encode(['success' => true]);
    exit;
}

// AJAX: Mark all notifications as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    header('Content-Type: application/json');
    $pdo->exec("UPDATE notifications SET is_read = 1 WHERE is_read = 0 OR is_read IS NULL");
    echo json_encode(['success' => true]);
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $title = sanitize($_POST['title']);
    $content = sanitize($_POST['content'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $status = $_POST['status'] ?? 1;

    try {
        if ($action == 'add') {
            $stmt = $pdo->prepare("INSERT INTO notices (title, content, priority, status, created_by) VALUES (?,?,?,?,?)");
            $stmt->execute([$title, $content, $priority, $status, $_SESSION['admin_id'] ?? null]);
            // Also insert into notifications for mobile app
            $allUsers = $pdo->query("SELECT id FROM users WHERE status = 1")->fetchAll();
            $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, 'announcement', 0, NOW())");
            foreach ($allUsers as $u) {
                $nStmt->execute([$u['id'], $title, $content]);
            }
            $message = 'Notice added successfully';
        } elseif ($action == 'edit') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE notices SET title=?, content=?, priority=?, status=? WHERE id=?");
            $stmt->execute([$title, $content, $priority, $status, $id]);
            $message = 'Notice updated successfully';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM notices WHERE id = ?")->execute([$id]);
    $message = 'Notice deleted';
}

$notices = $pdo->query("
    SELECT n.*, u.username as created_by_name
    FROM notices n
    LEFT JOIN users u ON u.id = n.created_by
    ORDER BY n.created_at DESC
")->fetchAll();

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-bullhorn me-2"></i>Notice Board</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#noticeModal">
        <i class="fas fa-plus me-1"></i>Add Notice
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
                    <tr><th>Title</th><th>Content</th><th>Priority</th><th>Posted By</th><th>Date</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($notices as $n): ?>
                    <tr>
                        <td><?php echo sanitize($n['title']); ?></td>
                        <td><?php echo sanitize(substr($n['content'] ?? '', 0, 80)) . (strlen($n['content'] ?? '') > 80 ? '...' : ''); ?></td>
                        <td>
                            <span class="badge bg-<?php echo strtolower($n['priority'] ?? '') == 'urgent' ? 'danger' : (strtolower($n['priority'] ?? '') == 'high' ? 'warning text-dark' : 'info'); ?>">
                                <?php echo ucfirst($n['priority']); ?>
                            </span>
                        </td>
                        <td><?php echo sanitize($n['created_by_name'] ?? '-'); ?></td>
                        <td><?php echo sanitize($n['created_at']); ?></td>
                        <td><span class="badge bg-<?php echo $n['status'] ? 'success' : 'secondary'; ?>"><?php echo $n['status'] ? 'Active' : 'Inactive'; ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editNotice(<?php echo htmlspecialchars(json_encode($n)); ?>)"><i class="fas fa-edit"></i></button>
                            <a href="?delete=<?php echo $n['id']; ?>" class="btn btn-sm btn-danger btn-delete"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="noticeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Notice</h5>
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
                        <div class="col-md-12">
                            <label class="form-label">Content</label>
                            <textarea name="content" id="f_content" class="form-control" rows="4"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Priority</label>
                            <select name="priority" id="f_priority" class="form-select">
                                <option value="normal">Normal</option>
                                <option value="high">Important</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="col-md-6">
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

<script>
function editNotice(n) {
    document.getElementById('modalTitle').textContent = 'Edit Notice';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = n.id;
    document.getElementById('f_title').value = n.title;
    document.getElementById('f_content').value = n.content || '';
    document.getElementById('f_priority').value = n.priority;
    document.getElementById('f_status').value = n.status;
    new bootstrap.Modal(document.getElementById('noticeModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
