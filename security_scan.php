<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/env.php';

$page_title = "Security Scanner (Snyk Integration)";

// ---------------------------------------------------------------------------
// Fetch latest scan results for dashboard
// ---------------------------------------------------------------------------
$latest_scan = null;
$scan_stats = [
    'total_vulnerabilities' => 0,
    'critical' => 0,
    'high' => 0,
    'medium' => 0,
    'low' => 0,
    'last_scan' => 'Never',
    'duration' => 0,
    'status' => 'Not Run',
    'trend' => 'Stable'
];

if ($DB) {
    try {
        $stmt = $DB->query('SELECT * FROM snyk_scan_results WHERE status = "completed" ORDER BY completed_at DESC LIMIT 1');
        $latest_scan = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($latest_scan) {
            $scan_stats = [
                'total_vulnerabilities' => (int)$latest_scan['total_vulnerabilities'],
                'critical' => (int)$latest_scan['critical_count'],
                'high' => (int)$latest_scan['high_count'],
                'medium' => (int)$latest_scan['medium_count'],
                'low' => (int)$latest_scan['low_count'],
                'last_scan' => $latest_scan['completed_at'],
                'duration' => (int)$latest_scan['duration_seconds'],
                'status' => ucfirst($latest_scan['status']),
                'trend' => 'Stable'
            ];
        }
    } catch (Exception $e) {
        // Silently handle error - dashboard will show "Never"
    }
}

// ---------------------------------------------------------------------------
// Handle configuration actions (POST)
// ---------------------------------------------------------------------------
$config_message = '';
$config_status = '';

// Show success message after API key save redirect
if (isset($_GET['saved']) && $_GET['saved'] == '1') {
    $config_message = 'Snyk API key saved successfully! You can now run security scans.';
    $config_status = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_api_key':
            $api_key = trim($_POST['snyk_api_key'] ?? '');
            if (!empty($api_key)) {
                // Save to .env file
                $env_file = __DIR__ . '/.env';
                $env_content = file_exists($env_file) ? file_get_contents($env_file) : '';

                // Remove old SNYK_TOKEN if exists
                $env_content = preg_replace('/^SNYK_TOKEN=.*$/m', '', $env_content);

                // Add new token
                $env_content = trim($env_content) . "\nSNYK_TOKEN=" . $api_key . "\n";
                file_put_contents($env_file, $env_content);

                // Try to configure Snyk CLI for the administrator user
                exec('sudo -u administrator snyk config set api=' . escapeshellarg($api_key) . ' 2>&1', $output, $return_code);

                header('Location: security_scan.php?saved=1');
                exit;
            } else {
                $config_message = 'API key cannot be empty.';
                $config_status = 'danger';
            }
            break;

        case 'install_snyk':
            $install_script = __DIR__ . '/scripts/install_snyk.sh';
            $log_file = '/tmp/snyk_install.log';

            exec("sudo $install_script 2>&1", $output, $return_code);

            if ($return_code === 0) {
                $config_message = '<strong>Installation successful!</strong><br><br>';
                if (file_exists($log_file)) {
                    $log_content = file_get_contents($log_file);
                    $config_message .= '<div class="alert alert-info" style="font-family: monospace; white-space: pre-wrap; font-size: 0.85rem;">';
                    $config_message .= htmlspecialchars($log_content);
                    $config_message .= '</div>';
                }
                $config_message .= '<strong>Node.js, npm, and Snyk are now installed!</strong><br>';
                $config_message .= 'Please configure your API key below to start scanning.';
                $config_status = 'success';
            } else {
                $config_message = '<strong>Installation failed.</strong><br><br>';
                if (!empty($output)) {
                    $config_message .= 'Error output:<br>';
                    $config_message .= '<div class="alert alert-danger" style="font-family: monospace; white-space: pre-wrap; font-size: 0.85rem;">';
                    $config_message .= htmlspecialchars(implode("\n", $output));
                    $config_message .= '</div>';
                }
                if (file_exists($log_file)) {
                    $log_content = file_get_contents($log_file);
                    $config_message .= 'Installation log:<br>';
                    $config_message .= '<div class="alert alert-warning" style="font-family: monospace; white-space: pre-wrap; font-size: 0.85rem;">';
                    $config_message .= htmlspecialchars($log_content);
                    $config_message .= '</div>';
                }
                $config_message .= 'If the issue persists, you can manually run:<br>';
                $config_message .= '<code>sudo ' . htmlspecialchars($install_script) . '</code>';
                $config_status = 'danger';
            }
            break;

        case 'update_snyk':
            exec('sudo npm update -g snyk 2>&1', $output, $return_code);
            if ($return_code === 0) {
                $config_message = '<strong>Snyk updated successfully!</strong>';
                $config_status = 'success';
            } else {
                $config_message = '<strong>Snyk update failed.</strong><br>';
                $config_message .= '<div class="alert alert-danger mt-2" style="font-family: monospace; white-space: pre-wrap; font-size: 0.85rem;">';
                $config_message .= htmlspecialchars(implode("\n", $output));
                $config_message .= '</div>';
                $config_status = 'danger';
            }
            break;

        case 'run_selected_scans':
            // Collect selected scan types
            $selected = [];
            if (!empty($_POST['scan_sca']))       $selected[] = 'sca';
            if (!empty($_POST['scan_code']))      $selected[] = 'code';
            if (!empty($_POST['scan_container'])) $selected[] = 'container';
            if (!empty($_POST['scan_iac']))       $selected[] = 'iac';
            if (!empty($_POST['scan_license']))   $selected[] = 'license';

            if (empty($selected)) {
                $config_message = 'Please select at least one scan type.';
                $config_status = 'warning';
            } else {
                $scan_id = uniqid('scan_');
                $types_str = implode(',', $selected);

                // Start background scan runner
                $runner = __DIR__ . '/snyk_scan_runner.php';
                exec("php $runner multi $scan_id $types_str > /dev/null 2>&1 &");

                header("Location: security_scan.php?monitoring=$scan_id&type=multi&types=$types_str");
                exit;
            }
            break;
    }
}

