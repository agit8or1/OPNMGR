<?php
/**
 * Background Snyk Scan Runner
 * Runs scans in background and updates progress file
 */

require_once __DIR__ . '/inc/env.php';

// Get parameters
$scan_type = $argv[1] ?? 'dependencies';
$scan_id = $argv[2] ?? uniqid('scan_');
$progress_file = "/tmp/snyk_scan_{$scan_id}.json";

// Initialize progress
function updateProgress($progress_file, $status, $message, $percent = 0, $output = '') {
    $data = [
        'status' => $status,
        'message' => $message,
        'percent' => $percent,
        'output' => $output,
        'timestamp' => time()
    ];
    file_put_contents($progress_file, json_encode($data));
}

// Load SNYK_TOKEN from .env
$snyk_token = env('SNYK_TOKEN', '');
if (empty($snyk_token)) {
    updateProgress($progress_file, 'error', 'SNYK_TOKEN not configured', 0);
    exit(1);
}

updateProgress($progress_file, 'running', 'Initializing scan...', 10);

// Prepare scan command
$base_dir = __DIR__;
switch ($scan_type) {
    case 'all':
        // Run comprehensive scan (dependencies + code)
        $cmd = "cd $base_dir && SNYK_TOKEN=" . escapeshellarg($snyk_token) . " snyk test 2>&1 && SNYK_TOKEN=" . escapeshellarg($snyk_token) . " snyk code test 2>&1";
        $scan_name = "Comprehensive Security Scan";
        break;

    case 'dependencies':
        $cmd = "cd $base_dir/scripts 2>/dev/null || cd $base_dir && SNYK_TOKEN=" . escapeshellarg($snyk_token) . " snyk test 2>&1";
        $scan_name = "Dependency Scan";
        break;

    case 'code':
        $cmd = "cd $base_dir && SNYK_TOKEN=" . escapeshellarg($snyk_token) . " snyk code test 2>&1";
        $scan_name = "Code Analysis";
        break;

    case 'monitor':
        $cmd = "cd $base_dir && SNYK_TOKEN=" . escapeshellarg($snyk_token) . " snyk monitor 2>&1";
        $scan_name = "Continuous Monitoring";
        break;

    default:
        updateProgress($progress_file, 'error', 'Invalid scan type', 0);
        exit(1);
}

updateProgress($progress_file, 'running', "Running $scan_name...", 30);

// Execute scan
$start_time = microtime(true);
exec($cmd, $output, $return_code);
$duration = round(microtime(true) - $start_time, 2);

updateProgress($progress_file, 'running', 'Processing results...', 80);

$output_text = implode("\n", $output);

// Parse results for summary
$vuln_count = 0;
$critical = 0;
$high = 0;
$medium = 0;
$low = 0;

// Try to parse JSON output
$json_start = strpos($output_text, '{');
if ($json_start !== false) {
    $json_str = substr($output_text, $json_start);
    $json_data = json_decode($json_str, true);

    if (json_last_error() === JSON_ERROR_NONE && isset($json_data['vulnerabilities'])) {
        $vuln_count = count($json_data['vulnerabilities']);

        foreach ($json_data['vulnerabilities'] as $vuln) {
            $severity = strtolower($vuln['severity'] ?? 'low');
            switch ($severity) {
                case 'critical': $critical++; break;
                case 'high': $high++; break;
                case 'medium': $medium++; break;
                case 'low': $low++; break;
            }
        }
    }
}

// Simple text-based summary
$summary = "Scan completed in {$duration}s\n";
if ($vuln_count > 0) {
    $summary .= "Found $vuln_count vulnerabilities\n";
    if ($critical > 0) $summary .= "  • Critical: $critical\n";
    if ($high > 0) $summary .= "  • High: $high\n";
    if ($medium > 0) $summary .= "  • Medium: $medium\n";
    if ($low > 0) $summary .= "  • Low: $low\n";
} else {
    $summary .= "No vulnerabilities found! ✓\n";
}

// Determine final status based on vulnerabilities found
if ($vuln_count === 0) {
    // No vulnerabilities = success (green)
    $final_status = 'success';
} elseif ($vuln_count > 0) {
    // Vulnerabilities found = warning (yellow)
    $final_status = 'warning';
} else {
    // Scan error = error (red)
    $final_status = 'error';
}

$final_message = $summary;

updateProgress($progress_file, $final_status, $final_message, 100, $output_text);

// Save scan results to database
require_once __DIR__ . '/inc/db.php';
if (isset($DB)) {
    try {
        // Map scan type to database enum values
        $db_scan_type = 'full';
        if ($scan_type === 'dependencies') {
            $db_scan_type = 'dependencies';
        } elseif ($scan_type === 'code') {
            $db_scan_type = 'code';
        }

        $stmt = $DB->prepare('INSERT INTO snyk_scan_results (scan_type, status, total_vulnerabilities, critical_count, high_count, medium_count, low_count, duration_seconds, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $db_scan_type,
            'completed',
            $vuln_count,
            $critical,
            $high,
            $medium,
            $low,
            $duration
        ]);
    } catch (Exception $e) {
        // Log error but don't fail the scan
        error_log("Failed to save scan results: " . $e->getMessage());
    }
}

exit(0);
