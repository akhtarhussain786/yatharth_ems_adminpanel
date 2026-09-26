<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect(BASE_URL . 'index');
requireModuleAccess('leave_requests');

// Get Departments & Employees for filters
$depts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
$employees = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status=1 ORDER BY first_name")->fetchAll();

require_once '../includes/header.php';
?>
<!-- FullCalendar CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-calendar-alt text-primary me-2"></i>Employee Leave Calendar</h4>
        <small class="text-muted">Visual monthly/weekly overview of approved and pending employee leaves</small>
    </div>
    <a href="leave_requests.php" class="btn btn-outline-primary"><i class="fas fa-list me-1"></i>Leave Requests List</a>
</div>

<!-- Filters -->
<div class="card mb-3 border-0 shadow-sm">
    <div class="card-body py-2">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Filter by Department</label>
                <select id="deptFilter" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                    <option value="<?php echo $d['id']; ?>"><?php echo sanitize($d['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Filter by Employee</label>
                <select id="empFilter" class="form-select form-select-sm">
                    <option value="">All Employees</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?php echo $e['id']; ?>"><?php echo sanitize($e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['employee_code'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="button" id="applyFilterBtn" class="btn btn-sm btn-primary w-100"><i class="fas fa-filter me-1"></i>Apply Filters</button>
                <button type="button" id="resetFilterBtn" class="btn btn-sm btn-outline-secondary w-100"><i class="fas fa-undo me-1"></i>Reset</button>
            </div>
        </form>
    </div>
</div>

<!-- Calendar Card -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-3">
        <div id="leaveCalendar" style="min-height: 600px;"></div>
    </div>
</div>

<!-- Event Details Modal -->
<div class="modal fade" id="eventModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-calendar-day text-primary me-2"></i>Leave Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="eventModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('leaveCalendar');
    
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek'
        },
        buttonText: {
            today: 'Today',
            month: 'Monthly View',
            week: 'Weekly View'
        },
        editable: false,
        selectable: true,
        events: function(info, successCallback, failureCallback) {
            const dept = document.getElementById('deptFilter').value;
            const emp = document.getElementById('empFilter').value;
            const monthStr = info.startStr.substring(0, 7);

            let url = `../../backend/index.php?url=leaves/calendar&department_id=${dept}&employee_id=${emp}&month=${monthStr}`;

            fetch(url, {
                headers: {
                    'Authorization': 'Bearer <?php echo $_SESSION['auth_token'] ?? ''; ?>'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data) {
                    successCallback(data.data);
                } else {
                    successCallback([]);
                }
            })
            .catch(err => {
                console.error("Calendar fetch error:", err);
                failureCallback(err);
            });
        },
        eventClick: function(info) {
            const props = info.event.extendedProps;
            const badgeClass = props.status === 'Approved' ? 'bg-success' : 'bg-warning text-dark';
            
            const html = `
                <dl class="row mb-0">
                    <dt class="col-sm-4">Employee</dt>
                    <dd class="col-sm-8">${props.employee_name || info.event.title}</dd>
                    <dt class="col-sm-4">Department</dt>
                    <dd class="col-sm-8">${props.department || 'N/A'}</dd>
                    <dt class="col-sm-4">Leave Type</dt>
                    <dd class="col-sm-8"><span class="badge bg-secondary">${props.leave_type}</span></dd>
                    <dt class="col-sm-4">Start Date</dt>
                    <dd class="col-sm-8">${info.event.startStr}</dd>
                    <dt class="col-sm-4">End Date</dt>
                    <dd class="col-sm-8">${info.event.endStr || info.event.startStr}</dd>
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8"><span class="badge ${badgeClass}">${props.status}</span></dd>
                    <dt class="col-sm-4">Reason</dt>
                    <dd class="col-sm-8">${props.reason || 'N/A'}</dd>
                </dl>
            `;
            document.getElementById('eventModalBody').innerHTML = html;
            new bootstrap.Modal(document.getElementById('eventModal')).show();
        }
    });

    calendar.render();

    document.getElementById('applyFilterBtn').addEventListener('click', function() {
        calendar.refetchEvents();
    });

    document.getElementById('resetFilterBtn').addEventListener('click', function() {
        document.getElementById('deptFilter').value = '';
        document.getElementById('empFilter').value = '';
        calendar.refetchEvents();
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
