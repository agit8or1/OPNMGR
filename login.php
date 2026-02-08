<?php
require_once 'inc/auth.php';
require_once 'inc/brute_force_protection.php';

$message = '';
$bfp = new BruteForceProtection($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $ip_address = $_SERVER['REMOTE_ADDR'];

    // Check if account is locked out
    $lockout_status = $bfp->is_locked_out($username, $ip_address);

    if ($lockout_status['locked']) {
        $remaining = $lockout_status['remaining_minutes'];
        $message = '<div class="alert alert-danger"><i class="fas fa-lock me-2"></i><strong>Account Locked</strong><br>Too many failed login attempts. Please try again in ' . $remaining . ' minute' . ($remaining > 1 ? 's' : '') . '.</div>';
        error_log("SECURITY: Login attempt on locked account - Username: {$username}, IP: {$ip_address}");
    } else {
        // Attempt login
        if (login($username, $password)) {
            // Clear failed attempts on successful login
            $bfp->clear_attempts($username, $ip_address);

            // Log successful login
            error_log("LOGIN: Successful login - Username: {$username}, IP: {$ip_address}");

            header('Location: /dashboard.php');
            exit;
        } else {
            // Record failed attempt
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

// Check for custom login background
$customBg = '';
if (file_exists(__DIR__ . '/inc/db.php')) {
    require_once __DIR__ . '/inc/db.php';
    if (isset($DB)) {
        $rows = $DB->query('SELECT `name`,`value` FROM settings WHERE name = "login_background"')->fetch(PDO::FETCH_ASSOC);
        if ($rows && file_exists(__DIR__ . $rows['value'])) {
            $customBg = $rows['value'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPNsense Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: url('<?php echo $customBg ?: "https://picsum.photos/1920/1080?random=" . rand(1, 1000); ?>') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: -1;
        }
        .login-card {
            max-width: 400px;
            width: 100%;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.9);
            border: none;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card login-card shadow">
                    <div class="card-body">
                        <h3 class="card-title text-center mb-4">
                            <i class="fa fa-shield-alt me-2"></i>OPNsense Login
                        </h3>
                        <?php echo $message; ?>
                        <form method="post">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fa fa-sign-in-alt me-2"></i>Login
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Set random background image only if no custom background
        <?php if (empty($customBg)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const randomId = Math.floor(Math.random() * 1000);
            document.body.style.backgroundImage = `url('https://picsum.photos/1920/1080?random=${randomId}')`;
        });
        <?php endif; ?>
    </script>
</body>
</html>
