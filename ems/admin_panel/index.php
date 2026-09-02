<?php
require_once 'includes/config.php';

$error = '';
$timeout = isset($_GET['timeout']);
if ($timeout) {
    $error = 'Session timed out due to inactivity. Please login again.';
}
$sessionError = isset($_GET['session_error']);
if ($sessionError) {
    $error = 'Session could not be established. Please clear your browser cookies and try again.';
}

if (isLoggedIn()) {
    $rc = getRedirectCount();
    if ($rc > 3) {
        $_SESSION = [];
        session_destroy();
        $error = 'Session error. Please login again.';
    } else {
        redirect(buildRedirectUrl('dashboard.php', $rc));
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT u.*, r.id as rid, r.name as role_name, r.description as role_display, 
                                      e.first_name, e.last_name, e.employee_code
                               FROM users u 
                               JOIN roles r ON r.id = u.role_id 
                               LEFT JOIN employees e ON e.user_id = u.id
                               WHERE (u.username = ? OR u.email = ?) AND u.status = 1
                               LIMIT 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_name'] = $user['first_name'] ? trim($user['first_name'] . ' ' . ($user['last_name'] ?? '')) : $user['username'];
            $_SESSION['admin_role'] = $user['role_name'];
            $_SESSION['admin_role_id'] = $user['rid'];
            $_SESSION['admin_role_display'] = $user['role_display'];
            $_SESSION['admin_employee_code'] = $user['employee_code'];
            $_SESSION['last_activity'] = time();

            // The previous sign-in time, read from the row above before the
            // update below overwrites it. Anything that arrived since then is
            // flagged as new in the notification bell until the admin opens it.
            // Null on a first-ever login, which flags everything unread as new.
            $_SESSION['notif_since'] = $user['last_login'] ?? null;
            $_SESSION['notif_seen'] = false;

            try {
                $pdo->prepare("UPDATE users SET last_login = NOW(), last_activity = NOW(), is_logged_in = 1 WHERE id = ?")->execute([$user['id']]);
            } catch (Exception $e) {}

            // Create session record
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + SESSION_EXPIRY);
            try {
                $stmt = $pdo->prepare("INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, login_time, last_activity, expiry_time, is_active) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, 1)");
                $stmt->execute([$user['id'], $token, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null, $expiry]);
                $pdo->prepare("UPDATE users SET api_token = ?, token_expiry = ? WHERE id = ?")->execute([$token, $expiry, $user['id']]);
            } catch (Exception $e) {}

            // Log activity
            try {
                $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, description, ip_address) 
                              VALUES (?, 'login', 'auth', 'Admin login', ?)")
                     ->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);
            } catch (Exception $e) {}

            redirect('dashboard.php');
        } else {
            $error = 'Invalid credentials or unauthorized access';
        }
    } else {
        $error = 'Please enter username and password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yatharth EMS - Employee Management System </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 50%, #1D4ED8 100%);
            position: relative;
            overflow: hidden;
        }
        .login-page::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 600px;
            height: 600px;
            border-radius: 50%;
            background: rgba(37,99,235,0.1);
        }
        .login-page::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -20%;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: rgba(37,99,235,0.08);
        }
        .login-card {
            width: 420px;
            padding: 40px;
            border-radius: 16px;
            background: rgba(255,255,255,0.98);
            box-shadow: 0 25px 80px rgba(0,0,0,0.3);
            position: relative;
            backdrop-filter: blur(20px);
        }
        .login-logo {
            width: 100px;
            height: 100px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
           
            background: rgba(255,255,255,0.1);
            padding: 10px;
        }
        .login-logo img {
            width: 340PX;
            height: 80PX;
            object-fit: contain;
            
        }
        .login-card h3 { 
            font-size: 1.4rem;
            color: #1E293B;
        }
        .login-card .subtitle {
            font-size: 0.85rem;
            color: #94A3B8;
        }
        .brand-name {
            font-weight: 800;
            background: linear-gradient(135deg, #2563EB, #1D4ED8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .alert-danger {
            background: #FEF2F2;
            border-color: #FECACA;
            color: #DC2626;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-card animate-fade-up">
        <!-- Logo Section -->
        <div class="login-logo">
            <img src="https://iili.io/CEx2XpI.png" alt="YATHARTH Logo">
        </div>
        
        <h3 class="text-center mb-0 fw-bold">Welcome to <span class="brand-name">YATHARTH</span></h3>
        <p class="text-center subtitle mb-4">Enterprise Management System</p>
        
        <?php if ($error): ?>
            <div class="alert alert-danger py-2" style="border-radius:10px;font-size:0.85rem;">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label" style="font-size:0.82rem;font-weight:500;color:var(--gray-700);">
                    <i class="fas fa-user me-1" style="color:#2563EB;"></i> Username / Email
                </label>
                <div class="input-group">
                    <span class="input-group-text" style="border-radius:8px 0 0 8px;background:white;border-right:none;">
                        <i class="fas fa-user" style="color:#94A3B8;"></i>
                    </span>
                    <input type="text" name="username" class="form-control" required autofocus 
                           style="border-radius:0 8px 8px 0;padding:10px 14px;font-size:0.9rem;border-left:none;">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label" style="font-size:0.82rem;font-weight:500;color:var(--gray-700);">
                    <i class="fas fa-lock me-1" style="color:#2563EB;"></i> Password
                </label>
                <div class="input-group">
                    <span class="input-group-text" style="border-radius:8px 0 0 8px;background:white;border-right:none;">
                        <i class="fas fa-lock" style="color:#94A3B8;"></i>
                    </span>
                    <input type="password" name="password" class="form-control" required 
                           style="border-radius:0 8px 8px 0;padding:10px 14px;font-size:0.9rem;border-left:none;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 fw-bold py-2" 
                    style="border-radius:10px;padding:12px;font-size:0.9rem;background:linear-gradient(135deg,#2563EB,#1D4ED8);border:none;box-shadow:0 4px 15px rgba(37,99,235,0.3);">
                <i class="fas fa-sign-in-alt me-2"></i>Sign In
            </button>
        </form>
        
        <div class="text-center mt-3" style="font-size:0.75rem;color:#94A3B8;">
            <i class="fas fa-shield-alt me-1"></i> Secure Login • v2.0
        </div>
    </div>
</body>
</html>