// ---------------------------------------------------------------------------
// Check Snyk installation and authentication status
// ---------------------------------------------------------------------------
exec('which snyk 2>&1', $snyk_check, $snyk_installed);
$snyk_available = ($snyk_installed === 0);

$snyk_authenticated = false;
$snyk_version = 'Not Installed';
$snyk_user = '';
$snyk_org = '';
$snyk_update_available = false;

if ($snyk_available) {
    // Check if SNYK_TOKEN is set in .env file
    $env_file = __DIR__ . '/.env';
    if (file_exists($env_file)) {
        $env_content = file_get_contents($env_file);
        if (preg_match('/^SNYK_TOKEN=(.+)$/m', $env_content, $matches)) {
            $token = trim($matches[1]);
            if (!empty($token) && $token !== '') {
                $snyk_authenticated = true;
            }
        }
    }

    // Fallback: check CLI config
    if (!$snyk_authenticated) {
        exec('snyk config get api 2>&1', $auth_check);
        $snyk_authenticated = !empty($auth_check[0]) && $auth_check[0] !== '';
    }

    // Get current Snyk version
    exec('snyk --version 2>&1', $version_output);
    if (!empty($version_output[0])) {
        $snyk_version = trim($version_output[0]);
    }

    // Get authenticated user info (fast)
    if ($snyk_authenticated) {
        $snyk_token_val = env('SNYK_TOKEN', '');
        if (!empty($snyk_token_val)) {
            exec('SNYK_TOKEN=' . escapeshellarg($snyk_token_val) . ' snyk whoami --experimental 2>&1', $whoami_output);
            if (!empty($whoami_output[0]) && strpos($whoami_output[0], 'ERROR') === false) {
                $snyk_user = trim($whoami_output[0]);
            }
        }
    }

    // Only check for updates if explicitly requested
    if (isset($_GET['check_updates'])) {
        exec('npm outdated -g snyk --json 2>&1', $outdated_output);
        $outdated_json = implode("\n", $outdated_output);
        if (!empty($outdated_json) && $outdated_json !== '{}') {
            $outdated_data = json_decode($outdated_json, true);
            if (isset($outdated_data['snyk'])) {
                $snyk_update_available = true;
            }
        }
    }
}

// Check if npm is available for installation
exec('which npm 2>&1', $npm_check, $npm_installed);
$npm_available = ($npm_installed === 0);

