<?php
// Development > Update Documentation Trigger
require_once __DIR__ . '/inc/bootstrap.php';
require_once 'inc/header.php';

// Only admins can access
if (!isAdmin()) {
    header('Location: /dashboard.php');
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $version = trim($_POST['version'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($version)) {
        $message = 'Version number is required';
        $messageType = 'danger';
    } elseif (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        $message = 'Version must be in format X.Y.Z (e.g., 2.2.3)';
        $messageType = 'danger';
    } elseif (empty($description)) {
        $message = 'Description is required';
        $messageType = 'danger';
    } else {
        // Execute the update script
        $scriptPath = '/var/www/opnsense/scripts/update_release_docs.php';
        $command = sprintf(
            'php %s %s %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($version),
            escapeshellarg($description)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            $message = 'Documentation updated successfully!<br><pre class="mt-2 p-2" style="background: rgba(0,0,0,0.2); border-radius: 4px;">' . 
                      htmlspecialchars(implode("\n", $output)) . '</pre>';
            $messageType = 'success';
        } else {
            $message = 'Error updating documentation:<br><pre class="mt-2 p-2" style="background: rgba(0,0,0,0.2); border-radius: 4px;">' . 
                      htmlspecialchars(implode("\n", $output)) . '</pre>';
            $messageType = 'danger';
        }
    }
}

// Get current version (check if already loaded by header)
if (!defined('APP_VERSION')) {
}
$currentVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';

// Calculate next version suggestions
$parts = explode('.', $currentVersion);
$nextPatch = ($parts[0] ?? 0) . '.' . ($parts[1] ?? 0) . '.' . (($parts[2] ?? 0) + 1);
$nextMinor = ($parts[0] ?? 0) . '.' . (($parts[1] ?? 0) + 1) . '.0';
$nextMajor = (($parts[0] ?? 0) + 1) . '.0.0';

// Analyze recent changes for smart suggestions
$smartSuggestions = [];
$smartDescription = 'Bug fixes and improvements';
$analysisScript = __DIR__ . '/scripts/analyze_changes.php';
if (file_exists($analysisScript)) {
    $output = shell_exec("php {$analysisScript} 2>&1");
    $analysis = json_decode($output, true);
    if ($analysis && isset($analysis['suggestions'])) {
        $smartSuggestions = $analysis['suggestions'];
        $smartDescription = $analysis['description'];
    }
}
?>

<style>
/* Override for better contrast */
.card-dark {
    background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.03)) !important;
    color: #ffffff !important;
    border: 1px solid rgba(255,255,255,0.15) !important;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border-radius: 8px;
}

