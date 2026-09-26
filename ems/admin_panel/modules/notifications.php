<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('notifications');
require_once __DIR__ . '/../../backend/helpers/fcm_helper.php';

// Handle Send Push Notification POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_push') {
    $title  = sanitize($_POST['title'] ?? '');
    $message = sanitize($_POST['message'] ?? '');
    $target = sanitize($_POST['target'] ?? 'all');
    $deptId = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
    $empId  = !empty($_POST['employee_id']) ? intval($_POST['employee_id']) : null;

    if ($title && $message) {
        try {
            $fcmResult = false;
            if ($target === 'employee' && $empId) {
                $emp = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
                $emp->execute([$empId]);
                $uid = $emp->fetchColumn();
                if ($uid) {
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'announcement')")->execute([$uid, $title, $message]);
                    $fcmResult = FCMHelper::sendToUsers($pdo, [$uid], $title, $message);
                } else {
                    $fcmResult = ['success' => false, 'message' => 'Selected employee has no user account linked.'];
                }
            } else {
                // All or Department
                $pdo->prepare("INSERT INTO notifications (title, message, type, send_to, department_id) VALUES (?, ?, 'announcement', ?, ?)")->execute([$title, $message, $target === 'department' ? 'department' : 'all', $deptId]);
                $fcmResult = FCMHelper::sendToTopicOrGroup($pdo, $target, $deptId, $title, $message);
            }

            if (is_array($fcmResult) && !empty($fcmResult['success'])) {
                $_SESSION['flash'] = $fcmResult['message'];
            } else {
                $errMsg = is_array($fcmResult) ? $fcmResult['message'] : 'FCM dispatch returned empty or failed.';
                $_SESSION['flash_error'] = 'FCM Warning: ' . $errMsg;
            }
        } catch (Throwable $e) {
            error_log("Push Notification Error: " . $e->getMessage());
            $_SESSION['flash_error'] = 'Push Notification Error: ' . $e->getMessage();
        }
    } else {
        $_SESSION['flash_error'] = 'Title and message are required';
    }
    redirect('notifications.php');
}

