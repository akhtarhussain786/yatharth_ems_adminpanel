<?php
function handleLeaveRequest($action, $param) {
    $db = (new Database())->getConnection();
    $auth = AuthMiddleware::authenticate();
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'apply':
            return applyLeave($db, $auth, $data);
        case 'my':
            return getMyLeaves($db, $auth);
        case 'details':
            return getLeaveDetails($db, $auth, $param);
        case 'list':
            return getLeaveList($db, $auth, $data);
        case 'approve':
            return approveLeave($db, $auth, $param, $data);
        case 'reject':
            return rejectLeave($db, $auth, $param, $data);
        case 'balance':
            return getLeaveBalance($db, $auth);
        case 'calendar':
            return getLeaveCalendar($db, $auth, $data);
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

function applyLeave($db, $auth, $data) {
    $eid = $auth['employee_id'];
    $empCheck = $db->prepare("SELECT id, first_name, last_name FROM employees WHERE id = ?");
    $empCheck->execute([$eid]);
    $emp = $empCheck->fetch();
    if (!$emp) return ['success' => false, 'message' => 'Employee not found'];

    $leaveType = Validator::sanitize($data['leave_type'] ?? '');
    $fromDate = Validator::sanitize($data['from_date'] ?? '');
    $toDate = Validator::sanitize($data['to_date'] ?? '');
    $dayType = Validator::sanitize($data['day_type'] ?? 'Full Day'); // Full Day / Half Day
    $reason = Validator::sanitize($data['reason'] ?? '');
    $attachment = $data['attachment'] ?? '';

    // Standardize type mapping
    $typeMap = [
        'CL' => 'Casual Leave',
        'Casual' => 'Casual Leave',
        'Casual Leave' => 'Casual Leave',
        'SL' => 'Sick Leave',
        'Sick' => 'Sick Leave',
        'Sick Leave' => 'Sick Leave',
        'EL' => 'Earned Leave',
        'PL' => 'Earned Leave',
        'Earned' => 'Earned Leave',
        'Earned Leave' => 'Earned Leave',
        'Privilege Leave' => 'Earned Leave',
        'Half Day' => 'Half Day',
        'LWP' => 'Leave Without Pay',
        'Unpaid' => 'Leave Without Pay',
        'Leave Without Pay' => 'Leave Without Pay',
        'Work From Home' => 'Work From Home'
    ];

    if (isset($typeMap[$leaveType])) {
        $leaveType = $typeMap[$leaveType];
    } else {
        return ['success' => false, 'message' => 'Invalid leave type selected'];
    }

    if (!$fromDate || !$reason) {
        return ['success' => false, 'message' => 'From date and reason are required'];
    }

    if (!$toDate) $toDate = $fromDate;
    if (strtotime($toDate) < strtotime($fromDate)) {
        return ['success' => false, 'message' => 'To date must be on or after from date'];
    }

    // Calculate total days
    $totalDays = 1.0;
    if ($dayType === 'Half Day' || $leaveType === 'Half Day') {
        $totalDays = 0.5;
        $dayType = 'Half Day';
    } else {
        $dayType = 'Full Day';
        $totalDays = (float)(floor((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1);
    }

    // Handle base64 attachment upload
    $attachmentPath = null;
    if ($attachment && strpos($attachment, 'base64,') !== false) {
        $targetDir = UPLOAD_PATH . 'leave_requests/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        
        $ext = 'jpg';
        if (preg_match('/^data:image\/(\w+);base64,/', $attachment, $m)) {
            $ext = strtolower($m[1]);
        } elseif (preg_match('/^data:application\/pdf;base64,/', $attachment)) {
            $ext = 'pdf';
        }

        $base64Data = substr($attachment, strpos($attachment, ',') + 1);
        $decodedData = base64_decode($base64Data);

        if ($decodedData) {
            $filename = 'leave_' . $eid . '_' . time() . '.' . $ext;
            file_put_contents($targetDir . $filename, $decodedData);
            $attachmentPath = 'uploads/leave_requests/' . $filename;
        }
    }

    $stmt = $db->prepare("
        INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, day_type, total_days, reason, attachment, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
    ");
    $stmt->execute([$eid, $leaveType, $fromDate, $toDate, $dayType, $totalDays, $reason, $attachmentPath]);
    $leaveId = $db->lastInsertId();

    // Send internal DB notifications + FCM Push Notifications to Admins
    $empName = $emp['first_name'] . ' ' . $emp['last_name'];
    $title = "New Leave Request";
    $message = "$empName requested $totalDays day(s) $leaveType ($fromDate to $toDate)";

    $admins = $db->query("SELECT id FROM users WHERE role_id IN (SELECT id FROM roles WHERE name IN ('super_admin','hr','hr_admin'))")->fetchAll();
    $adminIds = array_column($admins, 'id');

    if (!empty($adminIds)) {
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'leave', NOW())");
        foreach ($adminIds as $aid) {
            $notifStmt->execute([$aid, $title, $message]);
        }
        // FCM Push
        FCMHelper::sendToUsers($db, $adminIds, $title, $message, ['type' => 'leave_request', 'leave_id' => $leaveId]);
    }

    return [
        'success' => true,
        'message' => 'Leave application submitted successfully',
        'id' => $leaveId
    ];
}

function getLeaveDetails($db, $auth, $id) {
    $stmt = $db->prepare("
        SELECT l.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
        FROM leave_requests l
        JOIN employees e ON e.id = l.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE l.id = ?
    ");
    $stmt->execute([$id]);
    $leave = $stmt->fetch();
    if (!$leave) return ['success' => false, 'message' => 'Leave request not found'];

    if (!empty($leave['attachment'])) {
        $leave['attachment_url'] = BASE_URL . $leave['attachment'];
    }

    return ['success' => true, 'data' => $leave];
}

function getMyLeaves($db, $auth) {
    $eid = $auth['employee_id'];
    $stmt = $db->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC");
    $stmt->execute([$eid]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        if (!empty($r['attachment'])) {
            $r['attachment_url'] = BASE_URL . $r['attachment'];
        }
    }

    return ['success' => true, 'data' => $rows];
}

function getLeaveList($db, $auth, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leave_requests', 'can_view');
    $deptFilter = $data['department_id'] ?? $_GET['department'] ?? '';
    $statusFilter = $data['status'] ?? $_GET['status'] ?? '';
    $search = $data['search'] ?? $_GET['search'] ?? '';

    $sql = "SELECT l.*, e.first_name, e.last_name, e.employee_code, d.name as department_name 
            FROM leave_requests l 
            JOIN employees e ON e.id = l.employee_id 
            LEFT JOIN departments d ON d.id = e.department_id 
            WHERE 1=1";
    $params = [];

    if ($deptFilter) { $sql .= " AND e.department_id = ?"; $params[] = $deptFilter; }
    if ($statusFilter) { $sql .= " AND l.status = ?"; $params[] = $statusFilter; }
    if ($search) { 
        $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; 
    }

    $sql .= " ORDER BY FIELD(l.status,'Pending','Approved','Rejected'), l.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        if (!empty($r['attachment'])) {
            $r['attachment_url'] = BASE_URL . $r['attachment'];
        }
    }

    return ['success' => true, 'data' => $rows];
}

function approveLeave($db, $auth, $id, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leave_requests', 'can_edit');
    $remarks = Validator::sanitize($data['remarks'] ?? '');

    // Only a pending request may be approved. Without this guard a second click
    // re-ran the side effects and deducted the balance twice.
    $stmt = $db->prepare("UPDATE leave_requests SET status='Approved', approved_by=?, approved_at=NOW(), remarks=? WHERE id=? AND status='Pending'");
    $stmt->execute([$auth['user_id'], $remarks ?: null, $id]);

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'message' => 'This request is no longer pending'];
    }

    $stmt2 = $db->prepare("SELECT l.*, e.user_id, e.id as emp_id FROM leave_requests l JOIN employees e ON e.id = l.employee_id WHERE l.id = ?");
    $stmt2->execute([$id]);
    $leave = $stmt2->fetch();

    if ($leave) {
        $from = $leave['start_date'];
        $to = $leave['end_date'];

        // A half day leaves half the day workable, so it is recorded as a half
        // day rather than as full leave — that is what makes the salary report
        // pay 0.5 for it.
        $attStatus = (($leave['day_type'] ?? 'Full Day') === 'Half Day') ? 'half-day' : 'leave';

        $period = new DatePeriod(new DateTime($from), new DateInterval('P1D'), (new DateTime($to))->modify('+1 day'));
        foreach ($period as $d) {
            $dt = $d->format('Y-m-d');
            $db->prepare("INSERT INTO attendance (employee_id, attendance_date, status, leave_type) VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE status=VALUES(status), leave_type=VALUES(leave_type)")
               ->execute([$leave['emp_id'], $dt, $attStatus, $leave['leave_type']]);
        }

        // Update leave balance table
        $currentYear = date('Y');
        $db->prepare("
            INSERT INTO leave_balances (employee_id, leave_type, used, year)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE used = used + VALUES(used)
        ")->execute([$leave['emp_id'], $leave['leave_type'], $leave['total_days'], $currentYear]);

        // Push notification
        $uid = $leave['user_id'] ?? null;
        if ($uid) {
            $title = "Leave Approved";
            $msg = "Your " . $leave['leave_type'] . " (" . $from . " to " . $to . ") has been approved.";
            if ($remarks) $msg .= " Remarks: " . $remarks;

            $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'leave')")->execute([$uid, $title, $msg]);
            FCMHelper::sendToUsers($db, [$uid], $title, $msg, ['type' => 'leave_approved', 'leave_id' => $id]);
        }
    }

    return ['success' => true, 'message' => 'Leave approved successfully'];
}

