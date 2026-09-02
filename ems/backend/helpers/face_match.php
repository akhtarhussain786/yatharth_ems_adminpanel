<?php
/**
 * Face matching for attendance.
 *
 * The phone runs the model and sends the resulting embedding; the comparison
 * and the pass/fail decision happen here. That split matters: the expensive
 * work stays free on the device, but the verdict is made server-side, so a
 * modified build cannot simply declare itself a match.
 *
 * Only embeddings are handled — never the photograph.
 */

/** Longest embedding accepted, so a malformed payload cannot exhaust memory. */
define('FACE_MAX_DIMENSIONS', 1024);

/**
 * Parses an embedding from a request into a plain array of floats.
 *
 * Accepts a JSON array or a comma-separated list. Returns null when the input
 * is not a usable vector — nothing downstream should ever see a partial one.
 */
function parseFaceEmbedding($raw)
{
    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed === '') return null;
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            $decoded = array_map('trim', explode(',', $trimmed));
        }
        $raw = $decoded;
    }

    if (!is_array($raw) || count($raw) === 0 || count($raw) > FACE_MAX_DIMENSIONS) {
        return null;
    }

    $vector = [];
    foreach ($raw as $value) {
        if (!is_numeric($value)) return null;
        $f = (float) $value;
        if (!is_finite($f)) return null;
        $vector[] = $f;
    }

    // An all-zero vector has no direction, so similarity is undefined.
    if (faceVectorMagnitude($vector) <= 0.0) return null;

    return $vector;
}

function faceVectorMagnitude(array $v)
{
    $sum = 0.0;
    foreach ($v as $x) $sum += $x * $x;
    return sqrt($sum);
}

/**
 * Cosine similarity, in the range -1..1. Two embeddings of the same face score
 * near 1; unrelated faces score near 0.
 *
 * Returns null when the vectors cannot be compared — different lengths mean
 * they came from different models, and comparing them would be meaningless
 * rather than merely inaccurate.
 */
function faceSimilarity(array $a, array $b)
{
    if (count($a) !== count($b) || count($a) === 0) return null;

    $dot = 0.0;
    for ($i = 0, $n = count($a); $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
    }

    $magnitude = faceVectorMagnitude($a) * faceVectorMagnitude($b);
    if ($magnitude <= 0.0) return null;

    $similarity = $dot / $magnitude;

    // Guard against floating point drift pushing it just outside the range.
    if ($similarity > 1.0) $similarity = 1.0;
    if ($similarity < -1.0) $similarity = -1.0;

    return $similarity;
}

/** How face verification should behave: block | flag | log | off. */
function faceVerificationMode($db)
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $mode = 'block';
    try {
        $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'face_verification_mode' LIMIT 1");
        $value = $stmt ? $stmt->fetchColumn() : false;
        if ($value !== false && $value !== null && $value !== '') $mode = strtolower(trim($value));
    } catch (Exception $e) {
        error_log('faceVerificationMode: ' . $e->getMessage());
    }

    if (!in_array($mode, ['block', 'flag', 'log', 'off'], true)) $mode = 'block';
    return $cache = $mode;
}

function faceMatchThreshold($db)
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $threshold = 0.65;
    try {
        $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'face_match_threshold' LIMIT 1");
        $value = $stmt ? $stmt->fetchColumn() : false;
        if (is_numeric($value)) $threshold = (float) $value;
    } catch (Exception $e) {
        error_log('faceMatchThreshold: ' . $e->getMessage());
    }

    // A threshold outside this range would either pass everyone or nobody.
    if ($threshold < 0.30 || $threshold > 0.99) $threshold = 0.65;
    return $cache = $threshold;
}

