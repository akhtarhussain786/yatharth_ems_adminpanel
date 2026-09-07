<?php
function handleLeadRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            return getLeads($db, $auth, $data);
        case 'my':
            return getMyLeads($db, $auth);
        case 'create':
            return createLead($db, $auth, $data);
        case 'update':
            return updateLead($db, $auth, $data);
        case 'delete':
            return deleteLead($db, $auth, $data);
        case 'search':
            return searchLeads($db, $auth, $data);
        case 'history':
            return getLeadHistory($db, $auth, $data);
        case 'assign':
            return assignLead($db, $auth, $data);
        case 'update_status':
            return updateLeadStatus($db, $auth, $data);
        case 'import':
            return importLeadsCSV($db, $auth, $data);
        case 'assign_sales':
            return assignToSales($db, $auth, $data);
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

/**
 * Checks whether the current user is authorized to perform an operation on a specific lead.
 *
 * Admins have global access.
 * Marketing, telecaller, and regular employees can only access leads they created,
 * or that are explicitly assigned to them.
 */
function canAccessLead($db, $auth, $leadId, $permission = 'view') {
    $role = $auth['role'] ?? '';
    $eid = intval($auth['employee_id'] ?? 0);

    // Super Admin and Admin have unrestricted access for all operations
    if (in_array($role, ['super_admin', 'admin'], true)) {
        return true;
    }

    // HR Executive, HR Admin, Marketing Admin, Telecaller Admin, Sales Admin have unrestricted VIEW access
    if (in_array($role, ['hr_admin', 'hr', 'hr_executive', 'digital_marketing_admin', 'marketing_admin', 'telecaller_admin', 'sales_admin'], true)) {
        if ($permission === 'view') {
            return true;
        }
        if ($permission === 'edit' && in_array($role, ['digital_marketing_admin', 'marketing_admin', 'telecaller_admin', 'sales_admin', 'hr_admin'], true)) {
            return true;
        }
    }

    if (!$leadId || !$eid) return false;

    $stmt = $db->prepare("SELECT id, employee_id, created_by, assigned_to, assigned_sales, status FROM leads WHERE id = ? LIMIT 1");
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    if (!$lead) return false;

    $creatorEid = intval($lead['created_by'] ?? $lead['employee_id'] ?? 0);
    $assigneeEid = intval($lead['assigned_to'] ?? 0);
    $salesEid = intval($lead['assigned_sales'] ?? 0);

    // Deletion: restricted to original creator or admin
    if ($permission === 'delete') {
        return ($creatorEid === $eid || in_array($role, ['digital_marketing_admin', 'marketing_admin'], true));
    }

    // View / Edit / Status: must be creator or assigned handler
    if ($creatorEid === $eid || $assigneeEid === $eid || $salesEid === $eid) {
        return true;
    }

    // Sales role may access unassigned qualified leads pool
    if (strpos($role, 'sales') !== false && empty($salesEid) && in_array($lead['status'], ['qualified','meeting_scheduled','demo_scheduled','quotation_sent','negotiation'], true)) {
        return true;
    }

    return false;
}

/**
 * Who may see a lead.
 *
 * Admins, HR Executives, HR Admins, and Marketing Admins see all organization leads.
 * Marketing employees see ONLY their own created or assigned leads.
 * Telecallers see assigned leads.
 * Sales see assigned and qualified pool leads.
 *
 * Returns null when the caller may see all leads.
 */
function leadVisibilityScope($role, $employeeId) {
    if (in_array($role, ['super_admin', 'admin', 'hr_admin', 'hr', 'hr_executive', 'digital_marketing_admin', 'marketing_admin', 'telecaller_admin', 'sales_admin'], true)) return null;

    $sql = '(l.employee_id = ? OR l.created_by = ? OR l.assigned_to = ? OR l.assigned_sales = ?';
    $params = [$employeeId, $employeeId, $employeeId, $employeeId];

    if (strpos($role, 'sales') !== false) {
        $sql .= " OR (l.assigned_sales IS NULL AND l.status IN " .
                "('qualified','meeting_scheduled','demo_scheduled','quotation_sent','negotiation'))";
    }
    $sql .= ')';

    return ['sql' => $sql, 'params' => $params];
}

