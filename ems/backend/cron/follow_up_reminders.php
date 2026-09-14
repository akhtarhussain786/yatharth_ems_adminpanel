<?php
/**
 * Daily Follow-up Reminder Cron Job
 * 
 * Recommended execution: Daily at 10:00 AM IST via cPanel Cron
 * Command: php /path/to/ems/backend/cron/follow_up_reminders.php
 */

date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/fcm_helper.php';
require_once __DIR__ . '/../helpers/schema_guard.php';
require_once __DIR__ . '/../helpers/schema_migrations.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Ensure migrations are run if needed
    if (function_exists('runSchemaMigrations')) {
        runSchemaMigrations($db);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit(1);
}

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');
$stats = [
    'date' => $today,
    'started_at' => $now,
    'total_leads_due' => 0,
    'total_followups_due' => 0,
    'telecallers_notified' => 0,
    'skipped_already_sent' => 0,
    'details' => []
];

// Map follow-up items per telecaller/user: user_id => [ 'user_name' => ..., 'items' => [...] ]
$userFollowUps = [];

// 1. Fetch leads due today
try {
    $leadStmt = $db->prepare("
        SELECT 
            l.id as lead_id,
            l.customer_name,
            COALESCE(l.customer_phone, l.mobile, '') as phone,
            l.follow_up_date,
            l.follow_up_type,
            l.status,
            l.assigned_to as employee_id,
            e.user_id,
            TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))) as employee_name
        FROM leads l
        INNER JOIN employees e ON e.id = l.assigned_to
        WHERE DATE(l.follow_up_date) = ?
          AND l.status NOT IN ('won', 'lost', 'closed', 'duplicate')
          AND e.user_id IS NOT NULL
          AND e.status = 1
    ");
    $leadStmt->execute([$today]);
    $dueLeads = $leadStmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['total_leads_due'] = count($dueLeads);

    foreach ($dueLeads as $dl) {
        $uid = intval($dl['user_id']);
        if (!$uid) continue;
        if (!isset($userFollowUps[$uid])) {
            $userFollowUps[$uid] = [
                'user_id' => $uid,
                'name' => $dl['employee_name'] ?: 'Telecaller',
                'items' => []
            ];
        }
        $userFollowUps[$uid]['items'][] = [
            'lead_id' => $dl['lead_id'],
            'customer_name' => $dl['customer_name'] ?: 'Customer',
            'phone' => $dl['phone'],
            'type' => $dl['follow_up_type'] ?: 'Call',
            'source_table' => 'leads'
        ];
    }
} catch (Throwable $e) {
    error_log("Cron follow-up leads query error: " . $e->getMessage());
}

// 2. Fetch follow_ups table items due today (if table exists)
try {
    $checkTable = $db->query("SHOW TABLES LIKE 'follow_ups'")->fetch();
    if ($checkTable) {
        $fupStmt = $db->prepare("
            SELECT 
                f.id as followup_id,
                f.lead_id,
                f.follow_up_date,
                f.follow_up_time,
                f.remarks,
                f.status,
                COALESCE(f.assigned_to, l.assigned_to) as employee_id,
                e.user_id,
                TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))) as employee_name,
                l.customer_name,
                COALESCE(l.customer_phone, l.mobile, '') as phone
            FROM follow_ups f
            LEFT JOIN leads l ON l.id = f.lead_id
            LEFT JOIN employees e ON e.id = COALESCE(f.assigned_to, l.assigned_to)
            WHERE DATE(f.follow_up_date) = ?
              AND f.status NOT IN ('completed', 'cancelled')
              AND e.user_id IS NOT NULL
              AND e.status = 1
        ");
        $fupStmt->execute([$today]);
        $dueFups = $fupStmt->fetchAll(PDO::FETCH_ASSOC);
        $stats['total_followups_due'] = count($dueFups);

        foreach ($dueFups as $df) {
            $uid = intval($df['user_id']);
            if (!$uid) continue;
            if (!isset($userFollowUps[$uid])) {
                $userFollowUps[$uid] = [
                    'user_id' => $uid,
                    'name' => $df['employee_name'] ?: 'Telecaller',
                    'items' => []
                ];
            }
            // Avoid duplicate lead item if already listed from leads query
            $leadId = $df['lead_id'];
            $exists = false;
            foreach ($userFollowUps[$uid]['items'] as $it) {
                if ($it['lead_id'] == $leadId) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $userFollowUps[$uid]['items'][] = [
                    'lead_id' => $leadId,
                    'customer_name' => $df['customer_name'] ?: 'Customer',
                    'phone' => $df['phone'],
                    'type' => 'Follow-up',
                    'source_table' => 'follow_ups'
                ];
            }
        }
    }
} catch (Throwable $e) {
    error_log("Cron follow-up table query error: " . $e->getMessage());
}

