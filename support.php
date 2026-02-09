<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/env.php';

$page_title = "Support & Help";

$message = '';
$message_type = '';

// GitHub configuration
$github_repo = env('GITHUB_REPO', 'OPNMGR');
$github_username = env('GITHUB_USERNAME', 'agit8or1');
// REMOVED: GitHub PAT - users should use their own GitHub accounts

// REMOVED: All PAT-based GitHub integration
// Users should create issues directly on GitHub with their own accounts

include __DIR__ . '/inc/header.php';
?>

<style>
.support-card {
    background: #1e293b;
    border: 2px solid #334155;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1rem;
}

.issue-item {
    background: #0f172a;
    border-left: 4px solid #3b82f6;
    padding: 1rem;
    margin-bottom: 0.5rem;
    border-radius: 4px;
    transition: all 0.2s;
}

.issue-item:hover {
    background: #334155;
    transform: translateX(5px);
}

.issue-item.closed {
    border-left-color: #8b5cf6;
    opacity: 0.8;
}

.issue-number {
    font-family: 'Courier New', monospace;
    background: #1e293b;
    color: #e2e8f0;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    font-size: 0.85rem;
}

.category-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
}

.form-control-custom {
    background: #0f172a;
    border: 2px solid #334155;
    color: #e2e8f0;
}

.form-control-custom:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
    background: #1e293b;
}

