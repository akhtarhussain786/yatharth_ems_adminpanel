<?php
/**
 * The single definition of what a working day is worth.
 *
 * Check-in, check-out, the admin correction form and the salary calculation all
 * go through here, so a day can never be graded one way for a report and another
 * way for pay. The thresholds live in `settings` and are editable by an admin.
 */

/** Thresholds for judging a day, with the defaults used when a setting is absent. */
function attendanceRules($db)
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $rules = [
        'late_start_time'        => '10:00:00', // after this: recorded late, no deduction
        'half_day_time'          => '10:10:00', // after this: the day is a half day
        'half_day_checkout_time' => '17:00:00', // leaving before this: the day is a half day
        // Saturday is officially a short day, 10:00 to 14:30, so judging it by
        // the weekday threshold above marks a full Saturday as a half day.
        'saturday_end_time'      => '14:30:00',
    ];

    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings
                            WHERE setting_key IN ('late_start_time','half_day_time','half_day_checkout_time','saturday_end_time')");
        while ($row = $stmt->fetch()) {
            $value = normaliseTimeOfDay($row['setting_value']);
            if ($value !== null) $rules[$row['setting_key']] = $value;
        }
    } catch (Exception $e) {
        error_log('attendanceRules: ' . $e->getMessage());
    }

    return $cache = $rules;
}

/** Accepts 'HH:MM' or 'HH:MM:SS' and returns 'HH:MM:SS', or null if unusable. */
function normaliseTimeOfDay($value)
{
    if ($value === null) return null;
    $value = trim((string) $value);
    if ($value === '') return null;

    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }
    return null;
}

/** 'HH:MM:SS' out of a DATETIME, a TIME, or null. */
function timeOfDay($stamp)
{
    if (empty($stamp)) return null;
    $ts = strtotime($stamp);
    return $ts === false ? null : date('H:i:s', $ts);
}

/**
 * The date a timestamp belongs to, or null when it carries only a time.
 *
 * A bare 'HH:MM:SS' has no date, and strtotime() would quietly assume today —
 * which would grade a row from last month against this week's calendar.
 */
function dateOfStamp($stamp)
{
    if (empty($stamp)) return null;
    $value = trim((string) $stamp);
    if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $value)) return null;

    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d', $ts);
}

/**
 * The time before which leaving makes the day a half day.
 *
 * Saturday is a short day here, so it has its own end time. Judging Saturday by
 * the weekday figure marked everyone who worked their full Saturday as a half
 * day and docked half a day's pay for it.
 *
 * The weekday is taken from the row's own timestamps, never from today, so
 * correcting an old record still grades it by the day it actually happened on.
 */
function halfDayCheckoutTime($rules, $checkIn = null, $checkOut = null)
{
    $date = dateOfStamp($checkIn);
    if ($date === null) $date = dateOfStamp($checkOut);
    if ($date === null) return $rules['half_day_checkout_time'];

    // ISO-8601 day of week: 6 is Saturday.
    return (int) date('N', strtotime($date)) === 6
        ? $rules['saturday_end_time']
        : $rules['half_day_checkout_time'];
}

/**
 * How a day is graded: 'half-day', 'late' or 'present'.
 *
 * A day is a half day when the employee arrived after half_day_time OR left
 * before half_day_checkout_time. Breaking both still costs one half day — a
 * single day is never graded worse than half unless it is a full absence.
 *
 * A missing check-out is not treated as leaving early; there is no evidence of
 * when they left, and autoCheckoutExpiredRecords() fills it in later.
 */
function attendanceStatusFor($rules, $checkIn, $checkOut)
{
    $in = timeOfDay($checkIn);
    if ($in === null) return 'present';

    if ($in > $rules['half_day_time']) return 'half-day';

    $out = timeOfDay($checkOut);
    if ($out !== null && $out < halfDayCheckoutTime($rules, $checkIn, $checkOut)) return 'half-day';

    if ($in > $rules['late_start_time']) return 'late';

    return 'present';
}

/**
 * Whether a leave type is taken without pay.
 *
 * The salary report needs this because an approved leave is stored on the
 * attendance row as status 'leave' regardless of type — so without checking the
 * type, Leave Without Pay was being paid in full.
 */
function isUnpaidLeaveType($leaveType) {
    $type = strtolower(trim((string) $leaveType));
    if ($type === '') return false;

    foreach (['without pay', 'unpaid', 'lwp'] as $marker) {
        if (strpos($type, $marker) !== false) return true;
    }
    return false;
}

/**
 * Everything a row's clock times imply: status, lateness and hours worked.
 *
 * Used by the admin correction and manual-entry forms so that editing a
 * check-in updates the late figure, the hours and the grade together. Leaving
 * any of them stale is what made a corrected row still read "3h 40m late".
 *
 * Pass $keepStatus to preserve a deliberate leave/holiday/absent marking.
 */
function attendanceDerivedFields($rules, $checkIn, $checkOut, $keepStatus = null)
{
    $derived = [
        'status'                => 'present',
        'late_minutes'          => 0,
        'working_hours'         => null,
        'working_hours_decimal' => 0.00,
    ];

    $manual = strtolower((string) $keepStatus);
    $isManualMarking = in_array($manual, ['leave', 'holiday', 'absent', 'week-off', 'weekoff'], true);

    $derived['status'] = $isManualMarking
        ? $manual
        : attendanceStatusFor($rules, $checkIn, $checkOut);

    // Lateness is measured from late_start_time, and only when actually late.
    $in = timeOfDay($checkIn);
    if ($in !== null && !$isManualMarking && $in > $rules['late_start_time']) {
        $derived['late_minutes'] = (int) round(
            (strtotime($in) - strtotime($rules['late_start_time'])) / 60
        );
    }

    if (!empty($checkIn) && !empty($checkOut)) {
        $seconds = strtotime($checkOut) - strtotime($checkIn);
        if ($seconds > 0) {
            // attendance.working_hours is a TIME column, so it has to be
            // HH:MM:SS. This used to build '9h 0m', which MySQL rejected
            // outright — every correction of a day that had both a check-in
            // and a check-out failed on it.
            $derived['working_hours'] = sprintf('%02d:%02d:00', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
            $derived['working_hours_decimal'] = round($seconds / 3600, 2);
        }
    }

    return $derived;
}

/**
 * Fraction of a day's pay earned: 1.0 full, 0.5 half, 0.0 absent.
 *
 * Late arrival on its own costs nothing — it is recorded for reporting only.
 */
function attendanceDayFraction($rules, $status, $checkIn = null, $checkOut = null)
{
    $status = strtolower((string) $status);

    if ($status === 'absent') return 0.0;

    // Leave and holidays are paid, and are not judged on clock times.
    if (in_array($status, ['leave', 'holiday', 'week-off', 'weekoff'], true)) return 1.0;

    // Grade from the actual times so the answer follows any admin correction,
    // and stays right for rows saved before these rules existed.
    if ($checkIn !== null) {
        return attendanceStatusFor($rules, $checkIn, $checkOut) === 'half-day' ? 0.5 : 1.0;
    }

    return $status === 'half-day' ? 0.5 : 1.0;
}
