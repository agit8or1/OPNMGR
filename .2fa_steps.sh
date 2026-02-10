#!/usr/bin/env bash
set -euo pipefail
echo "Applying 2FA changes..."
# Backup
cp -a "$WEBROOT/inc/auth.php" "$WEBROOT/inc/auth.php.bak" || true
awk 'BEGIN{in=1}/function loginUser/ {print; while(getline){ if ($0 ~ /return false;/) { print "    return false;"; break } print }}' "$WEBROOT/inc/auth.php" > /tmp/auth_stub.php || true
# Overwrite with safer version (simple replace)
cat > "$WEBROOT/inc/auth.php" <<'PHP'
<?php
require_once __DIR__ . '/db.php';
// Simple session-based auth helpers
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function isLoggedIn() {
  return !empty($_SESSION['user_id']) && empty($_SESSION['2fa_required']);
}

function requireLogin() {
  if (!isLoggedIn()) {
    header('Location: /login.php');
    exit;
  }
}

function loginUser($username, $password) {
  global $DB;
  if (empty($username) || empty($password)) return false;
  $stmt = $DB->prepare('SELECT id, username, password, totp_secret FROM users WHERE username = ? LIMIT 1');
  $stmt->execute([$username]);
  $u = $stmt->fetch();
  if ($u && password_verify($password, $u['password'])) {
    // if user has a totp_secret, set a flag and require code verification
    session_regenerate_id(true);
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['username'] = $u['username'];
    if (!empty($u['totp_secret'])) {
      // mark that 2FA is required for this session until verified
      $_SESSION['2fa_required'] = true;
      // do not mark fully authenticated yet
      return '2fa';
    }
    return true;
  }
  return false;
}

function is2FARequired() {
  return !empty($_SESSION['2fa_required']);
}

function clear2FA() {
  unset($_SESSION['2fa_required']);
}

function logout() {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
  header('Location: /login.php');
  exit;
}
PHP

cat > "$WEBROOT/twofactor_setup.php" <<'PHP'
<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/src/TwoFactorAuth.php';

// simple base32 generator
function random_base32($length=16){
  $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $s = '';
  for ($i=0;$i<$length;$i++) $s .= $chars[random_int(0,31)];
  return $s;
}

$userId = $_SESSION['user_id'];
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['enable'])) {
  $secret = random_base32(16);
  $stmt = $DB->prepare('UPDATE users SET totp_secret = :s WHERE id = :id');
  $stmt->execute([':s'=>$secret, ':id'=>$userId]);
  $msg = '2FA enabled. Save the secret in your authenticator app: ' . $secret;
}
include __DIR__ . '/inc/header.php';
?>
<h4>Two Factor Setup</h4>
<div class="card card-dark p-3">
  <?php if ($msg) echo '<div class="alert alert-success">'.htmlspecialchars($msg).'</div>'; ?>
  <form method="post">
  <p>Click enable to generate a TOTP secret and add it to your authenticator app (Google Authenticator, Authy, etc.).</p>
  <button class="btn btn-primary" name="enable" value="1">Enable 2FA</button>
  </form>
</div>
<?php include __DIR__ . '/inc/footer.php';
PHP

cat > "$WEBROOT/verify2fa.php" <<'PHP'
<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/src/TwoFactorAuth.php';

if (empty($_SESSION['user_id'])) {
  header('Location: /login.php'); exit;
}
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code = trim($_POST['code'] ?? '');
  $stmt = $DB->prepare('SELECT totp_secret FROM users WHERE id = :id');
  $stmt->execute([':id'=>$_SESSION['user_id']]);
  $s = $stmt->fetchColumn();
  if ($s && TwoFactorAuth::verify($s, $code)) {
    // clear 2fa requirement
    clear2FA();
    header('Location: /dashboard.php'); exit;
  } else {
    $err = 'Invalid code';
  }
}
include __DIR__ . '/inc/header.php';
?>
<h4>Two Factor Verification</h4>
<div class="card card-dark p-3">
  <?php if ($err) echo '<div class="alert alert-danger">'.htmlspecialchars($err).'</div>'; ?>
  <form method="post">
  <div class="mb-2"><input name="code" class="form-control" placeholder="6-digit code"></div>
  <button class="btn btn-primary">Verify</button>
  </form>
</div>
<?php include __DIR__ . '/inc/footer.php';
PHP

echo "2FA files written. Be sure to restart php-fpm.\n"
