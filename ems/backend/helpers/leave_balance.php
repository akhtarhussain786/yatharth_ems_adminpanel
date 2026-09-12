<?php
/**
 * Leave entitlements and balances, in one place.
 *
 * The app reads these through leaves.php and the admin panel now shows the
 * same figures. Keeping the arithmetic here is what stops the two from
 * drifting apart and reporting different numbers for the same employee.
 */

/** Opening balance for Earned Leave can never exceed one year's allotment. */
if (!defined('LEAVE_EARNED_CARRY_CAP')) define('LEAVE_EARNED_CARRY_CAP', 15.0);

/** Yearly entitlement per leave type. Half Day and LWP are not entitlements. */
if (!function_exists('leaveAllocations')) {
    function leaveAllocations() {
        return [
            'Casual Leave'       => 8.0,
            'Sick Leave'         => 8.0,
            'Earned Leave'       => 15.0,
            'Half Day'           => 0.0,
            'Leave Without Pay'  => 0.0,
        ];
    }
}

/**
 * Unused Earned Leave from the previous year.
 *
 * Derived from last year's approved requests rather than a stored figure, so it
 * stays right even where leave_balances was never populated.
 */
if (!function_exists('earnedLeaveCarriedForward')) {
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
}

/**
 * One employee's balance per leave type for a year.
 *
 * `used` is taken as the larger of the stored leave_balances figure and what
 * the approved requests actually add up to, so a balance row that was never
 * written — or was written once and then missed — cannot under-report.
 */
if (!function_exists('computeLeaveBalances')) {
    function computeLeaveBalances($db, $employeeId, $year = null) {
        $year = (int) ($year ?: date('Y'));
        $allocations = leaveAllocations();

        $carried = earnedLeaveCarriedForward($db, $employeeId, $year);
        if ($carried > 0) {
            $allocations['Earned Leave'] = min(
                $allocations['Earned Leave'] + $carried,
                LEAVE_EARNED_CARRY_CAP
            );
        }

        $customAllotted = [];
        $customUsed = [];
        try {
            $balStmt = $db->prepare("SELECT leave_type, allotted, used FROM leave_balances WHERE employee_id = ? AND year = ?");
            $balStmt->execute([$employeeId, $year]);
            foreach ($balStmt->fetchAll() as $b) {
                if ($b['allotted'] > 0) $customAllotted[$b['leave_type']] = (float) $b['allotted'];
                $customUsed[$b['leave_type']] = (float) $b['used'];
            }
        } catch (Exception $e) {
            error_log('computeLeaveBalances balances: ' . $e->getMessage());
        }

        try {
            $reqStmt = $db->prepare("SELECT leave_type, SUM(total_days) AS used_days
                                     FROM leave_requests
                                     WHERE employee_id = ? AND YEAR(start_date) = ? AND status = 'Approved'
                                     GROUP BY leave_type");
            $reqStmt->execute([$employeeId, $year]);
            foreach ($reqStmt->fetchAll() as $ru) {
                $type = $ru['leave_type'];
                $usedVal = (float) $ru['used_days'];
                if (!isset($customUsed[$type]) || $usedVal > $customUsed[$type]) {
                    $customUsed[$type] = $usedVal;
                }
            }
        } catch (Exception $e) {
            error_log('computeLeaveBalances requests: ' . $e->getMessage());
        }

        // A type only ever used ad hoc still deserves a row, or the page would
        // show days taken against nothing.
        foreach (array_keys($customUsed) as $type) {
            if (!isset($allocations[$type])) $allocations[$type] = 0.0;
        }

        $balances = [];
        foreach ($allocations as $type => $defaultAllotted) {
            $allotted = $customAllotted[$type] ?? $defaultAllotted;
            $used = $customUsed[$type] ?? 0.0;
            $balances[] = [
                'leave_type' => $type,
                'allotted'   => $allotted,
                'used'       => $used,
                'remaining'  => max(0.0, $allotted - $used),
            ];
        }
        return $balances;
    }
}
