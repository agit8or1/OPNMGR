<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/env.php';

$page_title = "System Update";

$message = '';
$message_type = '';
$update_available = false;
$current_version = file_exists(__DIR__ . '/VERSION') ? trim(file_get_contents(__DIR__ . '/VERSION')) : 'Unknown';
$latest_version = 'Checking...';
$commit_log = [];

// GitHub configuration
$github_repo = env('GITHUB_REPO', 'OPNMGR');
$github_username = env('GITHUB_USERNAME', 'agit8or1');
$github_token = env('GITHUB_PAT', '');

// Check for updates
if (isset($_POST['check_updates'])) {
    $github_api_url = "https://api.github.com/repos/{$github_username}/{$github_repo}/commits/main";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $github_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'OPNsense-Manager');

    if (!empty($github_token)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: token {$github_token}",
            "Accept: application/vnd.github.v3+json"
        ]);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $commit_data = json_decode($response, true);
        $latest_commit = substr($commit_data['sha'], 0, 7);

        // Get local commit
        exec('cd ' . escapeshellarg(__DIR__) . ' && git rev-parse --short HEAD 2>&1', $local_commit_output, $return_code);
        $local_commit = ($return_code === 0 && !empty($local_commit_output)) ? trim($local_commit_output[0]) : 'unknown';

        if ($local_commit !== $latest_commit && $local_commit !== 'unknown') {
            $update_available = true;
            $message = "Update available! Current: {$local_commit}, Latest: {$latest_commit}";
            $message_type = 'warning';
        } else {
            $message = "You're running the latest version ({$local_commit})";
            $message_type = 'success';
        }

        // Get recent commits
        $commits_api_url = "https://api.github.com/repos/{$github_username}/{$github_repo}/commits?per_page=10";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $commits_api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'OPNsense-Manager');

        if (!empty($github_token)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: token {$github_token}",
                "Accept: application/vnd.github.v3+json"
            ]);
        }

        $commits_response = curl_exec($ch);
        curl_close($ch);

        if (!empty($commits_response)) {
            $commit_log = json_decode($commits_response, true);
        }
    } else {
        $message = "Failed to check for updates. HTTP Code: {$http_code}";
        $message_type = 'danger';
    }
}

// Perform update
if (isset($_POST['perform_update'])) {
    $update_log = [];

    // Create backup first
    $backup_dir = __DIR__ . '/backups/pre-update-' . date('Y-m-d_H-i-s');
    exec('mkdir -p ' . escapeshellarg($backup_dir) . ' 2>&1', $output, $return_code);

    if ($return_code === 0) {
        $update_log[] = "✓ Created backup directory: {$backup_dir}";

        // Backup database
        $db_backup = $backup_dir . '/database.sql';
        $db_host = env('DB_HOST', 'localhost');
        $db_name = env('DB_NAME', 'opnsense_fw');
        $db_user = env('DB_USER', 'opnsense_user');
        $db_pass = env('DB_PASS', '');

        exec("mysqldump -h " . escapeshellarg($db_host) . " -u " . escapeshellarg($db_user) . " -p" . escapeshellarg($db_pass) . " " . escapeshellarg($db_name) . " > " . escapeshellarg($db_backup) . " 2>&1", $output, $return_code);

        if ($return_code === 0) {
            $update_log[] = "✓ Database backed up successfully";
        } else {
            $update_log[] = "✗ Database backup failed: " . implode("\n", $output);
        }

        // Stash local changes
        exec('cd ' . escapeshellarg(__DIR__) . ' && git stash 2>&1', $output, $return_code);
        if ($return_code === 0) {
            $update_log[] = "✓ Stashed local changes";
        }

        // Pull from GitHub
        $git_command = 'cd ' . escapeshellarg(__DIR__) . ' && git pull origin main 2>&1';

        if (!empty($github_token)) {
            $git_command = 'cd ' . escapeshellarg(__DIR__) . ' && git pull https://' . escapeshellarg($github_token) . '@github.com/' . escapeshellarg($github_username) . '/' . escapeshellarg($github_repo) . '.git main 2>&1';
        }

        exec($git_command, $output, $return_code);

        if ($return_code === 0) {
            $update_log[] = "✓ Successfully pulled updates from GitHub";
            $update_log[] = "Output: " . implode("\n", $output);

            // Run any post-update scripts if they exist
            if (file_exists(__DIR__ . '/scripts/post_update.sh')) {
                exec('bash ' . escapeshellarg(__DIR__ . '/scripts/post_update.sh') . ' 2>&1', $output, $return_code);
                if ($return_code === 0) {
                    $update_log[] = "✓ Post-update script executed successfully";
                } else {
                    $update_log[] = "⚠ Post-update script had issues: " . implode("\n", $output);
                }
            }

            // Update version file
            exec('cd ' . escapeshellarg(__DIR__) . ' && git rev-parse --short HEAD 2>&1', $new_commit_output, $return_code);
            if ($return_code === 0 && !empty($new_commit_output)) {
                $new_commit = trim($new_commit_output[0]);
                file_put_contents(__DIR__ . '/VERSION', "3.0.0-{$new_commit}\n");
                $update_log[] = "✓ Version updated to 3.0.0-{$new_commit}";
            }

            $message = "Update completed successfully! Please review the log below.";
            $message_type = 'success';
        } else {
            $update_log[] = "✗ Failed to pull updates: " . implode("\n", $output);
            $message = "Update failed. Check the log below for details.";
            $message_type = 'danger';
        }
    } else {
        $update_log[] = "✗ Failed to create backup directory";
        $message = "Update aborted: Could not create backup directory";
        $message_type = 'danger';
    }
}

