<?php

require_once '../includes/config.php';

if (!isLoggedIn()) redirect('../index.php');

requireModuleAccess('holidays');
require_once __DIR__ . '/../../backend/helpers/fcm_helper.php';

// =============================================
// AUTO-GENERATE SUNDAYS AS WEEKLY OFF
// =============================================
function generateSundays($month, $year) {
    $sundays = [];
    $date = new DateTime("$year-$month-01");
    $endDate = new DateTime("$year-$month-" . $date->format('t'));
    
    while ($date <= $endDate) {
        if ($date->format('w') == 0) { // 0 = Sunday
            $sundays[] = $date->format('Y-m-d');
        }
        $date->modify('+1 day');
    }
    
    return $sundays;
}

function autoAddSundays($pdo) {
    $currentMonth = date('m');
    $currentYear = date('Y');
    $nextMonth = date('m', strtotime('+1 month'));
    $nextYear = date('Y', strtotime('+1 month'));
    
    $monthsToCheck = [
        ['month' => $currentMonth, 'year' => $currentYear],
        ['month' => $nextMonth, 'year' => $nextYear]
    ];
    
    $totalAdded = 0;
    
    foreach ($monthsToCheck as $period) {
        $month = $period['month'];
        $year = $period['year'];
        
        // Check if Sundays already exist for this month
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE type = 'weekly_off' AND MONTH(holiday_date) = ? AND YEAR(holiday_date) = ?");
        $stmt->execute([$month, $year]);
        $existingCount = $stmt->fetchColumn();
        
        $sundays = generateSundays($month, $year);
        
        // If no weekly off exists for this month, add all Sundays
        if ($existingCount == 0) {
            foreach ($sundays as $sunday) {
                // Check if this Sunday already has a festival
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date = ? AND type != 'weekly_off'");
                $stmt->execute([$sunday]);
                $hasFestival = $stmt->fetchColumn();
                
                if ($hasFestival > 0) {
                    // If festival exists, don't add weekly off on that Sunday
                    continue;
                }
                
                $stmt = $pdo->prepare("INSERT INTO holidays (title, holiday_date, description, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    'Sunday - Weekly Off',
                    $sunday,
                    'Weekly off - Sunday',
                    'weekly_off'
                ]);
                $totalAdded++;
            }
        } else {
            // Check if all Sundays exist, if not add missing ones
            $stmt = $pdo->prepare("SELECT holiday_date FROM holidays WHERE type = 'weekly_off' AND MONTH(holiday_date) = ? AND YEAR(holiday_date) = ?");
            $stmt->execute([$month, $year]);
            $existingSundays = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($sundays as $sunday) {
                if (!in_array($sunday, $existingSundays)) {
                    // Check if this Sunday already has a festival
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date = ? AND type != 'weekly_off'");
                    $stmt->execute([$sunday]);
                    $hasFestival = $stmt->fetchColumn();
                    
                    if ($hasFestival > 0) {
                        // If festival exists, don't add weekly off on that Sunday
                        continue;
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO holidays (title, holiday_date, description, type) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        'Sunday - Weekly Off',
                        $sunday,
                        'Weekly off - Sunday',
                        'weekly_off'
                    ]);
                    $totalAdded++;
                }
            }
        }
    }
    
    return $totalAdded;
}

// Auto-add Sundays on page load
$sundaysAdded = autoAddSundays($pdo);
if ($sundaysAdded > 0) {
    $_SESSION['flash'] = "$sundaysAdded Sunday(s) added as weekly off";
}