function getLeads($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leads', 'can_view');
    $role = $auth['role'];
    $eid = $auth['employee_id'];

    $sql = "SELECT l.*, 
            cr.id as creator_id, cr.first_name as creator_first, cr.last_name as creator_last, cr.employee_code as creator_code,
            d.name as creator_department,
            a.id as assigned_to_id, a.first_name as assigned_first, a.last_name as assigned_last, a.employee_code as assigned_code,
            s.id as assigned_sales_id, s.first_name as sales_first, s.last_name as sales_last, s.employee_code as sales_code
            FROM leads l
            LEFT JOIN employees cr ON cr.id = COALESCE(l.created_by, l.employee_id)
            LEFT JOIN departments d ON d.id = cr.department_id
            LEFT JOIN employees a ON a.id = l.assigned_to
            LEFT JOIN employees s ON s.id = l.assigned_sales
            WHERE 1=1";
    $params = [];

    // Status filter
    if (isset($data['status']) && $data['status'] !== '') {
        $sql .= " AND l.status = ?";
        $params[] = $data['status'];
    }

    // Source filter
    if (isset($data['source']) && $data['source'] !== '') {
        $sql .= " AND (l.source = ? OR l.lead_source = ?)";
        $params[] = $data['source'];
        $params[] = $data['source'];
    }

    // Priority filter
    if (isset($data['priority']) && $data['priority'] !== '') {
        $sql .= " AND l.priority = ?";
        $params[] = $data['priority'];
    }

    // Campaign filter
    if (isset($data['campaign']) && $data['campaign'] !== '') {
        $sql .= " AND l.campaign_name = ?";
        $params[] = $data['campaign'];
    } elseif (isset($data['campaign_name']) && $data['campaign_name'] !== '') {
        $sql .= " AND l.campaign_name = ?";
        $params[] = $data['campaign_name'];
    }

    // Assigned User filter
    if (isset($data['assigned_to']) && $data['assigned_to'] !== '') {
        $sql .= " AND l.assigned_to = ?";
        $params[] = intval($data['assigned_to']);
    }

    // Date filters
    if (isset($data['date']) && $data['date'] !== '') {
        $sql .= " AND DATE(l.created_at) = ?";
        $params[] = $data['date'];
    }
    if (isset($data['from_date']) && $data['from_date'] !== '') {
        $sql .= " AND DATE(l.created_at) >= ?";
        $params[] = $data['from_date'];
    } elseif (isset($data['date_from']) && $data['date_from'] !== '') {
        $sql .= " AND DATE(l.created_at) >= ?";
        $params[] = $data['date_from'];
    }
    if (isset($data['to_date']) && $data['to_date'] !== '') {
        $sql .= " AND DATE(l.created_at) <= ?";
        $params[] = $data['to_date'];
    } elseif (isset($data['date_to']) && $data['date_to'] !== '') {
        $sql .= " AND DATE(l.created_at) <= ?";
        $params[] = $data['date_to'];
    }

    // Search query
    if (isset($data['search']) && trim($data['search']) !== '') {
        $search = '%' . trim($data['search']) . '%';
        $sql .= " AND (l.customer_name LIKE ? OR l.customer_phone LIKE ? OR l.mobile LIKE ? OR l.email LIKE ? OR l.company_name LIKE ? OR l.city LIKE ?)";
        $params = array_merge($params, [$search, $search, $search, $search, $search, $search]);
    }

    // Scoping check: Admins can filter by specific employee, non-admins are strictly locked to their own leads
    $scope = leadVisibilityScope($role, $eid);
    if ($scope) {
        $sql .= ' AND ' . $scope['sql'];
        $params = array_merge($params, $scope['params']);
    } else {
        // Admin optional creator/employee filter
        if (isset($data['employee_id']) && $data['employee_id'] !== '') {
            $empFilter = intval($data['employee_id']);
            $sql .= " AND (l.employee_id = ? OR l.created_by = ?)";
            $params[] = $empFilter;
            $params[] = $empFilter;
        } elseif (isset($data['creator_id']) && $data['creator_id'] !== '') {
            $creatorFilter = intval($data['creator_id']);
            $sql .= " AND (l.employee_id = ? OR l.created_by = ?)";
            $params[] = $creatorFilter;
            $params[] = $creatorFilter;
        }
    }

    $sql .= " ORDER BY l.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getMyLeads($db, $auth) {
    return getLeads($db, $auth, []);
}

