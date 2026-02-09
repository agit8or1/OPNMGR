<?php
// Development > Package Builder
require_once 'inc/auth.php';
require_once 'inc/csrf.php';
requireLogin();
$page_title = "Deployment Package Builder";
include 'inc/header.php';
require_once 'inc/db.php';

// Files and directories to EXCLUDE from deployment package
$exclude_patterns = [
    // Deployment-related files
    'deploy_*.php',
    'deploy_*.sh',
    'manual_agent_deploy.html',
    'scripts/deploy_*',
    
    // Development tools (these stay on primary only)
    'package_builder.php',
    'license_server.php',
    'api/license_checkin.php',
    
    // Development menu items - ALL excluded from deployment
    'dev_info.php',
    'dev_tools.php',
    'dev_database.php',
    'dev_logs.php',
    'dev_test.php',
    'dev_api_test.php',
    'dev_cache.php',
    'dev_config.php',
    'dev_*.php',
    'phpinfo.php',
    'test.php',
    'test_*.php',
    'debug.php',
    'debug_*.php',
    
    // Temp/cache files
    '*.log',
    '*.tmp',
    'tmp/*',
    'cache/*',
    'logs/*',
    '/var/log/*',
    
    // Keys and sensitive data (will be regenerated on target)
    'keys/*',
    '.env',
    'config.php',
    
    // Git and development
    '.git/*',
    '.gitignore',
    '.vscode/*',
    '.idea/*',
    '*.swp',
    '*.swo',
    
    // Backup files
    '*.backup*',
    '*.bak',
    '*~',
    'backups/*',
    
    // Generated packages
    'packages/*',
    
    // Screenshots and documentation source
    'screenshots/*',
    'screenshots_*/*',
    '.archive/*',
];

$package_generated = false;
$package_url = '';
$package_filename = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_package'])) {
    try {
        // Create packages directory if it doesn't exist
        $packages_dir = '/var/www/opnsense/packages';
        if (!is_dir($packages_dir)) {
            mkdir($packages_dir, 0755, true);
        }
        
        // Generate unique package name with timestamp
        $timestamp = date('Ymd_His');
        $package_filename = "opnsense_deploy_{$timestamp}.tar.gz";
        $package_path = "{$packages_dir}/{$package_filename}";
        
        // Build tar command with exclusions
        $exclude_args = [];
        foreach ($exclude_patterns as $pattern) {
            $exclude_args[] = "--exclude='{$pattern}'";
        }
        $exclude_string = implode(' ', $exclude_args);
        
        // Create tar archive
        $source_dir = '/var/www/opnsense';
        $tar_command = "cd /var/www && tar czf {$package_path} {$exclude_string} opnsense/ 2>&1";
        
        error_log("[PACKAGE_BUILDER] Creating package: {$package_filename}");
        error_log("[PACKAGE_BUILDER] Command: {$tar_command}");
        
        exec($tar_command, $output, $return_code);
        
        error_log("[PACKAGE_BUILDER] Tar return code: {$return_code}");
        error_log("[PACKAGE_BUILDER] Tar output: " . implode("\n", $output));
        
        if ($return_code === 0 && file_exists($package_path)) {
            $filesize = filesize($package_path);
            error_log("[PACKAGE_BUILDER] Package created successfully: {$filesize} bytes");
            
            $package_generated = true;
            
            // Generate download URL
            $package_url = "https://" . $_SERVER['HTTP_HOST'] . "/packages/{$package_filename}";
            
            // Log package creation to system_logs
            $stmt = $DB->prepare("INSERT INTO system_logs (firewall_id, category, message, level, timestamp) VALUES (NULL, 'deployment', ?, 'INFO', NOW())");
            $stmt->execute(["Generated deployment package: {$package_filename} (" . round($filesize / 1024 / 1024, 2) . " MB)"]);
            error_log("[PACKAGE_BUILDER] Package created successfully: {$package_filename}");
            
            $success_message = "Deployment package created successfully! Size: " . round($filesize / 1024 / 1024, 2) . " MB";
        } else {
            $error_details = implode("\n", $output);
            error_log("[PACKAGE_BUILDER] ERROR: Failed to create package. Return code: {$return_code}, Output: {$error_details}");
            $error_message = "Failed to create package. Return code: {$return_code}. Check error logs for details.";
        }
    } catch (Exception $e) {
        error_log("package_builder.php error: " . $e->getMessage());
        $error_message = "An internal error occurred.";
    }
}