// =============================================
// CREATE HOLIDAY
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'create') {
    requireModuleAccess('holidays', 'can_create');
    
    $title = sanitize($_POST['title'] ?? '');
    $date = $_POST['holiday_date'] ?? '';
    $desc = sanitize($_POST['description'] ?? '');
    $type = sanitize($_POST['type'] ?? 'public');
    $sendNotification = !empty($_POST['send_notification']);
    
    // Check if date is Sunday
    $dayOfWeek = date('w', strtotime($date));
    $isSunday = ($dayOfWeek == 0);
    
    if ($isSunday && $type != 'weekly_off') {
        // If it's a festival on Sunday, we allow it
        // But check if weekly off already exists on this Sunday
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE type = 'weekly_off' AND holiday_date = ?");
        $checkStmt->execute([$date]);
        $weeklyOffExists = $checkStmt->fetchColumn();
        
        if ($weeklyOffExists > 0) {
            // Remove weekly off since we're adding a festival
            $pdo->prepare("DELETE FROM holidays WHERE type = 'weekly_off' AND holiday_date = ?")->execute([$date]);
            $_SESSION['flash'] = 'Weekly off removed, festival added on Sunday';
        }
    }
    
    // Check for duplicate holiday
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date = ? AND title = ?");
    $checkStmt->execute([$date, $title]);
    if ($checkStmt->fetchColumn() > 0) {
        $_SESSION['flash'] = 'Holiday already exists on this date';
        redirect('holidays.php');
    }
    
    $pdo->prepare("INSERT INTO holidays (title, holiday_date, description, type) VALUES (?,?,?,?)")->execute([$title, $date, $desc, $type]);
    $newHolidayId = (int)$pdo->lastInsertId();

    // Send FCM Push Notification to App if checked or if festival/holiday
    if ($sendNotification && $type !== 'weekly_off') {
        try {
            $dayName = date('l', strtotime($date));
            $dateFormatted = date('d M Y', strtotime($date));
            $typeTitle = ($type === 'festival') ? 'Festival Holiday' : (($type === 'national_holiday') ? 'National Holiday' : 'Holiday Announcement');
            $notifTitle = "🎉 " . $typeTitle . ": " . $title;
            $notifMsg = "Office will remain closed on " . $dateFormatted . " (" . $dayName . ") on the occasion of " . $title . ($desc ? ". " . $desc : ".");
            
            // In-app notification
            $pdo->prepare("INSERT INTO notifications (title, message, type, send_to) VALUES (?, ?, 'announcement', 'all')")->execute([$notifTitle, $notifMsg]);
            
            // Mobile FCM Push
            FCMHelper::sendToTopicOrGroup($pdo, 'all', null, $notifTitle, $notifMsg, [
                'type' => 'holiday',
                'holiday_id' => $newHolidayId,
                'title' => $title,
                'date' => $date
            ]);
            $_SESSION['flash'] = "Holiday added & FCM Push Notification sent to all employees!";
        } catch (Throwable $e) {
            error_log("Holiday FCM error: " . $e->getMessage());
            $_SESSION['flash'] = 'Holiday added successfully (FCM push notice logged).';
        }
    } else {
        $_SESSION['flash'] = 'Holiday added successfully';
    }

    redirect('holidays.php');
}

// =============================================
// EDIT HOLIDAY
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'edit') {
    requireModuleAccess('holidays', 'can_edit');
    
    $id = intval($_POST['id'] ?? 0);
    $title = sanitize($_POST['title'] ?? '');
    $date = $_POST['holiday_date'] ?? '';
    $desc = sanitize($_POST['description'] ?? '');
    $type = sanitize($_POST['type'] ?? 'public');
    $sendNotification = !empty($_POST['send_notification']);
    
    // If changing to weekly off, check if Sunday
    if ($type == 'weekly_off') {
        $dayOfWeek = date('w', strtotime($date));
        if ($dayOfWeek != 0) {
            $_SESSION['flash'] = 'Weekly off can only be set for Sunday';
            redirect('holidays.php');
        }
        
        // Check if festival exists on this Sunday
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date = ? AND type != 'weekly_off' AND id != ?");
        $checkStmt->execute([$date, $id]);
        if ($checkStmt->fetchColumn() > 0) {
            $_SESSION['flash'] = 'Cannot set weekly off on Sunday with festival';
            redirect('holidays.php');
        }
    }
    
    $pdo->prepare("UPDATE holidays SET title=?, holiday_date=?, description=?, type=? WHERE id=?")->execute([$title, $date, $desc, $type, $id]);
    
    if ($sendNotification && $type !== 'weekly_off') {
        try {
            $dayName = date('l', strtotime($date));
            $dateFormatted = date('d M Y', strtotime($date));
            $typeTitle = ($type === 'festival') ? 'Festival Holiday Update' : 'Holiday Update';
            $notifTitle = "🎉 " . $typeTitle . ": " . $title;
            $notifMsg = "Office will remain closed on " . $dateFormatted . " (" . $dayName . ") on the occasion of " . $title . ($desc ? ". " . $desc : ".");
            
            $pdo->prepare("INSERT INTO notifications (title, message, type, send_to) VALUES (?, ?, 'announcement', 'all')")->execute([$notifTitle, $notifMsg]);
            
            FCMHelper::sendToTopicOrGroup($pdo, 'all', null, $notifTitle, $notifMsg, [
                'type' => 'holiday',
                'holiday_id' => $id,
                'title' => $title,
                'date' => $date
            ]);
            $_SESSION['flash'] = "Holiday updated & FCM notification sent to all employees!";
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Holiday updated successfully';
        }
    } else {
        $_SESSION['flash'] = 'Holiday updated successfully';
    }

    redirect('holidays.php');
}