function autoAssignTelecaller($db) {
    $stmt = $db->query("SELECT e.id FROM employees e JOIN users u ON u.id = e.user_id JOIN roles r ON r.id = u.role_id WHERE r.name IN ('telecaller_admin','telecaller') AND e.status = 1 ORDER BY (SELECT COUNT(*) FROM leads WHERE assigned_to = e.id AND status NOT IN ('won','lost','closed','duplicate')) ASC LIMIT 1");
    $tc = $stmt->fetch();
    return $tc ? $tc['id'] : null;
}

function autoAssignSales($db) {
    $stmt = $db->query("SELECT e.id FROM employees e JOIN users u ON u.id = e.user_id JOIN roles r ON r.id = u.role_id WHERE r.name IN ('sales_admin','sales_executive','sales_manager') AND e.status = 1 ORDER BY (SELECT COUNT(*) FROM leads WHERE assigned_sales = e.id AND status NOT IN ('won','lost','closed')) ASC LIMIT 1");
    $s = $stmt->fetch();
    return $s ? $s['id'] : null;
}

function createLead($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leads', 'can_create');
    $eid = $auth['employee_id'];

    $customerName = Validator::sanitize($data['customer_name'] ?? '');
    $phone = Validator::sanitize($data['phone'] ?? $data['customer_phone'] ?? '');
    $email = Validator::sanitize($data['email'] ?? $data['customer_email'] ?? '');
    $companyName = Validator::sanitize($data['company_name'] ?? '');
    $city = Validator::sanitize($data['city'] ?? '');
    $source = Validator::sanitize($data['source'] ?? $data['lead_source'] ?? '');
    $campaignName = Validator::sanitize($data['campaign_name'] ?? '');
    $requirement = Validator::sanitize($data['requirement'] ?? '');
    $budget = $data['budget'] ?? null;
    $priority = Validator::sanitize($data['priority'] ?? 'medium');
    $notes = Validator::sanitize($data['notes'] ?? '');

    if (!$customerName) return ['success' => false, 'message' => 'Customer name is required'];
    if (!$phone) return ['success' => false, 'message' => 'Phone is required'];

    $allowedSources = ['Facebook','Google','Instagram','Website','WhatsApp','Referral','Manual','Field Visit','Other'];
    if ($source && !in_array($source, $allowedSources)) $source = 'Other';

    $assignedTo = null;
    if (isset($data['assigned_to']) && $data['assigned_to']) {
        $assignedTo = intval($data['assigned_to']);
    } else {
        $assignedTo = autoAssignTelecaller($db);
    }

    $stmt = $db->prepare("INSERT INTO leads (customer_name, first_name, last_name, customer_phone, mobile, email, customer_email, company_name, city, source, lead_source, campaign_name, requirement, budget, priority, notes, employee_id, created_by, assigned_to, assigned_by, status) VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')");
    $stmt->execute([
        $customerName, $customerName, $phone, $phone, $email, $email,
        $companyName, $city, $source, $source, $campaignName,
        $requirement, $budget, $priority, $notes,
        $eid, $eid, $assignedTo, $assignedTo ? $auth['user_id'] : null
    ]);

    $leadId = $db->lastInsertId();

    // Fetch creator info for notification
    $creatorName = 'Employee';
    $deptName = 'Marketing';
    if ($eid) {
        $cStmt = $db->prepare("SELECT e.first_name, e.last_name, d.name as dept_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ?");
        $cStmt->execute([$eid]);
        $cRow = $cStmt->fetch();
        if ($cRow) {
            $creatorName = trim(($cRow['first_name'] ?? '') . ' ' . ($cRow['last_name'] ?? ''));
            $deptName = $cRow['dept_name'] ?? 'Marketing';
        }
    }

    // 1. Notify assigned telecaller (if assigned and not self)
    if ($assignedTo && $assignedTo !== $eid) {
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES ((SELECT user_id FROM employees WHERE id = ?), 'New Lead Assigned', CONCAT('Lead: ', ?, ' assigned to you'), 'lead', CONCAT('/leads/', ?))");
        $stmt->execute([$assignedTo, $customerName, $leadId]);
    }

    // 2. Notify all super_admin / admin roles about new lead creation
    try {
        $adminUsers = $db->query("SELECT id FROM users WHERE role_id IN (SELECT id FROM roles WHERE name IN ('super_admin','admin')) AND status = 1")->fetchAll();
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (?, 'New Lead Created', CONCAT(?, ' created by ', ?, ' (', ?, ')'), 'lead', CONCAT('/leads/', ?), NOW())");
        foreach ($adminUsers as $au) {
            $notifStmt->execute([$au['id'], $customerName, $creatorName, $deptName, $leadId]);
        }
    } catch (Throwable $e) {
        error_log('Admin lead creation notification error: ' . $e->getMessage());
    }

    return ['success' => true, 'message' => 'Lead created', 'id' => $leadId, 'assigned_to' => $assignedTo];
}

