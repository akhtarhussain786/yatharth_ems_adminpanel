<?php
require_once '../includes/config.php';
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$empId = intval($_GET['employee_id'] ?? 0);
$month = filterMonth($_GET['month'] ?? '', date('Y-m'));

if (!$empId) {
    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
    exit;
}

try {
    // 1. Fetch Employee Profile
    $stmt = $pdo->prepare("
        SELECT e.*, d.name as department_name, des.name as designation_name 
        FROM employees e 
        LEFT JOIN departments d ON d.id = e.department_id 
        LEFT JOIN designations des ON des.id = e.designation_id 
        WHERE e.id = ? LIMIT 1
    ");
    $stmt->execute([$empId]);
    $emp = $stmt->fetch();

    if (!$emp) {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }

    $year = intval(substr($month, 0, 4));
    $monthNum = intval(substr($month, 5, 2));
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNum, $year);
    $startDate = "$month-01";
    $endDate = sprintf('%s-%02d', $month, $daysInMonth);

    // 2. Fetch Attendance Records for this month
    $attStmt = $pdo->prepare("
        SELECT * FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? 
        ORDER BY attendance_date ASC
    ");
    $attStmt->execute([$empId, $startDate, $endDate]);
    $attendanceRows = $attStmt->fetchAll();
    $attByDate = [];
    foreach ($attendanceRows as $row) {
        $attByDate[$row['attendance_date']] = $row;
    }

    // 3. Fetch Approved Leaves
    $leaveStmt = $pdo->prepare("
        SELECT * FROM leave_requests 
        WHERE employee_id = ? AND status = 'approved' 
        AND (
            (start_date BETWEEN ? AND ?) OR 
            (end_date BETWEEN ? AND ?) OR 
            (start_date <= ? AND end_date >= ?)
        )
    ");
    $leaveStmt->execute([$empId, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
    $leaveRows = $leaveStmt->fetchAll();
    $leaveDates = [];
    foreach ($leaveRows as $lr) {
        $curr = max($startDate, $lr['start_date']);
        $last = min($endDate, $lr['end_date']);
        while ($curr <= $last) {
            $leaveDates[$curr] = $lr['leave_type'] ?? 'Leave';
            $curr = date('Y-m-d', strtotime($curr . ' +1 day'));
        }
    }

    // 4. Fetch Holidays
    $holStmt = $pdo->prepare("
        SELECT * FROM holidays 
        WHERE (holiday_date BETWEEN ? AND ?) OR (start_date <= ? AND end_date >= ?)
    ");
    $holStmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $holRows = $holStmt->fetchAll();
    $holidayDates = [];
    foreach ($holRows as $hr) {
        $hStart = $hr['holiday_date'] ?? $hr['start_date'] ?? null;
        $hEnd = $hr['end_date'] ?? $hStart;
        if ($hStart) {
            $curr = max($startDate, $hStart);
            $last = min($endDate, $hEnd);
            while ($curr <= $last) {
                $holidayDates[$curr] = $hr['name'] ?? $hr['title'] ?? 'Holiday';
                $curr = date('Y-m-d', strtotime($curr . ' +1 day'));
            }
        }
    }

    // 5. Build Day-by-Day Calendar and Aggregate Totals
    $dailyRecords = [];
    $presentDays = 0;
    $lateDays = 0;
    $halfDays = 0;
    $absentDays = 0;
    $leaveDays = 0;
    $weeklyOffDays = 0;
    $holidayCount = 0;
    $totalWorkedMinutes = 0;
    $totalExpectedMinutes = 0;
    $totalOvertimeMinutes = 0;
    $todayDate = date('Y-m-d');

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $currDate = sprintf('%s-%02d', $month, $d);
        $dayOfWeek = date('l', strtotime($currDate));
        $dayNum = date('N', strtotime($currDate)); // 1 (Mon) to 7 (Sun)
        $isSunday = ($dayNum == 7);
        $isSaturday = ($dayNum == 6);
        $isFuture = ($currDate > $todayDate);

        // Required minutes
        $requiredMinutes = $isSunday ? 0 : ($isSaturday ? 270 : 540); // Sat = 4.5h, Weekday = 9h
        if (!$isSunday && !isset($holidayDates[$currDate]) && !$isFuture) {
            $totalExpectedMinutes += $requiredMinutes;
        }

        $att = $attByDate[$currDate] ?? null;
        $status = 'Absent';
        $statusClass = 'danger';
        $checkInFormatted = '-';
        $checkOutFormatted = '-';
        $workedStr = '-';
        $lateMinutes = 0;
        $overtimeMinutes = 0;
        $dayWorkedMinutes = 0;

        if ($att) {
            $rawStatus = strtolower($att['status'] ?? 'present');
            $checkIn = $att['check_in'] ?? null;
            $checkOut = $att['check_out'] ?? null;

            if ($checkIn) {
                $checkInFormatted = date('h:i A', strtotime($checkIn));
            }

            if ($checkOut) {
                $checkOutFormatted = date('h:i A', strtotime($checkOut));
            } elseif ($currDate === $todayDate && $checkIn) {
                $checkOutFormatted = 'Currently Working';
            }

            // Calculate worked minutes
            if ($checkIn && $checkOut) {
                $secs = strtotime($checkOut) - strtotime($checkIn);
                if ($secs > 0) {
                    $dayWorkedMinutes = round($secs / 60);
                }
            } elseif ($currDate === $todayDate && $checkIn) {
                $secs = time() - strtotime($checkIn);
                if ($secs > 0) {
                    $dayWorkedMinutes = round($secs / 60);
                }
            }

            $totalWorkedMinutes += $dayWorkedMinutes;

            if ($dayWorkedMinutes > $requiredMinutes && $requiredMinutes > 0) {
                $overtimeMinutes = $dayWorkedMinutes - $requiredMinutes;
                $totalOvertimeMinutes += $overtimeMinutes;
            }

            $lateMinutes = intval($att['late_minutes'] ?? 0);

            if ($rawStatus === 'half-day') {
                $status = 'Half Day';
                $statusClass = 'warning';
                $halfDays++;
            } elseif ($rawStatus === 'late' || $lateMinutes > 0) {
                $status = 'Late';
                $statusClass = 'warning';
                $lateDays++;
                $presentDays++;
            } elseif ($rawStatus === 'leave') {
                $status = 'Leave';
                $statusClass = 'info';
                $leaveDays++;
            } else {
                $status = 'Present';
                $statusClass = 'success';
                $presentDays++;
            }

            if ($dayWorkedMinutes > 0) {
                $h = intdiv($dayWorkedMinutes, 60);
                $m = $dayWorkedMinutes % 60;
                $workedStr = "{$h}h {$m}m";
                if ($checkOutFormatted === 'Currently Working') {
                    $workedStr .= ' (Running)';
                }
            }
        } elseif (isset($leaveDates[$currDate])) {
            $status = 'Leave (' . $leaveDates[$currDate] . ')';
            $statusClass = 'info';
            if (!$isFuture) $leaveDays++;
        } elseif (isset($holidayDates[$currDate])) {
            $status = 'Holiday (' . $holidayDates[$currDate] . ')';
            $statusClass = 'secondary';
            $holidayCount++;
        } elseif ($isSunday) {
            $status = 'Weekly Off';
            $statusClass = 'light';
            $weeklyOffDays++;
        } elseif ($isFuture) {
            $status = 'Upcoming';
            $statusClass = 'light';
        } else {
            $status = 'Absent';
            $statusClass = 'danger';
            $absentDays++;
        }

        $dailyRecords[] = [
            'date' => $currDate,
            'date_formatted' => date('d M Y', strtotime($currDate)),
            'day' => $dayOfWeek,
            'status' => $status,
            'status_class' => $statusClass,
            'check_in' => $checkInFormatted,
            'check_out' => $checkOutFormatted,
            'working_hours' => $workedStr,
            'required_hours' => $requiredMinutes > 0 ? (intdiv($requiredMinutes, 60) . 'h ' . ($requiredMinutes % 60) . 'm') : '-',
            'late_minutes' => $lateMinutes > 0 ? "{$lateMinutes}m" : '-',
            'overtime' => $overtimeMinutes > 0 ? "+{$overtimeMinutes}m" : '-',
        ];
    }

    $totalWorkingDaysInMonth = $daysInMonth - $weeklyOffDays - $holidayCount;
    $avgDailyMinutes = $presentDays > 0 ? round($totalWorkedMinutes / $presentDays) : 0;

    echo json_encode([
        'success' => true,
        'data' => [
            'employee' => [
                'id' => $emp['id'],
                'name' => trim($emp['first_name'] . ' ' . $emp['last_name']),
                'code' => $emp['employee_code'],
                'department' => $emp['department_name'] ?? 'General',
                'designation' => $emp['designation_name'] ?? 'Staff',
                'joining_date' => $emp['joining_date'] ? date('d M Y', strtotime($emp['joining_date'])) : '-',
                'mobile' => $emp['mobile'] ?? '-',
                'email' => $emp['email'] ?? '-',
            ],
            'month' => $month,
            'month_name' => date('F Y', strtotime($month . '-01')),
            'summary' => [
                'total_days' => $daysInMonth,
                'working_days' => $totalWorkingDaysInMonth,
                'present_days' => $presentDays,
                'late_days' => $lateDays,
                'half_days' => $halfDays,
                'leave_days' => $leaveDays,
                'absent_days' => $absentDays,
                'weekly_off_days' => $weeklyOffDays,
                'holiday_days' => $holidayCount,
                'total_working_hours' => formatMinutes($totalWorkedMinutes),
                'expected_working_hours' => formatMinutes($totalExpectedMinutes),
                'total_overtime_hours' => formatMinutes($totalOvertimeMinutes),
                'avg_daily_hours' => formatMinutes($avgDailyMinutes),
            ],
            'daily_records' => $dailyRecords
        ]
    ]);
} catch (Throwable $e) {
    error_log('Employee Monthly Work Report Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error generating report: ' . $e->getMessage()]);
}