/**
 * Undoes the side effects of an approval.
 *
 * Rejecting an already-approved leave used to only relabel the request: the
 * attendance rows stayed marked as leave (so the days were still paid) and the
 * balance stayed consumed. Both are reversed here.
 */
function revertApprovedLeave($db, $leave) {
    try {
        // Clear the attendance rows this approval created. Only rows still
        // carrying this leave type are touched, so a later correction or a real
        // check-in on the same day is left alone.
        $db->prepare("DELETE FROM attendance
                      WHERE employee_id = ?
                        AND attendance_date BETWEEN ? AND ?
                        AND leave_type = ?
                        AND check_in IS NULL")
           ->execute([$leave['emp_id'], $leave['start_date'], $leave['end_date'], $leave['leave_type']]);

        // Give the days back, never dropping below zero.
        $db->prepare("UPDATE leave_balances
                      SET used = GREATEST(used - ?, 0)
                      WHERE employee_id = ? AND leave_type = ? AND year = ?")
           ->execute([
               $leave['total_days'],
               $leave['emp_id'],
               $leave['leave_type'],
               (int) date('Y', strtotime($leave['start_date'])),
           ]);
    } catch (Exception $e) {
        error_log('revertApprovedLeave: ' . $e->getMessage());
    }
}

function rejectLeave($db, $auth, $id, $data) {
    PermissionHelper::checkPermission($db, $auth, 'leave_requests', 'can_edit');
    $remarks = Validator::sanitize($data['remarks'] ?? '');

    // Read the current state first: rejecting something already approved has to
    // undo the approval, not just relabel it.
    $before = $db->prepare("SELECT l.*, e.id as emp_id FROM leave_requests l JOIN employees e ON e.id = l.employee_id WHERE l.id = ?");
    $before->execute([$id]);
    $prior = $before->fetch();

    if (!$prior) return ['success' => false, 'message' => 'Leave request not found'];
    if ($prior['status'] === 'Rejected') {
        return ['success' => false, 'message' => 'This request is already rejected'];
    }

    $stmt = $db->prepare("UPDATE leave_requests SET status='Rejected', approved_by=?, approved_at=NOW(), remarks=? WHERE id=?");
    $stmt->execute([$auth['user_id'], $remarks ?: null, $id]);

    if ($prior['status'] === 'Approved') {
        revertApprovedLeave($db, $prior);
    }

    $stmt2 = $db->prepare("SELECT l.*, e.user_id FROM leave_requests l JOIN employees e ON e.id = l.employee_id WHERE l.id = ?");
    $stmt2->execute([$id]);
    $leave = $stmt2->fetch();

    if ($leave) {
        $uid = $leave['user_id'] ?? null;
        if ($uid) {
            $title = "Leave Rejected";
            $reasonText = $remarks ? " Reason: $remarks" : "";
            $msg = "Your " . $leave['leave_type'] . " request (" . $leave['start_date'] . " to " . $leave['end_date'] . ") was rejected." . $reasonText;

            $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'leave')")->execute([$uid, $title, $msg]);
            FCMHelper::sendToUsers($db, [$uid], $title, $msg, ['type' => 'leave_rejected', 'leave_id' => $id]);
        }
    }

    return ['success' => true, 'message' => 'Leave request rejected'];
}

/** Opening balance for Earned Leave can never exceed one year's allotment. */
define('LEAVE_EARNED_CARRY_CAP', 15.0);

/** Yearly entitlement per leave type. Half Day and LWP are not entitlements. */
function leaveAllocations() {
    return [
        'Casual Leave'       => 8.0,
        'Sick Leave'         => 8.0,
        'Earned Leave'       => 15.0,
        'Half Day'           => 0.0,
        'Leave Without Pay'  => 0.0,
    ];
}

/**
 * Unused Earned Leave from the previous year.
 *
 * Derived from last year's approved requests rather than a stored figure, so it
 * stays right even where leave_balances was never populated.
 */
function earnedLeaveCarriedForward($db, $employeeId, $year) {
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_days), 0) AS used
                              FROM leave_requests
                              WHERE employee_id = ? AND leave_type = 'Earned Leave'
                                AND status = 'Approved' AND YEAR(start_date) = ?");
        $stmt->execute([$employeeId, $year - 1]);
        $usedLastYear = (float) $stmt->fetchColumn();

        $allocations = leaveAllocations();
        return max(0.0, $allocations['Earned Leave'] - $usedLastYear);
    } catch (Exception $e) {
        error_log('earnedLeaveCarriedForward: ' . $e->getMessage());
        return 0.0;
    }
}