// ---------------------------------------------------------------------------
// Scan type definitions
// ---------------------------------------------------------------------------
$scan_types = [
    'sca' => [
        'label' => 'Open Source (SCA)',
        'icon' => 'fa-box-open',
        'color' => '#3b82f6',
        'command' => 'snyk test',
        'description' => 'Scans project dependencies (composer, npm) for known vulnerabilities and CVEs.',
        'enabled' => true,
        'default_checked' => true,
    ],
    'code' => [
        'label' => 'Code (SAST)',
        'icon' => 'fa-code',
        'color' => '#8b5cf6',
        'command' => 'snyk code test',
        'description' => 'Static Application Security Testing -- analyzes source code for security flaws.',
        'enabled' => true,
        'default_checked' => true,
        'note' => 'Requires Snyk Code to be enabled in your organization settings at app.snyk.io.',
    ],
    'container' => [
        'label' => 'Container',
        'icon' => 'fa-docker',
        'icon_prefix' => 'fab',
        'color' => '#06b6d4',
        'command' => 'snyk container test',
        'description' => 'Scans Docker/container images for OS-level and application vulnerabilities.',
        'enabled' => true,
        'default_checked' => false,
    ],
    'iac' => [
        'label' => 'Infrastructure as Code',
        'icon' => 'fa-server',
        'color' => '#f59e0b',
        'command' => 'snyk iac test',
        'description' => 'Scans Terraform, CloudFormation, Kubernetes, and other IaC files for misconfigurations.',
        'enabled' => true,
        'default_checked' => false,
    ],
    'license' => [
        'label' => 'License Compliance',
        'icon' => 'fa-gavel',
        'color' => '#10b981',
        'command' => 'snyk test --json (license check)',
        'description' => 'Checks open source dependencies for license compliance issues (GPL, AGPL, etc.).',
        'enabled' => true,
        'default_checked' => false,
    ],
];

include __DIR__ . '/inc/header.php';
?>

