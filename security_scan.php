<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/env.php';

$page_title = "Security Scanner (Snyk Integration)";

// Handle configuration actions
$config_message = '';
$config_status = '';

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

                // Also configure Snyk CLI
                exec('snyk config set api=' . escapeshellarg($api_key) . ' 2>&1', $output, $return_code);

                $config_message = 'Snyk API key saved and configured successfully!';
                $config_status = 'success';
            } else {
                $config_message = 'API key cannot be empty.';
                $config_status = 'danger';
            }
            break;

        case 'install_snyk':
            exec('npm install -g snyk 2>&1', $output, $return_code);
            if ($return_code === 0) {
                $config_message = 'Snyk installed successfully! Please configure your API key below.';
                $config_status = 'success';
            } else {
                $config_message = 'Failed to install Snyk. Error: ' . implode("\n", $output);
                $config_status = 'danger';
            }
            break;

        case 'scan_dependencies':
            exec('cd ' . __DIR__ . '/scripts && snyk test --json 2>&1', $output, $return_code);
            $scan_output = implode("\n", $output);
            $scan_status = ($return_code === 0) ? 'success' : 'warning';
            break;

        case 'scan_code':
            exec('cd ' . __DIR__ . ' && snyk code test --json 2>&1', $output, $return_code);
            $scan_output = implode("\n", $output);
            $scan_status = ($return_code === 0) ? 'success' : 'warning';
            break;

        case 'monitor':
            exec('cd ' . __DIR__ . ' && snyk monitor 2>&1', $output, $return_code);
            $scan_output = implode("\n", $output);
            $scan_status = 'info';
            break;
    }

    // Parse JSON output for scans
    if (isset($scan_output) && !empty($scan_output)) {
        $json_start = strpos($scan_output, '{');
        if ($json_start !== false) {
            $json_str = substr($scan_output, $json_start);
            $scan_data = json_decode($json_str, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $scan_output = json_encode($scan_data, JSON_PRETTY_PRINT);
            }
        }
    }
}

// Check if Snyk is installed and authenticated
exec('which snyk 2>&1', $snyk_check, $snyk_installed);
$snyk_available = ($snyk_installed === 0);

$snyk_authenticated = false;
if ($snyk_available) {
    exec('snyk config get api 2>&1', $auth_check);
    $snyk_authenticated = !empty($auth_check[0]) && $auth_check[0] !== '';
}

// Check if npm is available for installation
exec('which npm 2>&1', $npm_check, $npm_installed);
$npm_available = ($npm_installed === 0);

include __DIR__ . '/inc/header.php';
?>

<style>
.security-card {
    background: #ffffff;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.security-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.15);
    border-color: #3b82f6;
}

