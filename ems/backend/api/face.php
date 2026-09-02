<?php
/**
 * Face enrollment for attendance verification.
 *
 * An employee enrols once from the app; after that the record is locked and
 * only a super admin can clear it. That lock is the whole point — if an
 * employee could re-capture freely they would simply enrol a colleague's face,
 * and the verification would certify the very fraud it exists to stop.
 *
 * Only the embedding is stored, never the photograph.
 */

function handleFaceRequest($action, $param) {
    try {
        $auth = AuthMiddleware::authenticate();
        $db = (new Database())->getConnection();
        $data = json_decode($GLOBALS['_RAW_INPUT'] ?? '', true) ?: $_POST;

        switch ($action) {
            case 'status':
                return getFaceStatus($db, $auth);
            case 'enroll':
                return enrollFace($db, $auth, $data);
            case 'reset':
                // Clearing an enrollment is the security boundary, so it is
                // restricted to super admins alone. The role comes from the
                // token already decoded above, rather than re-authenticating.
                if (($auth['role'] ?? '') !== 'super_admin') {
                    http_response_code(403);
                    return ['success' => false, 'message' => 'Only a super admin can reset a face registration.'];
                }
                return resetFaceEnrollment($db, $auth, $param ?: ($data['employee_id'] ?? 0));
            default:
                return ['success' => false, 'message' => 'Invalid face action'];
        }
    } catch (Exception $e) {
        error_log('Face Error: ' . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}

/** Whether this employee has enrolled, and whether the app should prompt them. */
function getFaceStatus($db, $auth) {
    require_once __DIR__ . '/../helpers/face_match.php';

    $stmt = $db->prepare("SELECT dimensions, model_version, enrolled_at, is_locked, embedding
                          FROM employee_face_data WHERE employee_id = ? LIMIT 1");
    $stmt->execute([$auth['employee_id']]);
    $row = $stmt->fetch();

    // A reset leaves the row in place for the audit trail but empties the
    // embedding, so the row existing is not the same as being enrolled.
    $enrolled = $row && trim((string) $row['embedding']) !== '';

    $mode = faceVerificationMode($db);

    return [
        'success' => true,
        'data' => [
            'enrolled'     => $enrolled,
            'locked'       => $enrolled && (int) $row['is_locked'] === 1,
            'enrolled_at'  => $enrolled ? $row['enrolled_at'] : null,
            'model_version' => $enrolled ? $row['model_version'] : null,
            'dimensions'   => $enrolled ? (int) $row['dimensions'] : 0,
            // The app uses these to decide whether to prompt for enrollment.
            'verification_mode' => $mode,
            'required'     => $mode !== 'off',
        ],
    ];
}

function enrollFace($db, $auth, $data) {
    require_once __DIR__ . '/../helpers/face_match.php';

    $employeeId = $auth['employee_id'];
    if (!$employeeId) {
        return ['success' => false, 'message' => 'Your account is not linked to an employee record.'];
    }

    $vector = parseFaceEmbedding($data['embedding'] ?? null);
    if (!$vector) {
        return ['success' => false, 'message' => 'Face data was not captured correctly. Please try again.'];
    }

    // Already enrolled and locked: refuse, so nobody can overwrite their
    // template with someone else's face. A row left behind by a reset has an
    // empty embedding and does not count as enrolled.
    $existing = $db->prepare("SELECT id, is_locked, embedding FROM employee_face_data WHERE employee_id = ? LIMIT 1");
    $existing->execute([$employeeId]);
    $row = $existing->fetch();

    if ($row && (int) $row['is_locked'] === 1 && trim((string) $row['embedding']) !== '') {
        return [
            'success' => false,
            'already_enrolled' => true,
            'message' => 'Your face is already registered. Ask a super admin to reset it if you need to capture it again.',
        ];
    }

    $modelVersion = substr(Validator::sanitize($data['model_version'] ?? 'unknown'), 0, 50);
    $encoded = json_encode(array_map(static fn($v) => round($v, 6), $vector));

    if ($row) {
        // reset_by / reset_at are left as they are: who last cleared this
        // employee's face stays on the record after they re-enrol.
        $db->prepare("UPDATE employee_face_data
                      SET embedding = ?, dimensions = ?, model_version = ?, is_locked = 1,
                          enrolled_at = NOW()
                      WHERE employee_id = ?")
           ->execute([$encoded, count($vector), $modelVersion, $employeeId]);
    } else {
        $db->prepare("INSERT INTO employee_face_data (employee_id, embedding, dimensions, model_version, is_locked)
                      VALUES (?, ?, ?, ?, 1)")
           ->execute([$employeeId, $encoded, count($vector), $modelVersion]);
    }

    return [
        'success' => true,
        'message' => 'Face registered. It will be checked when you clock in and out.',
        'data' => ['dimensions' => count($vector), 'locked' => true],
    ];
}

/**
 * Super admin only — clears the stored face so the employee can capture again.
 *
 * The row is kept rather than deleted: who reset whose biometric, and when, is
 * exactly the sort of thing that has to be answerable afterwards. Emptying the
 * embedding removes the biometric itself, which is the part worth discarding.
 */
function resetFaceEnrollment($db, $auth, $employeeId) {
    $employeeId = (int) $employeeId;
    if (!$employeeId) return ['success' => false, 'message' => 'Employee ID required'];

    $stmt = $db->prepare("UPDATE employee_face_data
                          SET embedding = '', dimensions = 0, is_locked = 0,
                              reset_by = ?, reset_at = NOW()
                          WHERE employee_id = ? AND embedding <> ''");
    $stmt->execute([$auth['user_id'] ?? null, $employeeId]);

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'message' => 'That employee has no face registered.'];
    }

    error_log("Face enrollment reset for employee $employeeId by user " . ($auth['user_id'] ?? '?'));

    return ['success' => true, 'message' => 'Face registration cleared. The employee can now capture it again.'];
}
