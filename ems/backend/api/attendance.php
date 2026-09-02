<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define constants
if (!defined('BASE_URL')) {
    define('BASE_URL', 'https://ems.yatharthinstitution.in/ems/backend/');
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', __DIR__ . '/../uploads/');
}

function handleAttendanceRequest($action, $param) {
    try {
        $auth = AuthMiddleware::authenticate();
        $db = (new Database())->getConnection();
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        // Auto checkout records older than 9 hours
        autoCheckoutExpiredRecords($db);

        switch ($action) {
            case 'checkin':
                return checkIn($db, $auth, $data);
            case 'checkout':
                return checkOut($db, $auth, $data);
            case 'today':
                return getTodayAttendance($db, $auth);
            case 'history':
                return getAttendanceHistory($db, $auth, $param);
            case 'summary':
                return getAttendanceSummary($db, $auth, $param);
            case 'daily':
                return getDailyAttendance($db, $auth, $param);
            case 'monthly':
                return getMonthlyAttendance($db, $auth, $param);
            case 'late':
                return getLateAttendance($db, $auth, $param);
            case 'absent':
                return getAbsentAttendance($db, $auth, $param);
            case 'calendar':
                return getAttendanceCalendar($db, $auth, $param);
            case 'holidays':
                return getHolidayList($db, $auth, $param);
            default:
                return ['success' => false, 'message' => 'Invalid action'];
        }
    } catch (Exception $e) {
        error_log("Attendance Error: " . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}

/**
 * Auto Checkout employees after 9 hours if they forgot to check out
 */
/**
 * Closes check-ins that were never closed on a previous day.
 *
 * Deliberately global rather than per-employee: whoever opens the app first
 * tidies up yesterday for everyone, which is what keeps it working without a
 * cron job. It must therefore never touch today.
 */
function autoCheckoutExpiredRecords($db) {
    try {
        $db->exec("
            UPDATE attendance 
            SET check_out = DATE_ADD(check_in, INTERVAL 9 HOUR),
                working_hours = '09:00:00',
                working_hours_decimal = 9.00,
                remarks = CASE 
                    WHEN remarks IS NULL OR remarks = '' THEN '[Auto checked-out after 9 hours]'
                    ELSE CONCAT(remarks, ' [Auto checked-out after 9 hours]')
                END
            WHERE check_out IS NULL 
              AND check_in IS NOT NULL 
              -- Only ever close a day that has already ended. This used to close
              -- any session past nine hours, today's included: a 09:30 check-in
              -- was force-closed at 18:30, before the 18:30 office end, and the
              -- employee could then no longer clock out at all. Their real hours
              -- were replaced by a flat nine, and the salary report re-graded
              -- that fabricated early finish as a half day.
              AND attendance_date < CURDATE()
        ");
    } catch (Exception $e) {
        error_log("AutoCheckout Error: " . $e->getMessage());
    }
}

/**
 * Check if employee is field staff
 */
function isFieldStaff($db, $employeeId) {
    try {
        $stmt = $db->prepare("SELECT is_field_staff FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        $result = $stmt->fetch();
        return $result && $result['is_field_staff'] == 1;
    } catch (Exception $e) {
        error_log("isFieldStaff Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get full image URL
 */
function getFullImageUrl($path) {
    if (empty($path)) {
        return null;
    }
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }
    $path = ltrim($path, '/');
    if (strpos($path, 'uploads/') === 0) {
        return rtrim(BASE_URL, '/') . '/' . $path;
    }
    return rtrim(BASE_URL, '/') . '/uploads/attendance/' . $path;
}

/**
 * Upload image
 */
/**
 * Removes an attendance photo nothing will ever reference.
 *
 * The upload runs before the row is written, so a rejected INSERT — a duplicate
 * check-in being the common one — left the image on disk with no record
 * pointing at it. Marketing already does this; attendance did not.
 */
function discardAttendanceUpload($storedPath)
{
    if (!$storedPath) return;
    $full = UPLOAD_PATH . preg_replace('#^uploads/#', '', $storedPath);
    if (is_file($full)) @unlink($full);
}

function uploadAttendanceImage($employeeId, $type, $base64Image = null, $fileUpload = null) {
    try {
        $uploadDir = UPLOAD_PATH . 'attendance/';
        
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                error_log("Failed to create directory: " . $uploadDir);
                return null;
            }
        }

        if (!is_writable($uploadDir)) {
            error_log("Directory not writable: " . $uploadDir);
            return null;
        }

        $filename = $type . '_' . $employeeId . '_' . date('Ymd_His') . '.jpg';
        $filepath = $uploadDir . $filename;

        if ($base64Image) {
            if (strpos($base64Image, 'base64,') !== false) {
                $base64Image = explode('base64,', $base64Image)[1];
            }
            
            $imageData = base64_decode($base64Image);
            if ($imageData === false) {
                error_log("Failed to decode base64 image");
                return null;
            }

            $imageInfo = getimagesizefromstring($imageData);
            if ($imageInfo === false) {
                error_log("Invalid image data");
                return null;
            }

            if (file_put_contents($filepath, $imageData) === false) {
                error_log("Failed to save image: " . $filepath);
                return null;
            }

            return 'uploads/attendance/' . $filename;
        }

        if ($fileUpload && isset($fileUpload['tmp_name']) && $fileUpload['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            // Detect MIME type without requiring the fileinfo extension
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $fileUpload['tmp_name']);
                finfo_close($finfo);
            } elseif (function_exists('mime_content_type')) {
                $mimeType = mime_content_type($fileUpload['tmp_name']);
            } else {
                // Fallback: derive from extension sent by the client
                $extMap = [
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                    'webp' => 'image/webp',
                ];
                $ext = strtolower(pathinfo($fileUpload['name'], PATHINFO_EXTENSION));
                $mimeType = $extMap[$ext] ?? 'application/octet-stream';
            }

            if (!in_array($mimeType, $allowedTypes)) {
                error_log("Invalid file type: " . $mimeType);
                return null;
            }

            $ext = pathinfo($fileUpload['name'], PATHINFO_EXTENSION) ?: 'jpg';
            if (in_array($mimeType, ['image/png'])) {
                $ext = 'png';
            } elseif (in_array($mimeType, ['image/gif'])) {
                $ext = 'gif';
            } elseif (in_array($mimeType, ['image/webp'])) {
                $ext = 'webp';
            }

            $filename = $type . '_' . $employeeId . '_' . date('Ymd_His') . '.' . $ext;
            $filepath = $uploadDir . $filename;

            if (move_uploaded_file($fileUpload['tmp_name'], $filepath)) {
                return 'uploads/attendance/' . $filename;
            }
        }

        return null;
    } catch (Exception $e) {
        error_log("Upload error: " . $e->getMessage());
        return null;
    }
}

/**
 * Format late minutes
 */
function formatLateMinutes($minutes) {
    if (!$minutes || $minutes == 0) return '-';
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($mins == 0) {
        return $hours . 'h';
    }
    return $hours . 'h ' . $mins . 'm';
}

// ============================================
// CHECK IN - FIELD STAFF SUPPORT
// ============================================

function checkIn($db, $auth, $data) {
    try {
        $latitude = $data['latitude'] ?? $_POST['latitude'] ?? null;
        $longitude = $data['longitude'] ?? $_POST['longitude'] ?? null;
        $address = $data['address'] ?? $_POST['address'] ?? '';
        $photoPath = null;
        $leaveType = 'present';

        if (!$latitude || !$longitude) {
            return ['success' => false, 'message' => 'GPS location required'];
        }

        // Check if already checked in
        $stmt = $db->prepare("SELECT id, check_in, check_out FROM attendance WHERE employee_id = ? AND attendance_date = CURDATE()");
        $stmt->execute([$auth['employee_id']]);
        $existing = $stmt->fetch();

        if ($existing && $existing['check_in']) {
            return ['success' => false, 'message' => 'Already checked in today'];
        }

        // Face check before anything is written or uploaded, so a refused
        // attempt leaves nothing behind. The employee_id comes from the token,
        // never the request, so this compares the signed-in person's enrolled
        // face against the one in front of the camera right now.
        $face = verifyFaceForAttendance(
            $db, $auth['employee_id'],
            $data['face_embedding'] ?? $_POST['face_embedding'] ?? null,
            'checkin'
        );
        if (!$face['allowed']) {
            return [
                'success' => false,
                'message' => $face['message'],
                'face_mismatch' => true,
            ];
        }

        // ============================================
        // FIELD STAFF CHECK - Bahar se check-in allow
        // ============================================
        $isFieldStaff = isFieldStaff($db, $auth['employee_id']);
        
        $isFieldWork = 0;
        $locationType = 'office';
        $distanceFromOffice = null;
        
        if ($isFieldStaff) {
            $isFieldWork = 1;
            $locationType = 'field';
            // Optional distance calculation
            $office = getOfficeLocation($db);
            if ($office) {
                $distanceFromOffice = calculateDistance($latitude, $longitude, $office['latitude'], $office['longitude']);
            }
        }

        $today = date('Y-m-d');
        $holiday = isHoliday($db, $today);
        $isSunday = isSunday();

        if ($holiday) {
            $leaveType = 'holiday';
            $isHoliday = 1;
            $holidayTitle = $holiday['title'];
        } elseif ($isSunday) {
            $leaveType = 'weekly_off';
            $isHoliday = 1;
            $holidayTitle = 'Sunday - Weekly Off';
        } else {
            $isHoliday = 0;
            $holidayTitle = null;
        }

        $rules = attendanceRules($db);
        $lateStart = $rules['late_start_time'];
        $now = date('H:i:s');

        // Graded by the shared rule, so check-in, check-out, admin corrections
        // and the salary report can never disagree about the same day.
        $status = $isHoliday ? 'present' : attendanceStatusFor($rules, $now, null);
        $lateMinutes = 0;

        if (!$isHoliday && $now > $lateStart) {
            $lateMinutes = round((strtotime($now) - strtotime($lateStart)) / 60);
        }

        // Handle photo
        $photoBase64 = $data['photo_base64'] ?? $_POST['photo_base64'] ?? null;
        $photoFile = $_FILES['photo'] ?? null;
        
        if ($photoBase64 || $photoFile) {
            $photoPath = uploadAttendanceImage($auth['employee_id'], 'checkin', $photoBase64, $photoFile);
        }

        $stmt = $db->prepare("
            INSERT INTO attendance (
                employee_id, attendance_date, check_in, 
                latitude, longitude, address, check_in_photo, 
                status, late_minutes, leave_type, is_holiday, holiday_title,
                is_field_work, checkin_location_type, distance_from_office, created_at
            ) VALUES (
                :employee_id, CURDATE(), NOW(),
                :lat, :lng, :address, :photo, 
                :status, :late_minutes, :leave_type, :is_holiday, :holiday_title,
                :is_field_work, :location_type, :distance, NOW()
            )
        ");

        $stmt->execute([
            ':employee_id' => $auth['employee_id'],
            ':lat' => $latitude,
            ':lng' => $longitude,
            ':address' => $address,
            ':photo' => $photoPath,
            ':status' => $status,
            ':late_minutes' => $lateMinutes,
            ':leave_type' => $leaveType,
            ':is_holiday' => $isHoliday,
            ':holiday_title' => $holidayTitle,
            ':is_field_work' => $isFieldWork,
            ':location_type' => $locationType,
            ':distance' => $distanceFromOffice
        ]);

        $id = $db->lastInsertId();

        return [
            'success' => true,
            'message' => $isFieldStaff ? 'Field work check-in successful' : 'Check-in successful',
            'data' => [
                'attendance_id' => $id,
                'check_in' => date('Y-m-d H:i:s'),
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'late_minutes_formatted' => formatLateMinutes($lateMinutes),
                'leave_type' => $leaveType,
                'is_holiday' => $isHoliday,
                'holiday_title' => $holidayTitle,
                'photo' => getFullImageUrl($photoPath),
                'is_field_work' => $isFieldWork,
                'location_type' => $locationType,
                'distance_from_office_km' => $distanceFromOffice,
                // Lets the app nudge someone who has not registered a face yet
                // without ever standing between them and clocking in.
                'face_enrollment_pending' => !empty($face['not_enrolled']),
            ]
        ];
    } catch (Exception $e) {
        // The photo was written before the row; without this a rejected
        // insert leaves the image stranded on disk forever.
        discardAttendanceUpload($photoPath);
        error_log("CheckIn Error: " . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}

// ============================================
// CHECK OUT
// ============================================

function checkOut($db, $auth, $data) {
    try {
        $photoPath = null;
        $latitude = $data['latitude'] ?? $_POST['latitude'] ?? null;
        $longitude = $data['longitude'] ?? $_POST['longitude'] ?? null;
        $address = $data['address'] ?? $_POST['address'] ?? '';

        $stmt = $db->prepare("SELECT id, check_in, status FROM attendance WHERE employee_id = :employee_id AND attendance_date = CURDATE() AND check_out IS NULL");
        $stmt->execute([':employee_id' => $auth['employee_id']]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['success' => false, 'message' => 'No active check-in found'];
        }

        // Verified here for the same reason as check-in: otherwise a colleague
        // could close someone's day early from their own phone.
        $face = verifyFaceForAttendance(
            $db, $auth['employee_id'],
            $data['face_embedding'] ?? $_POST['face_embedding'] ?? null,
            'checkout'
        );
        if (!$face['allowed']) {
            return [
                'success' => false,
                'message' => $face['message'],
                'face_mismatch' => true,
            ];
        }

        // Uploaded only once the check-out is going to happen, so a refused or
        // impossible attempt does not leave a stray file behind.
        $photoBase64 = $data['photo_base64'] ?? $_POST['photo_base64'] ?? null;
        $photoFile = $_FILES['photo'] ?? null;

        if ($photoBase64 || $photoFile) {
            $photoPath = uploadAttendanceImage($auth['employee_id'], 'checkout', $photoBase64, $photoFile);
        }

        $workingHours = null;
        $workingHoursDecimal = 0.00;
        if ($row && $row['check_in']) {
            $checkIn = new DateTime($row['check_in']);
            $checkOut = new DateTime('now');
            $diff = $checkIn->diff($checkOut);
            // attendance.working_hours is a TIME column, and the app parses
            // this field with Helpers.formatDuration(), which splits on ':'
            // and int.parse()s the parts — 'Xh Ym' would throw there. One
            // format, HH:MM:SS, for both the column and the reply.
            $totalHours = ($diff->days * 24) + $diff->h;
            $workingHours = sprintf('%02d:%02d:00', $totalHours, $diff->i);
            $workingHoursDecimal = calculateWorkingHoursDecimal($row['check_in'], date('Y-m-d H:i:s'));
        }

        // Leaving early can only be judged now that there is a check-out time,
        // so the day is re-graded here. Leave and holidays keep their status.
        $sql = "UPDATE attendance SET
            check_out = NOW(),
            working_hours = :hours,
            working_hours_decimal = :hours_decimal,
            leave_type = COALESCE(leave_type, 'present')";
        $params = [
            ':employee_id' => $auth['employee_id'],
            ':hours' => $workingHours,
            ':hours_decimal' => $workingHoursDecimal
        ];

        if ($row && $row['check_in'] && !in_array(strtolower((string)($row['status'] ?? '')), ['leave', 'holiday'], true)) {
            $sql .= ", status = :status";
            $params[':status'] = attendanceStatusFor(
                attendanceRules($db), $row['check_in'], date('Y-m-d H:i:s')
            );
        }
        
        if ($photoPath) {
            $sql .= ", check_out_photo = :photo";
            $params[':photo'] = $photoPath;
        }
        if ($latitude && $longitude) {
            $sql .= ", latitude = :lat, longitude = :lng";
            $params[':lat'] = $latitude;
            $params[':lng'] = $longitude;
        }
        if ($address) {
            $sql .= ", address = :address";
            $params[':address'] = $address;
        }
        $sql .= " WHERE employee_id = :employee_id AND attendance_date = CURDATE() AND check_out IS NULL";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return [
            'success' => true,
            'message' => 'Check-out successful',
            'data' => [
                'photo' => getFullImageUrl($photoPath), 
                'working_hours' => $workingHours,
                'working_hours_decimal' => $workingHoursDecimal,
                'check_out' => date('Y-m-d H:i:s')
            ]
        ];
    } catch (Exception $e) {
        // The photo was written before the row; without this a rejected
        // insert leaves the image stranded on disk forever.
        discardAttendanceUpload($photoPath);
        error_log("CheckOut Error: " . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}

// ============================================
// GET TODAY ATTENDANCE
// ============================================

function getTodayAttendance($db, $auth) {
    try {
        $stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id = :employee_id AND attendance_date = CURDATE()");
        $stmt->execute([':employee_id' => $auth['employee_id']]);
        $attendance = $stmt->fetch();

        if ($attendance) {
            $attendance['check_in_photo'] = getFullImageUrl($attendance['check_in_photo']);
            $attendance['check_out_photo'] = getFullImageUrl($attendance['check_out_photo']);
            if (isset($attendance['late_minutes'])) {
                $attendance['late_minutes_formatted'] = formatLateMinutes($attendance['late_minutes']);
            }
        }

        $today = date('Y-m-d');
        $holiday = isHoliday($db, $today);
        $isSunday = isSunday();

        $isHolidayToday = $holiday ? true : $isSunday;
        $holidayTitle = $holiday ? $holiday['title'] : ($isSunday ? 'Sunday - Weekly Off' : null);

        $config = getOfficeSettings($db);
        $isFieldStaff = isFieldStaff($db, $auth['employee_id']);

        return [
            'success' => true,
            'data' => [
                'attendance' => $attendance,
                'is_holiday' => $isHolidayToday,
                'is_sunday' => $isSunday,
                'holiday_title' => $holidayTitle,
                'office_start' => $config['office_start_time'],
                'office_end' => getEffectiveEndTime($config),
                'saturday_end_time' => $config['saturday_end_time'],
                'is_field_staff' => $isFieldStaff,
                'today' => $today
            ]
        ];
    } catch (Exception $e) {
        error_log("getTodayAttendance Error: " . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}

// ============================================
// GET LATE ATTENDANCE
// ============================================

function getLateAttendance($db, $auth, $param) {
    try {
        $month = $param ?: date('Y-m');
        
        $sql = "
            SELECT a.*, 
                   e.first_name, e.last_name, e.employee_code,
                   e.is_field_staff,
                   d.name as department_name,
                   des.name as designation_name
            FROM attendance a
            JOIN employees e ON e.id = a.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN designations des ON des.id = e.designation_id
            WHERE DATE_FORMAT(a.attendance_date, '%Y-%m') = ? 
            AND a.status = 'late' 
            ORDER BY a.attendance_date DESC, a.late_minutes DESC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$month]);
        $records = $stmt->fetchAll();

        foreach ($records as &$record) {
            $record['check_in_photo'] = getFullImageUrl($record['check_in_photo']);
            $record['check_out_photo'] = getFullImageUrl($record['check_out_photo']);
            $record['late_minutes_formatted'] = formatLateMinutes($record['late_minutes'] ?? 0);
            $record['employee_name'] = $record['first_name'] . ' ' . $record['last_name'];
        }

        return [
            'success' => true,
            'data' => $records,
            'month' => $month,
            'total_late' => count($records)
        ];
    } catch (Exception $e) {
        error_log("getLateAttendance Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error fetching late attendance'];
    }
}

// ============================================
// OTHER HELPER FUNCTIONS
// ============================================

function isHoliday($db, $date) {
    $stmt = $db->prepare("SELECT id, title, type FROM holidays WHERE holiday_date = ? AND status = 1 LIMIT 1");
    $stmt->execute([$date]);
    return $stmt->fetch();
}

function getOfficeSettings($db) {
    $settings = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('office_start_time', 'late_start_time', 'office_end_time', 'half_day_time', 'saturday_end_time')");
    $config = ['office_start_time' => '09:30:00', 'late_start_time' => '10:00:00', 'office_end_time' => '18:30:00', 'half_day_time' => '10:10:00', 'saturday_end_time' => '14:00:00'];
    while ($row = $settings->fetch()) {
        $config[$row['setting_key']] = $row['setting_value'];
    }
    return $config;
}

function isSaturday($date = null) {
    $ts = $date ? strtotime($date) : time();
    return date('w', $ts) == 6;
}

function getEffectiveEndTime($config) {
    return isSaturday() ? $config['saturday_end_time'] : $config['office_end_time'];
}

function calculateWorkingHoursDecimal($checkIn, $checkOut) {
    if (!$checkIn || !$checkOut) return 0.00;
    $in = new DateTime($checkIn);
    $out = new DateTime($checkOut);
    $diff = $in->diff($out);
    $hours = $diff->h + ($diff->i / 60) + ($diff->s / 3600);
    return round($hours, 2);
}

function isSunday($date = null) {
    $d = $date ?: date('Y-m-d');
    return date('w', strtotime($d)) == 0;
}

function getOfficeLocation($db) {
    try {
        $stmt = $db->query("SELECT latitude, longitude, radius FROM office_locations WHERE status = 1 LIMIT 1");
        $settings = $stmt->fetch();
        if (!$settings) {
            return ['latitude' => 28.6139, 'longitude' => 77.2090, 'radius_km' => 5.00];
        }
        return [
            'latitude' => $settings['latitude'],
            'longitude' => $settings['longitude'],
            'radius_km' => $settings['radius'] / 1000
        ];
    } catch (Exception $e) {
        return ['latitude' => 28.6139, 'longitude' => 77.2090, 'radius_km' => 5.00];
    }
}

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + 
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($earthRadius * $c, 2);
}

// ============================================
// OTHER ATTENDANCE FUNCTIONS
// ============================================

function getAttendanceHistory($db, $auth, $param) {
    try {
        $month = $param ?: date('Y-m');
        $page = $_GET['page'] ?? 1;
        $limit = $_GET['limit'] ?? 30;
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare("
            SELECT * FROM attendance 
            WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
            ORDER BY attendance_date DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $auth['employee_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $month, PDO::PARAM_STR);
        $stmt->bindValue(3, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(4, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll();

        foreach ($records as &$record) {
            $record['check_in_photo'] = getFullImageUrl($record['check_in_photo']);
            $record['check_out_photo'] = getFullImageUrl($record['check_out_photo']);
            if (isset($record['late_minutes'])) {
                $record['late_minutes_formatted'] = formatLateMinutes($record['late_minutes']);
            }
        }

        $stmt = $db->prepare("SELECT COUNT(*) as total FROM attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?");
        $stmt->execute([$auth['employee_id'], $month]);
        $total = $stmt->fetch()['total'];

        return [
            'success' => true,
            'data' => $records,
            'total' => (int)$total,
            'page' => (int)$page,
            'limit' => (int)$limit,
        ];
    } catch (Exception $e) {
        error_log("getAttendanceHistory Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error fetching history'];
    }
}

function getAttendanceSummary($db, $auth, $param) {
    try {
        $month = $param ?: date('Y-m');
        
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN leave_type = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN leave_type = 'paid_leave' THEN 1 ELSE 0 END) as paid_leave_days,
                SUM(CASE WHEN leave_type = 'earned_leave' THEN 1 ELSE 0 END) as earned_leave_days,
                SUM(CASE WHEN leave_type = 'unpaid_leave' THEN 1 ELSE 0 END) as unpaid_leave_days,
                SUM(CASE WHEN leave_type = 'absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN leave_type = 'weekly_off' THEN 1 ELSE 0 END) as weekly_off_days,
                SUM(CASE WHEN leave_type = 'holiday' THEN 1 ELSE 0 END) as holiday_days,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_days,
                COALESCE(SUM(working_hours_decimal), 0) as total_working_hours
            FROM attendance 
            WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
        ");
        $stmt->execute([$auth['employee_id'], $month]);
        $summary = $stmt->fetch();

        $totalDays = (int)$summary['total_days'];
        $presentDays = (int)$summary['present_days'];
        $paidLeaveDays = (int)$summary['paid_leave_days'];
        $earnedLeaveDays = (int)$summary['earned_leave_days'];
        $weeklyOffDays = (int)$summary['weekly_off_days'];
        $holidayDays = (int)$summary['holiday_days'];

        $workingDays = max($totalDays - $weeklyOffDays - $holidayDays, 1);
        $attendedDays = $presentDays + $paidLeaveDays + $earnedLeaveDays;
        $percentage = round(($attendedDays / $workingDays) * 100, 2);

        $summary['working_days'] = $workingDays;
        $summary['attended_days'] = $attendedDays;
        $summary['attendance_percentage'] = $percentage;
        $summary['late_days_formatted'] = formatLateMinutes($summary['late_days']);
        
        return [
            'success' => true,
            'data' => $summary,
            'month' => $month
        ];
    } catch (Exception $e) {
        error_log("getAttendanceSummary Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error fetching summary'];
    }
}

function getDailyAttendance($db, $auth, $param) {
    try {
        $date = $param ?: date('Y-m-d');
        $stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
        $stmt->execute([$auth['employee_id'], $date]);
        $attendance = $stmt->fetch();

        if ($attendance) {
            $attendance['check_in_photo'] = getFullImageUrl($attendance['check_in_photo']);
            $attendance['check_out_photo'] = getFullImageUrl($attendance['check_out_photo']);
            if (isset($attendance['late_minutes'])) {
                $attendance['late_minutes_formatted'] = formatLateMinutes($attendance['late_minutes']);
            }
        }

        $config = getOfficeSettings($db);
        return [
            'success' => true,
            'data' => $attendance ?: null,
            'office_start' => $config['office_start_time'],
            'office_end' => getEffectiveEndTime($config),
            'date' => $date
        ];
    } catch (Exception $e) {
        error_log("getDailyAttendance Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error fetching daily attendance'];
    }
}

function getMonthlyAttendance($db, $auth, $param) {
    try {
        $month = $param ?: date('Y-m');
        $stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? ORDER BY attendance_date ASC");
        $stmt->execute([$auth['employee_id'], $month]);
        $records = $stmt->fetchAll();

        foreach ($records as &$record) {
            $record['check_in_photo'] = getFullImageUrl($record['check_in_photo']);
            $record['check_out_photo'] = getFullImageUrl($record['check_out_photo']);
            if (isset($record['late_minutes'])) {
                $record['late_minutes_formatted'] = formatLateMinutes($record['late_minutes']);
            }
        }

        $stmt = $db->prepare("SELECT COUNT(*) as present, SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late, SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_day FROM attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? AND leave_type = 'present'");
        $stmt->execute([$auth['employee_id'], $month]);
        $stats = $stmt->fetch();

        return [
            'success' => true,
            'data' => $records,
            'month' => $month,
            'stats' => [
                'present' => (int)$stats['present'],
                'late' => (int)$stats['late'],
                'late_formatted' => formatLateMinutes($stats['late']),
                'half_day' => (int)$stats['half_day']
            ]
        ];
    } catch (Exception $e) {
        error_log("getMonthlyAttendance Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error fetching monthly attendance'];
    }
}

function getAbsentAttendance($db, $auth, $param) {
    try {
        $month = $param ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
        $year = (int)substr($month, 0, 4);
        $monthNum = (int)substr($month, 5, 2);

        $lastCountableDate = date('Y-m-d', strtotime('-1 day'));

        $stmt = $db->prepare("SELECT joining_date FROM employees WHERE id = ?");
        $stmt->execute([$auth['employee_id']]);
        $joiningDate = $stmt->fetchColumn();
        if (!$joiningDate || $joiningDate === '0000-00-00') $joiningDate = null;

        $stmt = $db->prepare("SELECT attendance_date, status, leave_type FROM attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?");
        $stmt->execute([$auth['employee_id'], $month]);
        $records = $stmt->fetchAll();
        $attendanceDates = [];
        foreach ($records as $r) {
            $attendanceDates[$r['attendance_date']] = $r;
        }

        $stmt = $db->prepare("SELECT holiday_date FROM holidays WHERE status = 1 AND DATE_FORMAT(holiday_date, '%Y-%m') = ?");
        $stmt->execute([$month]);
        $holidays = $stmt->fetchAll();
        $holidayDates = [];
        foreach ($holidays as $h) {
            $holidayDates[$h['holiday_date']] = true;
        }

        $daysInMonth = (int)date('t', strtotime("$year-$monthNum-01"));
        $absentDays = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $monthNum, $day);
            if ($date > $lastCountableDate) break;
            if ($joiningDate && $date < $joiningDate) continue;

            $dayOfWeek = date('w', strtotime($date));
            if ($dayOfWeek == 0) continue;
            if (isset($holidayDates[$date])) continue;
            if (isset($attendanceDates[$date])) continue;

            $absentDays[] = [
                'attendance_date' => $date,
                'day_name' => date('l', strtotime($date)),
                'status' => 'absent',
                'leave_type' => 'absent'
            ];
        }

        return [
            'success' => true,
            'data' => $absentDays,
            'month' => $month,
            'total_absent' => count($absentDays)
        ];
    } catch (Exception $e) {
        error_log("getAbsentAttendance Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error fetching absent attendance'];
    }
}

function getAttendanceCalendar($db, $auth, $param) {
    try {
        $month = $param ?: date('Y-m');
        $year = (int)substr($month, 0, 4);
        $monthNum = (int)substr($month, 5, 2);

        $stmt = $db->prepare("
            SELECT attendance_date, status, leave_type, is_holiday, holiday_title, 
                   check_in, check_out, working_hours, working_hours_decimal, late_minutes,
                   is_field_work, checkin_location_type, distance_from_office
            FROM attendance 
            WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
            ORDER BY attendance_date ASC
        ");
        $stmt->execute([$auth['employee_id'], $month]);
        $records = $stmt->fetchAll();

        $attendanceByDate = [];
        foreach ($records as $r) {
            $attendanceByDate[$r['attendance_date']] = $r;
        }

        $stmt = $db->prepare("SELECT holiday_date, title, type FROM holidays WHERE status = 1 AND DATE_FORMAT(holiday_date, '%Y-%m') = ?");
        $stmt->execute([$month]);
        $holidays = $stmt->fetchAll();

        $holidaysByDate = [];
        foreach ($holidays as $h) {
            $holidaysByDate[$h['holiday_date']] = $h;
        }

        $daysInMonth = (int)date('t', strtotime("$year-$monthNum-01"));
        $calendar = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $monthNum, $day);
            $dayOfWeek = date('w', strtotime($date));
            $isSunday = ($dayOfWeek == 0);

            $entry = [
                'date' => $date,
                'day' => $day,
                'day_of_week' => $dayOfWeek,
                'day_name' => date('l', strtotime($date)),
                'is_sunday' => $isSunday,
                'is_holiday' => isset($holidaysByDate[$date]),
                'holiday_title' => $holidaysByDate[$date]['title'] ?? null,
                'has_attendance' => isset($attendanceByDate[$date]),
            ];

            if (isset($attendanceByDate[$date])) {
                $a = $attendanceByDate[$date];
                $entry['status'] = $a['status'];
                $entry['leave_type'] = $a['leave_type'];
                $entry['check_in'] = $a['check_in'];
                $entry['check_out'] = $a['check_out'];
                $entry['working_hours'] = $a['working_hours'];
                $entry['working_hours_decimal'] = $a['working_hours_decimal'];
                $entry['late_minutes'] = $a['late_minutes'];
                $entry['late_minutes_formatted'] = formatLateMinutes($a['late_minutes'] ?? 0);
                $entry['is_field_work'] = $a['is_field_work'] ?? 0;
                $entry['location_type'] = $a['checkin_location_type'] ?? 'unknown';
                $entry['distance_from_office'] = $a['distance_from_office'] ?? null;
            } elseif ($isSunday || isset($holidaysByDate[$date])) {
                $entry['status'] = 'holiday';
                $entry['leave_type'] = $isSunday ? 'weekly_off' : 'holiday';
            } else {
                $entry['status'] = 'absent';
                $entry['leave_type'] = 'absent';
            }

            $calendar[] = $entry;
        }

        return [
            'success' => true,
            'data' => $calendar,
            'month' => $month,
            'year' => $year,
            'days_in_month' => $daysInMonth
        ];
    } catch (Exception $e) {
        error_log("getAttendanceCalendar Error: " . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}

function getHolidayList($db, $auth, $param) {
    try {
        $year = $param ?: date('Y');

        $stmt = $db->prepare("
            SELECT id, title, holiday_date, description, type,
                   DAYNAME(holiday_date) as day_name,
                   CASE WHEN type = 'weekly_off' THEN 1 ELSE 0 END as is_weekly_off
            FROM holidays 
            WHERE YEAR(holiday_date) = ? AND status = 1
            ORDER BY holiday_date ASC
        ");
        $stmt->execute([$year]);
        $holidays = $stmt->fetchAll();

        $sundays = [];
        for ($m = 1; $m <= 12; $m++) {
            $daysInMonth = (int)date('t', strtotime("$year-$m-01"));
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date = sprintf('%04d-%02d-%02d', $year, $m, $d);
                if (date('w', strtotime($date)) == 0) {
                    $found = false;
                    foreach ($holidays as $h) {
                        if ($h['holiday_date'] == $date) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $sundays[] = [
                            'id' => null,
                            'title' => 'Sunday - Weekly Off',
                            'holiday_date' => $date,
                            'description' => 'Auto-detected weekly off',
                            'type' => 'weekly_off',
                            'day_name' => 'Sunday',
                            'is_weekly_off' => 1
                        ];
                    }
                }
            }
        }

        $allHolidays = array_merge($holidays, $sundays);
        usort($allHolidays, function($a, $b) {
            return strcmp($a['holiday_date'], $b['holiday_date']);
        });

        return [
            'success' => true,
            'data' => $allHolidays,
            'year' => $year,
            'total' => count($allHolidays)
        ];
    } catch (Exception $e) {
        error_log("getHolidayList Error: " . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}
?>