function getLeaveBalance($db, $auth) {
    $eid = $auth['employee_id'];
    $currentYear = date('Y');

    $allocations = leaveAllocations();

    // Earned Leave is transferable: whatever went unused last year is added on
    // top, but the opening balance is capped at one year's allotment.
    $carried = earnedLeaveCarriedForward($db, $eid, (int) $currentYear);
    if ($carried > 0) {
        $allocations['Earned Leave'] = min(
            $allocations['Earned Leave'] + $carried,
            LEAVE_EARNED_CARRY_CAP
        );
    }

    // Fetch custom allocations if present in leave_balances
    $balStmt = $db->prepare("SELECT leave_type, allotted, used FROM leave_balances WHERE employee_id = ? AND year = ?");
    $balStmt->execute([$eid, $currentYear]);
    $dbBalances = $balStmt->fetchAll();

    $customAllotted = [];
    $customUsed = [];
    foreach ($dbBalances as $b) {
        if ($b['allotted'] > 0) $customAllotted[$b['leave_type']] = (float)$b['allotted'];
        $customUsed[$b['leave_type']] = (float)$b['used'];
    }

    // Also calculate dynamically from approved leave_requests for fallback accuracy
    $reqStmt = $db->prepare("SELECT leave_type, SUM(total_days) as used_days FROM leave_requests WHERE employee_id = ? AND YEAR(start_date) = ? AND status = 'Approved' GROUP BY leave_type");
    $reqStmt->execute([$eid, $currentYear]);
    $reqUsed = $reqStmt->fetchAll();

    foreach ($reqUsed as $ru) {
        $type = $ru['leave_type'];
        $usedVal = (float)$ru['used_days'];
        if (!isset($customUsed[$type]) || $usedVal > $customUsed[$type]) {
            $customUsed[$type] = $usedVal;
        }
    }

    $balances = [];
    foreach ($allocations as $type => $defaultAllotted) {
        $allotted = $customAllotted[$type] ?? $defaultAllotted;
        $used = $customUsed[$type] ?? 0.0;
        $remaining = max(0.0, $allotted - $used);

        $balances[] = [
            'leave_type' => $type,
            'allotted'   => $allotted,
            'used'       => $used,
            'remaining'  => $remaining
        ];
    }

    return ['success' => true, 'data' => $balances];
}

