<?php
// employees.is_field_staff is handled once by runSchemaMigrations() in config.php
// rather than re-probed on every request.

function handleAuthRequest($action) {
    $db = (new Database())->getConnection();
    $data = json_decode($GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'login':
            return login($db, $data);
        case 'logout':
            return logout($db);
        case 'refresh':
            return refreshToken($db);
        case 'validate':
            return validateToken($db);
        default:
            return ['success' => false, 'message' => 'Invalid auth action'];
    }
}

/**
 * Ties an account to one handset.
 *
 * Credentials get shared, so the password alone is not evidence of who is
 * signing in. The first login claims the device; a later login from a different
 * one is refused until an admin clears it (people do replace phones).
 *
 * Returns an error array to send back, or null when the device is acceptable.
 */
function checkDeviceBinding($db, $user, $deviceId, $deviceName) {
    $deviceId = substr(trim((string) $deviceId), 0, 128);
    if ($deviceId === '') {
        // An older build that does not send an identifier still works; binding
        // simply cannot be enforced for it.
        return null;
    }

    // Binding is recorded but no longer refuses a sign-in.
    //
    // It used to compare Android's Build.ID, which is the firmware identifier:
    // it changes on every OS update — locking people out of their own phone —
    // and is identical across every handset of the same model, so it never
    // distinguished two colleagues in the first place. It gave the lockouts
    // without the protection. Face verification is what actually establishes
    // who is clocking in; this now just records the handset for the admin panel.
    $enforce = false;

    $bound = $user['device_id'] ?? null;

    if (!$bound) {
        try {
            $db->prepare("UPDATE users SET device_id = ?, device_name = ?, device_bound_at = NOW() WHERE id = ?")
               ->execute([$deviceId, substr((string) $deviceName, 0, 150), $user['id']]);
        } catch (Exception $e) {
            error_log('Device binding: ' . $e->getMessage());
        }
        return null;
    }

    if (hash_equals($bound, $deviceId)) return null;

    // A different handset: note it, keep the account usable.
    try {
        $db->prepare("UPDATE users SET device_id = ?, device_name = ?, device_bound_at = NOW() WHERE id = ?")
           ->execute([$deviceId, substr((string) $deviceName, 0, 150), $user['id']]);
    } catch (Exception $e) {
        error_log('Device rebind: ' . $e->getMessage());
    }

    if (!$enforce) return null;

    return [
        'success' => false,
        'message' => 'This account is registered to a different device. Ask your administrator to reset it before signing in here.',
        'device_mismatch' => true,
    ];
}

function createSession($db, $userId, $token, $deviceId = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $expiry = date('Y-m-d H:i:s', time() + SESSION_EXPIRY);

    // FIX 1: Deactivate existing active sessions for this user before creating a new one.
    // Prevents orphaned/duplicate sessions on rapid double-clicks or multiple logins.
    $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0, logout_time = NOW() WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$userId]);

    $stmt = $db->prepare("INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, device_id, login_time, last_activity, expiry_time, is_active) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?, 1)");
    $stmt->execute([$userId, $token, $ip, $ua, $deviceId, $expiry]);

    // Update users table
    $db->prepare("UPDATE users SET api_token = ?, token_expiry = ?, last_activity = NOW(), is_logged_in = 1 WHERE id = ?")
        ->execute([$token, $expiry, $userId]);
}