.help-section {
    background: #1e293b;
    border: 2px solid #3b82f6;
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.support-card .card-header {
    background: #0f172a;
    color: #e2e8f0;
    border-bottom: 2px solid #334155;
}

.support-card .card-header h5,
.support-card .card-header h6 {
    color: #e2e8f0;
}

.support-card .card-body {
    color: #cbd5e1;
}

.support-card h6 {
    color: #e2e8f0;
}

.support-card p,
.support-card li {
    color: #cbd5e1;
}

.form-label {
    color: #e2e8f0;
}

.form-label strong {
    color: #f1f5f9;
}

.text-dark, h2.text-dark, h5.text-dark, h6.text-dark {
    color: #e2e8f0 !important;
}

.text-muted, small.text-muted {
    color: #94a3b8 !important;
}

.text-primary {
    color: #60a5fa !important;
}

.help-section h6 {
    color: #60a5fa;
}

.help-section p {
    color: #cbd5e1;
}

.help-section ol,
.help-section li {
    color: #cbd5e1;
}

.issue-item h6 {
    color: #e2e8f0;
}

.issue-item a {
    text-decoration: none;
}

.issue-item a:hover h6 {
    color: #60a5fa;
}
</style>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="text-dark"><i class="fas fa-life-ring me-2 text-primary"></i>Support & Help</h2>
            <p class="text-muted">Get help or report issues with OPNsense Manager</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Create Issues on GitHub:</strong> Use your own GitHub account to create and track issues. This ensures your issues are directly associated with your account and you'll receive notifications.
    </div>

    <div class="row">
        <!-- Create Issue on GitHub -->
        <div class="col-lg-6 mb-4">
            <div class="support-card">
                <h5 class="text-dark mb-3"><i class="fas fa-plus-circle me-2"></i>Create Support Issue</h5>

                <div class="help-section">
                    <h6 class="text-primary mb-2"><i class="fas fa-info-circle me-2"></i>Create an Issue on GitHub</h6>
                    <p class="mb-3">Report bugs, request features, or ask questions using your GitHub account:</p>
                    <ol class="mb-3">
                        <li>Click the button below to open GitHub Issues</li>
                        <li>Sign in to your GitHub account (if not already signed in)</li>
                        <li>Click "New Issue" and select a template</li>
                        <li>Fill in the details and submit</li>
                    </ol>

                    <div class="alert alert-secondary small mb-3">
                        <strong>System Information (include in your issue):</strong><br>
                        Version: <?php echo htmlspecialchars(file_exists(__DIR__ . '/VERSION') ? trim(file_get_contents(__DIR__ . '/VERSION')) : 'Unknown'); ?><br>
                        PHP: <?php echo phpversion(); ?><br>
                        OS: <?php echo php_uname('s') . ' ' . php_uname('r'); ?>
                    </div>

                    <a href="https://github.com/<?php echo htmlspecialchars($github_username . '/' . $github_repo); ?>/issues/new/choose" target="_blank" class="btn btn-primary w-100">
                        <i class="fas fa-external-link-alt me-2"></i>Open GitHub Issues
                    </a>
                </div>
            </div>
        </div>

        <!-- Browse Issues (no longer needs PAT) -->
        <div class="col-lg-6 mb-4" style="display: none;">
            <div class="support-card">
                <h5 class="text-dark mb-3"><i class="fas fa-plus-circle me-2"></i>REMOVED - No longer shown</h5>

                <form method="post" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label text-dark"><strong>Category</strong></label>
                            <select name="issue_category" class="form-select form-control-custom" required>
                                <option value="bug" <?php echo ($_POST['issue_category'] ?? '') === 'bug' ? 'selected' : ''; ?>>
                                    🐛 Bug Report
                                </option>
                                <option value="feature" <?php echo ($_POST['issue_category'] ?? '') === 'feature' ? 'selected' : ''; ?>>
                                    ✨ Feature Request
                                </option>
                                <option value="question" <?php echo ($_POST['issue_category'] ?? '') === 'question' ? 'selected' : ''; ?>>
                                    ❓ Question
                                </option>
                                <option value="documentation" <?php echo ($_POST['issue_category'] ?? '') === 'documentation' ? 'selected' : ''; ?>>
                                    📚 Documentation
                                </option>
                                <option value="enhancement" <?php echo ($_POST['issue_category'] ?? '') === 'enhancement' ? 'selected' : ''; ?>>
                                    🚀 Enhancement
                                </option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-dark"><strong>Title</strong></label>
                            <input type="text" name="issue_title" class="form-control form-control-custom"
                                   placeholder="Brief description of the issue"
                                   value="<?php echo htmlspecialchars($_POST['issue_title'] ?? ''); ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-dark"><strong>Description</strong></label>
                            <textarea name="issue_description" class="form-control form-control-custom" rows="8"
                                      placeholder="Detailed description of the issue. Include steps to reproduce if reporting a bug."
                                      required><?php echo htmlspecialchars($_POST['issue_description'] ?? ''); ?></textarea>
                            <small class="text-muted">System information will be automatically added to your issue.</small>
                        </div>

                    </form>
            </div>
        </div>

        <!-- Help & Resources -->
        <div class="col-lg-6 mb-4">
            <div class="support-card">
                <h5 class="text-dark mb-3"><i class="fas fa-book me-2"></i>Help & Resources</h5>

                <div class="mb-3">
                    <h6 class="text-dark"><i class="fas fa-file-alt me-2 text-primary"></i>Documentation</h6>
                    <ul>
                        <li><a href="README_INSTALL.md" target="_blank">Installation Guide</a></li>
                        <li><a href="SECURITY.md" target="_blank">Security Policy</a></li>
                        <li><a href="https://github.com/<?php echo htmlspecialchars($github_username . '/' . $github_repo); ?>" target="_blank">GitHub Repository</a></li>
                    </ul>
                </div>

                <div class="mb-3">
                    <h6 class="text-dark"><i class="fas fa-comments me-2 text-success"></i>Community</h6>
                    <ul>
                        <li><a href="https://github.com/<?php echo htmlspecialchars($github_username . '/' . $github_repo); ?>/discussions" target="_blank">GitHub Discussions</a></li>
                        <li><a href="https://github.com/<?php echo htmlspecialchars($github_username . '/' . $github_repo); ?>/issues" target="_blank">Browse Issues</a></li>
                    </ul>
                </div>

                <div class="mb-3">
                    <h6 class="text-dark"><i class="fas fa-tools me-2 text-warning"></i>Quick Links</h6>
                    <div class="d-grid gap-2">
                        <a href="system_update.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-sync-alt me-2"></i>Check for Updates
                        </a>
                        <a href="security_scan.php" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-shield-alt me-2"></i>Security Scanner
                        </a>
                        <a href="documentation.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-book me-2"></i>View Documentation
                        </a>
                    </div>
                </div>

                <div class="help-section">
                    <h6 class="text-primary mb-2"><i class="fas fa-info-circle me-2"></i>System Information</h6>
                    <dl class="row mb-0 small">
                        <dt class="col-sm-5">Version:</dt>
                        <dd class="col-sm-7">
                            <?php echo htmlspecialchars(file_exists(__DIR__ . '/VERSION') ? trim(file_get_contents(__DIR__ . '/VERSION')) : 'Unknown'); ?>
                        </dd>

                        <dt class="col-sm-5">PHP Version:</dt>
                        <dd class="col-sm-7"><?php echo phpversion(); ?></dd>

                        <dt class="col-sm-5">Operating System:</dt>
                        <dd class="col-sm-7"><?php echo php_uname('s') . ' ' . php_uname('r'); ?></dd>

                        <dt class="col-sm-5">Logged in as:</dt>
                        <dd class="col-sm-7"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Issues removed - users can view directly on GitHub -->
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
