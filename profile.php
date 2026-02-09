<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
require_once __DIR__ . '/inc/db.php';

$page_title = "User Profile";
$message = '';
$message_type = '';

// Get current user info
$current_user = $_SESSION['username'] ?? null;
$user_data = null;

if ($current_user && $DB) {
    $stmt = $DB->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$current_user]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    switch ($action) {
        case 'update_profile':
            $email = trim($_POST['email'] ?? '');
            $timezone = trim($_POST['timezone'] ?? 'America/Chicago');

            if ($user_data && $DB) {
                try {
                    $stmt = $DB->prepare('UPDATE users SET email = ?, timezone = ? WHERE id = ?');
                    $stmt->execute([$email, $timezone, $user_data['id']]);
                    $message = 'Profile updated successfully!';
                    $message_type = 'success';

                    // Refresh user data
                    $stmt = $DB->prepare('SELECT * FROM users WHERE username = ?');
                    $stmt->execute([$current_user]);
                    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    error_log("profile.php error: " . $e->getMessage());
                    $message = 'An internal error occurred while updating the profile.';
                    $message_type = 'danger';
                }
            }
            break;

        case 'change_password':
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $message = 'All password fields are required.';
                $message_type = 'danger';
            } elseif ($new_password !== $confirm_password) {
                $message = 'New passwords do not match.';
                $message_type = 'danger';
            } elseif (strlen($new_password) < 8) {
                $message = 'Password must be at least 8 characters long.';
                $message_type = 'danger';
            } else {
                // Verify current password
                if ($user_data && password_verify($current_password, $user_data['password'])) {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    try {
                        $stmt = $DB->prepare('UPDATE users SET password = ? WHERE id = ?');
                        $stmt->execute([$new_hash, $user_data['id']]);
                        $message = 'Password changed successfully!';
                        $message_type = 'success';
                    } catch (Exception $e) {
                        error_log("profile.php error: " . $e->getMessage());
                        $message = 'An internal error occurred while changing the password.';
                        $message_type = 'danger';
                    }
                } else {
                    $message = 'Current password is incorrect.';
                    $message_type = 'danger';
                }
            }
            break;
    }
}

require_once __DIR__ . '/inc/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h2 class="text-light mb-3"><i class="fas fa-user-circle me-2"></i>User Profile</h2>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Profile Information Card -->
        <div class="col-md-6 mb-4">
            <div class="card card-dark">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Profile Information</h5>
                </div>
                <div class="card-body">
                    <?php if ($user_data): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="update_profile">

                            <div class="mb-3">
                                <label for="username" class="form-label text-light">Username</label>
                                <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" disabled>
                                <small class="text-muted">Username cannot be changed</small>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label text-light">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="role" class="form-label text-light">Role</label>
                                <input type="text" class="form-control" id="role" value="<?php echo ucfirst($user_data['role'] ?? 'user'); ?>" disabled>
                            </div>

                            <div class="mb-3">
                                <label for="timezone" class="form-label text-light">Timezone</label>
                                <select class="form-select" id="timezone" name="timezone">
                                    <?php
                                    $timezones = ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Phoenix', 'America/Anchorage', 'Pacific/Honolulu', 'UTC', 'Europe/London', 'Europe/Paris', 'Asia/Tokyo'];
                                    $current_tz = $user_data['timezone'] ?? 'America/Chicago';
                                    foreach ($timezones as $tz) {
                                        $selected = ($tz === $current_tz) ? 'selected' : '';
                                        echo "<option value=\"$tz\" $selected>$tz</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-light">2FA Status</label>
                                <div>
                                    <span class="badge bg-<?php echo !empty($user_data['two_factor_secret']) ? 'success' : 'warning'; ?>">
                                        <?php echo !empty($user_data['two_factor_secret']) ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                    <a href="/twofactor_setup.php" class="btn btn-sm btn-primary ms-2">
                                        <i class="fas fa-mobile-alt me-1"></i>Manage 2FA
                                    </a>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Profile
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">Unable to load user data.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Change Password Card -->
        <div class="col-md-6 mb-4">
            <div class="card card-dark">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-key me-2"></i>Change Password</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="change_password">

                        <div class="mb-3">
                            <label for="current_password" class="form-label text-light">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label text-light">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label text-light">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>

                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-lock me-1"></i>Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Details Card -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card card-dark">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Account Details</h5>
                </div>
                <div class="card-body">
                    <?php if ($user_data): ?>
                        <div class="row">
                            <div class="col-md-4">
                                <p class="text-muted mb-1">Account Created:</p>
                                <p class="text-light"><?php echo date('F j, Y g:i A', strtotime($user_data['created_at'] ?? 'now')); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-muted mb-1">Last Login:</p>
                                <p class="text-light"><?php echo !empty($user_data['last_login']) ? date('F j, Y g:i A', strtotime($user_data['last_login'])) : 'Never'; ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-muted mb-1">User ID:</p>
                                <p class="text-light"><?php echo $user_data['id']; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
