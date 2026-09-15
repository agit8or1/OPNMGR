<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/secrets.php';
require_once __DIR__ . '/vendor/autoload.php';

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

// Authenticate before anything is emitted. inc/header.php starts sending the
// page, and once output has begun header('Location: ...') cannot take effect:
// this file used to include it first, so an unauthenticated request got 200 and
// a bare page shell instead of a redirect to the login form.
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

// Everything that might redirect has run; start the page.
require_once __DIR__ . '/inc/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
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
                                <?php
                                // Rendered here, on this server. This used to be an
                                // <img> pointing at api.qrserver.com with the otpauth
                                // URI in the query string - which handed the shared
                                // TOTP secret to a third party, and put it in their
                                // logs and every proxy in between.
                                echo render2FAQrSvg($qrCodeUrl);
                                ?>
                                <p class="mt-3 mb-1">Or enter this key manually:</p>
                                <p><code style="font-size:1.05rem; letter-spacing:.08em;"><?php
                                    echo htmlspecialchars(chunk_split(totpSecretForApp($_SESSION['temp_2fa_secret']), 4, ' '));
                                ?></code></p>
                                <p class="text-muted small mb-0">Spaces are for readability; most apps ignore them.</p>
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

/**
 * Base32 (RFC 4648, no padding) - the encoding otpauth:// URIs require.
 *
 * The secret is generated and stored as hex. It used to be placed into the URI
 * as hex too, which cannot work: hex contains 0, 1, 8 and 9, none of which are
 * in the Base32 alphabet, and the letters a-f decode to entirely different
 * values. An authenticator therefore derived a different key from the one this
 * server verifies against, so a scanned code never matched and two-factor could
 * not be enabled at all.
 *
 * Encoding the same bytes correctly fixes that without changing what is stored,
 * so any existing row keeps verifying through the unchanged hex path.
 */
function base32Encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buffer = 0;
    $bitsLeft = 0;

    for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
        $buffer = ($buffer << 8) | ord($bytes[$i]);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $out .= $alphabet[($buffer >> $bitsLeft) & 31];
        }
    }
    if ($bitsLeft > 0) {
        $out .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
    }
    return $out;
}

/** The stored hex secret, in the form an authenticator app expects. */
function totpSecretForApp(string $hexSecret): string
{
    $raw = hex2bin($hexSecret);
    return $raw === false ? '' : base32Encode($raw);
}

function generateQRCodeUrl($secret, $username) {
    $issuer = 'OPNsense';
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($issuer),
        rawurlencode($username),
        totpSecretForApp($secret),
        rawurlencode($issuer)
    );
}

/**
 * Render the enrolment QR as an inline SVG.
 *
 * SVG rather than PNG so no image extension is required, and inline so the
 * secret never becomes a URL - not to a third party, and not to this server
 * either, where it would land in the access log.
 */
function render2FAQrSvg(string $uri): string
{
    try {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(220, 1),
            new SvgImageBackEnd()
        ));
        $svg = $writer->writeString($uri);

        // Strip the XML declaration so the fragment can be embedded directly.
        $svg = preg_replace('/<\?xml[^>]*\?>\s*/', '', $svg);

        return '<div class="d-inline-block bg-white p-3 rounded" role="img" '
             . 'aria-label="Two-factor enrolment QR code">' . $svg . '</div>';
    } catch (Throwable $e) {
        error_log('twofactor_setup.php could not render the QR code: ' . $e->getMessage());
        return '<div class="alert alert-warning">The QR code could not be rendered. '
             . 'Enter the key below into your authenticator app manually.</div>';
    }
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
    // Stored encrypted, not hashed: TOTP verification needs the secret back.
    $stmt = db()->prepare("UPDATE users SET totp_secret = ? WHERE id = ?");
    $stmt->execute([opnmgr_encrypt($secret), $userId]);
}

function disable2FA($userId) {
    $stmt = db()->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?");
    $stmt->execute([$userId]);
}
?>
