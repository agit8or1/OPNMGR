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
        clear2FA();
        header('Location: /dashboard.php'); exit;
    } else {
        $err = 'Invalid code';
    }
}
include __DIR__ . '/inc/header.php';
?>
<h4 class="text-center text-white mb-2" style="font-weight:600">Two Factor Verification</h4>
<div class="card card-dark p-2" style="background:transparent;border:1px solid rgba(255,255,255,0.03)">
  <?php if ($err) echo '<div class="alert alert-danger">'.htmlspecialchars($err).'</div>'; ?>
  <div class="row justify-content-center" style="margin-top:12px">
    <div class="col-12 col-sm-8 col-md-4">
      <form method="post" class="text-center">
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