// =============================================
// BROADCAST PUSH NOTIFICATION FOR HOLIDAY
// =============================================
if (isset($_GET['send_push'])) {
    requireModuleAccess('holidays', 'can_edit');
    $id = intval($_GET['send_push']);
    $stmt = $pdo->prepare("SELECT * FROM holidays WHERE id = ?");
    $stmt->execute([$id]);
    $h = $stmt->fetch();
    
    if ($h) {
        try {
            $dayName = date('l', strtotime($h['holiday_date']));
            $dateFormatted = date('d M Y', strtotime($h['holiday_date']));
            $typeTitle = ($h['type'] === 'festival') ? 'Festival Holiday Notice' : (($h['type'] === 'national_holiday') ? 'National Holiday Notice' : 'Holiday Notice');
            $notifTitle = "🎉 " . $typeTitle . ": " . $h['title'];
            $notifMsg = "Office will remain closed on " . $dateFormatted . " (" . $dayName . ") for " . $h['title'] . ($h['description'] ? ". " . $h['description'] : ".");
            
            $pdo->prepare("INSERT INTO notifications (title, message, type, send_to) VALUES (?, ?, 'announcement', 'all')")->execute([$notifTitle, $notifMsg]);
            
            $res = FCMHelper::sendToTopicOrGroup($pdo, 'all', null, $notifTitle, $notifMsg, [
                'type' => 'holiday',
                'holiday_id' => $id,
                'title' => $h['title'],
                'date' => $h['holiday_date']
            ]);
            
            $_SESSION['flash'] = "FCM push notification for '{$h['title']}' broadcasted to all employees mobile app!";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Notification Error: " . $e->getMessage();
        }
    }
    redirect('holidays.php');
}

// =============================================
// DELETE HOLIDAY
// =============================================
if (isset($_GET['delete'])) {
    requireModuleAccess('holidays', 'can_delete');
    
    $id = intval($_GET['delete']);
    
    // Check if it's a Sunday weekly off - prevent deletion of auto-generated Sundays
    $stmt = $pdo->prepare("SELECT type, title, holiday_date FROM holidays WHERE id = ?");
    $stmt->execute([$id]);
    $holiday = $stmt->fetch();
    
    if ($holiday && $holiday['type'] == 'weekly_off' && strpos($holiday['title'], 'Sunday') !== false) {
        // Check if there's a festival on this Sunday
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date = ? AND type != 'weekly_off'");
        $stmt->execute([$holiday['holiday_date']]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['flash'] = 'Cannot delete weekly off because festival exists on this Sunday';
            redirect('holidays.php');
        }
    }
    
    $pdo->prepare("DELETE FROM holidays WHERE id=?")->execute([$id]);
    $_SESSION['flash'] = 'Holiday deleted successfully';
    redirect('holidays.php');
}

// =============================================
// FETCH HOLIDAYS
// =============================================
$holidays = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date DESC")->fetchAll();

// Count statistics
$totalHolidays = count($holidays);
$weeklyOffCount = $pdo->query("SELECT COUNT(*) FROM holidays WHERE type = 'weekly_off'")->fetchColumn();
$festivalCount = $pdo->query("SELECT COUNT(*) FROM holidays WHERE type IN ('festival', 'national_holiday', 'company_holiday')")->fetchColumn();

// Group holidays by date to check conflicts
$holidaysByDate = [];
foreach ($holidays as $h) {
    $holidaysByDate[$h['holiday_date']][] = $h;
}

// =============================================
// INCLUDE HEADER
// =============================================
require_once '../includes/header.php';
$flash = $_SESSION['flash'] ?? ''; 
unset($_SESSION['flash']);

?>

<style>
.holiday-stats {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
    padding: 15px;
    color: white;
    margin-bottom: 20px;
}
.holiday-stats .stat-item {
    text-align: center;
}
.holiday-stats .stat-number {
    font-size: 28px;
    font-weight: bold;
}
.holiday-stats .stat-label {
    font-size: 14px;
    opacity: 0.9;
}
.conflict-badge {
    background: #ffc107;
    color: #000;
}
</style>