function updateLead($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leads', 'can_edit');
    $id = intval($data['id'] ?? $data['lead_id'] ?? 0);
    if (!$id) return ['success' => false, 'message' => 'Lead ID required'];

    // Strict ownership & permission check
    if (!canAccessLead($db, $auth, $id, 'edit')) {
        http_response_code(403);
        return ['success' => false, 'message' => 'Access denied: You do not have permission to edit this lead'];
    }

    $fields = ['customer_name','customer_phone','mobile','email','customer_email','company_name','city','source','lead_source','campaign_name','requirement','budget','priority','notes','status','follow_up_date'];
    $updates = []; $params = [];

    foreach ($fields as $f) {
        if (isset($data[$f])) {
            $updates[] = "$f=?";
            $params[] = Validator::sanitize(strval($data[$f]));
        }
    }

    // Only admins or managers may reassign
    if (isset($data['assigned_to']) && in_array($auth['role'], ['super_admin', 'admin', 'telecaller_admin', 'sales_admin'], true)) {
        $updates[] = "assigned_to=?";
        $params[] = intval($data['assigned_to']) ?: null;
        $updates[] = "assigned_by=?";
        $params[] = $auth['user_id'];
    }

    if (empty($updates)) return ['success' => false, 'message' => 'No data to update'];

    $params[] = $id;
    $stmt = $db->prepare("UPDATE leads SET " . implode(', ', $updates) . " WHERE id=?");
    $stmt->execute($params);
    return ['success' => true, 'message' => 'Lead updated'];
}