function getLeaveCalendar($db, $auth, $data) {
    $deptId = $data['department_id'] ?? $_GET['department_id'] ?? null;
    $empId  = $data['employee_id']   ?? $_GET['employee_id']   ?? null;
    $month  = filterMonth($data['month'] ?? $_GET['month'] ?? '');

    $sql = "SELECT l.*, e.first_name, e.last_name, e.employee_code, d.name as department_name
            FROM leave_requests l
            JOIN employees e ON e.id = l.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE l.status IN ('Approved', 'Pending') 
              AND (DATE_FORMAT(l.start_date, '%Y-%m') = ? OR DATE_FORMAT(l.end_date, '%Y-%m') = ?)";
    
    $params = [$month, $month];

    if ($deptId) {
        $sql .= " AND e.department_id = ?";
        $params[] = $deptId;
    }
    if ($empId) {
        $sql .= " AND l.employee_id = ?";
        $params[] = $empId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $events = [];
    foreach ($rows as $r) {
        $color = $r['status'] === 'Approved' ? '#28a745' : '#ffc107';
        $events[] = [
            'id' => $r['id'],
            'title' => $r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['leave_type'] . ')',
            'start' => $r['start_date'],
            'end' => date('Y-m-d', strtotime($r['end_date'] . ' +1 day')), // FullCalendar end date exclusive
            'color' => $color,
            'status' => $r['status'],
            'leave_type' => $r['leave_type'],
            'employee_name' => $r['first_name'] . ' ' . $r['last_name'],
            'department' => $r['department_name'] ?? 'N/A',
            'reason' => $r['reason']
        ];
    }

    return ['success' => true, 'data' => $events];
}
