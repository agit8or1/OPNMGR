<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Handle both JSON and form data
if (!$input) {
    $input = $_POST;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);
$target_track = trim($input['target_track'] ?? '');
$csrf_token = $input['csrf'] ?? '';

// Verify CSRF token
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if (!$firewall_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid firewall ID']);
    exit;
}

if (empty($target_track)) {
    echo json_encode(['success' => false, 'message' => 'Target track is required']);
    exit;
}

// Validate target track format (e.g., "25.7", "26.1")
if (!preg_match('/^\d+\.\d+$/', $target_track)) {
    echo json_encode(['success' => false, 'message' => 'Invalid track format. Expected format: XX.Y (e.g., 25.7)']);
    exit;
}

try {
    // Get firewall details
    $stmt = $DB->prepare("SELECT hostname, current_version FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();

    if (!$firewall) {
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }

    // Extract current series from version
    $current_series = '';
    if (preg_match('/^(\d+\.\d+)/', $firewall['current_version'], $matches)) {
        $current_series = $matches[1];
    }

    // Check if already on target track
    if ($current_series === $target_track) {
        echo json_encode([
            'success' => false,
            'message' => "Firewall is already on track {$target_track}.x"
        ]);
        exit;
    }

    // Check if the database schema supports track change fields
    // First, try to check if columns exist
    $columns_check = $DB->query("SHOW COLUMNS FROM firewalls LIKE 'track_change_requested'");
    $has_track_change_columns = $columns_check && $columns_check->rowCount() > 0;

    if (!$has_track_change_columns) {
        // Create the columns if they don't exist
        $DB->exec("ALTER TABLE firewalls
            ADD COLUMN IF NOT EXISTS track_change_requested TINYINT(1) DEFAULT 0,
            ADD COLUMN IF NOT EXISTS track_change_requested_at DATETIME DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS track_change_target VARCHAR(20) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS track_change_status VARCHAR(50) DEFAULT NULL");

        log_info('system', "Added track change columns to firewalls table", $_SESSION['user_id'] ?? null);
    }

    // Flag the firewall for track change
    $stmt = $DB->prepare("UPDATE firewalls SET
        track_change_requested = 1,
        track_change_requested_at = NOW(),
        track_change_target = ?,
        track_change_status = 'pending',
        status = 'track_change_pending'
        WHERE id = ?");

    $stmt->execute([$target_track, $firewall_id]);

    // Log the action
    log_info('firewall', "Track change requested for firewall {$firewall['hostname']} from {$current_series}.x to {$target_track}.x",
        $_SESSION['user_id'] ?? null, $firewall_id, [
            'action' => 'track_change_requested',
            'admin_user' => $_SESSION['username'] ?? 'unknown',
            'current_track' => $current_series,
            'target_track' => $target_track
        ]);

    echo json_encode([
        'success' => true,
        'message' => "Track change queued from {$current_series}.x to {$target_track}.x. The agent will process this on next check-in.",
        'current_track' => $current_series,
        'target_track' => $target_track
    ]);

} catch (Exception $e) {
    log_error('firewall', "Failed to initiate track change for firewall ID $firewall_id: " . $e->getMessage(),
        $_SESSION['user_id'] ?? null, $firewall_id);

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>
