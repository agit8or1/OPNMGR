<?php
/**
 * Update Documentation API Endpoint
 * Runs the feature documentation updater script
 * ONLY accessible from dev_features.php
 */
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Run the documentation update script
    $script_path = __DIR__ . '/../scripts/update_feature_docs.php';
    
    if (!file_exists($script_path)) {
        throw new Exception('Update script not found');
    }
    
    // Execute the script and capture output
    $output = shell_exec("php $script_path 2>&1");
    
    // Check if update was successful
    if (strpos($output, 'Documentation update complete') !== false || 
        strpos($output, 'All documentation updated successfully') !== false) {
        
        // Count what was updated
        $features_count = preg_match('/Found (\d+) features/', $output, $matches) ? $matches[1] : 0;
        $production_count = preg_match('/Production: (\d+)/', $output, $matches) ? $matches[1] : 0;
        
        echo json_encode([
            'success' => true,
            'message' => "Updated documentation for $features_count features ($production_count production)",
            'output' => $output
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Update script did not complete successfully',
            'output' => $output
        ]);
    }
    
} catch (Exception $e) {
    error_log('Error updating documentation: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
