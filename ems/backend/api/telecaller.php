<?php

function handleTelecallerRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode($GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'dashboard':
            return telecallerDashboard($db, $auth, $data);
        case 'leads':
            return telecallerLeads($db, $auth, $data);
        case 'creators':
            return getTelecallerLeadCreators($db, $auth);
        case 'telecallers':
            return getActiveTelecallers($db, $auth);
        case 'create-inquiry':
            return createInquiry($db, $auth, $data);
        case 'call-log':
        case 'call-feedback':
            return saveCallFeedback($db, $auth, $data);
        case 'feedback-history':
            return getCallFeedbackHistory($db, $auth, $data);
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

/**
 * Checks whether the user is an admin or manager with global/team telecaller overview.
 */
function isTelecallerManager($auth) {
    $role = $auth['role'] ?? '';
    return in_array($role, ['super_admin', 'admin', 'telecaller_admin', 'sales_admin', 'hr_admin', 'marketing_admin'], true);
}

/**
 * Builds the SQL scoping clause for telecaller leads.
 * Admins/managers see all leads (or filter by specific telecaller).
 * Regular telecallers see only leads assigned to them or created by them.
 */
function buildTelecallerScope($auth, $filterTelecallerId = null) {
    // All telecallers and managers see all leads by default so any telecaller can follow up
    if (!empty($filterTelecallerId)) {
        $tid = (int)$filterTelecallerId;
        return [
            'sql' => '(l.assigned_to = ? OR l.assigned_to = (SELECT user_id FROM employees WHERE id = ?))',
            'params' => [$tid, $tid]
        ];
    }

    return ['sql' => '1=1', 'params' => []];
}

/**
 * Returns list of active telecallers with open lead counts.
 */
function getActiveTelecallers($db, $auth) {
    $stmt = $db->query("
        SELECT e.id, e.user_id, e.employee_code, e.first_name, e.last_name, e.mobile,
               r.name as role_name, r.display_name as role_display,
               (SELECT COUNT(*) FROM leads WHERE assigned_to = e.id AND status NOT IN ('won','lost','closed','duplicate')) as open_leads_count,
               (SELECT COUNT(*) FROM leads WHERE assigned_to = e.id) as total_leads_count
        FROM employees e
        JOIN users u ON u.id = e.user_id
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE (r.name IN ('telecaller_admin','telecaller') OR LOWER(COALESCE(d.name,'')) = 'telecaller')
          AND e.status = 1
        ORDER BY e.first_name ASC
    ");
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function getTelecallerLeadCreators($db, $auth) {
    $stmt = $db->query("
        SELECT DISTINCT 
            e.id, 
            CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) as name,
            e.employee_code,
            COALESCE(r.name, '') as role
        FROM employees e
        JOIN leads l ON (l.created_by = e.id OR l.employee_id = e.id)
        LEFT JOIN users u ON u.id = e.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE e.first_name IS NOT NULL AND e.first_name != ''
        ORDER BY e.first_name ASC
    ");
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function telecallerDashboard($db, $auth, $data = []) {
    $eid = (int)($auth['employee_id'] ?? 0);
    $isManager = isTelecallerManager($auth);
    $filterTelecaller = !empty($data['telecaller_id']) ? $data['telecaller_id'] : (!empty($_GET['telecaller_id']) ? $_GET['telecaller_id'] : null);

    $scope = buildTelecallerScope($auth, $filterTelecaller);

    $sql = "
        SELECT l.*, 
               cr.first_name as creator_first, cr.last_name as creator_last, cr.employee_code as creator_code,
               ucr.username as creator_username,
               d.name as creator_department,
               a.first_name as assigned_first, a.last_name as assigned_last, a.employee_code as assigned_code,
               crp.status as latest_call_status,
               crp.call_outcome as latest_call_outcome,
               crp.notes as latest_call_notes,
               crp.created_at as latest_call_at
        FROM leads l 
        LEFT JOIN employees cr ON cr.id = COALESCE(l.created_by, l.employee_id)
        LEFT JOIN users ucr ON ucr.id = l.created_by
        LEFT JOIN departments d ON d.id = cr.department_id
        LEFT JOIN employees a ON a.id = l.assigned_to
        LEFT JOIN call_reports crp ON crp.id = (
            SELECT id FROM call_reports WHERE lead_id = l.id ORDER BY id DESC LIMIT 1
        )
        WHERE " . $scope['sql'] . "
        ORDER BY l.created_at DESC
        LIMIT 250
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($scope['params']);
    $leadsData = $stmt->fetchAll();

    // Today's Follow-ups
    $fupSql = "
        SELECT f.*, l.customer_name as lead_name, l.customer_phone as lead_phone, l.status as lead_status
        FROM follow_ups f 
        JOIN leads l ON l.id = f.lead_id 
        WHERE f.status = 'pending' AND f.follow_up_date <= CURDATE()
    ";
    $fupParams = [];
    if ($filterTelecaller) {
        $fupSql .= " AND (f.employee_id = ? OR l.assigned_to = ?)";
        $fupParams = [(int)$filterTelecaller, (int)$filterTelecaller];
    }
    $fupSql .= " ORDER BY f.follow_up_date ASC, f.created_at DESC LIMIT 50";

    $fupStmt = $db->prepare($fupSql);
    $fupStmt->execute($fupParams);
    $todayFollowUps = $fupStmt->fetchAll();

    // Statistics calculation
    $stats = [
        'total_assigned' => 0,
        'today_calls' => 0,
        'pending_followups' => 0,
        'converted' => 0,
    ];

    try {
        // Total Assigned / Total in Scope
        $s1 = $db->prepare("SELECT COUNT(*) as c FROM leads l WHERE " . $scope['sql']);
        $s1->execute($scope['params']);
        $stats['total_assigned'] = (int)($s1->fetch()['c'] ?? 0);

        // Today's Calls
        if (!$isManager && $eid > 0) {
            $s2 = $db->prepare("SELECT COUNT(*) as c FROM call_reports WHERE employee_id = ? AND DATE(created_at) = CURDATE()");
            $s2->execute([$eid]);
        } else {
            $s2 = $db->query("SELECT COUNT(*) as c FROM call_reports WHERE DATE(created_at) = CURDATE()");
        }
        $stats['today_calls'] = (int)($s2->fetch()['c'] ?? 0);

        // Pending Follow-ups
        if (!$isManager && $eid > 0) {
            $s3 = $db->prepare("SELECT COUNT(*) as c FROM follow_ups f JOIN leads l ON l.id = f.lead_id WHERE (f.employee_id = ? OR l.assigned_to = ?) AND f.status = 'pending' AND f.follow_up_date <= CURDATE()");
            $s3->execute([$eid, $eid]);
        } else {
            $s3 = $db->query("SELECT COUNT(*) as c FROM follow_ups WHERE status = 'pending' AND follow_up_date <= CURDATE()");
        }
        $stats['pending_followups'] = (int)($s3->fetch()['c'] ?? 0);

        // Converted Leads
        $s4 = $db->prepare("SELECT COUNT(*) as c FROM leads l WHERE (" . $scope['sql'] . ") AND (l.status = 'won' OR l.status = 'qualified')");
        $s4->execute($scope['params']);
        $stats['converted'] = (int)($s4->fetch()['c'] ?? 0);
    } catch (Throwable $se) {
        error_log('telecaller stats error: ' . $se->getMessage());
    }

    return [
        'success' => true,
        'data' => [
            'leads' => $leadsData,
            'today_follow_ups' => $todayFollowUps,
            'stats' => $stats,
        ],
    ];
}

function telecallerLeads($db, $auth, $data) {
    $filterTelecaller = !empty($data['telecaller_id']) ? $data['telecaller_id'] : (!empty($data['assigned_to']) ? $data['assigned_to'] : null);
    $scope = buildTelecallerScope($auth, $filterTelecaller);

    $status = $data['status'] ?? '';
    $priority = $data['priority'] ?? '';
    $source = $data['source'] ?? '';
    $search = trim($data['search'] ?? '');
    $followUpFilter = $data['follow_up_filter'] ?? '';
    $sortBy = $data['sort_by'] ?? 'newest';
    $createdBy = $data['created_by'] ?? $data['creator_id'] ?? '';

    $sql = "SELECT l.*, 
                   cr.first_name as creator_first, cr.last_name as creator_last, cr.employee_code as creator_code,
                   ucr.username as creator_username,
                   d.name as creator_department,
                   a.first_name as assigned_first, a.last_name as assigned_last, a.employee_code as assigned_code,
                   crp.status as latest_call_status,
                   crp.call_outcome as latest_call_outcome,
                   crp.notes as latest_call_notes,
                   crp.created_at as latest_call_at
            FROM leads l 
            LEFT JOIN employees cr ON cr.id = COALESCE(l.created_by, l.employee_id)
            LEFT JOIN users ucr ON ucr.id = l.created_by
            LEFT JOIN departments d ON d.id = cr.department_id
            LEFT JOIN employees a ON a.id = l.assigned_to
            LEFT JOIN call_reports crp ON crp.id = (
                SELECT id FROM call_reports WHERE lead_id = l.id ORDER BY id DESC LIMIT 1
            )
            WHERE " . $scope['sql'];
    $params = $scope['params'];

    if ($status !== '') {
        $sql .= " AND l.status = ?";
        $params[] = $status;
    }

    if ($priority !== '') {
        $sql .= " AND l.priority = ?";
        $params[] = $priority;
    }

    if ($source !== '') {
        $sql .= " AND (l.source = ? OR l.lead_source = ?)";
        $params[] = $source;
        $params[] = $source;
    }

    if ($createdBy !== '') {
        $sql .= " AND (l.employee_id = ? OR l.created_by = ?)";
        $params[] = intval($createdBy);
        $params[] = intval($createdBy);
    }
    
    // Follow-up condition filters
    if ($followUpFilter === 'today') {
        $sql .= " AND DATE(l.follow_up_date) = CURDATE()";
    } elseif ($followUpFilter === 'upcoming') {
        $sql .= " AND DATE(l.follow_up_date) > CURDATE()";
    } elseif ($followUpFilter === 'overdue') {
        $sql .= " AND DATE(l.follow_up_date) < CURDATE() AND l.follow_up_date IS NOT NULL AND l.follow_up_date != '' AND l.follow_up_date != '0000-00-00'";
    } elseif ($followUpFilter === 'no_followup' || $followUpFilter === 'no_follow_up') {
        $sql .= " AND (l.follow_up_date IS NULL OR l.follow_up_date = '' OR l.follow_up_date = '0000-00-00')";
    } elseif ($followUpFilter === 'has_followup' || $followUpFilter === 'has_follow_up') {
        $sql .= " AND (l.follow_up_date IS NOT NULL AND l.follow_up_date != '' AND l.follow_up_date != '0000-00-00')";
    }

    // Date range filters
    if (!empty($data['from_date'])) {
        $sql .= " AND DATE(l.created_at) >= ?";
        $params[] = $data['from_date'];
    }
    if (!empty($data['to_date'])) {
        $sql .= " AND DATE(l.created_at) <= ?";
        $params[] = $data['to_date'];
    }

    // Comprehensive multi-field search
    if ($search !== '') {
        $cleanSearch = trim($search);
        $sTerm = "%$cleanSearch%";

        // Normalized digits for phone match
        $digitsOnly = preg_replace('/[^0-9]/', '', $cleanSearch);
        $cleanPhoneTerm = $digitsOnly !== '' ? "%$digitsOnly%" : $sTerm;

        // Check if numeric ID (e.g. #219 or 219)
        $idMatch = ltrim($cleanSearch, '#');
        $isNumericId = ctype_digit($idMatch);

        $sql .= " AND (
            l.customer_name LIKE ? OR 
            l.customer_phone LIKE ? OR 
            l.mobile LIKE ? OR 
            l.alternate_mobile LIKE ? OR
            REPLACE(REPLACE(REPLACE(REPLACE(l.customer_phone, ' ', ''), '-', ''), '+91', ''), '+', '') LIKE ? OR
            REPLACE(REPLACE(REPLACE(REPLACE(l.mobile, ' ', ''), '-', ''), '+91', ''), '+', '') LIKE ? OR
            l.email LIKE ? OR 
            l.company_name LIKE ? OR 
            l.city LIKE ? OR 
            l.requirement LIKE ? OR 
            l.notes LIKE ? OR 
            l.source LIKE ? OR 
            cr.first_name LIKE ? OR 
            cr.last_name LIKE ? OR 
            cr.employee_code LIKE ?
        ";

        $searchParams = [
            $sTerm, $sTerm, $sTerm, $sTerm,
            $cleanPhoneTerm, $cleanPhoneTerm,
            $sTerm, $sTerm, $sTerm, $sTerm, $sTerm, $sTerm, $sTerm, $sTerm, $sTerm
        ];

        if ($isNumericId) {
            $sql .= " OR l.id = ?";
            $searchParams[] = intval($idMatch);
        }

        $sql .= ")";
        $params = array_merge($params, $searchParams);
    }

    // Sort order
    if ($sortBy === 'oldest') {
        $sql .= " ORDER BY l.created_at ASC";
    } elseif ($sortBy === 'follow_up_asc') {
        $sql .= " ORDER BY (l.follow_up_date IS NULL OR l.follow_up_date = '' OR l.follow_up_date = '0000-00-00') ASC, l.follow_up_date ASC, l.created_at DESC";
    } elseif ($sortBy === 'follow_up_desc') {
        $sql .= " ORDER BY (l.follow_up_date IS NULL OR l.follow_up_date = '' OR l.follow_up_date = '0000-00-00') ASC, l.follow_up_date DESC, l.created_at DESC";
    } elseif ($sortBy === 'name_asc') {
        $sql .= " ORDER BY l.customer_name ASC";
    } elseif ($sortBy === 'status_priority') {
        $sql .= " ORDER BY FIELD(l.status,'new','follow_up','interested','calling','connected','busy','no_answer','qualified','not_interested','wrong_number','duplicate','lost','won') ASC, l.created_at DESC";
    } else {
        // Default: Newest leads first (so new leads always appear at top!)
        $sql .= " ORDER BY l.created_at DESC";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createInquiry($db, $auth, $data) {
    $eid = (int)($auth['employee_id'] ?? 0);
    $customerName = Validator::sanitize($data['customer_name'] ?? '');
    $phone = Validator::sanitize($data['phone'] ?? $data['customer_phone'] ?? '');
    $email = Validator::sanitize($data['email'] ?? '');
    $requirement = Validator::sanitize($data['requirement'] ?? '');
    $city = Validator::sanitize($data['city'] ?? '');
    $source = Validator::sanitize($data['source'] ?? 'Call');
    $priority = Validator::sanitize($data['priority'] ?? 'medium');
    $notes = Validator::sanitize($data['notes'] ?? '');
    $followUpDate = trim((string) ($data['follow_up_date'] ?? ''));
    $followUpDate = $followUpDate !== '' ? $followUpDate : null;
    $status = $followUpDate ? 'follow_up' : 'new';

    if (!$customerName) return ['success' => false, 'message' => 'Customer name is required'];
    if (!$phone) return ['success' => false, 'message' => 'Phone is required'];

    $stmt = $db->prepare("INSERT INTO leads (customer_name, first_name, customer_phone, mobile, email, requirement, city, source, lead_source, priority, notes, follow_up_date, status, employee_id, created_by, assigned_to, lead_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inquiry')");
    $stmt->execute([
        $customerName, $customerName, $phone, $phone, $email,
        $requirement, $city, $source, $source, $priority, $notes,
        $followUpDate, $status, $eid ?: null, $eid ?: null, $eid ?: null
    ]);

    $leadId = $db->lastInsertId();

    if ($followUpDate) {
        $db->prepare("INSERT INTO follow_ups (lead_id, employee_id, follow_up_date, notes, status) VALUES (?, ?, ?, ?, 'pending')")
           ->execute([$leadId, $eid ?: null, $followUpDate, $notes ?: 'Initial Inquiry Follow-up']);
    }

    return ['success' => true, 'message' => 'Inquiry created successfully', 'id' => $leadId];
}

/**
 * Normalizes call outcome to standard format and maps to lead status.
 */
function normalizeCallOutcome($outcome) {
    $raw = strtolower(trim($outcome));
    $map = [
        'connected — interested'     => ['outcome' => 'Connected — Interested', 'status' => 'interested'],
        'connected - interested'     => ['outcome' => 'Connected — Interested', 'status' => 'interested'],
        'connected_interested'       => ['outcome' => 'Connected — Interested', 'status' => 'interested'],
        'interested'                 => ['outcome' => 'Connected — Interested', 'status' => 'interested'],

        'connected — not interested' => ['outcome' => 'Connected — Not Interested', 'status' => 'not_interested'],
        'connected - not interested' => ['outcome' => 'Connected — Not Interested', 'status' => 'not_interested'],
        'connected_not_interested'   => ['outcome' => 'Connected — Not Interested', 'status' => 'not_interested'],
        'not_interested'             => ['outcome' => 'Connected — Not Interested', 'status' => 'not_interested'],

        'no answer'                  => ['outcome' => 'No Answer', 'status' => 'no_answer'],
        'no_answer'                  => ['outcome' => 'No Answer', 'status' => 'no_answer'],

        'busy'                       => ['outcome' => 'Busy', 'status' => 'busy'],

        'switched off'               => ['outcome' => 'Switched Off', 'status' => 'no_answer'],
        'switched_off'               => ['outcome' => 'Switched Off', 'status' => 'no_answer'],

        'invalid number'             => ['outcome' => 'Invalid Number', 'status' => 'wrong_number'],
        'invalid_number'             => ['outcome' => 'Invalid Number', 'status' => 'wrong_number'],
        'wrong number'               => ['outcome' => 'Invalid Number', 'status' => 'wrong_number'],
        'wrong_number'               => ['outcome' => 'Invalid Number', 'status' => 'wrong_number'],

        'call back required'         => ['outcome' => 'Call Back Required', 'status' => 'follow_up'],
        'callback_required'          => ['outcome' => 'Call Back Required', 'status' => 'follow_up'],
        'callback'                   => ['outcome' => 'Call Back Required', 'status' => 'follow_up'],

        'follow-up scheduled'        => ['outcome' => 'Follow-up Scheduled', 'status' => 'follow_up'],
        'follow up scheduled'        => ['outcome' => 'Follow-up Scheduled', 'status' => 'follow_up'],
        'follow_up_scheduled'        => ['outcome' => 'Follow-up Scheduled', 'status' => 'follow_up'],
        'follow_up'                  => ['outcome' => 'Follow-up Scheduled', 'status' => 'follow_up'],

        'meeting requested'          => ['outcome' => 'Meeting Requested', 'status' => 'meeting_scheduled'],
        'meeting_requested'          => ['outcome' => 'Meeting Requested', 'status' => 'meeting_scheduled'],

        'converted / successful'     => ['outcome' => 'Converted / Successful', 'status' => 'won'],
        'converted'                  => ['outcome' => 'Converted / Successful', 'status' => 'won'],
        'won'                        => ['outcome' => 'Converted / Successful', 'status' => 'won'],

        'connected'                  => ['outcome' => 'Connected', 'status' => 'connected'],
        'other'                      => ['outcome' => 'Other', 'status' => 'contacted'],
    ];

    foreach ($map as $key => $val) {
        if ($raw === $key || strpos($raw, $key) !== false) {
            return $val;
        }
    }

    return ['outcome' => ucfirst(str_replace('_', ' ', $outcome)), 'status' => 'connected'];
}

/**
 * Immediate Call Feedback endpoint.
 * Saves feedback entry to call_reports (preserving call history).
 * Updates lead status, latest call feedback, and creates follow-up if scheduled.
 */
function saveCallFeedback($db, $auth, $data) {
    $eid = (int)($auth['employee_id'] ?? 0);
    $uid = (int)($auth['user_id'] ?? 0);
    $leadId = intval($data['lead_id'] ?? $data['id'] ?? 0);
    $rawOutcome = Validator::sanitize($data['call_status'] ?? $data['call_outcome'] ?? $data['status'] ?? '');
    $notes = Validator::sanitize($data['notes'] ?? '');
    $callDuration = intval($data['call_duration'] ?? 0);

    // Follow-up date & time
    $followUpDate = trim((string)($data['follow_up_date'] ?? ''));
    $followUpTime = trim((string)($data['follow_up_time'] ?? ''));

    if (!$leadId) {
        return ['success' => false, 'message' => 'Lead ID is required'];
    }

    if (empty($rawOutcome)) {
        return ['success' => false, 'message' => 'Call outcome is required'];
    }

    // Verify lead exists and user has access
    $leadStmt = $db->prepare("SELECT id, customer_name, customer_phone, mobile, status, assigned_to, employee_id, created_by FROM leads WHERE id = ?");
    $leadStmt->execute([$leadId]);
    $lead = $leadStmt->fetch();

    if (!$lead) {
        return ['success' => false, 'message' => 'Lead not found'];
    }

    // Shared leads policy: Any active telecaller or manager can record feedback and follow-up on any lead

    $normalized = normalizeCallOutcome($rawOutcome);
    $callOutcome = $normalized['outcome'];
    $mappedStatus = $normalized['status'];

    $customerName = $lead['customer_name'] ?? 'Customer';
    $customerPhone = !empty($lead['customer_phone']) ? $lead['customer_phone'] : ($lead['mobile'] ?? '');

    // Format follow-up timestamp if date provided
    $fullFollowUpDateTime = null;
    if (!empty($followUpDate)) {
        if (strpos($followUpDate, ' ') !== false) {
            $fullFollowUpDateTime = date('Y-m-d H:i:s', strtotime($followUpDate));
            if (empty($followUpTime)) {
                $followUpTime = date('h:i A', strtotime($followUpDate));
            }
        } else {
            $timePart = !empty($followUpTime) ? date('H:i:s', strtotime($followUpTime)) : '10:30:00';
            $fullFollowUpDateTime = date('Y-m-d H:i:s', strtotime("$followUpDate $timePart"));
        }
    }

    $db->beginTransaction();
    try {
        // 1. Insert into call_reports (stores in call history, never overwrites!)
        $reportStmt = $db->prepare("
            INSERT INTO call_reports 
                (employee_id, user_id, lead_id, customer_name, customer_phone, call_type, status, call_outcome, notes, call_duration, call_date, follow_up_date, follow_up_time, follow_up_required, created_at)
            VALUES (?, ?, ?, ?, ?, 'outgoing', ?, ?, ?, ?, CURDATE(), ?, ?, ?, NOW())
        ");
        $reportStmt->execute([
            $eid ?: null,
            $uid ?: null,
            $leadId,
            $customerName,
            $customerPhone,
            $mappedStatus,
            $callOutcome,
            $notes,
            $callDuration,
            $fullFollowUpDateTime,
            $followUpTime ?: null,
            $fullFollowUpDateTime ? 1 : 0
        ]);
        $callReportId = $db->lastInsertId();

        // 2. If follow-up scheduled, insert into follow_ups table
        if ($fullFollowUpDateTime) {
            $fupDateOnly = date('Y-m-d', strtotime($fullFollowUpDateTime));
            $fupStmt = $db->prepare("
                INSERT INTO follow_ups (lead_id, employee_id, follow_up_date, follow_up_time, notes, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $fupStmt->execute([
                $leadId,
                $eid ?: null,
                $fupDateOnly,
                $followUpTime ?: null,
                $notes ?: "Scheduled follow-up: $callOutcome"
            ]);
        }

        // 3. Update lead status & latest feedback cache
        $leadUpdates = [
            "latest_call_status = ?",
            "latest_call_notes = ?",
            "latest_call_at = NOW()",
        ];
        $leadParams = [$callOutcome, $notes];

        // Only update lead status if not already closed/qualified in sales pipeline
        $pipelineStatuses = ['meeting_scheduled','demo_scheduled','quotation_sent','negotiation','won','lost','closed'];
        if (!in_array($lead['status'], $pipelineStatuses) || $mappedStatus === 'won') {
            $leadUpdates[] = "status = ?";
            $leadParams[] = $mappedStatus;
        }

        if ($fullFollowUpDateTime) {
            $leadUpdates[] = "follow_up_date = ?";
            $leadParams[] = date('Y-m-d', strtotime($fullFollowUpDateTime));
            $leadUpdates[] = "follow_up_time = ?";
            $leadParams[] = $followUpTime ?: null;
            $leadUpdates[] = "next_action = ?";
            $leadParams[] = $callOutcome;
        }

        if (!empty($notes)) {
            $leadUpdates[] = "notes = CONCAT_WS('\n', notes, ?)";
            $leadParams[] = '[' . date('d-m-Y H:i') . ' ' . $callOutcome . '] ' . $notes;
        }

        $leadParams[] = $leadId;
        $db->prepare("UPDATE leads SET " . implode(', ', $leadUpdates) . " WHERE id = ?")->execute($leadParams);

        $db->commit();

        return [
            'success' => true,
            'message' => 'Call feedback saved successfully',
            'id' => $callReportId,
            'outcome' => $callOutcome,
            'status' => $mappedStatus,
            'follow_up_date' => $fullFollowUpDateTime
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('saveCallFeedback error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save feedback: ' . $e->getMessage()];
    }
}

/**
 * Returns previous call feedback entries for a lead (call history).
 */
function getCallFeedbackHistory($db, $auth, $data) {
    $leadId = intval($data['lead_id'] ?? $data['id'] ?? 0);
    if (!$leadId) return ['success' => false, 'message' => 'Lead ID required'];

    $stmt = $db->prepare("
        SELECT cr.*, e.first_name, e.last_name, e.employee_code, u.username
        FROM call_reports cr
        LEFT JOIN employees e ON e.id = cr.employee_id
        LEFT JOIN users u ON u.id = cr.user_id
        WHERE cr.lead_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([$leadId]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function createFollowUp($db, $auth, $data) {
    $eid = (int)($auth['employee_id'] ?? 0);
    $leadId = intval($data['lead_id'] ?? 0);
    $followUpDate = Validator::sanitize($data['follow_up_date'] ?? '');
    $followUpTime = Validator::sanitize($data['follow_up_time'] ?? '');
    $notes = Validator::sanitize($data['notes'] ?? '');

    if (!$leadId || !$followUpDate) return ['success' => false, 'message' => 'Lead ID and follow-up date required'];

    $db->prepare("INSERT INTO follow_ups (lead_id, employee_id, follow_up_date, follow_up_time, notes, status) VALUES (?, ?, ?, ?, ?, 'pending')")->execute([$leadId, $eid ?: null, $followUpDate, $followUpTime ?: null, $notes]);
    $db->prepare("UPDATE leads SET status = 'follow_up', follow_up_date = ?, follow_up_time = ? WHERE id = ?")->execute([$followUpDate, $followUpTime ?: null, $leadId]);

    return ['success' => true, 'message' => 'Follow-up scheduled', 'id' => $db->lastInsertId()];
}

function qualifyLead($db, $auth, $data) {
    $eid = (int)($auth['employee_id'] ?? 0);
    $leadId = intval($data['lead_id'] ?? 0);
    if (!$leadId) return ['success' => false, 'message' => 'Lead ID required'];

    $lead = $db->prepare("SELECT customer_name, status, assigned_sales, assigned_to FROM leads WHERE id = ?");
    $lead->execute([$leadId]);
    $l = $lead->fetch();
    if (!$l) return ['success' => false, 'message' => 'Lead not found'];

    // Any telecaller can qualify any lead

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
    $eid = (int)($auth['employee_id'] ?? 0);
    $leadId = intval($data['lead_id'] ?? $data['id'] ?? 0);
    $status = Validator::sanitize($data['status'] ?? '');
    $notes = Validator::sanitize($data['notes'] ?? '');

    $allowed = ['new','calling','connected','busy','no_answer','follow_up','interested','qualified','not_interested','wrong_number','duplicate','lost','won'];
    if (!in_array($status, $allowed)) return ['success' => false, 'message' => 'Invalid status'];

    $db->prepare("UPDATE leads SET status = ?, notes = CONCAT_WS('\n',notes,?) WHERE id = ?")->execute([$status, $notes ? '[' . date('Y-m-d H:i') . '] ' . $notes : '', $leadId]);

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