include __DIR__ . '/inc/header.php';
?>

<style>
.update-card {
    background: #ffffff;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1rem;
}

.version-badge {
    font-size: 1.5rem;
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 6px;
}

.commit-item {
    background: #f8fafc;
    border-left: 4px solid #3b82f6;
    padding: 1rem;
    margin-bottom: 0.5rem;
    border-radius: 4px;
}

.commit-sha {
    font-family: 'Courier New', monospace;
    background: #1e293b;
    color: #e2e8f0;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    font-size: 0.85rem;
}

.update-log {
    background: #1e293b;
    color: #e2e8f0;
    padding: 1rem;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    max-height: 400px;
    overflow-y: auto;
    white-space: pre-wrap;
}

.warning-box {
    background: #fef3c7;
    border: 2px solid #f59e0b;
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
}
</style>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="text-dark"><i class="fas fa-sync-alt me-2 text-primary"></i>System Update</h2>
            <p class="text-muted">Update OPNsense Manager from GitHub repository</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($github_token)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>GitHub Token Not Configured:</strong> Updates from private repositories require a GitHub Personal Access Token.
            Configure <code>GITHUB_PAT</code> in your .env file.
        </div>
    <?php endif; ?>

    <!-- Current Version -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="update-card">
                <h5 class="text-dark mb-3"><i class="fas fa-code-branch me-2"></i>Current Version</h5>
                <div class="text-center">
                    <span class="version-badge bg-primary text-white">
                        <?php echo htmlspecialchars($current_version); ?>
                    </span>
                </div>
                <div class="mt-3 text-center">
                    <form method="post" class="d-inline">
                        <button type="submit" name="check_updates" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Check for Updates
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="update-card">
                <h5 class="text-dark mb-3"><i class="fas fa-info-circle me-2"></i>Repository Info</h5>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Repository:</dt>
                    <dd class="col-sm-8">
                        <a href="https://github.com/<?php echo htmlspecialchars($github_username . '/' . $github_repo); ?>" target="_blank">
                            <?php echo htmlspecialchars($github_username . '/' . $github_repo); ?>
                            <i class="fas fa-external-link-alt ms-1"></i>
                        </a>
                    </dd>

                    <dt class="col-sm-4">Branch:</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-secondary">main</span>
                    </dd>

                    <dt class="col-sm-4">Token Status:</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-<?php echo empty($github_token) ? 'warning' : 'success'; ?>">
                            <?php echo empty($github_token) ? 'Not Configured' : 'Configured'; ?>
                        </span>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <?php if ($update_available): ?>
        <div class="warning-box">
            <h5 class="text-warning mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Update Available</h5>
            <p class="mb-3">A new version is available. Before updating:</p>
            <ul class="mb-3">
                <li>A database backup will be created automatically</li>
                <li>Your local changes will be stashed</li>
                <li>The application will pull the latest code from GitHub</li>
                <li>You can rollback using the backup if needed</li>
            </ul>
            <form method="post" onsubmit="return confirm('Are you sure you want to update? This will pull the latest code from GitHub.');">
                <button type="submit" name="perform_update" class="btn btn-warning">
                    <i class="fas fa-download me-2"></i>Update Now
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (!empty($update_log)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="update-card">
                    <h5 class="text-dark mb-3"><i class="fas fa-terminal me-2"></i>Update Log</h5>
                    <div class="update-log">
                        <?php echo implode("\n", array_map('htmlspecialchars', $update_log)); ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($commit_log)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="update-card">
                    <h5 class="text-dark mb-3"><i class="fas fa-list me-2"></i>Recent Changes</h5>
                    <?php foreach (array_slice($commit_log, 0, 10) as $commit): ?>
                        <div class="commit-item">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="commit-sha"><?php echo substr($commit['sha'], 0, 7); ?></span>
                                    <span class="ms-2 text-muted">by <?php echo htmlspecialchars($commit['commit']['author']['name']); ?></span>
                                </div>
                                <small class="text-muted">
                                    <?php
                                    $date = new DateTime($commit['commit']['author']['date']);
                                    echo $date->format('M d, Y H:i');
                                    ?>
                                </small>
                            </div>
                            <div class="text-dark">
                                <?php
                                $message_lines = explode("\n", $commit['commit']['message']);
                                echo htmlspecialchars($message_lines[0]);
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Help Section -->
    <div class="row">
        <div class="col-12">
            <div class="update-card">
                <h5 class="text-dark mb-3"><i class="fas fa-question-circle me-2"></i>Manual Update</h5>
                <p>If the automatic update fails, you can update manually:</p>
                <div class="update-log">
cd <?php echo htmlspecialchars(__DIR__); ?>

# Backup database
mysqldump -u opnsense_user -p opnsense_fw > backup_$(date +%Y%m%d_%H%M%S).sql

# Pull updates
git stash
git pull origin main

# If using private repo with token:
git pull https://YOUR_TOKEN@github.com/<?php echo htmlspecialchars($github_username . '/' . $github_repo); ?>.git main
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