// Get list of existing packages
$existing_packages = [];
$packages_dir = '/var/www/opnsense/packages';
if (is_dir($packages_dir)) {
    $files = scandir($packages_dir);
    foreach ($files as $file) {
        if (preg_match('/^opnsense_deploy_\d{8}_\d{6}\.tar\.gz$/', $file)) {
            $filepath = "{$packages_dir}/{$file}";
            $existing_packages[] = [
                'filename' => $file,
                'size' => filesize($filepath),
                'created' => filemtime($filepath),
                'url' => "https://" . $_SERVER['HTTP_HOST'] . "/packages/{$file}"
            ];
        }
    }
    // Sort by creation time, newest first
    usort($existing_packages, function($a, $b) {
        return $b['created'] - $a['created'];
    });
}
?>

<style>
body {
    background: #1a1d23;
    color: #e0e0e0;
}
.package-builder-container {
    max-width: 1200px;
    margin: 30px auto;
    padding: 0 20px;
}
.builder-card {
    background: #2d3139;
    border-radius: 8px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    border: 1px solid #3a3f4b;
}
.builder-card h2 {
    color: #4fc3f7;
    border-bottom: 3px solid #3498db;
    padding-bottom: 10px;
    margin-bottom: 20px;
}
.builder-card h3 {
    color: #81c784;
    margin-top: 20px;
    margin-bottom: 15px;
}
.exclude-list {
    background: #1a1d23;
    border: 1px solid #3a3f4b;
    border-radius: 4px;
    padding: 15px;
    max-height: 300px;
    overflow-y: auto;
}
.exclude-list ul {
    list-style: none;
    padding-left: 0;
    margin: 0;
}
.exclude-list li {
    padding: 5px 0;
    color: #95a5a6;
    font-family: 'Courier New', monospace;
    font-size: 14px;
}
.exclude-list li:before {
    content: "✗ ";
    color: #e74c3c;
    font-weight: bold;
    margin-right: 8px;
}
.generate-btn {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    padding: 12px 30px;
    font-size: 16px;
    font-weight: 600;
    border-radius: 6px;
    color: white;
    transition: all 0.3s;
}
.generate-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}
.package-info {
    background: #1a1d23;
    border: 2px solid #27ae60;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}
.package-info h4 {
    color: #27ae60;
    margin-bottom: 15px;
}
.copy-btn {
    background: #27ae60;
    border: none;
    padding: 8px 20px;
    border-radius: 4px;
    color: white;
    font-weight: 600;
    margin-left: 10px;
    cursor: pointer;
    transition: all 0.3s;
}
.copy-btn:hover {
    background: #229954;
    transform: translateY(-1px);
}
.delete-btn {
    background: #e74c3c;
    border: none;
    padding: 8px 20px;
    border-radius: 4px;
    color: white;
    font-weight: 600;
    margin-left: 10px;
    cursor: pointer;
    transition: all 0.3s;
}
.delete-btn:hover {
    background: #c0392b;
    transform: translateY(-1px);
}
.package-url {
    background: #2d3139;
    padding: 12px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    color: #4fc3f7;
    border: 1px solid #3a3f4b;
    margin: 10px 0;
    word-break: break-all;
}
.packages-table {
    width: 100%;
    margin-top: 15px;
}
.packages-table th {
    background: #1a1d23;
    color: #4fc3f7;
    padding: 12px;
    text-align: left;
    border-bottom: 2px solid #3498db;
}
.packages-table td {
    padding: 12px;
    border-bottom: 1px solid #3a3f4b;
}
.packages-table tr:hover {
    background: #363a45;
}
.download-btn {
    background: #3498db;
    color: white;
    padding: 6px 15px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.3s;
    display: inline-block;
}
.download-btn:hover {
    background: #2980b9;
    text-decoration: none;
    color: white;
}
.alert-success {
    background: rgba(39, 174, 96, 0.2);
    border: 1px solid #27ae60;
    color: #27ae60;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}
.alert-danger {
    background: rgba(231, 76, 60, 0.2);
    border: 1px solid #e74c3c;
    color: #e74c3c;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}
.info-box {
    background: rgba(52, 152, 219, 0.1);
    border-left: 4px solid #3498db;
    padding: 15px;
    margin: 20px 0;
    border-radius: 4px;
}
.info-box h4 {
    color: #4fc3f7;
    margin-top: 0;
}
</style>

<div class="package-builder-container">
    <div class="builder-card">
        <h2><i class="fa fa-box me-2"></i> Deployment Package Builder</h2>
        
        <?php if (isset($success_message)): ?>
            <div class="alert-success">
                <i class="fa fa-check-circle me-2"></i> <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert-danger">
                <i class="fa fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h4><i class="fa fa-info-circle me-2"></i> About Deployment Packages</h4>
            <p>
                Create a clean deployment package for replicating this system to other servers.
                The package automatically excludes:
            </p>
            <ul>
                <li>All deployment tools and scripts (these remain on the primary server only)</li>
                <li>Development menu items (hidden on deployed instances)</li>
                <li>SSH keys and sensitive configuration (regenerated on target server)</li>
                <li>Temporary files, logs, and backups</li>
            </ul>
            <p class="mb-0">
                <strong>Note:</strong> Deployed servers will use IP addresses until FQDN and ACME SSL are configured.
                They will check in with opn.agit8or.net every 4 hours for updates and licensing.
            </p>
        </div>
        
        <h3>Excluded Files & Patterns</h3>
        <div class="exclude-list">
            <ul>
                <?php foreach ($exclude_patterns as $pattern): ?>
                    <li><?= htmlspecialchars($pattern) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <form method="POST" style="margin-top: 30px;">
            <button type="submit" name="generate_package" class="generate-btn">
                <i class="fa fa-cogs me-2"></i> Generate Deployment Package
            </button>
        </form>
        
        <?php if ($package_generated): ?>
            <div class="package-info">
                <h4><i class="fa fa-check-circle me-2"></i> Package Generated Successfully!</h4>
                <p><strong>Filename:</strong> <?= htmlspecialchars($package_filename) ?></p>
                <p><strong>Download URL:</strong></p>
                <div class="package-url" id="packageUrl"><?= htmlspecialchars($package_url) ?></div>
                <button class="copy-btn" onclick="copyToClipboard()">
                    <i class="fa fa-copy me-2"></i> Copy URL
                </button>
                <a href="/packages/<?= htmlspecialchars($package_filename) ?>" class="download-btn" style="margin-left: 10px;">
                    <i class="fa fa-download me-2"></i> Download
                </a>
                
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #3a3f4b;">
                    <h5 style="color: #81c784;">Installation Instructions</h5>
                    <pre style="background: #1a1d23; padding: 15px; border-radius: 4px; overflow-x: auto; color: #e0e0e0;">
# On the target server:
cd /var/www
wget <?= htmlspecialchars($package_url) ?>

tar xzf <?= htmlspecialchars($package_filename) ?>

cd opnsense
# Run initial setup
php setup.php

# Configure database connection
nano inc/db.php

# Set up web server
# Configure FQDN and SSL as needed</pre>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Hidden CSRF token for API calls -->
    <input type="hidden" id="csrf_token" name="csrf" value="<?= csrf_token(); ?>">
    
    <?php if (!empty($existing_packages)): ?>
        <div class="builder-card">
            <h2><i class="fa fa-archive me-2"></i> Previous Packages</h2>
            <table class="packages-table">
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($existing_packages as $pkg): ?>
                        <tr>
                            <td><?= htmlspecialchars($pkg['filename']) ?></td>
                            <td><?= number_format($pkg['size'] / 1024 / 1024, 2) ?> MB</td>
                            <td><?= date('Y-m-d H:i:s', $pkg['created']) ?></td>
                            <td>
                                <a href="<?= htmlspecialchars($pkg['url']) ?>" class="download-btn">
                                    <i class="fa fa-download me-1"></i> Download
                                </a>
                                <button class="copy-btn" onclick="copyUrl('<?= htmlspecialchars($pkg['url']) ?>')">
                                    <i class="fa fa-copy me-1"></i> Copy URL
                                </button>
                                <button class="delete-btn" onclick="deletePackage('<?= htmlspecialchars($pkg['filename']) ?>')">
                                    <i class="fa fa-trash me-1"></i> Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function copyToClipboard() {
    const urlText = document.getElementById('packageUrl').textContent;
    navigator.clipboard.writeText(urlText).then(() => {
        alert('URL copied to clipboard!');
    }).catch(err => {
        console.error('Failed to copy:', err);
    });
}

function copyUrl(url) {
    navigator.clipboard.writeText(url).then(() => {
        alert('URL copied to clipboard!');
    }).catch(err => {
        console.error('Failed to copy:', err);
    });
}

function deletePackage(filename) {
    if (!confirm(`Are you sure you want to delete "${filename}"? This cannot be undone.`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('filename', filename);
    formData.append('csrf', document.querySelector('input[name="csrf"]')?.value || '');
    
    fetch('/api/delete_deployment_package.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Package deleted successfully');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Delete error:', error);
        alert('Failed to delete package: ' + error.message);
    });
}
</script>

<?php include 'inc/footer.php'; ?>
