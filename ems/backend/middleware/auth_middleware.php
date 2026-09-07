<?php
class AuthMiddleware {
    public static function authenticate() {
        $token = null;
        $authHeader = '';

        // Try 1: From Authorization header (getallheaders)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        // Try 2: From $_SERVER
        if (empty($authHeader) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (empty($authHeader) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        // Extract Bearer token
        if (!empty($authHeader) && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }

        // Try 3: GET parameter (Flutter fallback for multipart)
        if (empty($token) && isset($_GET['token']) && !empty($_GET['token'])) {
            $token = $_GET['token'];
        }

        // Try 4: POST parameter (multipart/form-data without auth header)
        if (empty($token)) {
            $token = $_POST['token'] ?? $_POST['_token'] ?? null;
        }

        // Try 5: JSON body (use cached raw input)
        if (empty($token)) {
            $rawInput = $GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input');
            if (!empty($rawInput) && stripos($rawInput, 'token') !== false) {
                $data = json_decode($rawInput, true);
                if (is_array($data)) {
                    $token = $data['token'] ?? $data['_token'] ?? null;
                }
            }
        }

        if (empty($token)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authorization token required']);
            exit;
        }

        $payload = JWT::decode($token);

        if (!$payload) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired token', 'force_logout' => true]);
            exit;
        }

        // A valid signature is not enough: the session it belongs to must still
        // be the active one. login() already deactivates a user's previous
        // sessions, but nothing checked that here — so a second person signing
        // in with shared credentials left the first phone working for the full
        // 12 hours. Enforcing it here is what makes one-login-at-a-time real.
        if (!self::sessionIsCurrent($payload['user_id'], $token)) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'You have been signed out because this account was used on another device.',
                'force_logout' => true,
            ]);
            exit;
        }

        // Verify that user is active and sync current role & role_id
        try {
            $db = (new Database())->getConnection();
            $uStmt = $db->prepare("SELECT u.id, u.status, u.role_id, r.name as role_name, e.id as employee_id 
                                   FROM users u 
                                   LEFT JOIN roles r ON r.id = u.role_id 
                                   LEFT JOIN employees e ON e.user_id = u.id 
                                   WHERE u.id = ? LIMIT 1");
            $uStmt->execute([$payload['user_id']]);
            $activeUser = $uStmt->fetch();

            if (!$activeUser || (int)$activeUser['status'] !== 1) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Account is inactive or suspended', 'force_logout' => true]);
                exit;
            }

            // Keep role, role_id, and employee_id dynamically synced with database
            if (!empty($activeUser['role_name'])) {
                $payload['role'] = $activeUser['role_name'];
            }
            if (!empty($activeUser['role_id'])) {
                $payload['role_id'] = (int)$activeUser['role_id'];
            }
            if (!empty($activeUser['employee_id'])) {
                $payload['employee_id'] = (int)$activeUser['employee_id'];
            }
        } catch (Throwable $e) {
            error_log('auth_middleware user validation: ' . $e->getMessage());
        }

        // Update last activity in session
        self::updateActivity($payload['user_id']);

        return $payload;
    }

    /**
     * True when this exact token is the user's live session.
     *
     * A database or schema failure returns true — an outage must not lock every
     * employee out of the app. Only a definite answer ("no such active session")
     * rejects the request.
     */
    private static function sessionIsCurrent($userId, $token)
    {
        try {
            $db = (new Database())->getConnection();
            $stmt = $db->prepare("SELECT id FROM user_sessions
                                  WHERE user_id = ? AND session_token = ? AND is_active = 1
                                  LIMIT 1");
            $stmt->execute([$userId, $token]);
            if ($stmt->fetch()) return true;

            // No live session for this token. Distinguish two cases:
            //   - the user has session rows, so this one was ended or replaced
            //     (signed out, or someone else signed in) -> reject
            //   - the user has no rows at all, meaning sessions were never
            //     recorded for them -> allow, so an older login is not punished
            //
            // Checking for *any* row rather than any *active* row matters: after
            // logout every row is inactive, and treating that as "never had a
            // session" would leave the token working after signing out.
            $any = $db->prepare("SELECT id FROM user_sessions WHERE user_id = ? LIMIT 1");
            $any->execute([$userId]);
            return $any->fetch() ? false : true;
        } catch (Throwable $e) {
            error_log('sessionIsCurrent: ' . $e->getMessage());
            return true;
        }
    }

    private static function updateActivity($userId) {
        try {
            $db = (new Database())->getConnection();
            $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$userId]);
            $db->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE user_id = ? AND is_active = 1")
                ->execute([$userId]);
        } catch (Exception $e) {
            // Non-critical, don't block the request
        }
    }

    public static function checkRole($allowedRoles) {
        $payload = self::authenticate();
        if (!in_array($payload['role'], $allowedRoles)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        return $payload;
    }

    public static function requirePermission($module, $action = 'can_view') {
        $payload = self::authenticate();
        if ($payload['role'] === 'super_admin') return $payload;
        $db = (new Database())->getConnection();
        PermissionHelper::checkPermission($db, $payload, $module, $action);
        return $payload;
    }
}
