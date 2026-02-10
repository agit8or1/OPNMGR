<?php
/**
 * Delete Deployment Package API
 * Allows deletion of previously generated deployment packages
 */
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
requireAdmin();

header('Content-Type: application/json');

// Verify CSRF token
if (!csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed']);
    exit;
}

$filename = trim($_POST['filename'] ?? '');

if (!$filename) {
    echo json_encode(['success' => false, 'error' => 'Missing filename']);
    exit;
}

// Validate filename - only allow opnsense_deploy_YYYYMMDD_HHMMSS.tar.gz format
if (!preg_match('/^opnsense_deploy_\d{8}_\d{6}\.tar\.gz$/', $filename)) {
    echo json_encode(['success' => false, 'error' => 'Invalid filename format']);
    exit;
}

$package_dir = '/var/www/opnsense/packages';
$filepath = $package_dir . '/' . $filename;

// Check if file exists first
if (!file_exists($filepath)) {
    echo json_encode(['success' => false, 'error' => 'Package file not found']);
    exit;
}

// Verify path is within packages directory (prevent directory traversal)
$real_filepath = realpath($filepath);
$real_package_dir = realpath($package_dir);
if ($real_filepath === false || $real_package_dir === false || strpos($real_filepath, $real_package_dir) !== 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid file path']);
    exit;
}

// Delete the file
if (unlink($filepath)) {
    // Log the deletion
    error_log("[DEPLOYMENT] Package deleted: {$filename}");
    
    echo json_encode(['success' => true, 'message' => 'Package deleted successfully']);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to delete package file']);
}
?>
