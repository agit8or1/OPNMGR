<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
requireLogin();

// Get all versions with their changes
$stmt = $pdo->query("
    SELECT pv.*, 
           (SELECT COUNT(*) FROM bugs WHERE version_fixed = pv.version) as bugs_fixed,
           (SELECT COUNT(*) FROM todos WHERE target_version = pv.version AND status = 'completed') as features_completed
    FROM platform_versions pv 
    ORDER BY pv.created_at DESC
");
$versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get resolved bugs grouped by version
$stmt = $pdo->query("
    SELECT version_fixed, title, description, severity, component, resolved_at 
    FROM bugs 
    WHERE status IN ('resolved', 'closed') AND version_fixed IS NOT NULL 
    ORDER BY resolved_at DESC
");
$resolved_bugs = [];
while ($bug = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $resolved_bugs[$bug['version_fixed']][] = $bug;
}

// Get completed todos grouped by version
$stmt = $pdo->query("
    SELECT target_version, title, description, category, priority, completed_at 
    FROM todos 
    WHERE status = 'completed' AND target_version IS NOT NULL 
    ORDER BY completed_at DESC
");
$completed_todos = [];
while ($todo = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $completed_todos[$todo['target_version']][] = $todo;
}

// Get changelog entries
$stmt = $pdo->query("
    SELECT * FROM change_log 
    ORDER BY created_at DESC
");
$change_entries = [];
while ($entry = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $change_entries[$entry['version']][] = $entry;
}

include __DIR__ . '/inc/header.php';
?>

<?php include __DIR__ . '/inc/navigation.php'; ?>

<div class="container-fluid">
    <div class="row">
        <!-- Administration Sidebar -->
        <div class="col-md-3">
            <?php include __DIR__ . '/inc/sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history me-2"></i>Change Log
                    </h3>
                    <div class="card-tools">
                        <a href="generate_pdf.php?page=changelog" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-file-pdf me-1"></i>Download PDF
                        </a>
                        <span class="badge bg-info"><?php echo count($versions); ?> Releases</span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Generate Now Button -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <button class="btn btn-primary" onclick="generateChangelog()">
                                <i class="fas fa-sync me-1"></i>Generate Latest Changelog
                            </button>
                            <small class="text-muted ms-2">Updates changelog from current bugs and todos</small>
                        </div>
                    </div>

                    <!-- Version Timeline -->
                    <div class="changelog-timeline">
                        <?php foreach ($versions as $version): ?>
                        <div class="version-block">
                            <div class="version-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="version-title">
                                            Version <?php echo htmlspecialchars($version['version']); ?>
                                            <span class="badge bg-<?php echo $version['status'] === 'released' ? 'success' : ($version['status'] === 'development' ? 'warning' : 'info'); ?>">
                                                <?php echo ucfirst($version['status']); ?>
                                            </span>
                                        </h4>
                                        <small class="text-muted">
                                            Released: <?php echo date('M j, Y', strtotime($version['release_date'])); ?>
                                        </small>
                                    </div>
                                    <div class="version-stats">
                                        <?php if ($version['bugs_fixed'] > 0): ?>
                                        <span class="badge bg-danger me-1"><?php echo $version['bugs_fixed']; ?> bugs fixed</span>
                                        <?php endif; ?>
                                        <?php if ($version['features_completed'] > 0): ?>
                                        <span class="badge bg-success"><?php echo $version['features_completed']; ?> features</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="version-content">
                                <?php if ($version['description']): ?>
                                <div class="version-description">
                                    <p><?php echo nl2br(htmlspecialchars($version['description'])); ?></p>
                                </div>
                                <?php endif; ?>

                                <!-- Changelog Entries -->
                                <?php if (isset($change_entries[$version['version']])): ?>
                                <div class="changelog-entries">
                                    <h6><i class="fas fa-list me-1"></i>Changes</h6>
                                    <?php foreach ($change_entries[$version['version']] as $entry): ?>
                                    <div class="changelog-entry">
                                        <span class="badge bg-<?php echo $entry['change_type'] === 'feature' ? 'success' : ($entry['change_type'] === 'bugfix' ? 'danger' : ($entry['change_type'] === 'improvement' ? 'info' : 'secondary')); ?> me-2">
                                            <?php echo ucfirst($entry['change_type']); ?>
                                        </span>
                                        <strong><?php echo htmlspecialchars($entry['title']); ?></strong>
                                        <?php if ($entry['component']): ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($entry['component']); ?>)</small>
                                        <?php endif; ?>
                                        <?php if ($entry['description']): ?>
                                        <div class="ms-4 mt-1">
                                            <small class="text-muted"><?php echo htmlspecialchars($entry['description']); ?></small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <!-- Fixed Bugs -->
                                <?php if (isset($resolved_bugs[$version['version']])): ?>
                                <div class="fixed-bugs mt-3">
                                    <h6><i class="fas fa-bug me-1 text-danger"></i>Bugs Fixed</h6>
                                    <?php foreach ($resolved_bugs[$version['version']] as $bug): ?>
                                    <div class="bug-entry">
                                        <span class="badge bg-<?php echo $bug['severity'] === 'critical' ? 'danger' : ($bug['severity'] === 'high' ? 'warning' : 'secondary'); ?> me-2">
                                            <?php echo ucfirst($bug['severity']); ?>
                                        </span>
                                        <strong><?php echo htmlspecialchars($bug['title']); ?></strong>
                                        <small class="text-muted">(<?php echo htmlspecialchars($bug['component']); ?>)</small>
                                        <?php if ($bug['description']): ?>
                                        <div class="ms-4 mt-1">
                                            <small class="text-muted"><?php echo htmlspecialchars(substr($bug['description'], 0, 200)); ?>...</small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <!-- Completed Features -->
                                <?php if (isset($completed_todos[$version['version']])): ?>
                                <div class="completed-features mt-3">
                                    <h6><i class="fas fa-star me-1 text-success"></i>New Features</h6>
                                    <?php foreach ($completed_todos[$version['version']] as $todo): ?>
                                    <div class="feature-entry">
                                        <span class="badge bg-<?php echo $todo['priority'] === 'urgent' ? 'danger' : ($todo['priority'] === 'high' ? 'warning' : 'secondary'); ?> me-2">
                                            <?php echo ucfirst($todo['category']); ?>
                                        </span>
                                        <strong><?php echo htmlspecialchars($todo['title']); ?></strong>
                                        <?php if ($todo['description']): ?>
                                        <div class="ms-4 mt-1">
                                            <small class="text-muted"><?php echo htmlspecialchars(substr($todo['description'], 0, 200)); ?>...</small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- No versions message -->
                    <?php if (empty($versions)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-history fa-3x mb-3"></i>
                        <h5>No Version History</h5>
                        <p>Version history will appear here as releases are created.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.changelog-timeline {
    position: relative;
}

.version-block {
    margin-bottom: 2rem;
    border-left: 4px solid #007bff;
    padding-left: 1rem;
    position: relative;
}

.version-block:before {
    content: '';
    position: absolute;
    left: -8px;
    top: 10px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #007bff;
    border: 2px solid #343a40;
}

.version-header {
    margin-bottom: 1rem;
}

.version-title {
    color: #fff;
    margin-bottom: 0.25rem;
}

.version-content {
    background: rgba(255, 255, 255, 0.05);
    padding: 1rem;
    border-radius: 0.375rem;
    margin-bottom: 1rem;
}

.changelog-entry, .bug-entry, .feature-entry {
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.changelog-entry:last-child, .bug-entry:last-child, .feature-entry:last-child {
    border-bottom: none;
}

.version-description {
    margin-bottom: 1rem;
    color: #adb5bd;
}

.version-stats .badge {
    font-size: 0.75rem;
}
</style>

<script>
function generateChangelog() {
    if (confirm('This will automatically update the changelog with recent bug fixes and completed features. Continue?')) {
        // Show loading state
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
        btn.disabled = true;
        
        fetch('/api/generate_changelog.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Changelog updated successfully!');
                location.reload();
            } else {
                alert('Error updating changelog: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error updating changelog: ' + error.message);
        })
        .finally(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
    }
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>