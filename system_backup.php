<?php
// System Backup & Restore
require_once 'inc/auth.php';
require_once 'inc/csrf.php';
require_once 'inc/db.php';
requireAdmin();

$message = '';
$messageType = '';
$backupDir = '/var/backups/opnmanager';

// Ensure backup directory exists
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Handle backup creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = 'CSRF validation failed';
        $messageType = 'danger';
    } else {
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "opnmanager_backup_{$timestamp}.tar.gz";
        $backupPath = "{$backupDir}/{$backupName}";
        $tempDir = "/tmp/opnmgr_backup_{$timestamp}";
        
        try {
            // Create temporary directory
            if (!mkdir($tempDir, 0755, true)) {
                throw new Exception("Failed to create temporary directory");
            }
            
            // Export database
            require_once __DIR__ . '/config.php';
            $dbName = DB_NAME;
            $dbUser = DB_USER;
            $dbPass = DB_PASS;
            $dbHost = DB_HOST;
            $sqlFile = "{$tempDir}/database.sql";
            
            // Use mysqldump with authentication
            $dumpCmd = sprintf(
                "mysqldump -h %s -u %s -p%s %s > %s 2>&1",
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($sqlFile)
            );
            exec($dumpCmd, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception("Database export failed: " . implode("\n", $output));
            }
            
            // Copy config files
            $configFiles = [
                '/var/www/opnsense/inc/config.php',
                '/var/www/opnsense/inc/db.php',
                '/etc/nginx/nginx.conf',
            ];
            
            mkdir("{$tempDir}/configs", 0755, true);
            foreach ($configFiles as $file) {
                if (file_exists($file)) {
                    $basename = basename($file);
                    copy($file, "{$tempDir}/configs/{$basename}");
                }
            }
            
            // Copy SSH keys
            $sshDir = '/var/www/opnsense/.ssh';
            if (is_dir($sshDir)) {
                mkdir("{$tempDir}/ssh_keys", 0755, true);
                exec("cp -r {$sshDir}/* {$tempDir}/ssh_keys/ 2>&1");
            }
            
            // Copy SSL certificates
            $certDirs = [
                '/etc/letsencrypt',
                '/etc/ssl/opnmanager'
            ];
            mkdir("{$tempDir}/certificates", 0755, true);
            foreach ($certDirs as $dir) {
                if (is_dir($dir)) {
                    $basename = basename($dir);
                    exec("cp -r {$dir} {$tempDir}/certificates/{$basename} 2>&1");
                }
            }
            
            // Create metadata file
            $metadata = [
                'backup_date' => date('Y-m-d H:i:s'),
                'version' => defined('VERSION') ? VERSION : 'unknown',
                'php_version' => PHP_VERSION,
                'server' => gethostname()
            ];
            file_put_contents("{$tempDir}/metadata.json", json_encode($metadata, JSON_PRETTY_PRINT));
            
            // Create tar.gz archive
            exec("tar -czf {$backupPath} -C /tmp " . basename($tempDir) . " 2>&1", $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception("Failed to create archive: " . implode("\n", $output));
            }
            
            // Clean up temp directory
            exec("rm -rf {$tempDir}");
            
            // Clean up old backups (keep last 10)
            $backups = glob("{$backupDir}/opnmanager_backup_*.tar.gz");
            rsort($backups); // Sort newest first
            
            if (count($backups) > 10) {
                $toDelete = array_slice($backups, 10);
                foreach ($toDelete as $old) {
                    unlink($old);
                }
            }
            
            $message = "Backup created successfully: {$backupName}";
            $messageType = 'success';
            
        } catch (Exception $e) {
            error_log("system_backup.php backup error: " . $e->getMessage());
            $message = "Backup failed due to an internal error.";
            $messageType = 'danger';
            
            // Clean up on failure
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
        }
    }
}

