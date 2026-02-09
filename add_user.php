<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $msg='Bad CSRF'; }
  else {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = in_array($_POST['role'] ?? 'user', ['admin','user']) ? $_POST['role'] : 'user';
    if ($username === '' || $password === '') { $msg = 'Username and password required'; }
    else {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $DB->prepare('INSERT INTO users (username, password, first_name, last_name, email, role) VALUES (:u,:p,:fn,:ln,:em,:r)');
      try {
        $stmt->execute([':u'=>$username,':p'=>$hash,':fn'=>$first,':ln'=>$last,':em'=>$email,':r'=>$role]);
        header('Location: /users.php'); exit;
      } catch (Exception $e) { error_log("add_user.php error: " . $e->getMessage()); $msg = 'Internal server error'; }
    }
  }
}
include __DIR__ . '/inc/header.php';
?>
<h3>Add User</h3>
<?php if ($msg): ?><div class="alert alert-danger"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<form method="post" style="max-width:520px">
  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
  <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control"></div>
  <div class="mb-3"><label class="form-label">Password</label><input name="password" type="password" class="form-control"></div>
  <div class="mb-3"><label class="form-label">First name</label><input name="first_name" class="form-control"></div>
  <div class="mb-3"><label class="form-label">Last name</label><input name="last_name" class="form-control"></div>
  <div class="mb-3"><label class="form-label">Email</label><input name="email" class="form-control"></div>
  <div class="mb-3"><label class="form-label">Role</label>
    <select name="role" class="form-select"><option value="user">user</option><option value="admin">admin</option></select>
  </div>
  <button class="btn btn-primary">Create</button>
  <a href="/users.php" class="btn btn-link ms-2">Cancel</a>
</form>
<?php include __DIR__ . '/inc/footer.php';
