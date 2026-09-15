<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/src/TwoFactorAuth.php';
require_once __DIR__ . '/inc/secrets.php';

// This page read $_SESSION['user_id'] - which is only set once a session is
// fully authenticated, so by the time anyone could reach it the second factor
// was already moot. It reads the pending state left by login() instead.
//
// The window is deliberately short. A pending session is an accepted password
// with no second factor yet, and it should not sit open indefinitely.
const PENDING_2FA_TTL = 300;

$pending_id = $_SESSION['pending_2fa_user_id'] ?? null;
$started    = (int) ($_SESSION['pending_2fa_started'] ?? 0);

if (!$pending_id || $started <= 0 || (time() - $started) > PENDING_2FA_TTL) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started'], $_SESSION['pending_2fa_ip']);
    header('Location: /login.php'); exit;
}

// The second factor must be presented from the same address that supplied the
// password, so a stolen session cookie cannot be completed elsewhere.
if (($_SESSION['pending_2fa_ip'] ?? '') !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started'], $_SESSION['pending_2fa_ip']);
    header('Location: /login.php'); exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? ($_POST['csrf_token'] ?? ''))) {
        $err = 'Session expired. Please try again.';
    } else {
    $code = trim($_POST['code'] ?? '');
    $stmt = db()->prepare('SELECT id, username, role, totp_secret FROM users WHERE id = :id');
    $stmt->execute([':id' => $pending_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // The stored TOTP secret is encrypted at rest; opnmgr_decrypt() returns
    // legacy plaintext unchanged so pre-encryption enrolments keep working.
    $s = $user ? opnmgr_decrypt((string) $user['totp_secret']) : '';

    if ($s && TwoFactorAuth::verify($s, $code)) {
        // Promote the pending session to an authenticated one. This used to
        // call clear2FA(), a function defined nowhere in the codebase - so
        // entering the *correct* code produced a fatal error, while a wrong
        // one returned a tidy "Invalid code". Two-factor could not be
        // completed even by someone who reached this page.
        session_regenerate_id(true);

        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started'], $_SESSION['pending_2fa_ip']);

        $_SESSION['user_id']          = $user['id'];
        $_SESSION['username']         = $user['username'];
        $_SESSION['role']             = $user['role'];
        $_SESSION['login_time']       = time();
        $_SESSION['created_at']       = time();
        $_SESSION['last_activity']    = time();
        $_SESSION['last_regenerated'] = time();
        $_SESSION['ip_address']       = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent']       = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        try {
            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
        } catch (Throwable $e) {
            // non-fatal
        }

        if (function_exists('audit_log')) {
            audit_log('auth.login.2fa_verified', [
                'success'     => true,
                'object_type' => 'user',
                'object_id'   => (string) $user['id'],
                'message'     => 'Second factor accepted',
            ]);
        }

        header('Location: /dashboard.php'); exit;
    } else {
        $err = 'Invalid code';
        if (function_exists('audit_log')) {
            audit_log('auth.login.2fa_failed', [
                'success'     => false,
                'object_type' => 'user',
                'object_id'   => (string) $pending_id,
                'message'     => 'Second factor rejected',
            ]);
        }
    }
    }
}
include __DIR__ . '/inc/header.php';
?>
<h4 class="text-center mb-2" style="font-weight:600">Two Factor Verification</h4>
<div class="card card-dark p-2" style="background:transparent;border:1px solid rgba(255,255,255,0.03)">
  <?php if ($err) echo '<div class="alert alert-danger">'.htmlspecialchars($err).'</div>'; ?>
  <div class="row justify-content-center" style="margin-top:12px">
    <div class="col-12 col-sm-8 col-md-4">
      <form method="post" class="text-center">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <div class="mb-2">
          <input name="code" class="form-control form-control-md text-center" placeholder="6-digit code" autofocus inputmode="numeric" pattern="[0-9]{6}" maxlength="6" style="letter-spacing:4px">
        </div>
        <div>
          <button class="btn btn-primary btn-sm">Verify</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/inc/footer.php';
