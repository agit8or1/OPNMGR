<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/header.php';

// Handle token generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_token'])) {
    $days = intval($_POST['days'] ?? 1);
    $days = max(1, min(30, $days));
    
    // Clean up expired tokens
    $DB->prepare("DELETE FROM enrollment_tokens WHERE expires_at < NOW()")->execute();
    
    // Generate new token
    $token = bin2hex(random_bytes(32));
    $stmt = $DB->prepare("INSERT INTO enrollment_tokens (token, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL ? DAY))");
    $stmt->execute([$token, $days]);
    
    $success_message = "New enrollment token generated successfully!";
    $new_token = $token;
}

// Handle token deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_token'])) {
    $token_id = intval($_POST['token_id']);
    $stmt = $DB->prepare("DELETE FROM enrollment_tokens WHERE id = ?");
    $stmt->execute([$token_id]);
    $success_message = "Token deleted successfully!";
}

// Get all tokens
$tokens = $DB->query("
    SELECT id, token, created_at, expires_at, used,
           CASE 
               WHEN expires_at < NOW() THEN 'expired'
               WHEN used = 1 THEN 'used'
               ELSE 'active'
           END as status,
           TIMESTAMPDIFF(HOUR, NOW(), expires_at) as hours_until_expiry
    FROM enrollment_tokens 
    ORDER BY created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$stats = $DB->query("
    SELECT 
        COUNT(*) as total_tokens,
        SUM(CASE WHEN used = 1 THEN 1 ELSE 0 END) as used_tokens,
        SUM(CASE WHEN used = 0 AND expires_at > NOW() THEN 1 ELSE 0 END) as active_tokens,
        SUM(CASE WHEN expires_at < NOW() THEN 1 ELSE 0 END) as expired_tokens
    FROM enrollment_tokens
")->fetch(PDO::FETCH_ASSOC);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-key"></i> Enrollment Token Management</h4>
                </div>
                <div class="card-body">
                    
                    <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($success_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($new_token)): ?>
                    <div class="alert alert-info">
                        <h5>New Token Generated:</h5>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control font-monospace" value="<?= $new_token ?>" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('<?= $new_token ?>')">Copy</button>
                        </div>
                        <small class="text-muted">Enrollment Command:</small>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace small" value="wget -q -O /tmp/opnsense_enroll.sh &quot;https://opn.agit8or.net/enroll_firewall.php?action=download&amp;token=<?= $new_token ?>&quot; && chmod +x /tmp/opnsense_enroll.sh && bash /tmp/opnsense_enroll.sh" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('wget -q -O /tmp/opnsense_enroll.sh &quot;https://opn.agit8or.net/enroll_firewall.php?action=download&token=<?= $new_token ?>&quot; && chmod +x /tmp/opnsense_enroll.sh && bash /tmp/opnsense_enroll.sh')">Copy</button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Token Stats -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body text-center">
                                    <h3><?= $stats['total_tokens'] ?></h3>
                                    <small>Total Tokens</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h3><?= $stats['active_tokens'] ?></h3>
                                    <small>Active Tokens</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h3><?= $stats['used_tokens'] ?></h3>
                                    <small>Used Tokens</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-danger text-white">
                                <div class="card-body text-center">
                                    <h3><?= $stats['expired_tokens'] ?></h3>
                                    <small>Expired Tokens</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Generate New Token -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5>Generate New Token</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="d-flex align-items-center gap-3">
                                <label for="days" class="form-label mb-0">Expires in:</label>
                                <select name="days" id="days" class="form-select" style="width: auto;">
                                    <option value="1">1 day</option>
                                    <option value="7" selected>7 days</option>
                                    <option value="14">14 days</option>
                                    <option value="30">30 days</option>
                                </select>
                                <button type="submit" name="generate_token" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Generate Token
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Tokens List -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5>All Tokens</h5>
                            <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Status</th>
                                            <th>Token</th>
                                            <th>Created</th>
                                            <th>Expires</th>
                                            <th>Time Remaining</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tokens as $token): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                $badgeClass = match($token['status']) {
                                                    'active' => 'bg-success',
                                                    'used' => 'bg-warning',
                                                    'expired' => 'bg-danger',
                                                    default => 'bg-secondary'
                                                };
                                                ?>
                                                <span class="badge <?= $badgeClass ?>"><?= ucfirst($token['status']) ?></span>
                                            </td>
                                            <td>
                                                <span class="font-monospace"><?= substr($token['token'], 0, 16) ?>...</span>
                                                <button class="btn btn-sm btn-outline-secondary ms-2" onclick="copyToClipboard('<?= $token['token'] ?>')">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </td>
                                            <td><?= date('M j, Y H:i', strtotime($token['created_at'])) ?></td>
                                            <td><?= date('M j, Y H:i', strtotime($token['expires_at'])) ?></td>
                                            <td>
                                                <?php if ($token['hours_until_expiry'] > 0): ?>
                                                    <?= $token['hours_until_expiry'] ?> hours
                                                <?php else: ?>
                                                    <span class="text-danger">Expired</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($token['status'] === 'active'): ?>
                                                <button class="btn btn-sm btn-info" onclick="showEnrollmentCommand('<?= $token['token'] ?>')">
                                                    <i class="fas fa-terminal"></i> Command
                                                </button>
                                                <?php endif; ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this token?')">
                                                    <input type="hidden" name="token_id" value="<?= $token['id'] ?>">
                                                    <button type="submit" name="delete_token" class="btn btn-sm btn-outline-danger">
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
    </div>
</div>

<!-- Enrollment Command Modal -->
<div class="modal fade" id="commandModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Enrollment Command</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Run this command on your OPNsense firewall:</p>
                <div class="input-group">
                    <textarea id="enrollmentCommand" class="form-control font-monospace" rows="3" readonly></textarea>
                    <button class="btn btn-outline-secondary" onclick="copyToClipboard(document.getElementById('enrollmentCommand').value)">Copy</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Show brief success feedback
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(() => btn.innerHTML = originalText, 1000);
    });
}

function showEnrollmentCommand(token) {
    const command = `wget -q -O /tmp/opnsense_enroll.sh "https://opn.agit8or.net/enroll_firewall.php?action=download&token=${token}" && chmod +x /tmp/opnsense_enroll.sh && bash /tmp/opnsense_enroll.sh`;
    document.getElementById('enrollmentCommand').value = command;
    new bootstrap.Modal(document.getElementById('commandModal')).show();
}
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>