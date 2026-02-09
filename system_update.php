<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/env.php';
require_once __DIR__ . '/inc/csrf.php';

$page_title = "System Update";

$message = '';
$message_type = '';
$update_available = false;
$current_version = file_exists(__DIR__ . '/VERSION') ? trim(file_get_contents(__DIR__ . '/VERSION')) : 'Unknown';
$latest_version = 'Checking...';
$commit_log = [];
$local_commit = 'unknown';

// GitHub configuration
$github_repo = env('GITHUB_REPO', 'OPNMGR');
$github_username = env('GITHUB_USERNAME', 'agit8or1');
// REMOVED: GITHUB_PAT - No longer needed for public repo checks

// Auto-check for updates on page load (not on POST requests)
$should_check_updates = ($_SERVER['REQUEST_METHOD'] !== 'POST');

// Check for updates (auto on page load, or manual via button)
if (isset($_POST['check_updates']) || $should_check_updates) {
    $github_api_url = "https://api.github.com/repos/{$github_username}/{$github_repo}/commits/main";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $github_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'OPNsense-Manager');

    // No authentication needed for public repos
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/vnd.github.v3+json"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $commit_data = json_decode($response, true);
        $latest_commit = substr($commit_data['sha'], 0, 7);

        // Get local commit from COMMIT file (www-data can't access the git repo directly)
        $commit_file = __DIR__ . '/COMMIT';
        if (file_exists($commit_file)) {
            $local_commit = trim(file_get_contents($commit_file));
        } else {
            // Fallback: try git directly in case we have access
            $local_commit_output = [];
            $return_code = 1;
            exec('git -C /home/administrator/opnsense rev-parse --short HEAD 2>/dev/null', $local_commit_output, $return_code);
            $local_commit = ($return_code === 0 && !empty($local_commit_output)) ? trim($local_commit_output[0]) : 'unknown';
        }

        if ($local_commit !== 'unknown' && $local_commit !== $latest_commit) {
            $update_available = true;
            $message = "Update available! Current: {$local_commit}, Latest: {$latest_commit}";
            $message_type = 'warning';
        } elseif ($local_commit !== 'unknown') {
            $message = "You're running the latest version ({$local_commit})";
            $message_type = 'success';
        } else {
            $message = "Unable to determine current version. Git repository may not be initialized.";
            $message_type = 'warning';
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

// Update is now handled via SSE stream (api/system_update_stream.php)
$update_log = [];

include __DIR__ . '/inc/header.php';
?>

<style>
.update-card {
    background: #1e293b;
    border: 2px solid #334155;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1rem;
}

.update-card .card-body,
.update-card .card-header h5,
.update-card h5 {
    color: #e2e8f0;
}

.update-card .card-header {
    background: #0f172a;
    color: #e2e8f0;
    border-bottom: 2px solid #334155;
}

.update-card dt {
    color: #94a3b8;
}

.update-card dd {
    color: #cbd5e1;
}

.config-section,
.warning-box {
    color: #cbd5e1;
}

.config-section h5,
.warning-box h5 {
    color: #e2e8f0;
}

.text-white, h2.text-white, h4.text-white, h5.text-white {
    color: #e2e8f0 !important;
}

.text-muted, small.text-muted {
    color: #94a3b8 !important;
}

.text-primary {
    color: #60a5fa !important;
}

.commit-item {
    color: #cbd5e1;
}

.commit-item .text-white {
    color: #e2e8f0 !important;
}

.warning-box ul,
.warning-box li,
.warning-box p {
    color: #1e293b;
}

.update-log {
    color: #e2e8f0;
}

.version-badge {
    font-size: 1.5rem;
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 6px;
}

.commit-item {
    background: #0f172a;
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

.progress-step {
    padding: 0.6rem 0.8rem;
    border-left: 3px solid #334155;
    margin-left: 0.5rem;
    margin-bottom: 0.25rem;
    color: #94a3b8;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.progress-step.active {
    border-left-color: #3b82f6;
    color: #e2e8f0;
    background: rgba(59, 130, 246, 0.1);
}

.progress-step.completed {
    border-left-color: #22c55e;
    color: #e2e8f0;
}

.progress-step.failed {
    border-left-color: #ef4444;
    color: #fca5a5;
}

.progress-step.warned {
    border-left-color: #f59e0b;
    color: #fde68a;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
.fa-spinner { animation: spin 1s linear infinite; }
</style>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="text-white"><i class="fas fa-sync-alt me-2 text-primary"></i>System Update</h2>
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

    <?php if (false): // Token no longer needed for public repos ?>
        <div class="alert alert-warning" style="display: none;">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>REMOVED:</strong> GitHub token no longer required.
            Configure <code>GITHUB_PAT</code> in your .env file.
        </div>
    <?php endif; ?>

    <!-- Current Version -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="update-card">
                <h5 class="text-white mb-3"><i class="fas fa-code-branch me-2"></i>Current Version</h5>
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
                <h5 class="text-white mb-3"><i class="fas fa-info-circle me-2"></i>Repository Info</h5>
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

                    <dt class="col-sm-4">Commit:</dt>
                    <dd class="col-sm-8">
                        <code style="color: #60a5fa;"><?php echo htmlspecialchars($local_commit); ?></code>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <?php if ($update_available): ?>
        <div class="warning-box" id="update-prompt">
            <h5 class="text-warning mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Update Available</h5>
            <p class="mb-3">A new version is available. Before updating:</p>
            <ul class="mb-3">
                <li>A database backup will be created automatically</li>
                <li>Your local changes will be stashed</li>
                <li>The application will pull the latest code from GitHub</li>
                <li>You can rollback using the backup if needed</li>
            </ul>
            <button type="button" class="btn btn-warning" id="btn-update" onclick="startUpdate()">
                <i class="fas fa-download me-2"></i>Update Now
            </button>
        </div>
    <?php endif; ?>

    <!-- Real-time Update Progress -->
    <div class="row mb-4" id="update-progress" style="display: none;">
        <div class="col-12">
            <div class="update-card">
                <h5 class="text-white mb-3"><i class="fas fa-terminal me-2"></i>Update Progress</h5>
                <div id="progress-steps">
                    <div class="progress-step" id="step-backup_dir">
                        <i class="fas fa-circle text-muted me-2 step-icon"></i>
                        <span class="step-label">Create backup directory</span>
                        <span class="step-status ms-2"></span>
                    </div>
                    <div class="progress-step" id="step-db_backup">
                        <i class="fas fa-circle text-muted me-2 step-icon"></i>
                        <span class="step-label">Backup database</span>
                        <span class="step-status ms-2"></span>
                    </div>
                    <div class="progress-step" id="step-git_stash">
                        <i class="fas fa-circle text-muted me-2 step-icon"></i>
                        <span class="step-label">Stash local changes</span>
                        <span class="step-status ms-2"></span>
                    </div>
                    <div class="progress-step" id="step-git_pull">
                        <i class="fas fa-circle text-muted me-2 step-icon"></i>
                        <span class="step-label">Pull latest code from GitHub</span>
                        <span class="step-status ms-2"></span>
                    </div>
                    <div class="progress-step" id="step-sync">
                        <i class="fas fa-circle text-muted me-2 step-icon"></i>
                        <span class="step-label">Sync to production</span>
                        <span class="step-status ms-2"></span>
                    </div>
                    <div class="progress-step" id="step-post_update">
                        <i class="fas fa-circle text-muted me-2 step-icon"></i>
                        <span class="step-label">Run post-update scripts</span>
                        <span class="step-status ms-2"></span>
                    </div>
                    <div class="progress-step" id="step-version">
                        <i class="fas fa-circle text-muted me-2 step-icon"></i>
                        <span class="step-label">Update version info</span>
                        <span class="step-status ms-2"></span>
                    </div>
                </div>
                <div class="mt-3" id="progress-bar-container">
                    <div class="progress" style="height: 8px; background: #334155;">
                        <div class="progress-bar bg-primary" id="update-progress-bar" role="progressbar" style="width: 0%; transition: width 0.3s ease;"></div>
                    </div>
                    <small id="progress-text" style="color: #94a3b8;" class="mt-1 d-block">Starting update...</small>
                </div>
                <div class="mt-3" id="update-result" style="display: none;"></div>
            </div>
        </div>
    </div>

    <?php if (!empty($commit_log)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="update-card">
                    <h5 class="text-white mb-3"><i class="fas fa-list me-2"></i>Recent Changes</h5>
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
                            <div class="text-white">
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
                <h5 class="text-white mb-3"><i class="fas fa-question-circle me-2"></i>Manual Update</h5>
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

<script>
const csrfToken = '<?php echo csrf_token(); ?>';
const totalSteps = 7;
let completedSteps = 0;

function startUpdate() {
    if (!confirm('Are you sure you want to update? This will pull the latest code from GitHub.')) return;

    document.getElementById('btn-update').disabled = true;
    document.getElementById('btn-update').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
    document.getElementById('update-progress').style.display = '';
    document.getElementById('update-prompt').style.display = 'none';

    const evtSource = new EventSource('/api/system_update_stream.php?csrf_token=' + encodeURIComponent(csrfToken));

    evtSource.onmessage = function(event) {
        const data = JSON.parse(event.data);
        const step = data.step;
        const message = data.message;
        const status = data.status;

        if (step === 'done') {
            evtSource.close();
            document.getElementById('update-progress-bar').style.width = '100%';
            document.getElementById('update-progress-bar').className = status === 'done'
                ? 'progress-bar bg-success' : 'progress-bar bg-danger';
            document.getElementById('progress-text').textContent = message;

            const result = document.getElementById('update-result');
            result.style.display = '';
            if (status === 'done') {
                result.innerHTML = '<div class="alert alert-success mb-0"><i class="fas fa-check-circle me-2"></i>' + message + ' <a href="javascript:location.reload()" class="ms-2 text-white"><strong>Reload page</strong></a></div>';
            } else {
                result.innerHTML = '<div class="alert alert-danger mb-0"><i class="fas fa-times-circle me-2"></i>' + message + '</div>';
            }
            return;
        }

        const el = document.getElementById('step-' + step);
        if (!el) return;

        const icon = el.querySelector('.step-icon');
        const statusSpan = el.querySelector('.step-status');

        if (status === 'running') {
            el.className = 'progress-step active';
            icon.className = 'fas fa-spinner me-2 step-icon';
            icon.style.color = '#3b82f6';
            statusSpan.textContent = '';
            document.getElementById('progress-text').textContent = message;
        } else if (status === 'done') {
            el.className = 'progress-step completed';
            icon.className = 'fas fa-check-circle me-2 step-icon';
            icon.style.color = '#22c55e';
            statusSpan.textContent = message;
            statusSpan.style.color = '#94a3b8';
            completedSteps++;
            document.getElementById('update-progress-bar').style.width = Math.round((completedSteps / totalSteps) * 100) + '%';
        } else if (status === 'warn') {
            el.className = 'progress-step warned';
            icon.className = 'fas fa-exclamation-triangle me-2 step-icon';
            icon.style.color = '#f59e0b';
            statusSpan.textContent = message;
            statusSpan.style.color = '#fde68a';
            completedSteps++;
            document.getElementById('update-progress-bar').style.width = Math.round((completedSteps / totalSteps) * 100) + '%';
        } else if (status === 'error') {
            el.className = 'progress-step failed';
            icon.className = 'fas fa-times-circle me-2 step-icon';
            icon.style.color = '#ef4444';
            statusSpan.textContent = message;
            statusSpan.style.color = '#fca5a5';
        }
    };

    evtSource.onerror = function() {
        evtSource.close();
        document.getElementById('progress-text').textContent = 'Connection lost';
        document.getElementById('update-progress-bar').className = 'progress-bar bg-danger';
    };
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