.card-dark h2, .card-dark h4, .card-dark h6 {
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

.form-label {
    color: #f7fafc !important;
    font-weight: 500 !important;
}

.text-danger {
    color: #fc8181 !important;
}

.text-success {
    color: #68d391 !important;
}

.text-info {
    color: #63b3ed !important;
}

.text-warning {
    color: #fbd38d !important;
}

.card-body {
    color: #e2e8f0 !important;
}

.card-title {
    color: #ffffff !important;
}
</style>

<div class="card-dark">
    <h2><i class="fa fa-sync-alt me-2"></i> Update Documentation & Version</h2>
    <p class="text-muted">
        Automatically update version numbers, changelog, security assessment, and release notes.
    </p>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
    <?php echo $message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card-dark">
    <div class="row">
        <div class="col-md-8">
            <h4 class="mb-3">Release Information</h4>
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label fw-bold" style="color: #e8f0f8;">Current Version</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($currentVersion); ?>" readonly style="background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.2);">
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold" style="color: #e8f0f8;">New Version <span class="text-danger">*</span></label>
                    <input type="text" name="version" class="form-control" placeholder="e.g., <?php echo $nextPatch; ?>" value="<?php echo $nextPatch; ?>" required style="background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.2);">
                    <small style="color: #a8c0d8;">
                        Quick suggestions: 
                        <button type="button" class="btn btn-sm btn-outline-light ms-2" onclick="document.querySelector('input[name=version]').value='<?php echo $nextPatch; ?>'">
                            <?php echo $nextPatch; ?> (patch)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-light" onclick="document.querySelector('input[name=version]').value='<?php echo $nextMinor; ?>'">
                            <?php echo $nextMinor; ?> (minor)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-light" onclick="document.querySelector('input[name=version]').value='<?php echo $nextMajor; ?>'">
                            <?php echo $nextMajor; ?> (major)
                        </button>
                    </small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold" style="color: #e8f0f8;">Release Description <span class="text-danger">*</span></label>
                    <textarea name="description" class="form-control" rows="4" placeholder="Example: Fixed backup system database export, improved contrast on all forms, enhanced documentation system..." required style="background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.2);"><?php echo htmlspecialchars($smartDescription); ?></textarea>
                    <small style="color: #a8c0d8;">This will be added to the changelog, release notes, and user documentation.</small>
                </div>
                
                <?php if (!empty($smartSuggestions)): ?>
                <div class="mb-3">
                    <label class="form-label fw-bold" style="color: #e8f0f8;"><i class="fas fa-lightbulb me-2"></i>Smart Suggestions (Click to add)</label>
                    <div class="alert" style="background: rgba(52, 152, 219, 0.1); border: 1px solid rgba(52, 152, 219, 0.3); color: #5dade2; max-height: 300px; overflow-y: auto;">
                        <small class="d-block mb-2" style="color: #a8c0d8;">Based on recent commits and file changes:</small>
                        <?php foreach (array_slice($smartSuggestions, 0, 15) as $suggestion): ?>
                            <button type="button" class="btn btn-sm btn-outline-info mb-1 me-1" onclick="addSuggestion(this)" data-suggestion="<?php echo htmlspecialchars($suggestion); ?>" style="font-size: 0.85rem;">
                                <i class="fas fa-plus-circle me-1"></i><?php echo htmlspecialchars($suggestion); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-play me-2"></i> Update Documentation
                </button>
                <a href="/dev_features.php" class="btn btn-secondary">
                    <i class="fa fa-arrow-left me-2"></i> Back to Features
                </a>
            </form>
        </div>
        
        <div class="col-md-4">
            <h4 class="mb-3">What This Does</h4>
            <div class="card" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);">
                <div class="card-body">
                    <h6 class="card-title"><i class="fa fa-check-circle text-success me-2"></i> Updates:</h6>
                    <ul class="small text-muted mb-0">
                        <li><strong>inc/version.php</strong> - VERSION constant</li>
                        <li><strong>CHANGELOG.md</strong> - New entry with date</li>
                        <li><strong>SECURITY_ASSESSMENT.md</strong> - Complete threat/vulnerability analysis</li>
                        <li><strong>RELEASE_NOTES_vX.X.X.md</strong> - Version-specific release notes</li>
                    </ul>
                    
                    <hr style="border-color: rgba(255,255,255,0.1);">
                    
                    <h6 class="card-title"><i class="fa fa-shield-alt text-info me-2"></i> Security Assessment Includes:</h6>
                    <ul class="small text-muted mb-0">
                        <li>10 Security Domains analyzed</li>
                        <li>Risk Matrix (severity/likelihood)</li>
                        <li>Penetration testing recommendations</li>
                        <li>Compliance mapping (PCI, HIPAA, SOC2)</li>
                        <li>Incident response procedures</li>
                        <li>Version history tracking</li>
                    </ul>
                    
                    <hr style="border-color: rgba(255,255,255,0.1);">
                    
                    <h6 class="card-title"><i class="fa fa-info-circle text-warning me-2"></i> Version Format:</h6>
                    <ul class="small text-muted mb-0">
                        <li><strong>Patch</strong> (X.X.Y) - Bug fixes, minor improvements</li>
                        <li><strong>Minor</strong> (X.Y.0) - New features, backwards compatible</li>
                        <li><strong>Major</strong> (X.0.0) - Breaking changes, major overhaul</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card-dark">
    <h4 class="mb-3"><i class="fa fa-terminal me-2"></i> Manual Execution</h4>
    <p class="text-muted">You can also run the script manually from the command line:</p>
    <pre class="p-3" style="background: rgba(0,0,0,0.3); border-radius: 4px; color: #8ab4f8;">php /var/www/opnsense/scripts/update_release_docs.php &lt;version&gt; "&lt;description&gt;"

Example:
php /var/www/opnsense/scripts/update_release_docs.php 2.2.4 "Fixed tunnel session persistence issues"</pre>
</div>

<script>
function addSuggestion(button) {
    const suggestion = button.getAttribute('data-suggestion');
    const textarea = document.querySelector('textarea[name="description"]');
    
    // Get current value
    let currentValue = textarea.value.trim();
    
    // If it's the default text, replace it
    if (currentValue === 'Bug fixes and improvements' || currentValue === '<?php echo addslashes($smartDescription); ?>') {
        textarea.value = suggestion;
    } else {
        // Check if suggestion already exists
        if (!currentValue.includes(suggestion)) {
            // Add as new line
            textarea.value = currentValue + '\n' + suggestion;
        }
    }
    
    // Visual feedback
    button.classList.remove('btn-outline-info');
    button.classList.add('btn-success');
    button.innerHTML = '<i class="fas fa-check-circle me-1"></i>' + suggestion;
    
    setTimeout(() => {
        button.classList.remove('btn-success');
        button.classList.add('btn-outline-info');
        button.innerHTML = '<i class="fas fa-plus-circle me-1"></i>' + suggestion;
    }, 1500);
    
    // Auto-resize textarea
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
}

// Auto-resize textarea on input
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.querySelector('textarea[name="description"]');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    }
});
</script>

<?php require_once 'inc/footer.php'; ?>
