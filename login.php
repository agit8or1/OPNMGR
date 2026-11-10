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
        $login_result = login($username, $password);

        // '2fa' is truthy, so it has to be tested before the success branch:
        // the password was right but the session is not authenticated yet.
        if ($login_result === '2fa') {
            $bfp->clear_attempts($username, $ip_address);
            error_log("LOGIN: Password accepted, awaiting 2FA - Username: {$username}, IP: {$ip_address}");
            header('Location: /verify2fa.php');
            exit;
        } elseif ($login_result) {
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
    <link rel="stylesheet" href="/assets/css/auth.css">
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