function deleteLead($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leads', 'can_delete');
    $id = intval($data['id'] ?? $data['lead_id'] ?? 0);
    if (!$id) return ['success' => false, 'message' => 'Lead ID required'];

    // Strict ownership check (only creator or admin can delete)
    if (!canAccessLead($db, $auth, $id, 'delete')) {
        http_response_code(403);
        return ['success' => false, 'message' => 'Access denied: You do not have permission to delete this lead'];
    }

    $stmt = $db->prepare("DELETE FROM leads WHERE id=?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'Lead deleted'];
}

function searchLeads($db, $auth, $data) {
    $query = Validator::sanitize($data['query'] ?? '');
    $eid = $auth['employee_id'];
    $role = $auth['role'];

    $like = "%$query%";
    $sql = "SELECT l.*, cr.first_name as creator_first, cr.last_name as creator_last 
            FROM leads l 
            LEFT JOIN employees cr ON cr.id = COALESCE(l.created_by, l.employee_id) 
            WHERE (l.customer_name LIKE ? OR l.customer_phone LIKE ? OR l.mobile LIKE ? OR l.email LIKE ? OR l.company_name LIKE ?)";
    $params = [$like, $like, $like, $like, $like];

    $scope = leadVisibilityScope($role, $eid);
    if ($scope) {
        $sql .= ' AND ' . $scope['sql'];
        $params = array_merge($params, $scope['params']);
    }

    $sql .= " ORDER BY l.created_at DESC LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getLeadHistory($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leads', 'can_view');
    $id = intval($data['lead_id'] ?? $data['id'] ?? 0);
    if (!$id) return ['success' => false, 'message' => 'Lead ID required'];

    if (!canAccessLead($db, $auth, $id, 'view')) {
        http_response_code(403);
        return ['success' => false, 'message' => 'Access denied: You do not have permission to view this lead'];
    }

    $sql = "SELECT l.*, 
            cr.first_name as creator_first, cr.last_name as creator_last,
            a.first_name as assigned_first, a.last_name as assigned_last
            FROM leads l
            LEFT JOIN employees cr ON cr.id = COALESCE(l.created_by, l.employee_id)
            LEFT JOIN employees a ON a.id = l.assigned_to
            WHERE l.id = ?";
    $params = [$id];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $lead = $stmt->fetch();
    if (!$lead) return ['success' => false, 'message' => 'Lead not found'];

    $calls = $db->prepare("SELECT cr.*, e.first_name, e.last_name FROM call_reports cr LEFT JOIN employees e ON e.id = cr.employee_id WHERE cr.lead_id = ? ORDER BY cr.created_at DESC");
    $calls->execute([$id]);

    $followups = $db->prepare("SELECT * FROM follow_ups WHERE lead_id = ? ORDER BY created_at DESC");
    $followups->execute([$id]);

    return ['success' => true, 'data' => ['lead' => $lead, 'calls' => $calls->fetchAll(), 'follow_ups' => $followups->fetchAll()]];
}

function assignLead($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leads', 'can_edit');
    
    // Assigning requires admin or telecaller manager role
    if (!in_array($auth['role'], ['super_admin', 'admin', 'telecaller_admin', 'sales_admin'], true)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'Access denied: Only managers can assign leads'];
    }

    $leadId = intval($data['lead_id'] ?? 0);
    $telecallerId = intval($data['telecaller_id'] ?? 0);
    if (!$leadId || !$telecallerId) return ['success' => false, 'message' => 'Lead ID and Telecaller ID required'];

    $db->prepare("UPDATE leads SET assigned_to = ?, assigned_by = ? WHERE id = ?")->execute([$telecallerId, $auth['user_id'], $leadId]);
    $lead = $db->prepare("SELECT customer_name FROM leads WHERE id = ?");
    $lead->execute([$leadId]); $l = $lead->fetch();
    $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES ((SELECT user_id FROM employees WHERE id = ?), 'Lead Assigned', CONCAT('Lead: ', ?, ' assigned to you'), 'lead', ?)")->execute([$telecallerId, $l['customer_name'] ?? 'Lead', '/leads/' . $leadId]);

    return ['success' => true, 'message' => 'Lead assigned'];
}

function assignToSales($db, $auth, $data) {
    $leadId = intval($data['lead_id'] ?? 0);
    if (!$leadId) return ['success' => false, 'message' => 'Lead ID required'];

    if (!canAccessLead($db, $auth, $leadId, 'edit')) {
        http_response_code(403);
        return ['success' => false, 'message' => 'Access denied: You do not have permission on this lead'];
    }

    $salesId = null;
    if (isset($data['sales_id']) && $data['sales_id']) {
        $salesId = intval($data['sales_id']);
    } else {
        $salesId = autoAssignSales($db);
    }

    $db->prepare("UPDATE leads SET assigned_sales = ?, status = 'qualified' WHERE id = ?")->execute([$salesId, $leadId]);
    $lead = $db->prepare("SELECT customer_name FROM leads WHERE id = ?");
    $lead->execute([$leadId]); $l = $lead->fetch();
    if ($salesId) {
        $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES ((SELECT user_id FROM employees WHERE id = ?), 'Lead Qualified', CONCAT('Qualified lead: ', ?, ' assigned to you'), 'lead', ?)")->execute([$salesId, $l['customer_name'] ?? 'Lead', '/leads/' . $leadId]);
    }

    return ['success' => true, 'message' => 'Lead assigned to sales', 'assigned_sales' => $salesId];
}

