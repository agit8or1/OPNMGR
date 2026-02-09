<?php
/**
 * Background Snyk Scan Runner
 * Supports all Snyk scan types: SCA, Code (SAST), Container, IaC, License
 * Runs scans in background and updates progress file for real-time UI updates
 */

require_once __DIR__ . '/inc/env.php';

// Get parameters
$scan_type = $argv[1] ?? 'dependencies';
$scan_id   = $argv[2] ?? uniqid('scan_');
$types_csv = $argv[3] ?? '';  // comma-separated list for 'multi' mode

$progress_file = "/tmp/snyk_scan_{$scan_id}.json";
$log_file      = __DIR__ . '/logs/snyk_scan_' . date('Y-m-d_His') . '.log';

// Ensure logs directory exists
if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0755, true);
}

// ---------------------------------------------------------------------------
// Progress helpers
// ---------------------------------------------------------------------------
function updateProgress($progress_file, $status, $message, $percent = 0, $output = '', $steps = []) {
    $data = [
        'status'    => $status,
        'message'   => $message,
        'percent'   => $percent,
        'output'    => $output,
        'steps'     => $steps,
        'timestamp' => time()
    ];
    file_put_contents($progress_file, json_encode($data));
}

function writeLog($log_file, $line) {
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

// ---------------------------------------------------------------------------
// Load SNYK_TOKEN
// ---------------------------------------------------------------------------
$snyk_token = env('SNYK_TOKEN', '');
if (empty($snyk_token)) {
    updateProgress($progress_file, 'error', 'SNYK_TOKEN not configured in .env', 0);
    exit(1);
}

$base_dir = __DIR__;
$env_prefix = "SNYK_TOKEN=" . escapeshellarg($snyk_token);

// ---------------------------------------------------------------------------
// Scan type definitions (command builders)
// ---------------------------------------------------------------------------
$scan_defs = [
    'sca' => [
        'label'   => 'Open Source (SCA)',
        'cmd'     => "cd $base_dir && $env_prefix snyk test 2>&1",
        'db_type' => 'dependencies',
    ],
    'code' => [
        'label'   => 'Code (SAST)',
        'cmd'     => "cd $base_dir && $env_prefix snyk code test 2>&1",
        'db_type' => 'code',
    ],
    'container' => [
        'label'   => 'Container',
        // Scan the local system image if available, otherwise test current dir
        'cmd'     => "cd $base_dir && $env_prefix snyk container test node:lts-slim 2>&1",
        'db_type' => 'container',
    ],
    'iac' => [
        'label'   => 'Infrastructure as Code',
        'cmd'     => "cd $base_dir && $env_prefix snyk iac test 2>&1",
        'db_type' => 'iac',
    ],
    'license' => [
        'label'   => 'License Compliance',
        'cmd'     => "cd $base_dir && $env_prefix snyk test --json 2>&1",
        'db_type' => 'license',
    ],
    // Legacy types (backwards compatibility)
    'dependencies' => [
        'label'   => 'Dependency Scan',
        'cmd'     => "cd $base_dir && $env_prefix snyk test 2>&1",
        'db_type' => 'dependencies',
    ],
    'all' => [
        'label'   => 'Comprehensive Scan',
        'cmd'     => null, // handled specially
        'db_type' => 'full',
    ],
    'monitor' => [
        'label'   => 'Continuous Monitoring',
        'cmd'     => "cd $base_dir && $env_prefix snyk monitor 2>&1",
        'db_type' => 'full',
    ],
];

// ---------------------------------------------------------------------------
// Determine which scans to run
// ---------------------------------------------------------------------------
$scans_to_run = [];

if ($scan_type === 'multi' && !empty($types_csv)) {
    // Multi-scan mode: run each selected type sequentially
    foreach (explode(',', $types_csv) as $t) {
        $t = trim($t);
        if (isset($scan_defs[$t])) {
            $scans_to_run[$t] = $scan_defs[$t];
        }
    }
} elseif ($scan_type === 'all') {
    // Legacy "all" mode: SCA + Code
    $scans_to_run['sca']  = $scan_defs['sca'];
    $scans_to_run['code'] = $scan_defs['code'];
} elseif (isset($scan_defs[$scan_type])) {
    $scans_to_run[$scan_type] = $scan_defs[$scan_type];
} else {
    updateProgress($progress_file, 'error', 'Invalid scan type: ' . $scan_type, 0);
    exit(1);
}

$total_scans = count($scans_to_run);
if ($total_scans === 0) {
    updateProgress($progress_file, 'error', 'No valid scan types selected', 0);
    exit(1);
}

// ---------------------------------------------------------------------------
// Build initial steps list for UI
// ---------------------------------------------------------------------------
$steps = [];
foreach ($scans_to_run as $key => $def) {
    $steps[] = [
        'key'    => $key,
        'label'  => $def['label'],
        'status' => 'pending',
        'detail' => '',
    ];
}

writeLog($log_file, "Starting scan: type=$scan_type scans=" . implode(',', array_keys($scans_to_run)));
updateProgress($progress_file, 'running', 'Initializing scan...', 5, '', $steps);

// ---------------------------------------------------------------------------
// Execute each scan
// ---------------------------------------------------------------------------
$start_time    = microtime(true);
$all_output    = '';
$total_vulns   = 0;
$total_critical = 0;
$total_high    = 0;
$total_medium  = 0;
$total_low     = 0;
$had_errors    = false;
$had_vulns     = false;
$scan_index    = 0;

foreach ($scans_to_run as $key => $def) {
    $scan_index++;
    $pct_base  = intval(5 + ($scan_index - 1) * (85 / $total_scans));
    $pct_mid   = intval(5 + ($scan_index - 0.5) * (85 / $total_scans));
    $pct_end   = intval(5 + $scan_index * (85 / $total_scans));

    // Mark this step as running
    foreach ($steps as &$s) {
        if ($s['key'] === $key) { $s['status'] = 'running'; break; }
    }
    unset($s);

    updateProgress($progress_file, 'running', "Running {$def['label']}...", $pct_base, $all_output, $steps);
    writeLog($log_file, "Running: {$def['label']} ({$key})");

    $cmd = $def['cmd'];
    if (empty($cmd)) {
        // Skip if no command defined (shouldn't happen)
        foreach ($steps as &$s) {
            if ($s['key'] === $key) { $s['status'] = 'skipped'; $s['detail'] = 'No command'; break; }
        }
        unset($s);
        continue;
    }

    $output = [];
    $return_code = 0;
    exec($cmd, $output, $return_code);
    $output_text = implode("\n", $output);

    $all_output .= "\n========================================\n";
    $all_output .= "  {$def['label']}\n";
    $all_output .= "========================================\n";
    $all_output .= $output_text . "\n";

    writeLog($log_file, "Completed: {$def['label']} exit_code=$return_code output_lines=" . count($output));

    // -----------------------------------------------------------------------
    // Parse results
    // -----------------------------------------------------------------------
    $vuln_count = 0;
    $crit = 0; $high = 0; $med = 0; $low = 0;
    $step_detail = '';
    $step_status = 'success';

    // Check for known errors
    if (strpos($output_text, 'SNYK-CODE-0005') !== false ||
        strpos($output_text, 'Snyk Code is not supported') !== false) {
        $step_status = 'error';
        $step_detail = 'Not enabled in org settings (SNYK-CODE-0005)';
        $had_errors = true;
        writeLog($log_file, "Error: Snyk Code not enabled for org");
    } elseif (strpos($output_text, 'EACCES') !== false) {
        $step_status = 'error';
        $step_detail = 'Permission denied';
        $had_errors = true;
        writeLog($log_file, "Error: Permission denied");
    } elseif (strpos($output_text, 'Could not detect supported target files') !== false) {
        $step_status = 'error';
        $step_detail = 'No supported files found';
        $had_errors = true;
        writeLog($log_file, "Error: No supported target files detected");
    } elseif ($return_code !== 0 && strpos($output_text, 'vulnerabilities') === false) {
        $step_status = 'error';
        // Extract short error message
        $error_msg = trim(substr($output_text, 0, 200));
        $step_detail = $error_msg ?: "Exit code $return_code";
        $had_errors = true;
        writeLog($log_file, "Error: $error_msg");
    } else {
        // Try to parse vulnerability counts
        if ($key === 'license') {
            // License scan: parse JSON for license issues
            $json_start = strpos($output_text, '{');
            if ($json_start !== false) {
                $json_str = substr($output_text, $json_start);
                $json_data = json_decode($json_str, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Check license issues
                    $license_issues = 0;
                    if (isset($json_data['licensesPolicy']['orgLicenseRules'])) {
                        // Count dependencies with license issues
                        if (isset($json_data['vulnerabilities'])) {
                            foreach ($json_data['vulnerabilities'] as $v) {
                                if (!empty($v['license'])) {
                                    $license_issues++;
                                    $severity = strtolower($v['severity'] ?? 'low');
                                    switch ($severity) {
                                        case 'critical': $crit++; break;
                                        case 'high':     $high++; break;
                                        case 'medium':   $med++; break;
                                        case 'low':      $low++; break;
                                    }
                                }
                            }
                        }
                    }
                    $vuln_count = $license_issues;
                    if ($license_issues > 0) {
                        $step_detail = "$license_issues license issue(s)";
                        $step_status = 'warning';
                        $had_vulns = true;
                    } else {
                        $step_detail = 'No license issues';
                    }
                }
            }
        } else {
            // Standard vulnerability parse
            $json_start = strpos($output_text, '{');
            if ($json_start !== false) {
                $json_str = substr($output_text, $json_start);
                $json_data = json_decode($json_str, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($json_data['vulnerabilities'])) {
                    $vuln_count = count($json_data['vulnerabilities']);
                    foreach ($json_data['vulnerabilities'] as $vuln) {
                        $severity = strtolower($vuln['severity'] ?? 'low');
                        switch ($severity) {
                            case 'critical': $crit++; break;
                            case 'high':     $high++; break;
                            case 'medium':   $med++; break;
                            case 'low':      $low++; break;
                        }
                    }
                }
            }

            // Also try text-based parsing (for non-JSON output like snyk code test)
            if ($vuln_count === 0) {
                if (preg_match('/found (\d+) issue/i', $output_text, $m)) {
                    $vuln_count = (int)$m[1];
                } elseif (preg_match('/(\d+) vulnerabilit/i', $output_text, $m)) {
                    $vuln_count = (int)$m[1];
                }
                // Parse severity from text output
                if (preg_match('/(\d+)\s+critical/i', $output_text, $m)) $crit = (int)$m[1];
                if (preg_match('/(\d+)\s+high/i', $output_text, $m))     $high = (int)$m[1];
                if (preg_match('/(\d+)\s+medium/i', $output_text, $m))   $med  = (int)$m[1];
                if (preg_match('/(\d+)\s+low/i', $output_text, $m))      $low  = (int)$m[1];
            }

            if ($vuln_count > 0) {
                $step_detail = "$vuln_count vulnerability(ies) found";
                $step_status = ($crit > 0 || $high > 0) ? 'warning' : 'success';
                $had_vulns = true;
            } else {
                $step_detail = 'No issues found';
            }
        }
    }

    // Accumulate totals
    $total_vulns    += $vuln_count;
    $total_critical += $crit;
    $total_high     += $high;
    $total_medium   += $med;
    $total_low      += $low;

    // Update step status
    foreach ($steps as &$s) {
        if ($s['key'] === $key) {
            $s['status'] = $step_status;
            $s['detail'] = $step_detail;
            break;
        }
    }
    unset($s);

    updateProgress($progress_file, 'running', "Completed {$def['label']}", $pct_end, $all_output, $steps);
}

// ---------------------------------------------------------------------------
// Build final summary
// ---------------------------------------------------------------------------
$duration = round(microtime(true) - $start_time, 2);

$summary = "Scan completed in {$duration}s\n";
$summary .= "Scans run: $total_scans\n\n";

if ($total_vulns > 0) {
    $summary .= "Found $total_vulns total issue(s):\n";
    if ($total_critical > 0) $summary .= "  Critical: $total_critical\n";
    if ($total_high > 0)     $summary .= "  High:     $total_high\n";
    if ($total_medium > 0)   $summary .= "  Medium:   $total_medium\n";
    if ($total_low > 0)      $summary .= "  Low:      $total_low\n";
} else {
    $summary .= "No vulnerabilities found.\n";
}

if ($had_errors) {
    $summary .= "\nSome scans encountered errors -- see step details above.\n";
}

// Determine final status
if ($had_errors && !$had_vulns && $total_vulns === 0) {
    $final_status = 'error';
} elseif ($had_vulns || $total_vulns > 0) {
    $final_status = 'warning';
} else {
    $final_status = 'success';
}

// If some scans had errors but others succeeded, show warning
if ($had_errors && ($had_vulns || !$had_errors)) {
    // At least some scans ran
    if ($final_status === 'success') {
        $final_status = 'warning';
        $summary .= "\nNote: Some scan types failed but others completed successfully.\n";
    }
}

writeLog($log_file, "Final: status=$final_status vulns=$total_vulns duration={$duration}s");
updateProgress($progress_file, $final_status, $summary, 100, $all_output, $steps);

// ---------------------------------------------------------------------------
// Save scan results to database
// ---------------------------------------------------------------------------
require_once __DIR__ . '/inc/db.php';
if (isset($DB)) {
    try {
        // Determine DB scan type
        if ($scan_type === 'multi') {
            $db_scan_type = 'full';
            // If only one type, use its specific db_type
            if ($total_scans === 1) {
                $first_key = array_key_first($scans_to_run);
                $db_scan_type = $scans_to_run[$first_key]['db_type'] ?? 'full';
            }
        } else {
            $db_scan_type = $scan_defs[$scan_type]['db_type'] ?? 'full';
        }

        $stmt = $DB->prepare('INSERT INTO snyk_scan_results (scan_type, status, total_vulnerabilities, critical_count, high_count, medium_count, low_count, duration_seconds, scan_output, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $db_scan_type,
            'completed',
            $total_vulns,
            $total_critical,
            $total_high,
            $total_medium,
            $total_low,
            (int)$duration,
            substr($all_output, 0, 65000), // Truncate to fit longtext safely
        ]);

        writeLog($log_file, "Saved results to database");
    } catch (Exception $e) {
        writeLog($log_file, "Failed to save to database: " . $e->getMessage());
        error_log("Failed to save scan results: " . $e->getMessage());
    }
}

writeLog($log_file, "Scan runner finished");
exit(0);
