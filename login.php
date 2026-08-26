<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once 'inc/brute_force_protection.php';

$message = '';
$bfp = new BruteForceProtection(db());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

    // CSRF: stops a third-party page from silently signing a visitor into an
    // account the attacker controls, which would then have their subsequent
    // actions attributed to that account.
    $csrf_ok = csrf_verify($_POST['csrf_token'] ?? '');

    if (!$csrf_ok) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><strong>Session expired</strong><br>Please try signing in again.</div>';
        error_log("SECURITY: login rejected on CSRF failure - Username: {$username}, IP: {$ip_address}");
    }

    // Check if account is locked out
    $lockout_status = $csrf_ok ? $bfp->is_locked_out($username, $ip_address) : ['locked' => false];

    if (!$csrf_ok) {
        // Message already set above; fall through to render the form.
    } elseif ($lockout_status['locked']) {
        $remaining = $lockout_status['remaining_minutes'];
        $message = '<div class="alert alert-danger"><i class="fas fa-lock me-2"></i><strong>Account Locked</strong><br>Too many failed login attempts. Please try again in ' . $remaining . ' minute' . ($remaining > 1 ? 's' : '') . '.</div>';
        error_log("SECURITY: Login attempt on locked account - Username: {$username}, IP: {$ip_address}");
    } else {
        if (login($username, $password)) {
            $bfp->clear_attempts($username, $ip_address);
            error_log("LOGIN: Successful login - Username: {$username}, IP: {$ip_address}");
            header('Location: /dashboard.php');
            exit;
        } else {
            $attempt_result = $bfp->record_failed_attempt($username, $ip_address);
            if ($attempt_result['locked']) {
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><strong>Account Locked</strong><br>Maximum login attempts exceeded. Your account has been locked for 15 minutes.</div>';
                error_log("SECURITY: Account locked due to failed attempts - Username: {$username}, IP: {$ip_address}, Total Attempts: {$attempt_result['attempts']}");
            } else {
                $remaining_attempts = MAX_LOGIN_ATTEMPTS - $attempt_result['attempts'];
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><strong>Login Failed</strong><br>Invalid username or password. You have ' . $remaining_attempts . ' attempt' . ($remaining_attempts > 1 ? 's' : '') . ' remaining.</div>';
                error_log("SECURITY: Failed login attempt - Username: {$username}, IP: {$ip_address}, Attempts: {$attempt_result['attempts']}");
            }
        }
    }
}

// Load brand name
$loginBrand = 'OPNManager';
try {
    $rows = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['brand_name'])) $loginBrand = $rows['brand_name'];
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlentities($loginBrand) ?> - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/js/theme.js"></script>
    <style>
        * { font-family: Inter, system-ui, -apple-system, sans-serif; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        [data-theme="dark"] body {
            background: #0f1117;
            background-image:
                radial-gradient(ellipse at 20% 50%, rgba(59,130,246,0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(99,102,241,0.06) 0%, transparent 50%);
        }

        [data-theme="light"] body {
            background: #f0f2f5;
            background-image:
                radial-gradient(ellipse at 20% 50%, rgba(59,130,246,0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(99,102,241,0.03) 0%, transparent 50%);
        }

        .login-wrapper { width: 100%; max-width: 400px; }

        .login-header { text-align: center; margin-bottom: 32px; }

        .login-header .brand-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.4rem; margin-bottom: 16px;
        }

        [data-theme="dark"] .login-header .brand-icon { background: rgba(59,130,246,0.15); color: #3b82f6; }
        [data-theme="light"] .login-header .brand-icon { background: rgba(59,130,246,0.1); color: #2563eb; }

        .login-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0; }
        [data-theme="dark"] .login-header h1 { color: #e8eaed; }
        [data-theme="light"] .login-header h1 { color: #1a1d23; }

        .login-header .subtitle { font-size: 0.875rem; margin-top: 4px; }
        [data-theme="dark"] .login-header .subtitle { color: #9aa0a6; }
        [data-theme="light"] .login-header .subtitle { color: #6b7280; }

        .login-card { border-radius: 12px; padding: 32px; }

        [data-theme="dark"] .login-card {
            background: #1a1d23; border: 1px solid rgba(255,255,255,0.06);
            box-shadow: 0 4px 24px rgba(0,0,0,0.4);
        }
        [data-theme="light"] .login-card {
            background: #ffffff; border: 1px solid rgba(0,0,0,0.08);
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }

        .form-label { font-size: 0.875rem; font-weight: 500; }
        [data-theme="dark"] .form-label { color: #9aa0a6; }
        [data-theme="light"] .form-label { color: #4b5563; }

        .form-control { border-radius: 8px; padding: 10px 14px; font-size: 0.9rem; transition: border-color 0.15s, box-shadow 0.15s; }
        [data-theme="dark"] .form-control { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #e8eaed; }
        [data-theme="dark"] .form-control:focus { background: rgba(255,255,255,0.08); border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); color: #e8eaed; }
        [data-theme="light"] .form-control { background: #f9fafb; border: 1px solid #d1d5db; color: #1a1d23; }
        [data-theme="light"] .form-control:focus { background: #ffffff; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }

        .btn-login {
            width: 100%; padding: 10px; border-radius: 8px; font-weight: 600;
            font-size: 0.9rem; border: none; background: #3b82f6; color: #fff;
            transition: background 0.15s, transform 0.1s;
        }
        .btn-login:hover { background: #2563eb; color: #fff; }
        .btn-login:active { transform: scale(0.98); }

        .login-footer { text-align: center; margin-top: 24px; font-size: 0.8rem; }
        [data-theme="dark"] .login-footer { color: #6b7280; }
        [data-theme="light"] .login-footer { color: #9ca3af; }

        .theme-toggle-login {
            position: fixed; top: 16px; right: 16px; background: none;
            border-radius: 8px; padding: 6px 10px; cursor: pointer;
            font-size: 0.9rem; transition: all 0.15s;
        }
        [data-theme="dark"] .theme-toggle-login { color: #9aa0a6; border: 1px solid rgba(255,255,255,0.1); }
        [data-theme="dark"] .theme-toggle-login:hover { color: #e8eaed; background: rgba(255,255,255,0.05); }
        [data-theme="light"] .theme-toggle-login { color: #6b7280; border: 1px solid rgba(0,0,0,0.1); }
        [data-theme="light"] .theme-toggle-login:hover { color: #1a1d23; background: rgba(0,0,0,0.03); }
    </style>
</head>
<body>
    <button class="theme-toggle-login" onclick="toggleTheme()" title="Toggle theme">
        <i class="fas fa-sun"></i>
    </button>

    <div class="login-wrapper">
        <div class="login-header">
            <div class="brand-icon"><i class="fas fa-shield-halved"></i></div>
            <h1><?php echo htmlentities($loginBrand) ?></h1>
            <div class="subtitle">Sign in to continue</div>
        </div>

        <div class="login-card">
            <?php echo $message; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required autocomplete="username" placeholder="Enter username">
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password" placeholder="Enter password">
                </div>
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>
            </form>
        </div>

        <div class="login-footer">
            Firewall Management Platform
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
