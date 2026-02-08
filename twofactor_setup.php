<?php
require_once 'inc/auth.php';
require_once 'inc/header.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: /login.php');
    exit;
}

// Get current user info
$userId = $_SESSION['user_id'];
$user = getUserById($userId);

// Handle 2FA setup
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['enable_2fa'])) {
        // Generate secret key
        $secret = generate2FASecret();
        
        // Store secret temporarily in session
        $_SESSION['temp_2fa_secret'] = $secret;
        
        // Generate QR code URL
        $qrCodeUrl = generateQRCodeUrl($secret, $user['username']);
        
        $message = '<div class="alert alert-info">Scan the QR code below with your authenticator app:</div>';
        $showQR = true;
    } elseif (isset($_POST['verify_2fa'])) {
        $code = trim($_POST['verification_code']);
        
        if (verify2FACode($_SESSION['temp_2fa_secret'], $code)) {
            // Enable 2FA for user
            enable2FA($userId, $_SESSION['temp_2fa_secret']);
            unset($_SESSION['temp_2fa_secret']);
            $message = '<div class="alert alert-success">2FA has been enabled successfully!</div>';
        } else {
            $message = '<div class="alert alert-danger">Invalid verification code. Please try again.</div>';
        }
    } elseif (isset($_POST['disable_2fa'])) {
        disable2FA($userId);
        $message = '<div class="alert alert-success">2FA has been disabled.</div>';
    }
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card card-dark">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fa fa-mobile-alt me-2"></i>Two-Factor Authentication Setup</h5>
                </div>
                <div class="card-body">
                    <?php echo $message; ?>
                    
                    <?php if (!empty($user['totp_secret'])): ?>
                        <div class="alert alert-success">
                            <i class="fa fa-check-circle me-2"></i>2FA is currently enabled for your account.
                        </div>
                        <form method="post">
                            <button type="submit" name="disable_2fa" class="btn btn-danger" onclick="return confirm('Are you sure you want to disable 2FA?')">
                                <i class="fa fa-times me-2"></i>Disable 2FA
                            </button>
                        </form>
                    <?php else: ?>
                        <?php if (isset($showQR) && $showQR): ?>
                            <div class="text-center mb-4">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($qrCodeUrl); ?>" alt="QR Code" class="img-fluid">
                                <p class="mt-2">Or enter this code manually: <code><?php echo $_SESSION['temp_2fa_secret']; ?></code></p>
                            </div>
                            <form method="post">
                                <div class="mb-3">
                                    <label for="verification_code" class="form-label">Enter verification code from your app:</label>
                                    <input type="text" class="form-control" id="verification_code" name="verification_code" required maxlength="6" pattern="[0-9]{6}">
                                </div>
                                <button type="submit" name="verify_2fa" class="btn btn-primary">
                                    <i class="fa fa-check me-2"></i>Verify & Enable 2FA
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fa fa-info-circle me-2"></i>Two-factor authentication adds an extra layer of security to your account. 
                                You'll need an authenticator app like Google Authenticator, Authy, or Microsoft Authenticator.
                            </div>
                            <form method="post">
                                <button type="submit" name="enable_2fa" class="btn btn-primary">
                                    <i class="fa fa-qrcode me-2"></i>Enable 2FA
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'inc/footer.php'; ?>

<?php
// Helper functions (these would typically be in a separate file)
function generate2FASecret() {
    return bin2hex(random_bytes(16));
}

function generateQRCodeUrl($secret, $username) {
    $issuer = 'OPNsense';
    return "otpauth://totp/{$issuer}:{$username}?secret={$secret}&issuer={$issuer}";
}

function verify2FACode($secret, $code) {
    // Convert hex secret to binary
    $secret = hex2bin($secret);
    
    // Get current timestamp
    $time = time();
    
    // Check current 30-second window and adjacent windows (±1)
    for ($i = -1; $i <= 1; $i++) {
        $timeWindow = floor(($time + ($i * 30)) / 30);
        $timeBytes = pack('N*', 0) . pack('N*', $timeWindow);
        
        $hash = hash_hmac('sha1', $timeBytes, $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncatedHash = substr($hash, $offset, 4);
        $codeInt = unpack('N', $truncatedHash)[1] & 0x7FFFFFFF;
        $generatedCode = str_pad($codeInt % 1000000, 6, '0', STR_PAD_LEFT);
        
        if ($generatedCode === $code) {
            return true;
        }
    }
    
    return false;
}

function enable2FA($userId, $secret) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET totp_secret = ? WHERE id = ?");
    $stmt->execute([$secret, $userId]);
}

function disable2FA($userId) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?");
    $stmt->execute([$userId]);
}
?>
