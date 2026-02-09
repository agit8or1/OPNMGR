<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';

$msg = '';
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /users.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { $msg='Bad CSRF'; }
  else {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = in_array($_POST['role'] ?? 'user', ['admin','user']) ? $_POST['role'] : 'user';
    $alert_levels = $_POST['alert_levels'] ?? [];
    $alert_levels_value = !empty($alert_levels) ? implode(',', $alert_levels) : 'warning,critical';
    $stmt = $DB->prepare('UPDATE users SET first_name = :fn, last_name = :ln, email = :em, role = :r, alert_levels = :al WHERE id = :id');
    $stmt->execute([':fn'=>$first_name,':ln'=>$last_name,':em'=>$email,':r'=>$role,':al'=>$alert_levels_value,':id'=>$id]);
    $msg = 'User updated.';
  }
}

$stmt = $DB->prepare('SELECT id, username, first_name, last_name, email, role, alert_levels FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id'=>$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: /users.php'); exit; }
include __DIR__ . '/inc/header.php';
?>
<div class="container mt-4" style="max-width:720px">
  <h3>Edit user: <?php echo htmlspecialchars($user['username']); ?></h3>
  <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <div class="mb-3"><label class="form-label">First name</label><input name="first_name" class="form-control" value="<?php echo htmlspecialchars($user['first_name']); ?>"></div>
    <div class="mb-3"><label class="form-label">Last name</label><input name="last_name" class="form-control" value="<?php echo htmlspecialchars($user['last_name']); ?>"></div>
    <div class="mb-3"><label class="form-label">Email</label><input name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>"></div>
    
    <!-- Alert Preferences Card -->
    <div class="mb-3">
      <div class="card bg-light border-primary">
        <div class="card-header bg-primary text-white">
          <i class="fas fa-bell me-2"></i><strong>Alert Preferences</strong>
        </div>
        <div class="card-body">
          <p class="text-muted mb-3">
            <i class="fas fa-info-circle me-1"></i>
            Select which alert levels this user should receive via email. Uncheck any levels you don't want to receive.
          </p>
          <?php
          $user_levels = !empty($user['alert_levels']) ? explode(',', $user['alert_levels']) : ['warning', 'critical'];
          ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="alert_levels[]" value="critical" id="alert_critical" <?php echo in_array('critical', $user_levels) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="alert_critical">
              <span class="badge bg-danger me-2"><i class="fas fa-exclamation-circle me-1"></i>CRITICAL</span> 
              <strong>Urgent issues requiring immediate attention</strong>
            </label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="alert_levels[]" value="warning" id="alert_warning" <?php echo in_array('warning', $user_levels) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="alert_warning">
              <span class="badge bg-warning text-white me-2"><i class="fas fa-exclamation-triangle me-1"></i>WARNING</span> 
              <strong>Important issues that need attention</strong>
            </label>
          </div>
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="alert_levels[]" value="info" id="alert_info" <?php echo in_array('info', $user_levels) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="alert_info">
              <span class="badge bg-info me-2"><i class="fas fa-info-circle me-1"></i>INFO</span> 
              <strong>Informational alerts and status updates</strong>
            </label>
          </div>
        </div>
      </div>
    </div>
    
    <div class="mb-3"><label class="form-label">Role</label>
      <select name="role" class="form-select"><option value="user" <?php echo $user['role']=='user'?'selected':''; ?>>user</option><option value="admin" <?php echo $user['role']=='admin'?'selected':''; ?>>admin</option></select>
    </div>
    <button class="btn btn-primary">Save</button>
    <a href="/users.php" class="btn btn-link ms-2">Cancel</a>
  </form>
</div>
<?php include __DIR__ . '/inc/footer.php';
