<?php
function handleTelecallerRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'dashboard':
            return telecallerDashboard($db, $auth);
        case 'leads':
            return telecallerLeads($db, $auth, $data);
        case 'call-log':
            return createCallLog($db, $auth, $data);
        case 'follow-up':
            return createFollowUp($db, $auth, $data);
        case 'qualify':
            return qualifyLead($db, $auth, $data);
        case 'update-status':
            return updateLeadStatus($db, $auth, $data);
        default:
            return ['success' => false, 'message' => 'Invalid telecaller action'];
    }
}

function telecallerDashboard($db, $auth) {
    $eid = $auth['employee_id'];

    $leads = $db->prepare("SELECT l.*, cr.first_name as creator_first, cr.last_name as creator_last FROM leads l LEFT JOIN employees cr ON cr.id = l.employee_id WHERE (l.assigned_to = ? OR l.employee_id = ? OR l.created_by = ?) ORDER BY FIELD(l.status, 'new','follow_up','interested','calling','connected','busy','no_answer','qualified','not_interested','wrong_number','duplicate','lost','won') ASC, l.created_at DESC");
    $leads->execute([$eid, $eid, $eid]);
    $leadsData = $leads->fetchAll();

    $todayFollowUps = $db->prepare("SELECT f.*, l.customer_name as lead_name, l.customer_phone as lead_phone FROM follow_ups f JOIN leads l ON l.id = f.lead_id WHERE f.employee_id = ? AND f.follow_up_date <= CURDATE() AND f.status = 'pending' ORDER BY f.follow_up_date ASC");
    $todayFollowUps->execute([$eid]);

    $stats = [
        'total_assigned' => 0,
        'today_calls' => 0,
        'pending_followups' => 0,
        'converted' => 0,
    ];
    $s1 = $db->prepare("SELECT COUNT(*) as c FROM leads WHERE (assigned_to = ? OR employee_id = ? OR created_by = ?)"); $s1->execute([$eid, $eid, $eid]); $stats['total_assigned'] = (int)$s1->fetch()['c'];
    $s2 = $db->prepare("SELECT COUNT(*) as c FROM call_reports WHERE employee_id = ? AND DATE(created_at) = CURDATE()"); $s2->execute([$eid]); $stats['today_calls'] = (int)$s2->fetch()['c'];
    $s3 = $db->prepare("SELECT COUNT(*) as c FROM follow_ups WHERE employee_id = ? AND status = 'pending' AND follow_up_date <= CURDATE()"); $s3->execute([$eid]); $stats['pending_followups'] = (int)$s3->fetch()['c'];
    $s4 = $db->prepare("SELECT COUNT(*) as c FROM leads WHERE (assigned_to = ? OR employee_id = ? OR created_by = ?) AND (status = 'won' OR status = 'qualified')"); $s4->execute([$eid, $eid, $eid]); $stats['converted'] = (int)$s4->fetch()['c'];

    return [
        'success' => true,
        'data' => [
            'leads' => $leadsData,
            'today_follow_ups' => $todayFollowUps->fetchAll(),
            'stats' => $stats,
        ],
    ];
}