function getEnrolledFace($db, $employeeId)
{
    try {
        $stmt = $db->prepare("SELECT embedding, dimensions, model_version FROM employee_face_data WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $vector = parseFaceEmbedding($row['embedding']);
        return $vector ? ['vector' => $vector, 'model' => $row['model_version']] : null;
    } catch (Exception $e) {
        error_log('getEnrolledFace: ' . $e->getMessage());
        return null;
    }
}

/**
 * Whether a stored template can be enforced against.
 *
 * Templates enrolled before the crop was fixed were built from a plain bounding
 * box rather than an eye-aligned face, and score little better than chance
 * against a correctly cropped check-in — on live that meant over half of all
 * genuine staff being refused. Such a template is compared and logged but never
 * used to turn anyone away; the employee is asked to register again, and from
 * the moment they do, enforcement applies to them.
 *
 * The app stamps ALIGNED_MODEL_MARKER into model_version once it crops properly,
 * so this needs no dates, no manual switch, and no flag to remember to flip.
 */
define('ALIGNED_MODEL_MARKER', 'aligned');

function faceTemplateIsEnforceable($modelVersion)
{
    return stripos((string) $modelVersion, ALIGNED_MODEL_MARKER) !== false;
}

/**
 * Decides whether an attendance action may proceed.
 *
 * Returns ['allowed' => bool, 'message' => string|null, 'similarity' => float|null].
 *
 * Someone who has not enrolled yet is always allowed through — otherwise the
 * feature could never be introduced without stopping the whole workforce
 * clocking in on the morning it ships.
 */
function verifyFaceForAttendance($db, $employeeId, $rawEmbedding, $action = 'checkin')
{
    $mode = faceVerificationMode($db);
    if ($mode === 'off') return ['allowed' => true, 'message' => null, 'similarity' => null];

    $enrolled = getEnrolledFace($db, $employeeId);
    if (!$enrolled) {
        return ['allowed' => true, 'message' => null, 'similarity' => null, 'not_enrolled' => true];
    }

    $candidate = parseFaceEmbedding($rawEmbedding);
    if (!$candidate) {
        // Enrolled, but the app sent nothing usable — an older build, or the
        // capture failed. Only refuse when the policy is to block.
        $allowed = $mode !== 'block';
        return [
            'allowed' => $allowed,
            'message' => $allowed ? null
                : 'Your face could not be read from the photo. Retake the selfie facing the '
                  . 'camera in good light. If this keeps happening, update the app.',
            'similarity' => null,
        ];
    }

    $similarity = faceSimilarity($enrolled['vector'], $candidate);
    $threshold = faceMatchThreshold($db);

    if ($similarity === null) {
        // Different vector lengths: the enrolled template came from a different
        // model. Blocking here would strand everyone enrolled on the old one.
        error_log("Face model mismatch for employee $employeeId — re-enrollment needed");
        return ['allowed' => true, 'message' => null, 'similarity' => null];
    }

    $passed = $similarity >= $threshold;
    logFaceVerification($db, $employeeId, $action, $similarity, $threshold, $passed, $mode);

    // An old-style template is measured but never enforced, so verification can
    // stay switched on while the corrected app reaches every phone.
    $enforceable = faceTemplateIsEnforceable($enrolled['model'] ?? '');

    if ($passed || !$enforceable || $mode !== 'block') {
        return [
            'allowed' => true,
            'message' => null,
            'similarity' => $similarity,
            'passed' => $passed,
            // Lets the app nudge them to register again on the new version.
            'reenroll_suggested' => !$enforceable,
        ];
    }

    return [
        'allowed' => false,
        'message' => 'Face did not match the enrolled photo. Please try again in good light, or contact your administrator.',
        'similarity' => $similarity,
        'passed' => false,
    ];
}

function logFaceVerification($db, $employeeId, $action, $similarity, $threshold, $passed, $mode)
{
    try {
        $db->prepare("INSERT INTO face_verification_log (employee_id, action, similarity, threshold, passed, mode)
                      VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$employeeId, $action, $similarity, $threshold, $passed ? 1 : 0, $mode]);
    } catch (Exception $e) {
        // Never let logging stop someone clocking in.
        error_log('logFaceVerification: ' . $e->getMessage());
    }
}
