<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('leads');

// Get creator statistics for dashboard
function getLeadCreatorStats($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT 
                e.id as employee_id,
                e.first_name,
                e.last_name,
                e.employee_code,
                d.name as department_name,
                COUNT(l.id) as total_leads,
                SUM(CASE WHEN l.status = 'new' THEN 1 ELSE 0 END) as new_leads,
                SUM(CASE WHEN l.status = 'calling' THEN 1 ELSE 0 END) as calling_leads,
                SUM(CASE WHEN l.status = 'connected' THEN 1 ELSE 0 END) as connected_leads,
                SUM(CASE WHEN l.status = 'interested' THEN 1 ELSE 0 END) as interested_leads,
                SUM(CASE WHEN l.status = 'qualified' THEN 1 ELSE 0 END) as qualified_leads,
                SUM(CASE WHEN l.status = 'won' THEN 1 ELSE 0 END) as won_leads,
                SUM(CASE WHEN l.status = 'lost' THEN 1 ELSE 0 END) as lost_leads,
                SUM(CASE WHEN DATE(l.created_at) = CURDATE() THEN 1 ELSE 0 END) as today_leads,
                SUM(CASE WHEN WEEK(l.created_at) = WEEK(CURDATE()) AND YEAR(l.created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as this_week_leads,
                SUM(CASE WHEN MONTH(l.created_at) = MONTH(CURDATE()) AND YEAR(l.created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as this_month_leads
            FROM employees e
            -- departments was selected as d.name but never joined, so the whole
            -- query failed with an unknown-column error. The catch returned an
            -- empty array, so the Lead Creator panel just showed nothing, with
            -- no sign that anything had gone wrong.
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN leads l ON (l.employee_id = e.id OR l.created_by = e.id OR l.created_by = e.user_id)
            WHERE e.status = 1
            GROUP BY e.id
            ORDER BY total_leads DESC
            LIMIT 20
        ");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('getLeadCreatorStats: ' . $e->getMessage());
        return [];
    }
}

// Get overall statistics
function getOverallLeadStats($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_leads,
                SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_leads,
                SUM(CASE WHEN status = 'calling' THEN 1 ELSE 0 END) as calling_leads,
                SUM(CASE WHEN status = 'connected' THEN 1 ELSE 0 END) as connected_leads,
                SUM(CASE WHEN status = 'interested' THEN 1 ELSE 0 END) as interested_leads,
                SUM(CASE WHEN status = 'qualified' THEN 1 ELSE 0 END) as qualified_leads,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won_leads,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_leads,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_leads,
                SUM(CASE WHEN WEEK(created_at) = WEEK(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as this_week_leads,
                SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as this_month_leads,
                COUNT(DISTINCT employee_id) as total_creators
            FROM leads
        ");
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log('getOverallLeadStats: ' . $e->getMessage());
        return [];
    }
}

/** Bootstrap colour for a pipeline stage. */
function leadStatusColour($status) {
    $map = [
        'won' => 'success', 'qualified' => 'warning', 'interested' => 'primary',
        'new' => 'info', 'follow_up' => 'dark', 'calling' => 'secondary',
        'connected' => 'secondary', 'meeting_scheduled' => 'primary',
        'demo_scheduled' => 'primary', 'quotation_sent' => 'primary',
        'negotiation' => 'warning',
        'lost' => 'danger', 'not_interested' => 'danger',
        'wrong_number' => 'danger', 'duplicate' => 'danger', 'closed' => 'dark',
    ];
    return $map[$status] ?? 'secondary';
}

/** Priority reads as a ranked chip rather than a bare word. */
function leadPriorityBadge($priority) {
    $p = strtolower(trim((string) $priority));
    if ($p === '') return '';
    $tone = ['urgent' => 'danger', 'high' => 'warning', 'medium' => 'secondary', 'low' => 'light'];
    $cls = $tone[$p] ?? 'secondary';
    $text = $cls === 'light' ? 'text-muted border' : '';
    return '<span class="badge bg-' . $cls . '-subtle text-' . ($cls === 'light' ? 'muted' : $cls) . ' border border-' . $cls . '-subtle ' . $text . '">' . ucfirst($p) . '</span>';
}

$creatorStats = getLeadCreatorStats($pdo);
$overallStats = getOverallLeadStats($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'create') {
    requireModuleAccess('leads', 'can_create');
    $name = sanitize($_POST['customer_name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $company = sanitize($_POST['company_name'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $source = sanitize($_POST['source'] ?? '');
    $campaign = sanitize($_POST['campaign_name'] ?? '');
    $requirement = sanitize($_POST['requirement'] ?? '');
    $budget = $_POST['budget'] ?? null;
    $priority = sanitize($_POST['priority'] ?? 'medium');
    $notes = sanitize($_POST['notes'] ?? '');

    if (!$name || !$phone) {
        $_SESSION['flash'] = 'Customer name and phone are required';
        redirect('leads.php');
    }

    $st = $pdo->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
    $st->execute([$_SESSION['admin_id']]);
    $creatorEmpId = $st->fetchColumn() ?: null;
    $adminUserId = $_SESSION['admin_id'] ?? null;

    $assignedTo = adminAutoAssignTelecaller($pdo);

    try {
        $pdo->prepare("INSERT INTO leads
                (customer_name, first_name, last_name, mobile, customer_phone, email, customer_email,
                 company_name, city, source, lead_source, campaign_name, requirement, budget,
                 priority, notes, employee_id, created_by, assigned_to, assigned_by, status)
            VALUES (?,?,'',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'new')")
            ->execute([
                $name, $name, $phone, $phone, $email, $email,
                $company, $city, $source, $source, $campaign, $requirement, $budget,
                $priority, $notes, $creatorEmpId, $creatorEmpId ?: $adminUserId, $assignedTo,
                $assignedTo ? $_SESSION['admin_id'] : null,
            ]);

        $leadId = $pdo->lastInsertId();

        if ($assignedTo) {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link)
                    VALUES ((SELECT user_id FROM employees WHERE id = ?), 'New Lead Assigned',
                            CONCAT('Lead: ', ?, ' assigned to you'), 'lead', ?)")
                ->execute([$assignedTo, $name, '/leads/' . $leadId]);
        }

        $_SESSION['flash'] = 'Lead created successfully';
    } catch (Exception $e) {
        error_log('Admin lead create: ' . $e->getMessage());
        $_SESSION['flash'] = 'Could not create lead. Please try again.';
    }
    redirect('leads.php');
}

/** Least loaded active telecaller */
function adminAutoAssignTelecaller($pdo) {
    try {
        $stmt = $pdo->query("SELECT e.id FROM employees e
            JOIN users u ON u.id = e.user_id
            JOIN roles r ON r.id = u.role_id
            WHERE r.name IN ('telecaller_admin','telecaller') AND e.status = 1
            ORDER BY (SELECT COUNT(*) FROM leads WHERE assigned_to = e.id
                      AND status NOT IN ('won','lost','closed','duplicate')) ASC
            LIMIT 1");
        $tc = $stmt->fetch();
        return $tc ? $tc['id'] : null;
    } catch (Exception $e) {
        error_log('adminAutoAssignTelecaller: ' . $e->getMessage());
        return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'update') {
    requireModuleAccess('leads', 'can_edit');
    $id = intval($_POST['id'] ?? 0);
    $name = sanitize($_POST['customer_name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $company = sanitize($_POST['company_name'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $source = sanitize($_POST['source'] ?? '');
    $campaign = sanitize($_POST['campaign_name'] ?? '');
    $requirement = sanitize($_POST['requirement'] ?? '');
    $budget = $_POST['budget'] ?? null;
    $priority = sanitize($_POST['priority'] ?? 'medium');
    $status = sanitize($_POST['status'] ?? 'new');
    $notes = sanitize($_POST['notes'] ?? '');
    $followUp = $_POST['follow_up_date'] ?? '';
    $assignedTo = $_POST['assigned_to'] ?? '';
    $pdo->prepare("UPDATE leads SET customer_name=?, mobile=?, customer_phone=?, email=?, customer_email=?, company_name=?, city=?, source=?, lead_source=?, campaign_name=?, requirement=?, budget=?, priority=?, status=?, notes=?, follow_up_date=?, assigned_to=?, assigned_by=? WHERE id=?")->execute([$name, $phone, $phone, $email, $email, $company, $city, $source, $source, $campaign, $requirement, $budget, $priority, $status, $notes, $followUp ?: null, $assignedTo ?: null, $assignedTo ? $_SESSION['admin_id'] : null, $id]);
    $_SESSION['flash'] = 'Lead updated';
    redirect('leads.php');
}

if (isset($_GET['delete'])) {
    requireModuleAccess('leads', 'can_delete');
    $pdo->prepare("DELETE FROM leads WHERE id=?")->execute([intval($_GET['delete'])]);
    $_SESSION['flash'] = 'Lead deleted';
    redirect('leads.php');
}

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$creatorFilter = $_GET['creator_id'] ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$sourceFilter = $_GET['source'] ?? '';
$campaignFilter = $_GET['campaign'] ?? '';
$assigneeFilter = $_GET['assigned_to'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Modified SQL to include creator details (supports both employee and admin user accounts)
$sql = "SELECT 
            l.*, 
            e.id as creator_emp_id,
            e.first_name as creator_first, 
            e.last_name as creator_last,
            e.employee_code as creator_code,
            uc.username as creator_username,
            rc.display_name as creator_role_display,
            rc.name as creator_role,
            a.id as assignee_emp_id,
            a.first_name as assigned_first, 
            a.last_name as assigned_last,
            a.employee_code as assignee_code,
            s.id as sales_emp_id,
            s.first_name as sales_first, 
            s.last_name as sales_last,
            s.employee_code as sales_code
        FROM leads l 
        LEFT JOIN employees e ON (e.id = l.employee_id OR e.id = l.created_by OR e.user_id = l.created_by) 
        LEFT JOIN users uc ON (uc.id = l.created_by OR uc.id = e.user_id)
        LEFT JOIN roles rc ON rc.id = uc.role_id
        LEFT JOIN employees a ON a.id = l.assigned_to 
        LEFT JOIN employees s ON s.id = l.assigned_sales 
        WHERE 1=1";

$params = [];

if ($search) { 
    $sql .= " AND (l.customer_name LIKE ? OR l.mobile LIKE ? OR l.email LIKE ? OR l.customer_phone LIKE ? OR l.company_name LIKE ?)"; 
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}

if ($statusFilter) { 
    $sql .= " AND l.status = ?"; 
    $params[] = $statusFilter; 
}

if ($creatorFilter) { 
    $sql .= " AND (l.employee_id = ? OR l.created_by = ?)"; 
    $params[] = $creatorFilter; 
    $params[] = $creatorFilter; 
}

if ($priorityFilter) {
    $sql .= " AND l.priority = ?";
    $params[] = $priorityFilter;
}

if ($sourceFilter) {
    $sql .= " AND (l.source = ? OR l.lead_source = ?)";
    $params[] = $sourceFilter;
    $params[] = $sourceFilter;
}

if ($campaignFilter) {
    $sql .= " AND l.campaign_name LIKE ?";
    $params[] = "%$campaignFilter%";
}

if ($assigneeFilter) {
    $sql .= " AND l.assigned_to = ?";
    $params[] = intval($assigneeFilter);
}

if ($dateFrom) {
    $sql .= " AND DATE(l.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(l.created_at) <= ?";
    $params[] = $dateTo;
}

$sql .= " ORDER BY l.created_at DESC";
$stmt = $pdo->prepare($sql); 
$stmt->execute($params); 
$leads = $stmt->fetchAll();

// Get all employees for creator filter
$allEmployees = $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status = 1 ORDER BY first_name")->fetchAll();

$telecallers = $pdo->query("SELECT e.id, e.first_name, e.last_name, e.employee_code FROM employees e JOIN users u ON u.id = e.user_id JOIN roles r ON r.id = u.role_id WHERE r.name IN ('telecaller_admin','telecaller') AND e.status = 1 ORDER BY e.first_name")->fetchAll();
$salesUsers = $pdo->query("SELECT e.id, e.first_name, e.last_name, e.employee_code FROM employees e JOIN users u ON u.id = e.user_id JOIN roles r ON r.id = u.role_id WHERE r.name IN ('sales_admin','sales_executive','sales_manager') AND e.status = 1 ORDER BY e.first_name")->fetchAll();

require_once '../includes/header.php';
$flash = $_SESSION['flash'] ?? ''; 
unset($_SESSION['flash']);
?>

<style>
.lead-creator {
    font-size: 0.8rem;
    color: #6c757d;
}
.lead-creator i {
    font-size: 0.7rem;
}
.creator-stats-card {
    border-left: 4px solid #1E3A5F;
}
.creator-stats-card .stat-number {
    font-size: 1.8rem;
    font-weight: bold;
    color: #1E3A5F;
}
.creator-stats-card .stat-label {
    font-size: 0.8rem;
    color: #6c757d;
}
.creator-rank {
    display: inline-block;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    text-align: center;
    line-height: 28px;
    font-weight: bold;
    font-size: 12px;
}
.rank-1 { background: #FFD700; color: #000; }
.rank-2 { background: #C0C0C0; color: #000; }
.rank-3 { background: #CD7F32; color: #fff; }
.rank-other { background: #e9ecef; color: #6c757d; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold">
        <i class="fas fa-users-cog me-2"></i>Lead Management
        <small class="text-muted fs-6">Total Leads: <?php echo $overallStats['total_leads'] ?? 0; ?></small>
    </h4>
    <?php if (hasModuleAccess('leads', 'can_create')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus"></i> Add Lead
    </button>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
<div class="alert alert-success alert-dismissible fade show py-2">
    <?php echo $flash; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Overall Statistics Cards -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card creator-stats-card">
            <div class="card-body p-3">
                <div class="stat-number"><?php echo $overallStats['total_leads'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-users"></i> Total Leads</div>
                <small class="text-muted">Today: <?php echo $overallStats['today_leads'] ?? 0; ?> | This Week: <?php echo $overallStats['this_week_leads'] ?? 0; ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card creator-stats-card" style="border-left-color: #28a745;">
            <div class="card-body p-3">
                <div class="stat-number" style="color: #28a745;"><?php echo $overallStats['this_month_leads'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-calendar-alt"></i> This Month</div>
                <small class="text-muted"><?php echo date('F Y'); ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card creator-stats-card" style="border-left-color: #ffc107;">
            <div class="card-body p-3">
                <div class="stat-number" style="color: #ffc107;"><?php echo $overallStats['total_creators'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-user-tie"></i> Total Creators</div>
                <small class="text-muted">Employees who created leads</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card creator-stats-card" style="border-left-color: #17a2b8;">
            <div class="card-body p-3">
                <div class="stat-number" style="color: #17a2b8;"><?php echo $overallStats['qualified_leads'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle"></i> Qualified Leads</div>
                <small class="text-muted">Won: <?php echo $overallStats['won_leads'] ?? 0; ?> | Lost: <?php echo $overallStats['lost_leads'] ?? 0; ?></small>
            </div>
        </div>
    </div>
</div>

<!-- Creator Statistics Table -->
<div class="card mb-3">
    <div class="card-header bg-light">
        <h6 class="mb-0"><i class="fas fa-trophy me-2"></i>Top Lead Creators</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:0.85rem">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Code</th>
                        <th>Department</th>
                        <th>Total Leads</th>
                        <th>New</th>
                        <th>Calling</th>
                        <th>Connected</th>
                        <th>Interested</th>
                        <th>Qualified</th>
                        <th>Won</th>
                        <th>Lost</th>
                        <th>Today</th>
                        <th>This Week</th>
                        <th>This Month</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($creatorStats)): ?>
                    <tr>
                        <td colspan="15" class="text-center py-3 text-muted">No lead creators found</td>
                    </tr>
                    <?php else: ?>
                    <?php 
                    $rank = 1;
                    foreach ($creatorStats as $cs): 
                        $rankClass = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : 'rank-other'));
                    ?>
                    <tr>
                        <td><span class="creator-rank <?php echo $rankClass; ?>"><?php echo $rank; ?></span></td>
                        <td>
                            <strong><?php echo sanitize($cs['first_name'] . ' ' . $cs['last_name']); ?></strong>
                            <?php if ($cs['total_leads'] > 10): ?>
                            <span class="badge bg-success">Top Performer</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo sanitize($cs['employee_code'] ?? '-'); ?></td>
                        <td><?php echo sanitize($cs['department_name'] ?? '-'); ?></td>
                        <td><strong class="text-primary"><?php echo $cs['total_leads']; ?></strong></td>
                        <td><span class="badge bg-info"><?php echo $cs['new_leads']; ?></span></td>
                        <td><span class="badge bg-secondary"><?php echo $cs['calling_leads']; ?></span></td>
                        <td><span class="badge bg-success"><?php echo $cs['connected_leads']; ?></span></td>
                        <td><span class="badge bg-primary"><?php echo $cs['interested_leads']; ?></span></td>
                        <td><span class="badge bg-warning"><?php echo $cs['qualified_leads']; ?></span></td>
                        <td><span class="badge bg-success"><?php echo $cs['won_leads']; ?></span></td>
                        <td><span class="badge bg-danger"><?php echo $cs['lost_leads']; ?></span></td>
                        <td><span class="badge bg-info"><?php echo $cs['today_leads']; ?></span></td>
                        <td><span class="badge bg-secondary"><?php echo $cs['this_week_leads']; ?></span></td>
                        <td><span class="badge bg-primary"><?php echo $cs['this_month_leads']; ?></span></td>
                    </tr>
                    <?php 
                        $rank++;
                    endforeach; 
                    ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="card card-body p-3 mb-3 bg-light border-0 shadow-sm">
    <div class="row g-2">
        <div class="col-md-3">
            <label class="form-label small fw-semibold text-muted mb-1">Search</label>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Name, Phone, Email, Company..." value="<?php echo sanitize($search); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">Status</label>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="new" <?php echo $statusFilter=='new'?'selected':'';?>>New</option>
                <option value="calling" <?php echo $statusFilter=='calling'?'selected':'';?>>Calling</option>
                <option value="connected" <?php echo $statusFilter=='connected'?'selected':'';?>>Connected</option>
                <option value="busy" <?php echo $statusFilter=='busy'?'selected':'';?>>Busy</option>
                <option value="no_answer" <?php echo $statusFilter=='no_answer'?'selected':'';?>>No Answer</option>
                <option value="follow_up" <?php echo $statusFilter=='follow_up'?'selected':'';?>>Follow Up</option>
                <option value="interested" <?php echo $statusFilter=='interested'?'selected':'';?>>Interested</option>
                <option value="qualified" <?php echo $statusFilter=='qualified'?'selected':'';?>>Qualified</option>
                <option value="meeting_scheduled" <?php echo $statusFilter=='meeting_scheduled'?'selected':'';?>>Meeting Scheduled</option>
                <option value="demo_scheduled" <?php echo $statusFilter=='demo_scheduled'?'selected':'';?>>Demo Scheduled</option>
                <option value="quotation_sent" <?php echo $statusFilter=='quotation_sent'?'selected':'';?>>Quotation Sent</option>
                <option value="negotiation" <?php echo $statusFilter=='negotiation'?'selected':'';?>>Negotiation</option>
                <option value="not_interested" <?php echo $statusFilter=='not_interested'?'selected':'';?>>Not Interested</option>
                <option value="won" <?php echo $statusFilter=='won'?'selected':'';?>>Won</option>
                <option value="lost" <?php echo $statusFilter=='lost'?'selected':'';?>>Lost</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">Created By</label>
            <select name="creator_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Creators</option>
                <?php foreach ($allEmployees as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php echo ($creatorFilter ?? '') == $emp['id'] ? 'selected' : ''; ?>>
                    <?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name']); ?>
                    (<?php echo $emp['employee_code']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">Assigned User</label>
            <select name="assigned_to" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Assigned</option>
                <?php foreach ($allEmployees as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php echo ($assigneeFilter ?? '') == $emp['id'] ? 'selected' : ''; ?>>
                    <?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold text-muted mb-1">Campaign</label>
            <input type="text" name="campaign" class="form-control form-control-sm" placeholder="Campaign name..." value="<?php echo sanitize($campaignFilter); ?>">
        </div>
    </div>
    <div class="row g-2 mt-1 align-items-end">
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">Priority</label>
            <select name="priority" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Priorities</option>
                <?php foreach (['urgent','high','medium','low'] as $pr): ?>
                <option value="<?php echo $pr; ?>" <?php echo $priorityFilter === $pr ? 'selected' : ''; ?>><?php echo ucfirst($pr); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">Source</label>
            <select name="source" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Sources</option>
                <?php foreach (['Facebook','Google','Instagram','Website','WhatsApp','Referral','Manual','Field Visit','Other'] as $src): ?>
                <option value="<?php echo $src; ?>" <?php echo $sourceFilter === $src ? 'selected' : ''; ?>><?php echo $src; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">From Date</label>
            <input type="date" name="date_from" value="<?php echo sanitize($dateFrom); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted mb-1">To Date</label>
            <input type="date" name="date_to" value="<?php echo sanitize($dateTo); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="fas fa-filter me-1"></i> Filter</button>
            <a href="leads.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="fas fa-undo"></i></a>
        </div>
    </div>
</form>

<!-- Leads Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-list"></i> All Leads (<?php echo count($leads); ?>)</span>
        <span class="text-muted small">Showing latest leads first</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0" style="font-size:0.85rem">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Requirement</th>
                        <th>Source</th>
                        <th>Priority</th>
                        <th>Created By</th>
                        <th>Telecaller</th>
                        <th>Sales</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $l): 
                        // Format creator name (employee, admin user, or admin panel)
                        $creatorName = '';
                        if (!empty($l['creator_first'])) {
                            $creatorName = sanitize($l['creator_first'] . ' ' . $l['creator_last']);
                            if (!empty($l['creator_code'])) {
                                $creatorName .= ' <small class="text-muted">(' . $l['creator_code'] . ')</small>';
                            }
                        } elseif (!empty($l['creator_username'])) {
                            $roleLabel = !empty($l['creator_role_display']) ? $l['creator_role_display'] : (!empty($l['creator_role']) ? ucfirst(str_replace('_', ' ', $l['creator_role'])) : 'Admin');
                            $creatorName = '<span class="fw-semibold text-primary"><i class="fas fa-user-shield me-1"></i>' . sanitize($l['creator_username']) . '</span> <small class="text-muted">(' . sanitize($roleLabel) . ')</small>';
                        } else {
                            $creatorName = '<span class="badge bg-secondary-subtle text-dark border"><i class="fas fa-shield-halved me-1 text-primary"></i>Admin Panel</span>';
                        }
                    ?>
                    <tr class="lead-row" style="cursor:pointer"
                        onclick='showLead(<?php echo htmlspecialchars(json_encode($l), ENT_QUOTES); ?>)'>
                        <td>
                            <strong><?php echo sanitize($l['customer_name']); ?></strong>
                            <?php if ($l['company_name']): ?>
                            <br><small class="text-muted"><i class="fas fa-building"></i> <?php echo sanitize($l['company_name']); ?></small>
                            <?php endif; ?>
                            <?php if (!empty($l['city'])): ?>
                            <br><small class="text-muted"><i class="fas fa-location-dot"></i> <?php echo sanitize($l['city']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo sanitize(($l['customer_phone'] ?? '') ?: ($l['mobile'] ?? '') ?: '-'); ?>
                            <?php if (($l['email'] ?? '') || ($l['customer_email'] ?? '')): ?>
                            <br><small class="text-muted"><i class="fas fa-envelope"></i> <?php echo sanitize(($l['email'] ?? '') ?: ($l['customer_email'] ?? '')); ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:220px;">
                            <?php if (!empty($l['requirement'])): ?>
                            <span class="d-inline-block text-truncate" style="max-width:210px;" title="<?php echo sanitize($l['requirement']); ?>"><?php echo sanitize($l['requirement']); ?></span>
                            <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                            <?php if (!empty($l['budget']) && (float)$l['budget'] > 0): ?>
                            <br><small class="fw-semibold text-success">&#8377;<?php echo number_format((float)$l['budget'], 2); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo sanitize($l['lead_source'] ?: $l['source'] ?: '-'); ?>
                            <?php if (!empty($l['campaign_name'])): ?>
                            <br><small class="text-muted"><i class="fas fa-bullhorn"></i> <?php echo sanitize($l['campaign_name']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo leadPriorityBadge($l['priority'] ?? ''); ?></td>
                        <td>
                            <div class="lead-creator">
                                <i class="fas fa-user"></i> <?php echo $creatorName; ?>
                            </div>
                            <small class="text-muted"><i class="fas fa-clock"></i> <?php echo date('d-m-Y h:i A', strtotime($l['created_at'])); ?></small>
                        </td>
                        <td>
                            <?php if ($l['assigned_first']): ?>
                            <span class="badge bg-info">
                                <i class="fas fa-phone"></i> <?php echo sanitize($l['assigned_first'].' '.$l['assigned_last']); ?>
                                <?php if ($l['assignee_code']): ?>
                                <small class="text-muted">(<?php echo $l['assignee_code']; ?>)</small>
                                <?php endif; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['sales_first']): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-user-tie"></i> <?php echo sanitize($l['sales_first'].' '.$l['sales_last']); ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo leadStatusColour($l['status']); ?>">
                                <?php echo ucfirst(str_replace('_',' ', $l['status'])); ?>
                            </span>
                            <?php if (!empty($l['follow_up_date']) && $l['follow_up_date'] !== '0000-00-00'): ?>
                            <br><small class="text-muted"><i class="fas fa-bell"></i> <?php echo date('d M', strtotime($l['follow_up_date'])); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small><?php echo date('d-m-Y', strtotime($l['created_at'])); ?></small>
                        </td>
                            <button class="btn btn-sm btn-outline-secondary" title="View details"
                                    onclick='showLead(<?php echo htmlspecialchars(json_encode($l), ENT_QUOTES); ?>)'>
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if (hasModuleAccess('leads', 'can_edit')): ?>
                            <button class="btn btn-sm btn-outline-primary" title="Edit"
                                    onclick='editLead(<?php echo htmlspecialchars(json_encode($l), ENT_QUOTES); ?>)'>
                                <i class="fas fa-pen"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (hasModuleAccess('leads', 'can_delete')): ?>
                            <a href="?delete=<?php echo $l['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this lead?')">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($leads)): ?>
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x d-block mb-2"></i>
                            No leads found
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><form method="POST" action="?action=create">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Add Lead</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row"><div class="col-md-6 mb-2"><label class="form-label">Customer Name *</label><input type="text" name="customer_name" class="form-control" required></div>
                <div class="col-md-6 mb-2"><label class="form-label">Phone *</label><input type="text" name="phone" class="form-control" required></div></div>
                <div class="row"><div class="col-md-6 mb-2"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                <div class="col-md-6 mb-2"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control"></div></div>
                <div class="row"><div class="col-md-4 mb-2"><label class="form-label">City</label><input type="text" name="city" class="form-control"></div>
                <div class="col-md-4 mb-2"><label class="form-label">Source</label><select name="source" class="form-select"><option value="Facebook">Facebook</option><option value="Google">Google</option><option value="Instagram">Instagram</option><option value="Website">Website</option><option value="WhatsApp">WhatsApp</option><option value="Referral">Referral</option><option value="Manual">Manual</option><option value="Other">Other</option></select></div>
                <div class="col-md-4 mb-2"><label class="form-label">Priority</label><select name="priority" class="form-select"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></div></div>
                <div class="row"><div class="col-md-6 mb-2"><label class="form-label">Campaign Name</label><input type="text" name="campaign_name" class="form-control"></div>
                <div class="col-md-6 mb-2"><label class="form-label">Budget</label><input type="number" name="budget" class="form-control" step="0.01"></div></div>
                <div class="mb-2"><label class="form-label">Requirement</label><textarea name="requirement" class="form-control" rows="2"></textarea></div>
                <div class="mb-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save Lead</button></div>
        </div>
    </form></div>
</div>

<!-- Edit Modal (one, populated by JS — there used to be one per lead in the DOM) -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><form method="POST" action="?action=update">
        <input type="hidden" name="id" id="e_id">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Lead<span id="e_title" class="text-muted fw-normal"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-2"><label class="form-label">Customer Name *</label><input type="text" name="customer_name" id="e_name" class="form-control" required></div>
                    <div class="col-md-6 mb-2"><label class="form-label">Phone *</label><input type="text" name="phone" id="e_phone" class="form-control" required></div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2"><label class="form-label">Email</label><input type="email" name="email" id="e_email" class="form-control"></div>
                    <div class="col-md-6 mb-2"><label class="form-label">Company Name</label><input type="text" name="company_name" id="e_company" class="form-control"></div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-2"><label class="form-label">City</label><input type="text" name="city" id="e_city" class="form-control"></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Source</label>
                        <select name="source" id="e_source" class="form-select">
                            <?php foreach (['Facebook','Google','Instagram','Website','WhatsApp','Referral','Manual','Other'] as $src): ?>
                            <option value="<?php echo $src; ?>"><?php echo $src; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2"><label class="form-label">Priority</label>
                        <select name="priority" id="e_priority" class="form-select">
                            <option value="low">Low</option><option value="medium">Medium</option>
                            <option value="high">High</option><option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2"><label class="form-label">Status</label>
                        <select name="status" id="e_status" class="form-select">
                            <?php foreach (['new','calling','connected','busy','no_answer','follow_up','interested','qualified','not_interested','wrong_number','duplicate','won','lost','closed'] as $st): ?>
                            <option value="<?php echo $st; ?>"><?php echo ucfirst(str_replace('_',' ', $st)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2"><label class="form-label">Campaign</label><input type="text" name="campaign_name" id="e_campaign" class="form-control"></div>
                    <div class="col-md-4 mb-2"><label class="form-label">Budget</label><input type="number" step="0.01" name="budget" id="e_budget" class="form-control"></div>
                    <div class="col-md-4 mb-2"><label class="form-label">Follow Up Date</label><input type="date" name="follow_up_date" id="e_followup" class="form-control"></div>
                </div>
                <div class="mb-2"><label class="form-label">Requirement</label><textarea name="requirement" id="e_requirement" class="form-control" rows="2"></textarea></div>
                <div class="mb-2"><label class="form-label">Notes</label><textarea name="notes" id="e_notes" class="form-control" rows="2"></textarea></div>
                <div class="mb-2"><label class="form-label">Assign To Telecaller</label>
                    <select name="assigned_to" id="e_assigned" class="form-select">
                        <option value="">Unassigned</option>
                        <?php foreach ($telecallers as $t): ?>
                        <option value="<?php echo $t['id']; ?>"><?php echo sanitize($t['first_name'].' '.$t['last_name']); ?> (<?php echo $t['employee_code']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save Changes</button></div>
        </div>
    </form></div>
</div>

<!-- Detail view: every field captured on the lead, read-only -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="v_title">Lead</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body"><div id="v_body"></div></div>
        </div>
    </div>
</div>

<script>
function val(v) { return (v === null || v === undefined || v === '') ? '' : v; }
function esc(v) {
    return String(val(v)).replace(/[&<>"']/g, c => (
        {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]
    ));
}

function editLead(l) {
    document.getElementById('e_title').textContent = ' — ' + val(l.customer_name);
    document.getElementById('e_id').value = l.id;
    document.getElementById('e_name').value = val(l.customer_name);
    document.getElementById('e_phone').value = val(l.customer_phone) || val(l.mobile) || val(l.phone);
    document.getElementById('e_email').value = val(l.email) || val(l.customer_email);
    document.getElementById('e_company').value = val(l.company_name);
    document.getElementById('e_city').value = val(l.city);
    document.getElementById('e_source').value = val(l.lead_source) || val(l.source) || 'Other';
    document.getElementById('e_priority').value = val(l.priority) || 'medium';
    document.getElementById('e_status').value = val(l.status) || 'new';
    document.getElementById('e_campaign').value = val(l.campaign_name);
    document.getElementById('e_budget').value = val(l.budget);
    document.getElementById('e_followup').value = (val(l.follow_up_date) || '').substring(0, 10);
    document.getElementById('e_requirement').value = val(l.requirement);
    document.getElementById('e_notes').value = val(l.notes);
    document.getElementById('e_assigned').value = val(l.assigned_to);
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function showLead(l) {
    const person = (f, s) => [val(f), val(s)].filter(Boolean).join(' ') || '—';
    const money  = l.budget && parseFloat(l.budget) > 0
        ? '₹' + parseFloat(l.budget).toLocaleString('en-IN', {minimumFractionDigits: 2}) : '—';

    let creatorText = 'Admin Panel';
    if (l.creator_first && l.creator_first.trim()) {
        creatorText = person(l.creator_first, l.creator_last) + (l.creator_code ? ' (' + l.creator_code + ')' : '');
    } else if (l.creator_username) {
        const roleLbl = l.creator_role_display || l.creator_role || 'Admin';
        creatorText = l.creator_username + ' (' + roleLbl + ')';
    }

    const rows = [
        ['Company',      esc(l.company_name)],
        ['Phone',        esc(val(l.customer_phone) || val(l.mobile) || val(l.phone))],
        ['Email',        esc(val(l.email) || val(l.customer_email))],
        ['City',         esc(l.city)],
        ['Source',       esc(val(l.lead_source) || val(l.source))],
        ['Campaign',     esc(l.campaign_name)],
        ['Priority',     esc(l.priority)],
        ['Status',       esc(String(val(l.status)).replace(/_/g, ' '))],
        ['Budget',       money],
        ['Follow-up',    esc(String(val(l.follow_up_date)).substring(0, 10))],
        ['Created by',   esc(creatorText)],
        ['Telecaller',   esc(person(l.assigned_first, l.assigned_last))],
        ['Sales',        esc(person(l.sales_first, l.sales_last))],
        ['Created',      esc(String(val(l.created_at)).substring(0, 16))],
    ].filter(r => r[1] && r[1] !== '—');

    let html = '<dl class="row mb-0 small">';
    rows.forEach(([k, v]) => {
        html += '<dt class="col-sm-4 text-muted fw-normal">' + k + '</dt><dd class="col-sm-8">' + v + '</dd>';
    });
    html += '</dl>';

    ['requirement', 'notes'].forEach(field => {
        if (val(l[field])) {
            html += '<hr><h6 class="fw-bold text-capitalize">' + field + '</h6>'
                  + '<p class="small mb-0" style="white-space:pre-wrap">' + esc(l[field]) + '</p>';
        }
    });

    document.getElementById('v_title').textContent = val(l.customer_name) || 'Lead';
    document.getElementById('v_body').innerHTML = html;
    new bootstrap.Modal(document.getElementById('viewModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
