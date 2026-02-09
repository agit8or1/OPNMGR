<?php
/**
 * System Update - Server-Sent Events stream for real-time progress
 */
require_once __DIR__ . '/../inc/auth.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/env.php';
require_once __DIR__ . '/../inc/csrf.php';

// Validate CSRF token
$token = $_GET['csrf_token'] ?? '';
if (!csrf_verify($token)) {
    header('Content-Type: text/event-stream');
    echo "data: " . json_encode(['step' => 'error', 'message' => 'Invalid CSRF token']) . "\n\n";
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable output buffering
while (ob_get_level()) ob_end_clean();
set_time_limit(120);

function sendEvent($step, $message, $status = 'running') {
    echo "data: " . json_encode(['step' => $step, 'message' => $message, 'status' => $status]) . "\n\n";
    flush();
}

$app_dir = '/var/www/opnsense';
$git_dir = '/home/administrator/opnsense';

// Step 1: Create backup directory
sendEvent('backup_dir', 'Creating backup directory...', 'running');
$backup_dir = $app_dir . '/backups/pre-update-' . date('Y-m-d_H-i-s');
exec('mkdir -p ' . escapeshellarg($backup_dir) . ' 2>&1', $output, $rc);
if ($rc !== 0) {
    sendEvent('backup_dir', 'Failed to create backup directory', 'error');
    sendEvent('done', 'Update aborted', 'error');
    exit;
}
sendEvent('backup_dir', 'Backup directory created', 'done');

// Step 2: Database backup
sendEvent('db_backup', 'Backing up database...', 'running');
$db_host = env('DB_HOST', 'localhost');
$db_name = env('DB_NAME', 'opnsense_fw');
$db_user = env('DB_USER', 'opnsense_user');
$db_pass = env('DB_PASS', '');
$db_backup = $backup_dir . '/database.sql';

$output = [];
exec("mysqldump -h " . escapeshellarg($db_host) . " -u " . escapeshellarg($db_user) . " -p" . escapeshellarg($db_pass) . " " . escapeshellarg($db_name) . " > " . escapeshellarg($db_backup) . " 2>&1", $output, $rc);
if ($rc === 0) {
    sendEvent('db_backup', 'Database backed up successfully', 'done');
} else {
    sendEvent('db_backup', 'Database backup failed (continuing anyway)', 'warn');
}

// Step 3: Git stash
sendEvent('git_stash', 'Stashing local changes...', 'running');
$output = [];
exec('cd ' . escapeshellarg($git_dir) . ' && git stash 2>&1', $output, $rc);
sendEvent('git_stash', 'Local changes stashed', 'done');

// Step 4: Git pull
sendEvent('git_pull', 'Pulling latest code from GitHub...', 'running');
$output = [];
exec('cd ' . escapeshellarg($git_dir) . ' && git pull origin main 2>&1', $output, $rc);
if ($rc !== 0) {
    sendEvent('git_pull', 'Failed to pull updates: ' . implode(' ', $output), 'error');
    sendEvent('done', 'Update failed', 'error');
    exit;
}
sendEvent('git_pull', 'Code pulled successfully', 'done');

// Step 5: Sync to production
sendEvent('sync', 'Syncing to production...', 'running');
$output = [];
exec('rsync -a --exclude=".git" --exclude="backups" --exclude=".env" --exclude="keys" ' . escapeshellarg($git_dir . '/') . ' ' . escapeshellarg($app_dir . '/') . ' 2>&1', $output, $rc);
if ($rc === 0) {
    sendEvent('sync', 'Files synced to production', 'done');
} else {
    sendEvent('sync', 'Sync warning: ' . implode(' ', $output), 'warn');
}

// Step 6: Post-update script
if (file_exists($app_dir . '/scripts/post_update.sh')) {
    sendEvent('post_update', 'Running post-update script...', 'running');
    $output = [];
    exec('bash ' . escapeshellarg($app_dir . '/scripts/post_update.sh') . ' 2>&1', $output, $rc);
    if ($rc === 0) {
        sendEvent('post_update', 'Post-update script completed', 'done');
    } else {
        sendEvent('post_update', 'Post-update script had issues', 'warn');
    }
} else {
    sendEvent('post_update', 'No post-update script found (skipped)', 'done');
}

// Step 7: Update COMMIT file
sendEvent('version', 'Updating version info...', 'running');
$output = [];
exec('git -C ' . escapeshellarg($git_dir) . ' rev-parse --short HEAD 2>&1', $output, $rc);
if ($rc === 0 && !empty($output)) {
    $new_commit = trim($output[0]);
    file_put_contents($app_dir . '/COMMIT', $new_commit . "\n");
    sendEvent('version', 'Version updated to commit ' . $new_commit, 'done');
} else {
    sendEvent('version', 'Could not update commit file', 'warn');
}

// Done
$version = file_exists($app_dir . '/VERSION') ? trim(file_get_contents($app_dir . '/VERSION')) : 'Unknown';
sendEvent('done', 'Update complete! Now running v' . $version, 'done');