function telecallerLeads($db, $auth, $data) {
    $eid = $auth['employee_id'];
    $status = $data['status'] ?? '';
    $search = $data['search'] ?? '';

    $sql = "SELECT l.* FROM leads l WHERE (l.assigned_to = ? OR l.employee_id = ? OR l.created_by = ?)";
    $params = [$eid, $eid, $eid];

    if ($status) { $sql .= " AND l.status = ?"; $params[] = $status; }
    if ($search) { $sql .= " AND (l.customer_name LIKE ? OR l.customer_phone LIKE ? OR l.mobile LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

    $sql .= " ORDER BY FIELD(l.status,'new','follow_up','interested','calling','connected','busy','no_answer','qualified','not_interested','wrong_number','duplicate','lost','won') ASC, l.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createCallLog($db, $auth, $data) {
    $eid = $auth['employee_id'];
    $leadId = intval($data['lead_id'] ?? 0);
    $callStatus = Validator::sanitize($data['call_status'] ?? '');
    $notes = Validator::sanitize($data['notes'] ?? '');
    $callDuration = intval($data['call_duration'] ?? 0);

    if (!$leadId) return ['success' => false, 'message' => 'Lead ID required'];

    $lead = $db->prepare("SELECT customer_name, customer_phone, mobile, status FROM leads WHERE id = ? AND assigned_to = ?");
    $lead->execute([$leadId, $eid]);
    $l = $lead->fetch();
    if (!$l) return ['success' => false, 'message' => 'Lead not found or not assigned to you'];

    $stmt = $db->prepare("INSERT INTO call_reports (employee_id, lead_id, customer_name, customer_phone, call_type, status, notes, call_duration, call_date) VALUES (?, ?, ?, ?, 'outgoing', ?, ?, ?, CURDATE())");
    $stmt->execute([$eid, $leadId, $l['customer_name'], ($l['customer_phone'] ?: $l['mobile']), $callStatus, $notes, $callDuration]);

    // Only update lead status if it's not already in sales pipeline
    $pipelineStatuses = ['qualified','meeting_scheduled','demo_scheduled','quotation_sent','negotiation','won','lost','closed'];
    if (!in_array($l['status'], $pipelineStatuses)) {
        $statusMap = [
            'connected' => 'connected', 'interested' => 'interested', 'not_interested' => 'not_interested',
            'busy' => 'busy', 'no_answer' => 'no_answer', 'callback' => 'follow_up', 'wrong_number' => 'wrong_number',
        ];
        if (isset($statusMap[$callStatus])) {
            $db->prepare("UPDATE leads SET status = ? WHERE id = ?")->execute([$statusMap[$callStatus], $leadId]);
        }
    }

    return ['success' => true, 'message' => 'Call log saved', 'id' => $db->lastInsertId()];
}

function createFollowUp($db, $auth, $data) {
    $eid = $auth['employee_id'];
    $leadId = intval($data['lead_id'] ?? 0);
    $followUpDate = Validator::sanitize($data['follow_up_date'] ?? '');
    $followUpTime = Validator::sanitize($data['follow_up_time'] ?? '');
    $notes = Validator::sanitize($data['notes'] ?? '');

    if (!$leadId || !$followUpDate) return ['success' => false, 'message' => 'Lead ID and follow-up date required'];

    $db->prepare("INSERT INTO follow_ups (lead_id, employee_id, follow_up_date, follow_up_time, notes, status) VALUES (?, ?, ?, ?, ?, 'pending')")->execute([$leadId, $eid, $followUpDate, $followUpTime ?: null, $notes]);
    $db->prepare("UPDATE leads SET status = 'follow_up', follow_up_date = ? WHERE id = ?")->execute([$followUpDate, $leadId]);

    return ['success' => true, 'message' => 'Follow-up scheduled', 'id' => $db->lastInsertId()];
}

function qualifyLead($db, $auth, $data) {
    $eid = $auth['employee_id'];
    $leadId = intval($data['lead_id'] ?? 0);
    if (!$leadId) return ['success' => false, 'message' => 'Lead ID required'];

    $lead = $db->prepare("SELECT customer_name, status, assigned_sales FROM leads WHERE id = ? AND assigned_to = ?");
    $lead->execute([$leadId, $eid]);
    $l = $lead->fetch();
    if (!$l) return ['success' => false, 'message' => 'Lead not found or not assigned to you'];
    if (in_array($l['status'], ['won','lost','closed'])) return ['success' => false, 'message' => 'Lead already closed'];
    if ($l['assigned_sales']) return ['success' => false, 'message' => 'Lead already assigned to sales'];

    $db->prepare("UPDATE leads SET status = 'qualified' WHERE id = ?")->execute([$leadId]);

    // Auto-assign to sales
    $salesId = autoAssignSales($db);
    if ($salesId) {
        $db->prepare("UPDATE leads SET assigned_sales = ? WHERE id = ?")->execute([$salesId, $leadId]);
        $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES ((SELECT user_id FROM employees WHERE id = ?), 'Lead Qualified', CONCAT('Qualified lead assigned to you'), 'lead', ?)")->execute([$salesId, '/leads/' . $leadId]);
    }

    return ['success' => true, 'message' => 'Lead qualified and sent to sales', 'assigned_sales' => $salesId];
}

function updateLeadStatus($db, $auth, $data) {
    $eid = $auth['employee_id'];
    $leadId = intval($data['lead_id'] ?? $data['id'] ?? 0);
    $status = Validator::sanitize($data['status'] ?? '');
    $notes = Validator::sanitize($data['notes'] ?? '');

    $allowed = ['new','calling','connected','busy','no_answer','follow_up','interested','qualified','not_interested','wrong_number','duplicate','lost','won'];
    if (!in_array($status, $allowed)) return ['success' => false, 'message' => 'Invalid status'];

    $db->prepare("UPDATE leads SET status = ?, notes = CONCAT_WS('\n',notes,?) WHERE id = ? AND assigned_to = ?")->execute([$status, $notes ? '[' . date('Y-m-d H:i') . '] ' . $notes : '', $leadId, $eid]);

    if ($status === 'qualified') {
        $salesId = autoAssignSales($db);
        if ($salesId) {
            $db->prepare("UPDATE leads SET assigned_sales = ? WHERE id = ?")->execute([$salesId, $leadId]);
            $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES ((SELECT user_id FROM employees WHERE id = ?), 'Lead Qualified', CONCAT('Qualified lead assigned to you'), 'lead', ?)")->execute([$salesId, '/leads/' . $leadId]);
        }
    }

    return ['success' => true, 'message' => 'Status updated'];
}

function autoAssignSales($db) {
    $stmt = $db->query("SELECT e.id FROM employees e JOIN users u ON u.id = e.user_id JOIN roles r ON r.id = u.role_id WHERE r.name IN ('sales_admin','sales_executive','sales_manager') AND e.status = 1 ORDER BY (SELECT COUNT(*) FROM leads WHERE assigned_sales = e.id AND status NOT IN ('won','lost','closed')) ASC LIMIT 1");
    $s = $stmt->fetch();
    return $s ? $s['id'] : null;
}