// Handle backup download
if (isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    $filepath = "{$backupDir}/{$filename}";
    
    if (file_exists($filepath) && strpos($filename, 'opnmanager_backup_') === 0) {
        header('Content-Type: application/x-gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

// Handle backup deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_backup'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = 'CSRF validation failed';
        $messageType = 'danger';
    } else {
        $filename = basename($_POST['backup_file']);
        $filepath = "{$backupDir}/{$filename}";
        
        if (file_exists($filepath) && strpos($filename, 'opnmanager_backup_') === 0) {
            if (unlink($filepath)) {
                $message = "Backup deleted: {$filename}";
                $messageType = 'success';
            } else {
                $message = "Failed to delete backup";
                $messageType = 'danger';
            }
        } else {
            $message = "Invalid backup file";
            $messageType = 'danger';
        }
    }
}

// Handle uploaded backup restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_uploaded'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = 'CSRF validation failed';
        $messageType = 'danger';
    } else {
        if (!isset($_FILES['backup_upload']) || $_FILES['backup_upload']['error'] !== UPLOAD_ERR_OK) {
            $message = "No file uploaded or upload error occurred";
            $messageType = 'danger';
        } else {
            $uploadedFile = $_FILES['backup_upload'];
            $originalName = basename($uploadedFile['name']);
            
            // Validate file extension
            if (!preg_match('/\.tar\.gz$/', $originalName) && !preg_match('/\.tgz$/', $originalName)) {
                $message = "Invalid file type. Only .tar.gz or .tgz files allowed.";
                $messageType = 'danger';
            }
            // Validate file size (max 500MB)
            elseif ($uploadedFile['size'] > 500 * 1024 * 1024) {
                $message = "File too large. Maximum size is 500MB.";
                $messageType = 'danger';
            }
            // Validate filename pattern
            elseif (strpos($originalName, 'opnmanager_backup_') !== 0) {
                $message = "Invalid backup filename. Must start with 'opnmanager_backup_'";
                $messageType = 'danger';
            } else {
                // Move uploaded file to backups directory temporarily
                $tempFilepath = "{$backupDir}/{$originalName}";
                
                if (!move_uploaded_file($uploadedFile['tmp_name'], $tempFilepath)) {
                    $message = "Failed to save uploaded file";
                    $messageType = 'danger';
                } else {
                    // Validate it's a valid tar.gz archive
                    exec("tar -tzf " . escapeshellarg($tempFilepath) . " > /dev/null 2>&1", $output, $returnCode);
                    
                    if ($returnCode !== 0) {
                        unlink($tempFilepath);
                        $message = "Invalid or corrupted backup file";
                        $messageType = 'danger';
                    } else {
                        // File is valid, proceed with restore
                        $_POST['backup_file'] = $originalName;
                        $_POST['restore_backup'] = '1';
                        $filepath = $tempFilepath;
                        $isUploadedBackup = true;
                    }
                }
            }
        }
    }
}