function updateLeadStatus($db, $auth, $data) {
    $id = intval($data['id'] ?? $data['lead_id'] ?? 0);
    $status = Validator::sanitize($data['status'] ?? '');
    $notes = Validator::sanitize($data['notes'] ?? '');

    if (!$id) return ['success' => false, 'message' => 'Lead ID required'];

    // Strict ownership & permission check
    if (!canAccessLead($db, $auth, $id, 'edit')) {
        http_response_code(403);
        return ['success' => false, 'message' => 'Access denied: You do not have permission to update this lead status'];
    }

    $allowed = ['new','contacted','calling','connected','busy','no_answer','follow_up','interested','qualified','proposal','meeting_scheduled','demo_scheduled','quotation_sent','negotiation','not_interested','wrong_number','duplicate','lost','won','closed'];
    if (!in_array($status, $allowed)) return ['success' => false, 'message' => 'Invalid status'];

    $noteLine = $notes ? '[' . date('Y-m-d H:i') . '] ' . $notes : '';
    $stmt = $db->prepare("UPDATE leads SET status = ?, notes = CONCAT_WS('\n', notes, ?) WHERE id = ?");
    $stmt->execute([$status, $noteLine, $id]);

    // If qualified, auto-assign to sales
    if ($status === 'qualified') {
        $salesId = autoAssignSales($db);
        if ($salesId) {
            $db->prepare("UPDATE leads SET assigned_sales = ? WHERE id = ?")->execute([$salesId, $id]);
            $lead = $db->prepare("SELECT customer_name FROM leads WHERE id = ?");
            $lead->execute([$id]); $l = $lead->fetch();
            $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES ((SELECT user_id FROM employees WHERE id = ?), 'Lead Qualified', CONCAT('Qualified lead: ', ?, ' assigned to you'), 'lead', ?)")->execute([$salesId, $l['customer_name'] ?? 'Lead', '/leads/' . $id]);
        }
    }

    return ['success' => true, 'message' => 'Status updated to ' . $status];
}

function importLeadsCSV($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leads', 'can_create');
    $eid = $auth['employee_id'];
    $leads = $data['leads'] ?? [];
    $imported = 0; $errors = [];

    foreach ($leads as $i => $row) {
        $name = Validator::sanitize($row['customer_name'] ?? $row['name'] ?? '');
        $phone = Validator::sanitize($row['phone'] ?? $row['customer_phone'] ?? '');
        if (!$name || !$phone) { $errors[] = "Row $i: name and phone required"; continue; }
        $source = Validator::sanitize($row['source'] ?? 'Import');
        $assignedTo = autoAssignTelecaller($db);

        $db->prepare("INSERT INTO leads (customer_name, first_name, last_name, customer_phone, mobile, email, customer_email, company_name, city, source, lead_source, campaign_name, requirement, budget, priority, notes, employee_id, created_by, assigned_to, assigned_by, status) VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')")->execute([
            $name, $name, $phone, $phone,
            Validator::sanitize($row['email'] ?? ''),
            Validator::sanitize($row['email'] ?? ''),
            Validator::sanitize($row['company_name'] ?? ''),
            Validator::sanitize($row['city'] ?? ''),
            $source, $source,
            Validator::sanitize($row['campaign_name'] ?? ''),
            Validator::sanitize($row['requirement'] ?? ''),
            $row['budget'] ?? null,
            Validator::sanitize($row['priority'] ?? 'medium'),
            Validator::sanitize($row['notes'] ?? ''),
            $eid, $eid, $assignedTo, $assignedTo ? $auth['user_id'] : null
        ]);
        $imported++;
    }

    return ['success' => true, 'message' => "Imported $imported leads", 'imported' => $imported, 'errors' => $errors];
}