// Mark single notification as read via AJAX
if (isset($_GET['read']) && $_GET['read'] === 'ajax' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([(int)$_GET['id']]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Mark all as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    try {
        $pdo->exec("UPDATE notifications SET is_read = 1 WHERE is_read = 0 OR is_read IS NULL");
        $_SESSION['flash'] = 'All notifications marked as read';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Error marking read: ' . $e->getMessage();
    }
    redirect('notifications.php');
}

// Delete single notification
if (isset($_GET['delete'])) {
    try {
        $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([(int)$_GET['delete']]);
        $_SESSION['flash'] = 'Notification deleted';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Error deleting: ' . $e->getMessage();
    }
    redirect('notifications.php');
}

// Delete all read notifications
if (isset($_GET['clear_read'])) {
    try {
        $pdo->exec("DELETE FROM notifications WHERE is_read = 1");
        $_SESSION['flash'] = 'Read notifications cleared';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Error clearing notifications: ' . $e->getMessage();
    }
    redirect('notifications.php');
}

// Fetch departments & employees for Push modal
$depts = [];
$employees = [];
$notifs = [];
$totalPages = 1;

try {
    $depts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
    $employees = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status=1 ORDER BY first_name")->fetchAll();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $total = (int)$pdo->query("SELECT COUNT(*) as c FROM notifications")->fetch()['c'];
    $totalPages = max(1, ceil($total / $limit));

    $notifs = $pdo->query("
        SELECT n.*, CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) as employee_name
        FROM notifications n
        LEFT JOIN employees e ON e.user_id = n.user_id
        ORDER BY n.created_at DESC
        LIMIT $limit OFFSET $offset
    ")->fetchAll();
} catch (Throwable $e) {
    error_log("Notifications list error: " . $e->getMessage());
}

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-bell me-2"></i>Notifications & FCM Push System</h4>
        <small class="text-muted">Manage app announcements and dispatch push notifications to mobile devices</small>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#pushModal">
            <i class="fas fa-paper-plane me-1"></i>Send FCM Push Notification
        </button>
        <form method="POST" style="display:inline">
            <button type="submit" name="action" value="mark_all_read" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-check-double me-1"></i>Mark All Read
            </button>
        </form>
        <a href="?clear_read=1" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete all read notifications?')">
            <i class="fas fa-trash me-1"></i>Clear Read
        </a>
    </div>
</div>

<?php if (isset($_SESSION['flash'])): ?>
<div class="alert alert-success alert-dismissible fade show"><?php echo $_SESSION['flash']; unset($_SESSION['flash']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if (isset($_SESSION['flash_error'])): ?>
<div class="alert alert-danger alert-dismissible fade show"><?php echo $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (count($notifs) > 0): ?>
        <div class="list-group list-group-flush">
            <?php foreach ($notifs as $n):
                $isUnread = !$n['is_read'];
                $icon = 'bell'; $color = 'primary';
                $t = $n['type'] ?? '';
                if ($t === 'leave') { $icon = 'envelope'; $color = 'warning'; }
                elseif ($t === 'attendance') { $icon = 'calendar-check'; $color = 'success'; }
                elseif ($t === 'announcement') { $icon = 'bullhorn'; $color = 'info'; }
            ?>
            <div class="list-group-item list-group-item-action d-flex align-items-start gap-3 px-4 py-3 <?php echo $isUnread ? 'list-group-item-' . $color : 'text-muted'; ?>">
                <div class="mt-1">
                    <span class="badge bg-<?php echo $color; ?> rounded-circle p-2" style="width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center">
                        <i class="fas fa-<?php echo $icon; ?> fa-xs"></i>
                    </span>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-bold <?php echo $isUnread ? '' : 'text-muted'; ?>"><?php echo sanitize($n['title']); ?></div>
                    <div class="small text-muted mb-1"><?php echo sanitize($n['message']); ?></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="far fa-clock me-1"></i><?php echo $n['created_at']; ?>
                            <?php if ($n['employee_name']): ?> &middot; Target: <?php echo sanitize($n['employee_name']); ?><?php endif; ?>
                        </small>
                        <div class="d-flex gap-2">
                            <?php if ($isUnread): ?>
                            <a href="?read=ajax&id=<?php echo $n['id']; ?>" class="btn btn-sm btn-outline-success mark-read-btn" data-id="<?php echo $n['id']; ?>">
                                <i class="fas fa-check"></i>
                            </a>
                            <?php endif; ?>
                            <a href="?delete=<?php echo $n['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete?')">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-bell-slash fa-3x mb-3" style="opacity:0.3"></i>
            <p>No notifications recorded</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination justify-content-center pagination-sm">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $page - 1; ?>">Previous</a>
        </li>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
        </li>
        <?php endfor; ?>
        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $page + 1; ?>">Next</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<!-- Send FCM Push Notification Modal -->
<div class="modal fade" id="pushModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="send_push">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-paper-plane text-primary me-2"></i>Send FCM Push Notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Target Audience</label>
                        <select name="target" id="targetSelect" class="form-select" onchange="toggleTargetFields()">
                            <option value="all">All Employees (Broadcast)</option>
                            <option value="department">Specific Department</option>
                            <option value="employee">Specific Employee</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="deptGroup">
                        <label class="form-label fw-semibold">Select Department</label>
                        <select name="department_id" class="form-select">
                            <?php foreach ($depts as $d): ?>
                            <option value="<?php echo $d['id']; ?>"><?php echo sanitize($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="empGroup">
                        <label class="form-label fw-semibold">Select Employee</label>
                        <select name="employee_id" class="form-select">
                            <?php foreach ($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>"><?php echo sanitize($e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['employee_code'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notification Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Important Announcement / Office Notice" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Message Body</label>
                        <textarea name="message" class="form-control" rows="3" placeholder="Enter message text to display on mobile devices..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Send Push Notification</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleTargetFields() {
    const target = document.getElementById('targetSelect').value;
    document.getElementById('deptGroup').classList.toggle('d-none', target !== 'department');
    document.getElementById('empGroup').classList.toggle('d-none', target !== 'employee');
}

$(document).on('click', '.mark-read-btn', function(e) {
    e.preventDefault();
    var btn = $(this);
    $.get(btn.attr('href'), function() {
        btn.closest('.list-group-item').removeClass('list-group-item-warning list-group-item-success list-group-item-info').addClass('text-muted');
        btn.closest('.d-flex').find('.fw-bold').addClass('text-muted');
        btn.remove();
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