<style>
.security-card {
    background: var(--card, #1e293b);
    border: 1px solid var(--border, rgba(255,255,255,0.08));
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    transition: all 0.3s ease;
}
.security-card:hover {
    transform: translateY(-2px);
    border-color: var(--accent, #3b82f6);
}
.scan-output {
    background: #0f172a;
    border: 1px solid var(--border, rgba(255,255,255,0.08));
    border-radius: 6px;
    padding: 1rem;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    color: #e2e8f0;
    max-height: 600px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.config-section {
    background: var(--card, #1e293b);
    border: 1px solid var(--border, rgba(255,255,255,0.08));
    border-radius: 8px;
    padding: 2rem;
}
.card-dark-custom {
    background: var(--card, #1e293b) !important;
    border: 1px solid var(--border, rgba(255,255,255,0.08)) !important;
    color: var(--text, #f1f5f9) !important;
}
.card-dark-custom .card-header {
    background: rgba(0,0,0,0.2);
    color: var(--text, #e2e8f0);
    border-bottom: 1px solid var(--border, rgba(255,255,255,0.08));
}
.card-dark-custom .card-header h5,
.card-dark-custom .card-header h6 {
    color: var(--text, #e2e8f0);
}
.card-dark-custom .card-body {
    color: var(--muted, #cbd5e1);
}
.card-dark-custom .card-body dt {
    color: var(--muted, #94a3b8);
}
.card-dark-custom .card-body dd {
    color: var(--text, #cbd5e1);
}

/* Scan type toggle cards */
.scan-type-card {
    background: var(--card, #1e293b);
    border: 2px solid var(--border, rgba(255,255,255,0.08));
    border-radius: 10px;
    padding: 1.25rem;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}
.scan-type-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.scan-type-card.selected {
    border-color: var(--accent, #3b82f6);
    background: rgba(59,130,246,0.08);
}
.scan-type-card .scan-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: #fff;
    margin-bottom: 0.75rem;
}
.scan-type-card .scan-label {
    font-weight: 600;
    font-size: 1rem;
    color: var(--text, #e2e8f0);
    margin-bottom: 0.25rem;
}
.scan-type-card .scan-cmd {
    font-family: 'Courier New', monospace;
    font-size: 0.75rem;
    color: var(--muted, #94a3b8);
    margin-bottom: 0.5rem;
}
.scan-type-card .scan-desc {
    font-size: 0.85rem;
    color: var(--muted, #94a3b8);
    line-height: 1.4;
}
.scan-type-card .scan-note {
    font-size: 0.75rem;
    color: #f59e0b;
    margin-top: 0.5rem;
    font-style: italic;
}
.scan-type-card .form-check-input {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 1.25rem;
    height: 1.25rem;
}
.scan-type-card .form-check-input:checked {
    background-color: var(--accent, #3b82f6);
    border-color: var(--accent, #3b82f6);
}

/* Auth status badge */
.auth-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
}
.auth-status.connected {
    background: rgba(16,185,129,0.15);
    color: #10b981;
    border: 1px solid rgba(16,185,129,0.3);
}
.auth-status.disconnected {
    background: rgba(239,68,68,0.15);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,0.3);
}

/* Progress section */
.scan-progress-section {
    border: 2px solid var(--accent, #3b82f6);
    border-radius: 10px;
    overflow: hidden;
}
.scan-progress-header {
    background: rgba(59,130,246,0.15);
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border);
}
.scan-step {
    padding: 0.5rem 1rem;
    border-bottom: 1px solid var(--border, rgba(255,255,255,0.05));
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.9rem;
}
.scan-step .step-icon {
    width: 24px;
    text-align: center;
}
.scan-step.pending { color: var(--muted, #64748b); }
.scan-step.running { color: #3b82f6; }
.scan-step.success { color: #10b981; }
.scan-step.error   { color: #ef4444; }
.scan-step.skipped { color: var(--muted, #64748b); text-decoration: line-through; }

.form-control-custom {
    background: rgba(255,255,255,0.1) !important;
    border: 1px solid var(--border, rgba(255,255,255,0.15)) !important;
    color: var(--text, #e2e8f0) !important;
}
.form-control-custom:focus {
    border-color: var(--accent, #3b82f6) !important;
    background: rgba(255,255,255,0.15) !important;
}
.form-control-custom::placeholder {
    color: var(--muted, #64748b) !important;
}
</style>

<div class="container-fluid">

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-shield-alt me-2 text-primary"></i>Security Scanner</h2>
                    <p class="text-muted mb-0">Powered by Snyk -- Comprehensive vulnerability scanning</p>
                </div>
                <div>
                    <?php if ($snyk_authenticated && !empty($snyk_user)): ?>
                        <span class="auth-status connected">
                            <i class="fas fa-check-circle"></i>
                            Connected as <strong><?php echo htmlspecialchars($snyk_user); ?></strong>
                        </span>
                    <?php elseif ($snyk_authenticated): ?>
                        <span class="auth-status connected">
                            <i class="fas fa-check-circle"></i> Snyk Connected
                        </span>
                    <?php else: ?>
                        <span class="auth-status disconnected">
                            <i class="fas fa-times-circle"></i> Snyk Not Connected
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Vulnerability Stats Dashboard -->
    <div class="row mb-4 g-3">
        <div class="col-md-3">
            <div class="card card-dark-custom">
                <div class="card-body text-center py-3">
                    <h2 class="mb-1" style="color: var(--text);"><?php echo $scan_stats['total_vulnerabilities']; ?></h2>
                    <small class="text-muted">Total Vulnerabilities</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card card-dark-custom">
                <div class="card-body text-center py-3">
                    <h2 class="text-danger mb-1"><?php echo $scan_stats['critical']; ?></h2>
                    <small class="text-muted">Critical</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card card-dark-custom">
                <div class="card-body text-center py-3">
                    <h2 class="text-warning mb-1"><?php echo $scan_stats['high']; ?></h2>
                    <small class="text-muted">High</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card card-dark-custom">
                <div class="card-body text-center py-3">
                    <h2 class="text-info mb-1"><?php echo $scan_stats['medium']; ?></h2>
                    <small class="text-muted">Medium</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-dark-custom">
                <div class="card-body text-center py-3">
                    <h2 class="text-success mb-1"><?php echo $scan_stats['low']; ?></h2>
                    <small class="text-muted">Low</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Last Scan Info Bar -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card card-dark-custom">
                <div class="card-body py-2">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <small class="text-muted d-block">Last Scan</small>
                            <span style="color: var(--text);"><?php echo htmlspecialchars($scan_stats['last_scan']); ?></span>
                        </div>
                        <div class="col-md-2">
                            <small class="text-muted d-block">Duration</small>
                            <span style="color: var(--text);"><?php echo $scan_stats['duration']; ?>s</span>
                        </div>
                        <div class="col-md-2">
                            <small class="text-muted d-block">Status</small>
                            <span style="color: var(--text);"><?php echo htmlspecialchars($scan_stats['status']); ?></span>
                        </div>
                        <div class="col-md-2">
                            <small class="text-muted d-block">Trend</small>
                            <span style="color: var(--text);"><i class="fas fa-arrow-right me-1"></i><?php echo htmlspecialchars($scan_stats['trend']); ?></span>
                        </div>
                        <div class="col-md-3 text-end">
                            <small class="text-muted">Snyk v<?php echo htmlspecialchars($snyk_version); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($config_message): ?>
        <div class="alert alert-<?php echo $config_status; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo $config_status === 'success' ? 'check-circle' : ($config_status === 'warning' ? 'exclamation-triangle' : 'exclamation-triangle'); ?> me-2"></i>
            <?php echo $config_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ================================================================== -->
    <!-- SECTION 1: Authentication Status & Configuration                   -->
    <!-- ================================================================== -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card card-dark-custom">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Snyk Configuration</h5>
                    <?php if ($snyk_available && !$snyk_update_available && !isset($_GET['check_updates'])): ?>
                        <a href="?check_updates=1" class="btn btn-sm btn-outline-secondary" style="font-size: 0.75rem;">
                            <i class="fas fa-sync-alt me-1"></i>Check for Updates
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Status Column -->
                        <div class="col-md-5 mb-3">
                            <h6 style="color: var(--text);" class="mb-3"><i class="fas fa-info-circle me-2"></i>Status</h6>
                            <dl class="row mb-0">
                                <dt class="col-sm-5 text-muted">Snyk CLI</dt>
                                <dd class="col-sm-7">
                                    <span class="badge bg-<?php echo $snyk_available ? 'success' : 'danger'; ?>">
                                        <?php echo $snyk_available ? 'Installed' : 'Not Installed'; ?>
                                    </span>
                                </dd>

                                <dt class="col-sm-5 text-muted">Authentication</dt>
                                <dd class="col-sm-7">
                                    <span class="badge bg-<?php echo $snyk_authenticated ? 'success' : 'warning'; ?>">
                                        <?php echo $snyk_authenticated ? 'Authenticated' : 'Not Configured'; ?>
                                    </span>
                                </dd>

                                <?php if (!empty($snyk_user)): ?>
                                <dt class="col-sm-5 text-muted">Snyk User</dt>
                                <dd class="col-sm-7" style="color: var(--text);">
                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($snyk_user); ?>
                                </dd>
                                <?php endif; ?>

                                <dt class="col-sm-5 text-muted">Version</dt>
                                <dd class="col-sm-7" style="color: var(--text);">
                                    <?php echo htmlspecialchars($snyk_version); ?>
                                    <?php if ($snyk_update_available): ?>
                                        <span class="badge bg-warning ms-2">Update Available</span>
                                    <?php endif; ?>
                                </dd>

                                <dt class="col-sm-5 text-muted">NPM</dt>
                                <dd class="col-sm-7">
                                    <span class="badge bg-<?php echo $npm_available ? 'success' : 'danger'; ?>">
                                        <?php echo $npm_available ? 'Available' : 'Not Found'; ?>
                                    </span>
                                </dd>
                            </dl>

                            <?php if ($snyk_update_available): ?>
                                <form method="post" class="mt-3">
                                    <input type="hidden" name="action" value="update_snyk">
                                    <button type="submit" class="btn btn-warning btn-sm w-100">
                                        <i class="fas fa-arrow-up me-2"></i>Update Snyk to Latest
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <!-- API Configuration Column -->
                        <div class="col-md-7 mb-3">
                            <h6 style="color: var(--text);" class="mb-3"><i class="fas fa-key me-2"></i>API Configuration</h6>
                            <?php if (!$snyk_available): ?>
                                <p class="text-muted mb-3">Snyk is not installed. Click below to install automatically:</p>
                                <form method="post">
                                    <input type="hidden" name="action" value="install_snyk">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-download me-2"></i>Install Snyk Now
                                    </button>
                                </form>
                                <small class="text-muted d-block mt-2">This will automatically install Node.js, npm, and Snyk if needed.</small>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="save_api_key">
                                    <div class="mb-3">
                                        <label class="form-label" style="color: var(--text);">
                                            <strong>Snyk API Token</strong>
                                        </label>
                                        <small class="text-muted d-block mb-2">
                                            Get your token from <a href="https://app.snyk.io/account" target="_blank" style="color: var(--accent);">app.snyk.io/account</a>
                                        </small>
                                        <input type="password" name="snyk_api_key" class="form-control form-control-custom"
                                               placeholder="<?php echo $snyk_authenticated ? 'Token configured -- enter new value to update' : 'Enter your Snyk API token'; ?>">
                                        <?php if ($snyk_authenticated): ?>
                                            <small class="text-success d-block mt-1">
                                                <i class="fas fa-check-circle me-1"></i>Currently authenticated
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save me-2"></i><?php echo $snyk_authenticated ? 'Update' : 'Save'; ?> API Key
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- SECTION 2: Scan Options (all Snyk types as selectable checkboxes)  -->
    <!-- ================================================================== -->
    <?php if (!$snyk_available || !$snyk_authenticated): ?>
        <div class="alert alert-warning mb-4">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Scanning is disabled:</strong>
            <?php if (!$snyk_available): ?>
                Please install Snyk using the "Install Snyk Now" button in the Configuration section above.
            <?php else: ?>
                Please configure your Snyk API key in the Configuration section above.
                <br><small>Don't have a Snyk API key? Get one free at <a href="https://snyk.io/signup" target="_blank" style="color: #856404; text-decoration: underline;">snyk.io/signup</a></small>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" id="scan-form">
        <input type="hidden" name="action" value="run_selected_scans">

        <div class="row mb-3">
            <div class="col-12">
                <h4 style="color: var(--text);"><i class="fas fa-tasks me-2"></i>Scan Options</h4>
                <p class="text-muted">Select the scan types to run. Each scan checks for different categories of security issues.</p>
            </div>
        </div>

        <div class="row mb-4 g-3">
            <?php foreach ($scan_types as $key => $type): ?>
                <div class="col-md-4 col-lg">
                    <div class="scan-type-card <?php echo $type['default_checked'] ? 'selected' : ''; ?>"
                         id="card-<?php echo $key; ?>"
                         onclick="toggleScanType('<?php echo $key; ?>')">
                        <input type="checkbox"
                               class="form-check-input"
                               name="scan_<?php echo $key; ?>"
                               id="check-<?php echo $key; ?>"
                               value="1"
                               <?php echo $type['default_checked'] ? 'checked' : ''; ?>
                               <?php echo (!$snyk_available || !$snyk_authenticated) ? 'disabled' : ''; ?>>
                        <div class="scan-icon" style="background: <?php echo $type['color']; ?>;">
                            <i class="<?php echo ($type['icon_prefix'] ?? 'fas') . ' ' . $type['icon']; ?>"></i>
                        </div>
                        <div class="scan-label"><?php echo htmlspecialchars($type['label']); ?></div>
                        <div class="scan-cmd"><?php echo htmlspecialchars($type['command']); ?></div>
                        <div class="scan-desc"><?php echo htmlspecialchars($type['description']); ?></div>
                        <?php if (!empty($type['note'])): ?>
                            <div class="scan-note"><i class="fas fa-info-circle me-1"></i><?php echo htmlspecialchars($type['note']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Run Scan Button -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="security-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="text-white mb-2">
                                <i class="fas fa-shield-alt me-2"></i>Run Selected Scans
                            </h4>
                            <p class="text-white mb-0" style="opacity: 0.9;">
                                <span id="selected-count"><?php
                                    $default_count = 0;
                                    foreach ($scan_types as $t) { if ($t['default_checked']) $default_count++; }
                                    echo $default_count;
                                ?></span> scan type(s) selected.
                                Results will be displayed in real-time with detailed findings.
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <button type="submit" class="btn btn-light btn-lg" style="min-width: 200px;"
                                    id="run-scan-btn"
                                    <?php echo (!$snyk_available || !$snyk_authenticated) ? 'disabled' : ''; ?>>
                                <i class="fas fa-play-circle me-2"></i>Run Scan
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- ================================================================== -->
    <!-- SECTION 3: Real-time Scan Progress Monitor                         -->
    <!-- ================================================================== -->
    <?php if (isset($_GET['monitoring'])): ?>
        <?php
        $monitoring_scan_id = $_GET['monitoring'];
        $monitoring_scan_type = $_GET['type'] ?? 'multi';
        $monitoring_types = $_GET['types'] ?? '';

        $scan_names = [
            'multi' => 'Security Scan',
            'all' => 'Comprehensive Security Scan',
            'dependencies' => 'Dependency Scan',
            'code' => 'Code Analysis',
            'sca' => 'Open Source (SCA) Scan',
            'container' => 'Container Scan',
            'iac' => 'IaC Scan',
            'license' => 'License Compliance Scan',
        ];

        $type_labels = [];
        if (!empty($monitoring_types)) {
            foreach (explode(',', $monitoring_types) as $t) {
                $t = trim($t);
                if (isset($scan_types[$t])) {
                    $type_labels[] = $scan_types[$t]['label'];
                }
            }
        }
        $scan_display_name = !empty($type_labels) ? implode(', ', $type_labels) : ($scan_names[$monitoring_scan_type] ?? 'Security Scan');
        ?>
        <div class="row mb-4" id="scan-progress-container">
            <div class="col-12">
                <div class="card card-dark-custom scan-progress-section">
                    <div class="scan-progress-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0" style="color: var(--text);">
                            <i class="fas fa-sync fa-spin me-2" id="progress-spinner"></i>
                            <span id="progress-title">Scan In Progress</span>
                        </h5>
                        <small class="text-muted"><?php echo htmlspecialchars($scan_display_name); ?></small>
                    </div>
                    <div class="card-body">
                        <!-- Progress bar -->
                        <div class="mb-3">
                            <div class="progress" style="height: 28px; background: rgba(255,255,255,0.05);">
                                <div id="progress-bar"
                                     class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                                     role="progressbar" style="width: 0%">
                                    <span id="progress-percent">0%</span>
                                </div>
                            </div>
                        </div>

                        <!-- Step list (populated by JS) -->
                        <div id="scan-steps" class="mb-3"></div>

                        <!-- Status message -->
                        <div id="progress-message" class="text-center mb-3" style="color: var(--text);">
                            Initializing scan...
                        </div>

                        <!-- Summary (shown when done) -->
                        <div id="progress-summary" class="alert" style="display: none; white-space: pre-wrap; font-family: monospace;"></div>

                        <!-- Action buttons -->
                        <div class="text-center">
                            <button id="show-full-output-btn" class="btn btn-sm btn-outline-primary" onclick="toggleFullOutput()" style="display: none;">
                                <i class="fas fa-list me-2"></i>Show Full Output
                            </button>
                            <button id="download-btn" class="btn btn-sm btn-outline-success" onclick="downloadResults()" style="display: none;">
                                <i class="fas fa-download me-2"></i>Download Results
                            </button>
                            <a href="security_scan.php" class="btn btn-sm btn-secondary" id="back-btn" style="display: none;">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>

                        <!-- Full output area -->
                        <div id="full-output" class="scan-output mt-3" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        let scanId = <?php echo json_encode($monitoring_scan_id); ?>;
        let pollInterval;
        let fullOutputData = '';

        function updateProgress() {
            fetch('snyk_scan_progress.php?scan_id=' + encodeURIComponent(scanId))
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('progress-message').textContent = 'Error: ' + data.error;
                        clearInterval(pollInterval);
                        return;
                    }

                    // Update progress bar
                    document.getElementById('progress-bar').style.width = data.percent + '%';
                    document.getElementById('progress-percent').textContent = data.percent + '%';
                    document.getElementById('progress-message').textContent = data.message;

                    // Update step list if available
                    if (data.steps && data.steps.length > 0) {
                        let stepsHtml = '';
                        data.steps.forEach(function(step) {
                            let iconClass = 'fas fa-circle text-muted';
                            let stepClass = 'pending';
                            if (step.status === 'running') {
                                iconClass = 'fas fa-spinner fa-spin text-primary';
                                stepClass = 'running';
                            } else if (step.status === 'success') {
                                iconClass = 'fas fa-check-circle text-success';
                                stepClass = 'success';
                            } else if (step.status === 'error') {
                                iconClass = 'fas fa-times-circle text-danger';
                                stepClass = 'error';
                            } else if (step.status === 'skipped') {
                                iconClass = 'fas fa-minus-circle text-muted';
                                stepClass = 'skipped';
                            }
                            stepsHtml += '<div class="scan-step ' + stepClass + '">';
                            stepsHtml += '<span class="step-icon"><i class="' + iconClass + '"></i></span>';
                            stepsHtml += '<span>' + step.label + '</span>';
                            if (step.detail) {
                                stepsHtml += '<small class="ms-auto text-muted">' + step.detail + '</small>';
                            }
                            stepsHtml += '</div>';
                        });
                        document.getElementById('scan-steps').innerHTML = stepsHtml;
                    }

                    // Store full output
                    fullOutputData = data.output || '';

                    // Check if completed
                    if (data.status === 'success' || data.status === 'warning' || data.status === 'error') {
                        clearInterval(pollInterval);

                        let progressBar = document.getElementById('progress-bar');
                        progressBar.classList.remove('progress-bar-animated', 'bg-primary');
                        if (data.status === 'success') {
                            progressBar.classList.add('bg-success');
                        } else if (data.status === 'warning') {
                            progressBar.classList.add('bg-warning');
                        } else {
                            progressBar.classList.add('bg-danger');
                        }

                        // Update title
                        document.getElementById('progress-title').textContent = 'Scan Complete';
                        let spinner = document.getElementById('progress-spinner');
                        spinner.classList.remove('fa-spin', 'fa-sync');
                        spinner.classList.add(data.status === 'success' ? 'fa-check-circle' : (data.status === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle'));

                        // Show summary
                        let summaryDiv = document.getElementById('progress-summary');
                        summaryDiv.textContent = data.message;
                        summaryDiv.className = 'alert alert-' + (data.status === 'success' ? 'success' : (data.status === 'warning' ? 'warning' : 'danger'));
                        summaryDiv.style.display = 'block';
                        summaryDiv.style.whiteSpace = 'pre-wrap';
                        summaryDiv.style.fontFamily = 'monospace';

                        // Show buttons
                        if (fullOutputData) {
                            document.getElementById('show-full-output-btn').style.display = 'inline-block';
                            document.getElementById('download-btn').style.display = 'inline-block';
                        }
                        document.getElementById('back-btn').style.display = 'inline-block';
                    }
                })
                .catch(error => {
                    console.error('Progress check failed:', error);
                });
        }

        function toggleFullOutput() {
            let outputDiv = document.getElementById('full-output');
            let btn = document.getElementById('show-full-output-btn');
            if (outputDiv.style.display === 'none') {
                outputDiv.textContent = fullOutputData;
                outputDiv.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-times me-2"></i>Hide Full Output';
            } else {
                outputDiv.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-list me-2"></i>Show Full Output';
            }
        }

        function downloadResults() {
            const blob = new Blob([fullOutputData], { type: 'text/plain' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'snyk-scan-' + new Date().toISOString().split('T')[0] + '.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Start polling
        updateProgress();
        pollInterval = setInterval(updateProgress, 2000);
        </script>
    <?php endif; ?>

    <!-- ================================================================== -->
    <!-- SECTION 4: Snyk Code Notice & Reference                            -->
    <!-- ================================================================== -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card card-dark-custom">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-exclamation-circle me-2 text-warning"></i>Snyk Code (SAST) Notice</h5>
                </div>
                <div class="card-body">
                    <p>Snyk Code (SAST) must be enabled in your Snyk organization settings before it can be used.
                       If you see error <code>SNYK-CODE-0005</code>, follow these steps:</p>
                    <ol class="mb-3">
                        <li>Log in to <a href="https://app.snyk.io" target="_blank" style="color: var(--accent);">app.snyk.io</a></li>
                        <li>Go to <strong>Settings</strong> (gear icon) for your organization</li>
                        <li>Navigate to <strong>Snyk Code</strong> in the left menu</li>
                        <li>Toggle <strong>"Enable Snyk Code"</strong> to ON</li>
                        <li>Wait a few minutes for the change to propagate</li>
                    </ol>
                    <a href="https://docs.snyk.io/scan-with-snyk/snyk-code" target="_blank" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-external-link-alt me-1"></i>Snyk Code Documentation
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-dark-custom">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Scan Types Reference</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0" style="color: var(--text);">
                        <thead>
                            <tr>
                                <th style="border-color: var(--border); color: var(--muted);">Type</th>
                                <th style="border-color: var(--border); color: var(--muted);">Command</th>
                                <th style="border-color: var(--border); color: var(--muted);">What It Checks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="border-color: var(--border);"><span class="badge" style="background: #3b82f6;">SCA</span></td>
                                <td style="border-color: var(--border);"><code>snyk test</code></td>
                                <td style="border-color: var(--border);">Dependency CVEs</td>
                            </tr>
                            <tr>
                                <td style="border-color: var(--border);"><span class="badge" style="background: #8b5cf6;">SAST</span></td>
                                <td style="border-color: var(--border);"><code>snyk code test</code></td>
                                <td style="border-color: var(--border);">Source code flaws</td>
                            </tr>
                            <tr>
                                <td style="border-color: var(--border);"><span class="badge" style="background: #06b6d4;">Container</span></td>
                                <td style="border-color: var(--border);"><code>snyk container test</code></td>
                                <td style="border-color: var(--border);">Docker image vulns</td>
                            </tr>
                            <tr>
                                <td style="border-color: var(--border);"><span class="badge" style="background: #f59e0b;">IaC</span></td>
                                <td style="border-color: var(--border);"><code>snyk iac test</code></td>
                                <td style="border-color: var(--border);">Infra misconfigs</td>
                            </tr>
                            <tr>
                                <td style="border-color: var(--border);"><span class="badge" style="background: #10b981;">License</span></td>
                                <td style="border-color: var(--border);"><code>snyk test --json</code></td>
                                <td style="border-color: var(--border);">License compliance</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// Toggle scan type card selection
function toggleScanType(key) {
    let card = document.getElementById('card-' + key);
    let checkbox = document.getElementById('check-' + key);
    if (checkbox.disabled) return;
    checkbox.checked = !checkbox.checked;
    card.classList.toggle('selected', checkbox.checked);
    updateSelectedCount();
}

// Update selected count display
function updateSelectedCount() {
    let count = document.querySelectorAll('.scan-type-card .form-check-input:checked').length;
    document.getElementById('selected-count').textContent = count;
    let btn = document.getElementById('run-scan-btn');
    if (btn && !btn.hasAttribute('data-force-disabled')) {
        btn.disabled = (count === 0);
    }
}

// Prevent checkbox click from double-toggling
document.querySelectorAll('.scan-type-card .form-check-input').forEach(function(cb) {
    cb.addEventListener('click', function(e) {
        e.stopPropagation();
        let key = this.id.replace('check-', '');
        let card = document.getElementById('card-' + key);
        card.classList.toggle('selected', this.checked);
        updateSelectedCount();
    });
});

// Initialize count
updateSelectedCount();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