// Handle restore (from existing backups or uploaded file)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup']) && !isset($messageType)) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = 'CSRF validation failed';
        $messageType = 'danger';
    } else {
        $filename = basename($_POST['backup_file']);
        $filepath = isset($isUploadedBackup) ? $filepath : "{$backupDir}/{$filename}";
        
        if (!file_exists($filepath) || strpos($filename, 'opnmanager_backup_') !== 0) {
            $message = "Invalid backup file";
            $messageType = 'danger';
        } else {
            $tempDir = "/tmp/opnmgr_restore_" . time();
            
            try {
                // Extract backup
                mkdir($tempDir, 0755, true);
                exec("tar -xzf {$filepath} -C {$tempDir} 2>&1", $output, $returnCode);
                
                if ($returnCode !== 0) {
                    throw new Exception("Failed to extract backup: " . implode("\n", $output));
                }
                
                // Find extracted directory
                $extracted = glob("{$tempDir}/opnmgr_backup_*");
                if (empty($extracted)) {
                    throw new Exception("Backup structure invalid");
                }
                $extractedDir = $extracted[0];
                
                // Read metadata
                $metadataFile = "{$extractedDir}/metadata.json";
                if (file_exists($metadataFile)) {
                    $metadata = json_decode(file_get_contents($metadataFile), true);
                    error_log("Restoring backup from: " . $metadata['backup_date']);
                }
                
                // Restore database
                $sqlFile = "{$extractedDir}/database.sql";
                if (file_exists($sqlFile)) {
                    require_once __DIR__ . '/config.php';
                    $dbName = DB_NAME;
                    $dbUser = DB_USER;
                    $dbPass = DB_PASS;
                    $dbHost = DB_HOST;
                    
                    $restoreCmd = sprintf(
                        "mysql -h %s -u %s -p%s %s < %s 2>&1",
                        escapeshellarg($dbHost),
                        escapeshellarg($dbUser),
                        escapeshellarg($dbPass),
                        escapeshellarg($dbName),
                        escapeshellarg($sqlFile)
                    );
                    exec($restoreCmd, $output, $returnCode);
                    
                    if ($returnCode !== 0) {
                        throw new Exception("Database restore failed: " . implode("\n", $output));
                    }
                }
                
                // Restore config files
                $configsDir = "{$extractedDir}/configs";
                if (is_dir($configsDir)) {
                    // Backup current configs first
                    $currentBackup = "/tmp/current_configs_" . time();
                    mkdir($currentBackup, 0755, true);
                    
                    $configFiles = glob("{$configsDir}/*");
                    foreach ($configFiles as $file) {
                        $basename = basename($file);
                        $dest = '/var/www/opnsense/inc/' . $basename;
                        
                        // Backup current
                        if (file_exists($dest)) {
                            copy($dest, "{$currentBackup}/{$basename}");
                        }
                        
                        // Restore from backup
                        copy($file, $dest);
                    }
                }
                
                // Restore SSH keys
                $sshKeysDir = "{$extractedDir}/ssh_keys";
                if (is_dir($sshKeysDir)) {
                    $targetSshDir = '/var/www/opnsense/.ssh';
                    if (!is_dir($targetSshDir)) {
                        mkdir($targetSshDir, 0700, true);
                    }
                    exec("cp -r {$sshKeysDir}/* {$targetSshDir}/ 2>&1");
                    exec("chown -R www-data:www-data {$targetSshDir}");
                    exec("chmod 700 {$targetSshDir}");
                    exec("chmod 600 {$targetSshDir}/*");
                }
                
                // Clean up
                exec("rm -rf {$tempDir}");
                
                // If this was an uploaded backup, optionally delete it from backups dir
                // (keeping it so users can see it in the list for future restores)
                
                $message = "Restore completed successfully! Please verify system functionality.";
                $messageType = 'success';
                
            } catch (Exception $e) {
                error_log("system_backup.php restore error: " . $e->getMessage());
                $message = "Restore failed due to an internal error.";
                $messageType = 'danger';
                
                // Clean up on failure
                if (is_dir($tempDir)) {
                    exec("rm -rf {$tempDir}");
                }
                
                // Delete uploaded file if restore failed
                if (isset($isUploadedBackup) && file_exists($filepath)) {
                    unlink($filepath);
                }
            }
        }
    }
}