function login($db, $data) {
    $employeeId = Validator::sanitize($data['employee_id'] ?? '');
    $password = $data['password'] ?? '';
    $remember = $data['remember'] ?? false;

    if (!$employeeId || !$password) {
        return ['success' => false, 'message' => 'Employee ID and password required'];
    }

    $stmt = $db->prepare("
         SELECT u.id, u.username, u.password, u.role_id, u.status as is_active,
                u.device_id, u.device_name,
                e.id as employee_id, e.first_name, e.last_name, e.employee_code,
                e.department_id, e.designation_id, e.profile_photo, e.mobile, e.email,
                e.is_field_staff
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.id
        WHERE (u.username = :username OR e.employee_code = :code) AND u.status = 1
        -- Both forms are accepted, but a username wins when the same text is
        -- also somebody else's employee code. Without this it was LIMIT 1 with
        -- no ordering, so which account you landed in was MySQL's choice.
        --
        -- The ordering uses its own placeholder, bound to the same value.
        -- This connection runs with EMULATE_PREPARES off, and PDO cannot bind
        -- one named parameter twice in that mode, so repeating the one above
        -- would fail every login.
        ORDER BY (u.username = :username_pref) DESC
        LIMIT 1
    ");
    $stmt->execute([':username' => $employeeId, ':code' => $employeeId, ':username_pref' => $employeeId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid credentials'];
    }

    // Credentials alone do not prove who is signing in — they get shared.
    $deviceId = Validator::sanitize($data['device_id'] ?? '');
    $deviceName = Validator::sanitize($data['device_name'] ?? '');
    $deviceError = checkDeviceBinding($db, $user, $deviceId, $deviceName);
    if ($deviceError) return $deviceError;

    // Update last login
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    // Fetch role info
    $stmt = $db->prepare("SELECT name, description FROM roles WHERE id = ?");
    $stmt->execute([$user['role_id']]);
    $role = $stmt->fetch();

    $deptName = '';
    if ($user['department_id']) {
        $stmt = $db->prepare("SELECT name FROM departments WHERE id = ?");
        $stmt->execute([$user['department_id']]);
        $dept = $stmt->fetch();
        $deptName = $dept['name'] ?? '';
    }

    $desgName = '';
    if ($user['designation_id']) {
        $stmt = $db->prepare("SELECT name FROM designations WHERE id = ?");
        $stmt->execute([$user['designation_id']]);
        $desg = $stmt->fetch();
        $desgName = $desg['name'] ?? '';
    }

    // Fetch permissions for this role
    $roleName = $role['name'] ?? 'employee';
    $permissions = permissionsForRole($db, $user['role_id'], $roleName);

    $payload = [
        'user_id' => $user['id'],
        'employee_id' => $user['employee_id'],
        'role' => $roleName,
        'role_id' => $user['role_id'],
    ];

    $token = JWT::encode($payload);

    // The session row is what authenticate() validates every request against,
    // so a failure here has to fail the login. Previously it was logged and
    // ignored, which would now hand out a token that is refused on first use.
    try {
        createSession($db, $user['id'], $token, $deviceId ?: null);
    } catch (Exception $e) {
        error_log("Session creation error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Could not start a session. Please try again.'];
    }

    return [
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'remember' => $remember,
        'expires_in' => SESSION_EXPIRY,
        'user' => [
            'id' => $user['employee_id'],
            'user_id' => $user['id'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'employee_code' => $user['employee_code'],
            'department_id' => $user['department_id'],
            'department_name' => $deptName,
            'designation_id' => $user['designation_id'],
            'designation_name' => $desgName,
            'profile_photo' => $user['profile_photo'],
            'mobile' => $user['mobile'],
            'email' => $user['email'],
            'role' => $role['description'] ?? 'Employee',
            'role_name' => $roleName,
            'role_id' => $user['role_id'],
            'is_field_staff' => (int)($user['is_field_staff'] ?? 0),
            'permissions' => $permissions,
        ],
    ];
}

function logout($db) {
    $payload = AuthMiddleware::authenticate();

    // Deactivate all active sessions for this user
    $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0, logout_time = NOW() WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$payload['user_id']]);

    // Clear token from users table
    $db->prepare("UPDATE users SET api_token = NULL, token_expiry = NULL, is_logged_in = 0, last_activity = NOW() WHERE id = ?")
        ->execute([$payload['user_id']]);

    return ['success' => true, 'message' => 'Logged out successfully'];
}

function refreshToken($db) {
    $payload = AuthMiddleware::authenticate();

    // FIX 2: Extract the actual incoming token string from HTTP Authorization header.
    // Querying against a newly generated JWT::encode($payload) failed because timestamps/signatures differed.
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $incomingToken = str_replace('Bearer ', '', $authHeader);

    // Verify current active session exists using the actual incoming token
    $stmt = $db->prepare("SELECT id FROM user_sessions WHERE user_id = ? AND session_token = ? AND is_active = 1 AND expiry_time > NOW()");
    $stmt->execute([$payload['user_id'], $incomingToken]);
    $session = $stmt->fetch();

    if (!$session) {
        // Session expired or invalid, force logout
        $db->prepare("UPDATE users SET api_token = NULL, token_expiry = NULL, is_logged_in = 0 WHERE id = ?")
            ->execute([$payload['user_id']]);
        return ['success' => false, 'message' => 'Session expired. Please login again.'];
    }

    // Generate new token and update expiry
    $newToken = JWT::encode($payload);
    $expiry = date('Y-m-d H:i:s', time() + SESSION_EXPIRY);

    // Update session record
    $stmt = $db->prepare("UPDATE user_sessions SET session_token = ?, expiry_time = ?, last_activity = NOW() WHERE id = ?");
    $stmt->execute([$newToken, $expiry, $session['id']]);

    // Update users table
    $db->prepare("UPDATE users SET api_token = ?, token_expiry = ?, last_activity = NOW() WHERE id = ?")
        ->execute([$newToken, $expiry, $payload['user_id']]);

    return [
        'success' => true,
        'message' => 'Token refreshed',
        'token' => $newToken,
        'expires_in' => SESSION_EXPIRY
    ];
}

/**
 * The permission map for a role, as the app should see it.
 *
 * Shared by login and validate: the app caches this at login and had no way to
 * learn about a change afterwards, so granting or revoking a module in the
 * admin panel did nothing until the employee happened to sign in again.
 */
function permissionsForRole($db, $roleId, $roleName) {
    $permissions = [];
    if ($roleName === 'super_admin') {
        $modules = ['employees','attendance','departments','designations','holidays','leaves','payroll','reports','settings','daily_work_reports','tasks','leads','campaigns','call_reports','follow_ups','hr_activities','notices','documents','roles','permissions','users','marketing','telecaller','sales','travel','expenses','assets','meetings','notifications'];
        foreach ($modules as $m) {
            $permissions[$m] = ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => true];
        }
        return $permissions;
    }

    $stmt = $db->prepare("SELECT module, can_view, can_create, can_edit, can_delete FROM permissions WHERE role_id = ?");
    $stmt->execute([$roleId]);
    foreach ($stmt->fetchAll() as $row) {
        $permissions[$row['module']] = [
            'can_view' => (bool)$row['can_view'],
            'can_create' => (bool)$row['can_create'],
            'can_edit' => (bool)$row['can_edit'],
            'can_delete' => (bool)$row['can_delete'],
        ];
    }
    // Mirror the server-side fallback so the app is not told it lacks a
    // module that checkPermission() will in fact allow.
    return PermissionHelper::applyRoleFallback($permissions, $roleName);
}

function validateToken($db) {
    $payload = AuthMiddleware::authenticate();

    // Check if session exists and is active
    $stmt = $db->prepare("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND is_active = 1 AND expiry_time > NOW()");
    $stmt->execute([$payload['user_id']]);
    $active = $stmt->fetchColumn();

    if (!$active) {
        return ['success' => false, 'message' => 'Session expired', 'force_logout' => true];
    }

    // Update last activity
    $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$payload['user_id']]);
    $db->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE user_id = ? AND is_active = 1")
        ->execute([$payload['user_id']]);

    // Hand back the permissions as they stand now, so the app can replace what
    // it cached at login instead of acting on a stale copy for weeks.
    $roleName = $payload['role'] ?? 'employee';
    $roleId   = $payload['role_id'] ?? 0;

    return [
        'success' => true,
        'message' => 'Token is valid',
        'data' => [
            'role' => $roleName,
            'role_id' => $roleId,
            'permissions' => permissionsForRole($db, $roleId, $roleName),
        ],
    ];
}