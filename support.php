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
$github_token = env('GITHUB_PAT', '');

// Handle issue creation
if (isset($_POST['create_issue'])) {
    $title = trim($_POST['issue_title'] ?? '');
    $description = trim($_POST['issue_description'] ?? '');
    $category = $_POST['issue_category'] ?? 'bug';

    if (empty($title) || empty($description)) {
        $message = 'Title and description are required.';
        $message_type = 'danger';
    } else {
        // Add labels based on category
        $labels = [$category];

        // Build issue body
        $issue_body = $description . "\n\n";
        $issue_body .= "---\n";
        $issue_body .= "**Environment:**\n";
        $issue_body .= "- Version: " . (file_exists(__DIR__ . '/VERSION') ? trim(file_get_contents(__DIR__ . '/VERSION')) : 'Unknown') . "\n";
        $issue_body .= "- PHP Version: " . phpversion() . "\n";
        $issue_body .= "- OS: " . php_uname('s') . " " . php_uname('r') . "\n";
        $issue_body .= "- Reported by: " . ($_SESSION['username'] ?? 'Unknown') . "\n";

        // Create GitHub issue via API
        $api_url = "https://api.github.com/repos/{$github_username}/{$github_repo}/issues";

        $data = [
            'title' => $title,
            'body' => $issue_body,
            'labels' => $labels
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_USERAGENT, 'OPNsense-Manager');

        $headers = [
            "Accept: application/vnd.github.v3+json",
            "Content-Type: application/json"
        ];

        if (!empty($github_token)) {
            $headers[] = "Authorization: token {$github_token}";
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 201) {
            $issue_data = json_decode($response, true);
            $issue_url = $issue_data['html_url'];
            $message = "Issue created successfully! <a href='{$issue_url}' target='_blank'>View issue #{$issue_data['number']}</a>";
            $message_type = 'success';

            // Clear form
            $_POST = [];
        } else {
            $error_data = json_decode($response, true);
            $error_message = $error_data['message'] ?? 'Unknown error';
            $message = "Failed to create issue: {$error_message} (HTTP {$http_code})";
            $message_type = 'danger';
        }
    }
}

// Get recent issues
$recent_issues = [];
if (!empty($github_token)) {
    $api_url = "https://api.github.com/repos/{$github_username}/{$github_repo}/issues?state=all&per_page=10";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'OPNsense-Manager');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: token {$github_token}",
        "Accept: application/vnd.github.v3+json"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $recent_issues = json_decode($response, true);
    }
}

include __DIR__ . '/inc/header.php';
?>

<style>
.support-card {
    background: #ffffff;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1rem;
}

.issue-item {
    background: #f8fafc;
    border-left: 4px solid #3b82f6;
    padding: 1rem;
    margin-bottom: 0.5rem;
    border-radius: 4px;
    transition: all 0.2s;
}

.issue-item:hover {
    background: #e0f2fe;
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
    background: #ffffff;
    border: 2px solid #cbd5e1;
    color: #1e293b;
}

.form-control-custom:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
}

.help-section {
    background: #f0f9ff;
    border: 2px solid #3b82f6;
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
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

    <?php if (empty($github_token)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>GitHub Token Not Configured:</strong> Creating issues requires a GitHub Personal Access Token with 'repo' scope.
            Configure <code>GITHUB_PAT</code> in your .env file. You can still browse issues on GitHub manually.
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Create Issue Form -->
        <div class="col-lg-6 mb-4">
            <div class="support-card">
                <h5 class="text-dark mb-3"><i class="fas fa-plus-circle me-2"></i>Create Support Issue</h5>

                <?php if (!empty($github_token)): ?>
                    <form method="post">
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

                        <button type="submit" name="create_issue" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i>Submit Issue
                        </button>
                    </form>
                <?php else: ?>
                    <div class="help-section">
                        <h6 class="text-primary mb-2"><i class="fas fa-info-circle me-2"></i>Manual Issue Creation</h6>
                        <p class="mb-2">To create an issue without a token configured:</p>
                        <ol class="mb-2">
                            <li>Visit the <a href="https://github.com/<?php echo htmlspecialchars($github_username . '/' . $github_repo); ?>/issues/new" target="_blank">GitHub Issues page</a></li>
                            <li>Sign in to your GitHub account</li>
                            <li>Click "New Issue"</li>
                            <li>Fill in the details and submit</li>
                        </ol>
                        <a href="https://github.com/<?php echo htmlspecialchars($github_username . '/' . $github_repo); ?>/issues/new" target="_blank" class="btn btn-primary btn-sm">
                            <i class="fas fa-external-link-alt me-2"></i>Open GitHub Issues
                        </a>
                    </div>
                <?php endif; ?>
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

    <?php if (!empty($recent_issues)): ?>
        <div class="row">
            <div class="col-12">
                <div class="support-card">
                    <h5 class="text-dark mb-3"><i class="fas fa-list me-2"></i>Recent Issues</h5>
                    <?php foreach ($recent_issues as $issue): ?>
                        <?php if (isset($issue['pull_request'])) continue; // Skip pull requests ?>
                        <div class="issue-item <?php echo $issue['state'] === 'closed' ? 'closed' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="issue-number">#<?php echo $issue['number']; ?></span>
                                    <span class="badge bg-<?php echo $issue['state'] === 'open' ? 'success' : 'secondary'; ?> ms-2">
                                        <?php echo ucfirst($issue['state']); ?>
                                    </span>
                                    <?php foreach ($issue['labels'] as $label): ?>
                                        <span class="badge ms-1" style="background-color: #<?php echo $label['color']; ?>">
                                            <?php echo htmlspecialchars($label['name']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">
                                    <?php
                                    $date = new DateTime($issue['created_at']);
                                    echo $date->format('M d, Y');
                                    ?>
                                </small>
                            </div>
                            <a href="<?php echo htmlspecialchars($issue['html_url']); ?>" target="_blank" class="text-decoration-none">
                                <h6 class="text-dark mb-0"><?php echo htmlspecialchars($issue['title']); ?></h6>
                            </a>
                            <small class="text-muted">
                                by <?php echo htmlspecialchars($issue['user']['login']); ?>
                                <?php if ($issue['comments'] > 0): ?>
                                    • <i class="fas fa-comment"></i> <?php echo $issue['comments']; ?> comments
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