// Get list of backups
$backups = [];
if (is_dir($backupDir)) {
    $files = glob("{$backupDir}/opnmanager_backup_*.tar.gz");
    rsort($files); // Newest first
    
    foreach ($files as $file) {
        $backups[] = [
            'filename' => basename($file),
            'size' => filesize($file),
            'date' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }
}

require_once 'inc/header.php';
?>

<div class="card-dark">
    <h2><i class="fa fa-archive me-2"></i> System Backup & Restore</h2>
    <p class="text-muted">
        Create and manage full system backups including database, configuration files, SSH keys, and SSL certificates.
    </p>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
    <?php echo $message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Create Backup Card -->
    <div class="col-md-6">
        <div class="card-dark">
            <h4><i class="fa fa-plus-circle me-2"></i> Create New Backup</h4>
            <p class="text-muted">
                This will create a compressed backup containing:
            </p>
            <ul class="text-muted">
                <li>Complete database export</li>
                <li>Configuration files</li>
                <li>SSH keys for firewall access</li>
                <li>SSL certificates</li>
                <li>System metadata</li>
            </ul>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                <button type="submit" name="create_backup" class="btn btn-success btn-lg w-100">
                    <i class="fa fa-download me-2"></i> Create Backup Now
                </button>
            </form>
            
            <div class="alert alert-info mt-3 mb-0">
                <i class="fa fa-info-circle me-2"></i>
                <strong>Retention Policy:</strong> Last 10 backups are kept automatically. Older backups are deleted.
            </div>
        </div>
    </div>
    
    <!-- Backup Statistics Card -->
    <div class="col-md-6">
        <div class="card-dark">
            <h4><i class="fa fa-chart-bar me-2"></i> Backup Statistics</h4>
            
            <div class="row text-center mt-3">
                <div class="col-6 mb-3">
                    <div class="p-3" style="background: rgba(40,167,69,0.1); border-radius: 8px;">
                        <h2 class="text-success mb-0"><?php echo count($backups); ?></h2>
                        <small class="text-muted">Total Backups</small>
                    </div>
                </div>
                <div class="col-6 mb-3">
                    <div class="p-3" style="background: rgba(23,162,184,0.1); border-radius: 8px;">
                        <h2 class="text-info mb-0">
                            <?php 
                            $totalSize = array_sum(array_column($backups, 'size'));
                            echo round($totalSize / 1024 / 1024, 1) . ' MB'; 
                            ?>
                        </h2>
                        <small class="text-muted">Total Size</small>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($backups)): ?>
            <div class="mt-3">
                <strong>Latest Backup:</strong><br>
                <span class="text-muted"><?php echo htmlspecialchars($backups[0]['date']); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="alert alert-warning mt-3 mb-0">
                <i class="fa fa-exclamation-triangle me-2"></i>
                <strong>Important:</strong> Always download and store backups off-server for disaster recovery.
            </div>
        </div>
    </div>
</div>

<!-- Upload Backup for Restore -->
<div class="card-dark mt-4">
    <h4><i class="fa fa-cloud-upload-alt me-2"></i> Upload Backup to Restore</h4>
    <p class="text-muted">
        Upload a backup file from another OPNManager installation or a previously downloaded backup for disaster recovery.
    </p>
    
    <form method="POST" action="" enctype="multipart/form-data" id="uploadRestoreForm">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="restore_uploaded" value="1">
        
        <div class="row align-items-end">
            <div class="col-md-8">
                <label for="backup_upload" class="form-label">
                    <i class="fa fa-file-archive me-2"></i>Select Backup File
                </label>
                <input 
                    type="file" 
                    class="form-control" 
                    id="backup_upload" 
                    name="backup_upload" 
                    accept=".tar.gz,.tgz"
                    required
                >
                <div class="form-text text-muted">
                    Accepted formats: .tar.gz or .tgz (max 500MB)
                </div>
            </div>
            <div class="col-md-4">
                <button 
                    type="button" 
                    class="btn btn-warning btn-lg w-100" 
                    onclick="confirmUploadRestore()"
                >
                    <i class="fa fa-upload me-2"></i> Upload & Restore
                </button>
            </div>
        </div>
        
        <div class="alert alert-danger mt-3 mb-0">
            <i class="fa fa-exclamation-triangle me-2"></i>
            <strong>Warning:</strong> Restoring will overwrite all current data including database, configuration, and SSH keys. 
            Make sure to create a backup of the current system first!
        </div>
    </form>
</div>