// 3. Process notifications per telecaller/user
foreach ($userFollowUps as $uid => $data) {
    $items = $data['items'];
    $count = count($items);
    if ($count === 0) continue;

    // Check deduplication: Did we already send a 10 AM / daily follow_up_reminder to this user today?
    $checkStmt = $db->prepare("
        SELECT id FROM notifications 
        WHERE user_id = ? 
          AND type = 'follow_up_reminder' 
          AND DATE(created_at) = ? 
        LIMIT 1
    ");
    $checkStmt->execute([$uid, $today]);
    if ($checkStmt->fetch()) {
        $stats['skipped_already_sent']++;
        $stats['details'][] = [
            'user_id' => $uid,
            'name' => $data['name'],
            'status' => 'skipped_already_sent',
            'count' => $count
        ];
        continue;
    }

    // Insert individual in-app notification rows for each follow up item
    $notifInsert = $db->prepare("
        INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
        VALUES (?, ?, ?, 'follow_up', ?, 0, NOW())
    ");
    foreach ($items as $item) {
        $leadTitle = "Follow-up Due Today: " . $item['customer_name'];
        $leadMsg = "Scheduled follow-up with " . $item['customer_name'] . ($item['phone'] ? " ({$item['phone']})" : "") . " is due today.";
        $leadLink = "/leads/" . $item['lead_id'];
        try {
            $notifInsert->execute([$uid, $leadTitle, $leadMsg, $leadLink]);
        } catch (Throwable $e) {
            error_log("Failed to insert lead notification: " . $e->getMessage());
        }
    }

    // Record the daily summary reminder row in notifications table for deduplication & summary tray
    $summaryTitle = "Today's Follow-up Reminder";
    $summaryBody = $count === 1
        ? "You have 1 follow-up scheduled for today."
        : "You have {$count} follow-ups scheduled for today.";
    
    try {
        $sumInsert = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
            VALUES (?, ?, ?, 'follow_up_reminder', '/telecaller', 0, NOW())
        ");
        $sumInsert->execute([$uid, $summaryTitle, $summaryBody]);
    } catch (Throwable $e) {
        error_log("Failed to insert summary notification: " . $e->getMessage());
    }

    // Send 1 single consolidated Push Notification via FCM
    $fcmResult = null;
    try {
        $fcmResult = FCMHelper::sendToUsers(
            $db,
            [$uid],
            $summaryTitle,
            $summaryBody,
            [
                'type' => 'follow_up_reminder',
                'count' => strval($count),
                'date' => $today,
                'screen' => 'telecaller'
            ]
        );
    } catch (Throwable $e) {
        error_log("FCM send error for user $uid: " . $e->getMessage());
        $fcmResult = ['success' => false, 'error' => $e->getMessage()];
    }

    $stats['telecallers_notified']++;
    $stats['details'][] = [
        'user_id' => $uid,
        'name' => $data['name'],
        'status' => 'notified',
        'follow_ups_count' => $count,
        'fcm_result' => $fcmResult
    ];
}

$stats['completed_at'] = date('Y-m-d H:i:s');
echo json_encode([
    'success' => true,
    'message' => 'Daily follow-up reminders processed successfully.',
    'stats' => $stats
], JSON_PRETTY_PRINT);
