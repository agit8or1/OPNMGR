<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $error = 'CSRF verification failed.';
    } elseif (isset($_POST['add_command'])) {
        $pattern = trim($_POST['command_pattern'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? 'system';
        $risk_level = $_POST['risk_level'] ?? 'LOW';
        $requires_confirmation = isset($_POST['requires_confirmation']);
        $timeout = (int)($_POST['timeout_seconds'] ?? 30);
        
        if (empty($pattern) || empty($description)) {
            $error = 'Command pattern and description are required.';
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO approved_commands (command_pattern, description, category, risk_level, requires_confirmation, timeout_seconds) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$pattern, $description, $category, $risk_level, $requires_confirmation, $timeout]);
                $success = 'Command added successfully.';
            } catch (Exception $e) {
                error_log("approved_commands.php error: " . $e->getMessage());
                $error = 'An internal error occurred while adding the command.';
            }
        }
    } elseif (isset($_POST['delete_command'])) {
        $id = (int)($_POST['command_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = db()->prepare('DELETE FROM approved_commands WHERE id = ?');
                $stmt->execute([$id]);
                $success = 'Command deleted successfully.';
            } catch (Exception $e) {
                error_log("approved_commands.php error: " . $e->getMessage());
                $error = 'An internal error occurred while deleting the command.';
            }
        }
    }
}

// Get all approved commands
$stmt = db()->query('SELECT * FROM approved_commands ORDER BY category, risk_level, command_pattern');
$commands = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$stmt = db()->query('SELECT category, risk_level, COUNT(*) as count FROM approved_commands GROUP BY category, risk_level ORDER BY category, risk_level');
$stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/inc/header.php';
?>

<div class="card card-dark">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-4">
            <small class="text-light fw-bold mb-0">
                <i class="fas fa-terminal me-1"></i>Approved Commands Management
            </small>
            <div class="ms-auto">
                <a href="/firewalls.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Firewalls
                </a>
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card card-ghost p-3 border border-secondary">
                    <h6 class="text-white fw-bold mb-3">Command Statistics</h6>
                    <div class="row">
                        <?php
                        $category_counts = [];
                        $risk_counts = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
                        foreach ($stats as $stat) {
                            $category_counts[$stat['category']] = ($category_counts[$stat['category']] ?? 0) + $stat['count'];
                            $risk_counts[$stat['risk_level']] += $stat['count'];
                        }
                        ?>
                        <div class="col-md-6">
                            <strong class="text-white">By Category:</strong><br>
                            <?php foreach ($category_counts as $cat => $count): ?>
                                <span class="badge bg-info me-2"><?php echo ucfirst($cat); ?>: <?php echo $count; ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-md-6">
                            <strong class="text-white">By Risk Level:</strong><br>
                            <span class="badge bg-success me-1">LOW: <?php echo $risk_counts['LOW']; ?></span>
                            <span class="badge bg-warning me-1">MEDIUM: <?php echo $risk_counts['MEDIUM']; ?></span>
                            <span class="badge bg-danger me-1">HIGH: <?php echo $risk_counts['HIGH']; ?></span>
                            <span class="badge bg-dark">CRITICAL: <?php echo $risk_counts['CRITICAL']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Add New Command -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card card-ghost p-3 border border-secondary">
                    <h6 class="text-white fw-bold mb-3">Add New Approved Command</h6>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label text-white">Command Pattern</label>
                                    <input type="text" name="command_pattern" class="form-control" placeholder="e.g., ping -c 4 %" required>
                                    <small class="text-muted">Use % as wildcard for parameters</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-white">Description</label>
                                    <input type="text" name="description" class="form-control" placeholder="e.g., Network connectivity test" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label text-white">Category</label>
                                    <select name="category" class="form-control">
                                        <option value="system">System</option>
                                        <option value="network">Network</option>
                                        <option value="packages">Packages</option>
                                        <option value="services">Services</option>
                                        <option value="logs">Logs</option>
                                        <option value="security">Security</option>
                                        <option value="agent">Agent</option>
                                        <option value="files">Files</option>
                                        <option value="backup">Backup</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-white">Risk Level</label>
                                    <select name="risk_level" class="form-control">
                                        <option value="LOW">LOW</option>
                                        <option value="MEDIUM">MEDIUM</option>
                                        <option value="HIGH">HIGH</option>
                                        <option value="CRITICAL">CRITICAL</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-white">Timeout (seconds)</label>
                                    <input type="number" name="timeout_seconds" class="form-control" value="30" min="5" max="3600">
                                </div>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="requires_confirmation" class="form-check-input">
                                        <label class="form-check-label text-white">Requires Confirmation</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="add_command" class="btn btn-success">
                            <i class="fas fa-plus me-1"></i>Add Command
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Commands List -->
        <div class="row">
            <div class="col-md-12">
                <div class="card card-ghost p-3 border border-secondary">
                    <h6 class="text-white fw-bold mb-3">Approved Commands (<?php echo count($commands); ?>)</h6>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Pattern</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th>Risk</th>
                                    <th>Timeout</th>
                                    <th>Confirm</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($commands as $cmd): ?>
                                <tr>
                                    <td><code class="text-light"><?php echo htmlspecialchars($cmd['command_pattern']); ?></code></td>
                                    <td class="text-light small"><?php echo htmlspecialchars($cmd['description']); ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($cmd['category']); ?></span></td>
                                    <td>
                                        <?php
                                        $risk_class = match($cmd['risk_level']) {
                                            'LOW' => 'success',
                                            'MEDIUM' => 'warning',
                                            'HIGH' => 'danger',
                                            'CRITICAL' => 'dark',
                                            default => 'secondary'
                                        };
                                        ?>
                                        <span class="badge bg-<?php echo $risk_class; ?>"><?php echo $cmd['risk_level']; ?></span>
                                    </td>
                                    <td><small class="text-muted"><?php echo $cmd['timeout_seconds']; ?>s</small></td>
                                    <td><?php echo $cmd['requires_confirmation'] ? '<i class="fas fa-check text-warning"></i>' : '<i class="fas fa-times text-muted"></i>'; ?></td>
                                    <td class="text-end">
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Delete this command?');">
                                            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="command_id" value="<?php echo $cmd['id']; ?>">
                                            <button type="submit" name="delete_command" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
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

<?php include __DIR__ . '/inc/footer.php'; ?>