<!-- Existing Backups -->
<div class="card-dark mt-4">
    <h4><i class="fa fa-list me-2"></i> Existing Backups</h4>
    
    <?php if (empty($backups)): ?>
        <div class="alert alert-info">
            <i class="fa fa-info-circle me-2"></i>
            No backups found. Create your first backup above.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-striped">
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Date Created</th>
                        <th>Size</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td>
                            <i class="fa fa-file-archive me-2 text-success"></i>
                            <?php echo htmlspecialchars($backup['filename']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($backup['date']); ?></td>
                        <td><?php echo round($backup['size'] / 1024 / 1024, 2); ?> MB</td>
                        <td>
                            <a href="?download=<?php echo urlencode($backup['filename']); ?>" 
                               class="btn btn-sm btn-primary" 
                               title="Download backup">
                                <i class="fa fa-download"></i> Download
                            </a>
                            
                            <button type="button" 
                                    class="btn btn-sm btn-warning" 
                                    onclick="confirmRestore('<?php echo htmlspecialchars($backup['filename']); ?>')"
                                    title="Restore from this backup">
                                <i class="fa fa-upload"></i> Restore
                            </button>
                            
                            <button type="button" 
                                    class="btn btn-sm btn-danger" 
                                    onclick="confirmDelete('<?php echo htmlspecialchars($backup['filename']); ?>')"
                                    title="Delete backup">
                                <i class="fa fa-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Hidden forms for restore and delete -->
<form id="restoreForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="restore_backup" value="1">
    <input type="hidden" name="backup_file" id="restoreBackupFile">
</form>

<form id="deleteForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="delete_backup" value="1">
    <input type="hidden" name="backup_file" id="deleteBackupFile">
</form>

<script>
function confirmRestore(filename) {
    if (confirm('⚠️ WARNING: This will OVERWRITE the current system with data from the backup!\n\n' +
                'This includes:\n' +
                '- Database (all firewalls, users, settings)\n' +
                '- Configuration files\n' +
                '- SSH keys\n\n' +
                'Are you absolutely sure you want to restore from:\n' + filename + '?')) {
        document.getElementById('restoreBackupFile').value = filename;
        document.getElementById('restoreForm').submit();
    }
}

function confirmUploadRestore() {
    const fileInput = document.getElementById('backup_upload');
    
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Please select a backup file first.');
        return;
    }
    
    const file = fileInput.files[0];
    const filename = file.name;
    const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
    
    // Validate filename pattern
    if (!filename.startsWith('opnmanager_backup_')) {
        alert('Invalid backup file!\n\nFilename must start with "opnmanager_backup_".\n\nSelected: ' + filename);
        return;
    }
    
    // Validate file extension
    if (!filename.endsWith('.tar.gz') && !filename.endsWith('.tgz')) {
        alert('Invalid file type!\n\nOnly .tar.gz or .tgz files are allowed.\n\nSelected: ' + filename);
        return;
    }
    
    // Validate file size (500MB max)
    if (file.size > 500 * 1024 * 1024) {
        alert('File too large!\n\nMaximum size is 500MB.\n\nYour file: ' + fileSizeMB + ' MB');
        return;
    }
    
    if (confirm('⚠️ CRITICAL WARNING: This will COMPLETELY OVERWRITE the current system!\n\n' +
                'The uploaded backup will replace:\n' +
                '- ALL database data (firewalls, users, settings, history)\n' +
                '- ALL configuration files\n' +
                '- ALL SSH keys\n' +
                '- ALL SSL certificates\n\n' +
                'File: ' + filename + ' (' + fileSizeMB + ' MB)\n\n' +
                '⚠️ Make sure you have a backup of the CURRENT system first!\n\n' +
                'Continue with restore?')) {
        
        // Show loading indicator
        const button = event.target;
        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i> Uploading & Restoring...';
        
        // Submit the form
        document.getElementById('uploadRestoreForm').submit();
    }
}

function confirmDelete(filename) {
    if (confirm('Are you sure you want to delete this backup?\n\n' + filename)) {
        document.getElementById('deleteBackupFile').value = filename;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<style>
.card-dark {
    background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.03)) !important;
    color: #ffffff !important;
    border: 1px solid rgba(255,255,255,0.15) !important;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border-radius: 8px;
}

.card-dark h2, .card-dark h3, .card-dark h4, .card-dark h5, .card-dark h6 {
    color: #ffffff !important;
}

.card-dark .text-muted, .card-dark small {
    color: #cbd5e0 !important;
}

.card-dark ul li {
    color: #e2e8f0 !important;
}

.card-dark strong {
    color: #ffffff !important;
}

.card-dark p {
    color: #e2e8f0 !important;
}

.form-label {
    color: #f7fafc !important;
    font-weight: 500 !important;
}

.table-dark {
    color: #fff !important;
}

.table-dark th {
    border-color: rgba(255,255,255,0.1);
}

.table-dark td {
    border-color: rgba(255,255,255,0.05);
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: rgba(255,255,255,0.02);
}
</style>

<?php require_once 'inc/footer.php'; ?>
