<?php
/**
 * AJAX endpoint for checking scan progress
 */
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$scan_id = $_GET['scan_id'] ?? '';
if (empty($scan_id) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $scan_id)) {
    echo json_encode(['error' => 'Invalid scan ID']);
    exit;
}

$progress_file = "/tmp/snyk_scan_{$scan_id}.json";

if (!file_exists($progress_file)) {
    echo json_encode(['error' => 'Scan not found']);
    exit;
}

// Read progress
$progress_data = file_get_contents($progress_file);
echo $progress_data;

// Clean up old progress files (older than 1 hour)
$files = glob('/tmp/snyk_scan_*.json');
foreach ($files as $file) {
    if (filemtime($file) < time() - 3600) {
        @unlink($file);
    }
}
