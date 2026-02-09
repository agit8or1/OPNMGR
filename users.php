<?php
require_once 'inc/auth.php';
require_once 'inc/header.php';

// Require admin access
requireAdmin();

// Handle user actions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $firstName = trim($_POST['first_name']);
        $lastName = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        
        if (empty($username) || empty($password)) {
            $message = '<div class="alert alert-danger">Username and password are required.</div>';
        } else {
            try {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, first_name, last_name, email, role) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashedPassword, $firstName, $lastName, $email, $role]);
                $message = '<div class="alert alert-success">User added successfully!</div>';
            } catch (PDOException $e) {
                error_log("users.php error: " . $e->getMessage());
                $message = '<div class="alert alert-danger">An internal error occurred while adding the user.</div>';
            }
        }
    } elseif (isset($_POST['delete_user'])) {
        $userId = $_POST['user_id'];
        if ($userId != $_SESSION['user_id']) { // Prevent self-deletion
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $message = '<div class="alert alert-success">User deleted successfully!</div>';
        } else {
            $message = '<div class="alert alert-danger">You cannot delete your own account.</div>';
        }
    } elseif (isset($_POST['change_password'])) {
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        if (empty($newPassword) || strlen($newPassword) < 6) {
            $message = '<div class="alert alert-danger">Password must be at least 6 characters long.</div>';
        } elseif ($newPassword !== $confirmPassword) {
            $message = '<div class="alert alert-danger">Passwords do not match.</div>';
        } else {
            try {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
                $message = '<div class="alert alert-success">Password changed successfully!</div>';
            } catch (PDOException $e) {
                error_log("users.php error: " . $e->getMessage());
                $message = '<div class="alert alert-danger">An internal error occurred while changing the password.</div>';
            }
        }
    } elseif (isset($_POST['edit_user'])) {
        $userId = $_POST['user_id'];
        $firstName = trim($_POST['first_name']);
        $lastName = trim($_POST['last_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $password = $_POST['password'];
        
        if (empty($username)) {
            $message = '<div class="alert alert-danger">Username is required.</div>';
        } else {
            try {
                if (!empty($password)) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?, role = ?, password = ? WHERE id = ?");
                    $stmt->execute([$firstName, $lastName, $username, $email, $role, $hashedPassword, $userId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?, role = ? WHERE id = ?");
                    $stmt->execute([$firstName, $lastName, $username, $email, $role, $userId]);
                }
                $message = '<div class="alert alert-success">User updated successfully!</div>';
            } catch (PDOException $e) {
                error_log("users.php error: " . $e->getMessage());
                $message = '<div class="alert alert-danger">An internal error occurred while updating the user.</div>';
            }
        }
    }
}

// Get all users
$stmt = $pdo->query("SELECT id, username, first_name, last_name, email, role, created_at FROM users ORDER BY username");
$users = $stmt->fetchAll();
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h4 class="mb-0">User Management</h4>
                </div>
                <div class="card-body">
                    <?php echo $message; ?>
                    
                    <!-- Password Change Section -->
                    <div class="mb-4">
                        <h6>Change Your Password</h6>
                        <form method="post" class="row g-3">
                            <div class="col-md-4">
                                <input type="password" class="form-control" name="new_password" placeholder="New Password" required>
                            </div>
                            <div class="col-md-4">
                                <input type="password" class="form-control" name="confirm_password" placeholder="Confirm Password" required>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" name="change_password" class="btn btn-primary">
                                    <i class="fa fa-key me-2"></i>Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <hr>
                    
                    <!-- Add User Section -->
                    <div class="mb-4">
                        <h6>Add New User</h6>
                        <form method="post" class="row g-3">
                            <div class="col-md-2">
                                <input type="text" class="form-control" name="first_name" placeholder="First Name">
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control" name="last_name" placeholder="Last Name">
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control" name="username" placeholder="Username" required>
                            </div>
                            <div class="col-md-2">
                                <input type="email" class="form-control" name="email" placeholder="Email">
                            </div>
                            <div class="col-md-2">
                                <input type="password" class="form-control" name="password" placeholder="Password" required>
                            </div>
                            <div class="col-md-1">
                                <select class="form-select" name="role" required>
                                    <option value="user">User</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <button type="submit" name="add_user" class="btn btn-success">
                                    <i class="fa fa-plus me-2"></i>Add User
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Users List -->
                    <h6>Current Users</h6>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $user['role'] === 'admin' ? 'primary' : 'secondary'; ?>">
                                            <?php echo ucfirst($user['username'] === $_SESSION['username'] ? $user['role'] . ' (You)' : $user['role']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary me-1" onclick="editUser(<?php echo $user['id']; ?>)">
                                            <i class="fa fa-edit"></i> Edit
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" name="delete_user" class="btn btn-sm btn-danger">
                                                <i class="fa fa-trash"></i> Delete
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-muted">Current User</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header bg-dark border-secondary">
                <h5 class="modal-title text-light">Edit User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-dark">
                <form id="editUserForm" method="post">
                    <input type="hidden" name="user_id" id="editUserId">
                    <div class="mb-3">
                        <label for="editFirstName" class="form-label text-light fw-bold">First Name</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" id="editFirstName" name="first_name">
                    </div>
                    <div class="mb-3">
                        <label for="editLastName" class="form-label text-light fw-bold">Last Name</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" id="editLastName" name="last_name">
                    </div>
                    <div class="mb-3">
                        <label for="editUsername" class="form-label text-light fw-bold">Username</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" id="editUsername" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="editEmail" class="form-label text-light fw-bold">Email</label>
                        <input type="email" class="form-control bg-dark text-light border-secondary" id="editEmail" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="editRole" class="form-label text-light fw-bold">Role</label>
                        <select class="form-select bg-dark text-light border-secondary" id="editRole" name="role" required>
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="editPassword" class="form-label text-light fw-bold">New Password (leave blank to keep current)</label>
                        <input type="password" class="form-control bg-dark text-light border-secondary" id="editPassword" name="password" placeholder="Leave blank to keep current password">
                    </div>
                    <button type="submit" name="edit_user" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function editUser(userId) {
    // Fetch user data and populate modal
    fetch('get_user.php?id=' + userId)
        .then(response => response.json())
        .then(user => {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editFirstName').value = user.first_name || '';
            document.getElementById('editLastName').value = user.last_name || '';
            document.getElementById('editUsername').value = user.username;
            document.getElementById('editEmail').value = user.email || '';
            document.getElementById('editRole').value = user.role;
            
            new bootstrap.Modal(document.getElementById('editUserModal')).show();
        });
}
</script>

<?php require_once 'inc/footer.php'; ?>