.scan-output {
    background: #1e293b;
    border: 2px solid #475569;
    border-radius: 6px;
    padding: 1rem;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    color: #e2e8f0;
    max-height: 500px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-success {
    background: #10b981;
    color: white;
}

.status-warning {
    background: #f59e0b;
    color: white;
}

.status-error {
    background: #ef4444;
    color: white;
}

.status-info {
    background: #3b82f6;
    color: white;
}

.config-section {
    background: #f8fafc;
    border: 2px solid #cbd5e1;
    border-radius: 8px;
    padding: 2rem;
}

.card-header-custom {
    background: #1e293b;
    color: white;
    font-weight: 600;
}

.security-card h5 {
    color: #1e293b;
    font-weight: 600;
}

.security-card p {
    color: #64748b;
}

.card-dark-custom {
    background: #ffffff;
    border: 2px solid #e5e7eb;
}

.card-dark-custom .card-header {
    background: #f1f5f9;
    color: #1e293b;
    border-bottom: 2px solid #e5e7eb;
}

.card-dark-custom .card-body {
    color: #1e293b;
}

.form-control-custom {
    background: #ffffff;
    border: 2px solid #cbd5e1;
    color: #1e293b;
}

.form-control-custom:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
}
</style>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="text-dark"><i class="fas fa-shield-alt me-2 text-primary"></i>Security Scanner</h2>
            <p class="text-muted">Powered by Snyk - Continuous security monitoring for vulnerabilities</p>
        </div>
    </div>

    <?php if ($config_message): ?>
        <div class="alert alert-<?php echo $config_status; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo $config_status === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
            <?php echo htmlspecialchars($config_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Configuration Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="config-section">
                <h4 class="mb-3 text-dark"><i class="fas fa-cog me-2 text-primary"></i>Snyk Configuration</h4>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="card card-dark-custom">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Status</h6>
                            </div>
                            <div class="card-body">
                                <dl class="row mb-0">
                                    <dt class="col-sm-5">Snyk CLI:</dt>
                                    <dd class="col-sm-7">
                                        <span class="badge bg-<?php echo $snyk_available ? 'success' : 'danger'; ?>">
                                            <?php echo $snyk_available ? 'Installed' : 'Not Installed'; ?>
                                        </span>
                                    </dd>

                                    <dt class="col-sm-5">Authentication:</dt>
                                    <dd class="col-sm-7">
                                        <span class="badge bg-<?php echo $snyk_authenticated ? 'success' : 'warning'; ?>">
                                            <?php echo $snyk_authenticated ? 'Authenticated' : 'Not Configured'; ?>
                                        </span>
                                    </dd>

                                    <dt class="col-sm-5">NPM Available:</dt>
                                    <dd class="col-sm-7">
                                        <span class="badge bg-<?php echo $npm_available ? 'success' : 'danger'; ?>">
                                            <?php echo $npm_available ? 'Yes' : 'No'; ?>
                                        </span>
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <div class="card card-dark-custom">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-key me-2"></i>API Configuration</h6>
                            </div>
                            <div class="card-body">
                                <?php if (!$snyk_available && $npm_available): ?>
                                    <p class="mb-3 text-dark">Snyk is not installed. Click below to install automatically:</p>
                                    <form method="post">
                                        <input type="hidden" name="action" value="install_snyk">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-download me-2"></i>Install Snyk Now
                                        </button>
                                    </form>
                                <?php elseif (!$snyk_available): ?>
                                    <div class="alert alert-danger mb-0">
                                        <strong>NPM Required:</strong> Install Node.js and npm first.
                                    </div>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="save_api_key">
                                        <div class="mb-3">
                                            <label class="form-label text-dark">
                                                <strong>Snyk API Token</strong>
                                                <small class="text-muted d-block">Get your token from <a href="https://app.snyk.io/account" target="_blank">snyk.io/account</a></small>
                                            </label>
                                            <input type="text" name="snyk_api_key" class="form-control form-control-custom"
                                                   placeholder="Enter your Snyk API token"
                                                   value="<?php echo $snyk_authenticated ? '••••••••••••' : ''; ?>">
                                        </div>
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-save me-2"></i>Save API Key
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scan Actions -->
    <div class="row mb-4">
        <!-- Dependency Scan -->
        <div class="col-md-4">
            <div class="security-card">
                <div class="text-center mb-3">
                    <i class="fas fa-box fa-3x text-primary"></i>
                </div>
                <h5 class="text-center mb-3">Dependency Scan</h5>
                <p class="text-center small">
                    Scan Node.js dependencies for known vulnerabilities (CVEs)
                </p>
                <form method="post" class="text-center">
                    <input type="hidden" name="action" value="scan_dependencies">
                    <button type="submit" class="btn btn-primary" <?php echo (!$snyk_available || !$snyk_authenticated) ? 'disabled' : ''; ?>>
                        <i class="fas fa-search me-2"></i>Scan Dependencies
                    </button>
                </form>
            </div>
        </div>

        <!-- Code Scan -->
        <div class="col-md-4">
            <div class="security-card">
                <div class="text-center mb-3">
                    <i class="fas fa-code fa-3x text-success"></i>
                </div>
                <h5 class="text-center mb-3">Code Analysis</h5>
                <p class="text-center small">
                    Static analysis to find security issues in your source code
                </p>
                <form method="post" class="text-center">
                    <input type="hidden" name="action" value="scan_code">
                    <button type="submit" class="btn btn-success" <?php echo (!$snyk_available || !$snyk_authenticated) ? 'disabled' : ''; ?>>
                        <i class="fas fa-search me-2"></i>Scan Code
                    </button>
                </form>
            </div>
        </div>

        <!-- Monitor -->
        <div class="col-md-4">
            <div class="security-card">
                <div class="text-center mb-3">
                    <i class="fas fa-bell fa-3x text-warning"></i>
                </div>
                <h5 class="text-center mb-3">Continuous Monitoring</h5>
                <p class="text-center small">
                    Monitor project for new vulnerabilities over time
                </p>
                <form method="post" class="text-center">
                    <input type="hidden" name="action" value="monitor">
                    <button type="submit" class="btn btn-warning" <?php echo (!$snyk_available || !$snyk_authenticated) ? 'disabled' : ''; ?>>
                        <i class="fas fa-bell me-2"></i>Enable Monitoring
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php if (isset($scan_output) && $scan_output): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card card-dark-custom">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-terminal me-2"></i>Scan Results
                        </h5>
                        <span class="status-badge status-<?php echo $scan_status; ?>">
                            <?php echo strtoupper($scan_status); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="scan-output">
                            <?php echo htmlspecialchars($scan_output); ?>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-sm btn-primary" onclick="downloadScanResults()">
                                <i class="fas fa-download me-2"></i>Download Results
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="copyScanResults()">
                                <i class="fas fa-copy me-2"></i>Copy to Clipboard
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Information Panel -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card card-dark-custom">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>About Snyk</h5>
                </div>
                <div class="card-body">
                    <p>Snyk is a developer security platform that helps you find and fix vulnerabilities in:</p>
                    <ul>
                        <li><strong>Open Source Dependencies:</strong> Known CVEs in npm, composer, etc.</li>
                        <li><strong>Code:</strong> Security issues in your source code (SAST)</li>
                        <li><strong>Containers:</strong> Vulnerabilities in Docker images</li>
                        <li><strong>IaC:</strong> Misconfigurations in Terraform, Kubernetes, etc.</li>
                    </ul>
                    <p class="mb-0">
                        <a href="https://snyk.io/product/" target="_blank" class="btn btn-sm btn-outline-primary">
                            Learn More <i class="fas fa-external-link-alt ms-1"></i>
                        </a>
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-dark-custom">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-question-circle me-2"></i>Quick Start Guide</h5>
                </div>
                <div class="card-body">
                    <ol class="mb-0">
                        <li class="mb-2"><strong>Get API Token:</strong> Sign up at <a href="https://snyk.io" target="_blank">snyk.io</a></li>
                        <li class="mb-2"><strong>Configure:</strong> Enter your API token in the configuration section above</li>
                        <li class="mb-2"><strong>Run Scans:</strong> Click any scan button to check for vulnerabilities</li>
                        <li><strong>Review Results:</strong> Download or copy results for your records</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function downloadScanResults() {
    const output = document.querySelector('.scan-output').textContent;
    const blob = new Blob([output], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'snyk-scan-' + new Date().toISOString().split('T')[0] + '.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

function copyScanResults() {
    const output = document.querySelector('.scan-output').textContent;
    navigator.clipboard.writeText(output).then(() => {
        alert('Scan results copied to clipboard!');
    }).catch(err => {
        console.error('Failed to copy:', err);
    });
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