<!-- ============================================= -->
<!-- PAGE HEADER -->
<!-- ============================================= -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold">
            <i class="fas fa-calendar-alt me-2"></i>Holidays
            <small class="text-muted fs-6">(Total: <?php echo $totalHolidays; ?>)</small>
        </h4>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasModuleAccess('holidays', 'can_create')): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-plus"></i> Add Holiday
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================= -->
<!-- FLASH MESSAGES -->
<!-- ============================================= -->
<?php if ($flash): ?>
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo $flash; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ============================================= -->
<!-- STATISTICS CARDS -->
<!-- ============================================= -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Holidays</h6>
                        <h3 class="mb-0"><?php echo $totalHolidays; ?></h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                        <i class="fas fa-calendar-check text-primary fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Weekly Off (Sundays)</h6>
                        <h3 class="mb-0"><?php echo $weeklyOffCount; ?></h3>
                    </div>
                    <div class="bg-info bg-opacity-10 p-3 rounded-circle">
                        <i class="fas fa-calendar-week text-info fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Festivals</h6>
                        <h3 class="mb-0"><?php echo $festivalCount; ?></h3>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                        <i class="fas fa-gift text-success fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Holidays</h6>
                        <h3 class="mb-0"><?php echo $totalHolidays; ?></h3>
                    </div>
                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                        <i class="fas fa-star text-warning fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================= -->
<!-- HOLIDAYS TABLE -->
<!-- ============================================= -->
<div class="card shadow-sm">
    <div class="card-header bg-white py-3">
        <div class="row align-items-center">
            <div class="col">
                <h6 class="mb-0"><i class="fas fa-list me-2"></i>Holidays List</h6>
            </div>
            <div class="col-auto">
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary active" data-filter="all">All</button>
                    <button type="button" class="btn btn-outline-secondary" data-filter="weekly_off">Weekly Off</button>
                    <button type="button" class="btn btn-outline-secondary" data-filter="festival">Festivals</button>
                    <button type="button" class="btn btn-outline-secondary" data-filter="sunday_festival">Sunday Festivals</button>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0" id="holidaysTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; ?>
                    <?php foreach ($holidays as $h): 
                        $dayOfWeek = date('l', strtotime($h['holiday_date']));
                        $isSunday = ($dayOfWeek == 'Sunday');
                        $isAutoSunday = ($h['type'] == 'weekly_off' && strpos($h['title'], 'Sunday') !== false);
                        $isFestivalOnSunday = ($isSunday && $h['type'] != 'weekly_off');
                    ?>
                    <tr data-type="<?php echo $h['type']; ?>" data-sunday="<?php echo $isSunday ? 'yes' : 'no'; ?>">
                        <td><?php echo $counter++; ?></td>
                        <td>
                            <?php echo sanitize($h['title']); ?>
                            <?php if ($isAutoSunday): ?>
                                <span class="badge bg-info ms-1">Auto</span>
                            <?php endif; ?>
                            <?php if ($isFestivalOnSunday): ?>
                                <span class="badge bg-warning text-dark ms-1">Sunday Festival</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('d M Y', strtotime($h['holiday_date'])); ?></td>
                        <td>
                            <?php if ($isSunday): ?>
                                <span class="text-danger fw-bold"><?php echo $dayOfWeek; ?></span>
                            <?php else: ?>
                                <?php echo $dayOfWeek; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php 
                                $typeClasses = [
                                    'weekly_off'=>'secondary',
                                    'festival'=>'success',
                                    'national_holiday'=>'primary',
                                    'company_holiday'=>'info'
                                ];
                                echo $typeClasses[$h['type']] ?? 'secondary';
                            ?>">
                            <?php 
                                $typeLabels = [
                                    'weekly_off'=>'Weekly Off',
                                    'festival'=>'Festival',
                                    'national_holiday'=>'National Holiday',
                                    'company_holiday'=>'Company Holiday'
                                ];
                                echo $typeLabels[$h['type']] ?? ucfirst($h['type']);
                            ?>
                            </span>
                        </td>
                        <td><?php echo sanitize($h['description']) ?: '-'; ?></td>
                        <td class="text-center">
                            <?php if (!($isAutoSunday)): ?>
                                <a href="?send_push=<?php echo $h['id']; ?>" class="btn btn-sm btn-warning text-dark me-1" title="Send FCM Push Notification to App" onclick="return confirm('Kya aap sabhi employees ke phone par is Holiday ka FCM Push Notification bhejna chahte hain?')">
                                    <i class="fas fa-bell"></i>
                                </a>
                                <button class="btn btn-sm btn-info me-1" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $h['id']; ?>" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                            <?php endif; ?>
                            
                            <?php if (hasModuleAccess('holidays', 'can_delete') && !($isAutoSunday)): ?>
                                <a href="?delete=<?php echo $h['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this holiday?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($isAutoSunday): ?>
                                <span class="badge bg-secondary">Auto-generated</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($holidays)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="fas fa-calendar-times fa-2x d-block mb-2"></i>
                            No holidays found. Add a new holiday or let the system auto-generate Sundays.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================= -->
