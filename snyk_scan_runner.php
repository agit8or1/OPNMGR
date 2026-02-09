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

// Determine final status
$final_status = ($return_code === 0) ? 'success' : (($vuln_count > 0) ? 'warning' : 'error');
$final_message = $summary;

updateProgress($progress_file, $final_status, $final_message, 100, $output_text);

exit(0);
