<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $title = sanitize($_POST['title']);
    $description = sanitize($_POST['description'] ?? '');
    $meeting_date = $_POST['meeting_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'] ?: null;
    $venue = sanitize($_POST['venue'] ?? '');
    $meeting_link = sanitize($_POST['meeting_link'] ?? '');

    try {
        if ($action == 'add') {
            $stmt = $pdo->prepare("INSERT INTO meetings (title, description, meeting_date, start_time, end_time, venue, meeting_link, created_by) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$title, $description, $meeting_date, $start_time, $end_time, $venue, $meeting_link, $_SESSION['admin_id'] ?? null]);
            $message = 'Meeting added successfully';
        } elseif ($action == 'edit') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE meetings SET title=?, description=?, meeting_date=?, start_time=?, end_time=?, venue=?, meeting_link=? WHERE id=?");
            $stmt->execute([$title, $description, $meeting_date, $start_time, $end_time, $venue, $meeting_link, $id]);
            $message = 'Meeting updated successfully';
        } elseif ($action == 'update_status') {
            $id = (int)$_POST['id'];
            $status = $_POST['status'];
            $stmt = $pdo->prepare("UPDATE meetings SET status=? WHERE id=?")->execute([$status, $id]);
            $message = 'Meeting status updated';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM meetings WHERE id = ?")->execute([$id]);
    $message = 'Meeting deleted';
}

$meetings = $pdo->query("
    SELECT m.*, u.username as created_by_name
    FROM meetings m
    LEFT JOIN users u ON u.id = m.created_by
    ORDER BY m.meeting_date DESC, m.start_time ASC
")->fetchAll();

require_once '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-calendar-alt me-2"></i>Meeting Management</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#meetingModal">
        <i class="fas fa-plus me-1"></i>Add Meeting
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
                    <tr><th>Title</th><th>Date</th><th>Time</th><th>Venue</th><th>Status</th><th>Created By</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($meetings as $m): ?>
                    <tr>
                        <td><?php echo sanitize($m['title']); ?></td>
                        <td><?php echo sanitize($m['meeting_date']); ?></td>
                        <td><?php echo sanitize($m['start_time']) . ($m['end_time'] ? ' - ' . sanitize($m['end_time']) : ''); ?></td>
                        <td><?php echo sanitize($m['venue'] ?: '-'); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $m['status'] == 'scheduled' ? 'primary' : ($m['status'] == 'ongoing' ? 'warning text-dark' : ($m['status'] == 'completed' ? 'success' : 'secondary')); ?>">
                                <?php echo ucfirst($m['status']); ?>
                            </span>
                        </td>
                        <td><?php echo sanitize($m['created_by_name'] ?? '-'); ?></td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editMeeting(<?php echo htmlspecialchars(json_encode($m)); ?>)"><i class="fas fa-edit"></i></button>
                            <?php if ($m['status'] == 'scheduled'): ?>
                            <button class="btn btn-sm btn-warning" onclick="updateStatus(<?php echo $m['id']; ?>, 'ongoing')"><i class="fas fa-play"></i></button>
                            <?php endif; ?>
                            <?php if ($m['status'] != 'completed' && $m['status'] != 'cancelled'): ?>
                            <button class="btn btn-sm btn-success" onclick="updateStatus(<?php echo $m['id']; ?>, 'completed')"><i class="fas fa-check"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="updateStatus(<?php echo $m['id']; ?>, 'cancelled')"><i class="fas fa-ban"></i></button>
                            <?php endif; ?>
                            <a href="?delete=<?php echo $m['id']; ?>" class="btn btn-sm btn-secondary btn-delete"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="meetingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Meeting</h5>
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
                            <label class="form-label">Description</label>
                            <textarea name="description" id="f_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date *</label>
                            <input type="date" name="meeting_date" id="f_date" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Start Time *</label>
                            <input type="time" name="start_time" id="f_start" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End Time</label>
                            <input type="time" name="end_time" id="f_end" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Venue</label>
                            <input type="text" name="venue" id="f_venue" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Meeting Link</label>
                            <input type="url" name="meeting_link" id="f_link" class="form-control">
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
function editMeeting(m) {
    document.getElementById('modalTitle').textContent = 'Edit Meeting';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = m.id;
    document.getElementById('f_title').value = m.title;
    document.getElementById('f_description').value = m.description || '';
    document.getElementById('f_date').value = m.meeting_date;
    document.getElementById('f_start').value = m.start_time;
    document.getElementById('f_end').value = m.end_time || '';
    document.getElementById('f_venue').value = m.venue || '';
    document.getElementById('f_link').value = m.meeting_link || '';
    new bootstrap.Modal(document.getElementById('meetingModal')).show();
}

function updateStatus(id, status) {
    if (confirm('Are you sure you want to mark this meeting as ' + status + '?')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="' + id + '"><input type="hidden" name="status" value="' + status + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