<!-- ADD HOLIDAY MODAL -->
<!-- ============================================= -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="?action=create">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Holiday</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g., Diwali, Holi, Independence Day" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                        <input type="date" name="holiday_date" class="form-control" required>
                        <small class="text-muted">Sundays are automatically marked as weekly off (unless festival added)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Type <span class="text-danger">*</span></label>
                        <select name="type" class="form-select" required>
                            <option value="festival" selected>Festival</option>
                            <option value="national_holiday">National Holiday</option>
                            <option value="company_holiday">Company Holiday</option>
                            <option value="weekly_off">Weekly Off</option>
                        </select>
                        <small class="text-muted">If festival on Sunday, weekly off will be automatically removed</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Optional holiday details / greetings..."></textarea>
                    </div>
                    <div class="form-check form-switch mb-2 p-2 bg-light rounded border">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="send_notification" id="sendNotification" value="1" checked>
                        <label class="form-check-label fw-bold text-primary" for="sendNotification">
                            <i class="fas fa-bell me-1"></i> Send Instant FCM Push Notification to App
                        </label>
                        <div class="form-text text-muted small ms-4">Holiday add hote hi sabhi employees ke mobile par notification chala jayega.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save & Broadcast Holiday
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================= -->
<!-- EDIT HOLIDAY MODALS -->
<!-- ============================================= -->
<?php foreach ($holidays as $h): 
    $isAutoSunday = ($h['type'] == 'weekly_off' && strpos($h['title'], 'Sunday') !== false);
    if ($isAutoSunday) continue; // Skip edit for auto-generated Sundays
?>
<div class="modal fade" id="editModal<?php echo $h['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="?action=edit">
            <input type="hidden" name="id" value="<?php echo $h['id']; ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Holiday</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="<?php echo sanitize($h['title']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                        <input type="date" name="holiday_date" class="form-control" value="<?php echo $h['holiday_date']; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Type <span class="text-danger">*</span></label>
                        <select name="type" class="form-select" required>
                            <option value="weekly_off" <?php echo $h['type']=='weekly_off'?'selected':''; ?>>Weekly Off</option>
                            <option value="festival" <?php echo $h['type']=='festival'?'selected':''; ?>>Festival</option>
                            <option value="national_holiday" <?php echo $h['type']=='national_holiday'?'selected':''; ?>>National Holiday</option>
                            <option value="company_holiday" <?php echo $h['type']=='company_holiday'?'selected':''; ?>>Company Holiday</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?php echo sanitize($h['description']); ?></textarea>
                    </div>
                    <div class="form-check form-switch mb-2 p-2 bg-light rounded border">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="send_notification" id="sendNotificationEdit<?php echo $h['id']; ?>" value="1">
                        <label class="form-check-label fw-bold text-primary" for="sendNotificationEdit<?php echo $h['id']; ?>">
                            <i class="fas fa-bell me-1"></i> Send Updated FCM Push Notification to App
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Holiday
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- ============================================= -->
<!-- JAVASCRIPT FOR FILTERING -->
<!-- ============================================= -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Filter buttons
    const filterButtons = document.querySelectorAll('[data-filter]');
    const tableRows = document.querySelectorAll('#holidaysTable tbody tr');
    
    filterButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Update active state
            filterButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            const filter = this.dataset.filter;
            
            tableRows.forEach(row => {
                if (filter === 'all') {
                    row.style.display = '';
                } else if (filter === 'sunday_festival') {
                    const isSunday = row.dataset.sunday === 'yes';
                    const type = row.dataset.type;
                    if (isSunday && type !== 'weekly_off') {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                } else {
                    const type = row.dataset.type;
                    if (type === filter) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>