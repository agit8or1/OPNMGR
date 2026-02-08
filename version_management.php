<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/doc_auto_update.php';
requireLogin();
requireAdmin();

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_bug':
            $stmt = $pdo->prepare("INSERT INTO bugs (title, description, severity, component, reported_by, version_found) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['severity'],
                $_POST['component'],
                $_SESSION['username'],
                $_POST['version_found']
            ]);
            $success = "Bug report created successfully";
            
            // Auto-update documentation
            log_documentation_update("Bug Report Created", "New bug report: " . $_POST['title']);
            break;
            
        case 'add_todo':
            $stmt = $pdo->prepare("INSERT INTO todos (title, description, category, priority, component, target_version) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['category'],
                $_POST['priority'],
                $_POST['component'],
                $_POST['target_version']
            ]);
            $success = "Todo item created successfully";
            
            // Auto-update documentation
            log_documentation_update("Feature Request Created", "New feature request: " . $_POST['title']);
            break;
            
        case 'update_bug_status':
            $stmt = $pdo->prepare("UPDATE bugs SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$_POST['status'], $_POST['bug_id']]);
            if ($_POST['status'] === 'resolved' || $_POST['status'] === 'closed') {
                $stmt = $pdo->prepare("UPDATE bugs SET resolved_at = NOW() WHERE id = ?");
                $stmt->execute([$_POST['bug_id']]);
                
                // Auto-update documentation for resolved bugs
                on_version_management_change('bug_resolved', 'bug', $_POST['bug_id']);
            }
            $success = "Bug status updated";
            break;
            
        case 'update_todo_status':
            $stmt = $pdo->prepare("UPDATE todos SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$_POST['status'], $_POST['todo_id']]);
            if ($_POST['status'] === 'completed') {
                $stmt = $pdo->prepare("UPDATE todos SET completed_at = NOW() WHERE id = ?");
                $stmt->execute([$_POST['todo_id']]);
                
                // Auto-update documentation for completed features
                on_version_management_change('todo_completed', 'todo', $_POST['todo_id']);
            }
            $success = "Todo status updated";
            break;
    }
}

// Get current version
$stmt = $pdo->query("SELECT version FROM platform_versions WHERE status = 'released' ORDER BY created_at DESC LIMIT 1");
$current_version = $stmt->fetchColumn() ?: '1.0.0';

// Get bugs
$bug_filter = $_GET['bug_status'] ?? 'open';
$bug_query = "SELECT * FROM bugs WHERE 1=1";
$bug_params = [];

if ($bug_filter !== 'all') {
    $bug_query .= " AND status = ?";
    $bug_params[] = $bug_filter;
}
$bug_query .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($bug_query);
$stmt->execute($bug_params);
$bugs = $stmt->fetchAll();

// Get todos
$todo_filter = $_GET['todo_status'] ?? 'backlog';
$todo_query = "SELECT * FROM todos WHERE 1=1";
$todo_params = [];

if ($todo_filter !== 'all') {
    $todo_query .= " AND status = ?";
    $todo_params[] = $todo_filter;
}
$todo_query .= " ORDER BY priority DESC, created_at DESC";

$stmt = $pdo->prepare($todo_query);
$stmt->execute($todo_params);
$todos = $stmt->fetchAll();

// Get version history
$stmt = $pdo->query("SELECT * FROM platform_versions ORDER BY created_at DESC");
$versions = $stmt->fetchAll();

include __DIR__ . '/inc/header.php';
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
    <div class="container-fluid">
        <a class="navbar-brand" href="firewalls.php">
            <i class="fas fa-shield-alt me-2"></i>OPNsense Manager
        </a>
        <div class="navbar-nav">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'firewalls.php' ? 'active' : ''; ?>" href="firewalls.php">
                <i class="fas fa-network-wired me-1"></i>Firewalls
            </a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'version_management.php' ? 'active' : ''; ?>" href="version_management.php">
                <i class="fas fa-code-branch me-1"></i>Version Management
            </a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'about.php' ? 'active' : ''; ?>" href="about.php">
                <i class="fas fa-info-circle me-1"></i>About
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-code-branch me-2"></i>Version Management
                    </h3>
                    <div class="card-tools">
                        <span class="badge bg-primary">Current Version: <?php echo htmlspecialchars($current_version); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Stats Row -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-danger">
                <div class="card-body text-center">
                    <h3><?php echo count(array_filter($bugs, fn($b) => $b['status'] === 'open')); ?></h3>
                    <p>Open Bugs</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning">
                <div class="card-body text-center">
                    <h3><?php echo count(array_filter($bugs, fn($b) => $b['status'] === 'in-progress')); ?></h3>
                    <p>In Progress</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info">
                <div class="card-body text-center">
                    <h3><?php echo count(array_filter($todos, fn($t) => $t['status'] === 'backlog')); ?></h3>
                    <p>Backlog Items</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success">
                <div class="card-body text-center">
                    <h3><?php echo count(array_filter($todos, fn($t) => $t['status'] === 'completed')); ?></h3>
                    <p>Completed</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs" id="versionTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="bugs-tab" data-bs-toggle="tab" data-bs-target="#bugs" type="button">
                <i class="fas fa-bug me-1"></i>Bug Reports
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="todos-tab" data-bs-toggle="tab" data-bs-target="#todos" type="button">
                <i class="fas fa-tasks me-1"></i>Todo List
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="versions-tab" data-bs-toggle="tab" data-bs-target="#versions" type="button">
                <i class="fas fa-history me-1"></i>Version History
            </button>
        </li>
    </ul>

    <div class="tab-content" id="versionTabsContent">
        <!-- Bug Reports Tab -->
        <div class="tab-pane fade show active" id="bugs" role="tabpanel">
            <div class="card card-dark">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Bug Reports</h5>
                        <div class="d-flex gap-2">
                            <select class="form-select form-select-sm" onchange="window.location.href='?bug_status=' + this.value">
                                <option value="all" <?php echo ($bug_filter === 'all') ? 'selected' : ''; ?>>All</option>
                                <option value="open" <?php echo ($bug_filter === 'open') ? 'selected' : ''; ?>>Open</option>
                                <option value="in-progress" <?php echo ($bug_filter === 'in-progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="resolved" <?php echo ($bug_filter === 'resolved') ? 'selected' : ''; ?>>Resolved</option>
                            </select>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBugModal">
                                <i class="fas fa-plus me-1"></i>Report Bug
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Severity</th>
                                    <th>Component</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bugs as $bug): ?>
                                <tr>
                                    <td><?php echo $bug['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bug['title']); ?></strong>
                                        <?php if ($bug['description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($bug['description'], 0, 100)); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $bug['severity'] === 'critical' ? 'danger' : ($bug['severity'] === 'high' ? 'warning' : ($bug['severity'] === 'medium' ? 'info' : 'secondary')); ?>">
                                            <?php echo ucfirst($bug['severity']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($bug['component']); ?></td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="update_bug_status">
                                            <input type="hidden" name="bug_id" value="<?php echo $bug['id']; ?>">
                                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <option value="open" <?php echo ($bug['status'] === 'open') ? 'selected' : ''; ?>>Open</option>
                                                <option value="in-progress" <?php echo ($bug['status'] === 'in-progress') ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="testing" <?php echo ($bug['status'] === 'testing') ? 'selected' : ''; ?>>Testing</option>
                                                <option value="resolved" <?php echo ($bug['status'] === 'resolved') ? 'selected' : ''; ?>>Resolved</option>
                                                <option value="closed" <?php echo ($bug['status'] === 'closed') ? 'selected' : ''; ?>>Closed</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($bug['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-info" onclick="viewBug(<?php echo $bug['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Todo List Tab -->
        <div class="tab-pane fade" id="todos" role="tabpanel">
            <div class="card card-dark">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Todo List</h5>
                        <div class="d-flex gap-2">
                            <select class="form-select form-select-sm" onchange="window.location.href='?todo_status=' + this.value">
                                <option value="all" <?php echo ($todo_filter === 'all') ? 'selected' : ''; ?>>All</option>
                                <option value="backlog" <?php echo ($todo_filter === 'backlog') ? 'selected' : ''; ?>>Backlog</option>
                                <option value="planned" <?php echo ($todo_filter === 'planned') ? 'selected' : ''; ?>>Planned</option>
                                <option value="in-progress" <?php echo ($todo_filter === 'in-progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo ($todo_filter === 'completed') ? 'selected' : ''; ?>>Completed</option>
                            </select>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTodoModal">
                                <i class="fas fa-plus me-1"></i>Add Todo
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Priority</th>
                                    <th>Component</th>
                                    <th>Status</th>
                                    <th>Target Version</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todos as $todo): ?>
                                <tr>
                                    <td><?php echo $todo['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($todo['title']); ?></strong>
                                        <?php if ($todo['description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($todo['description'], 0, 100)); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo ucfirst($todo['category']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $todo['priority'] === 'urgent' ? 'danger' : ($todo['priority'] === 'high' ? 'warning' : ($todo['priority'] === 'medium' ? 'info' : 'secondary')); ?>">
                                            <?php echo ucfirst($todo['priority']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($todo['component']); ?></td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="update_todo_status">
                                            <input type="hidden" name="todo_id" value="<?php echo $todo['id']; ?>">
                                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <option value="backlog" <?php echo ($todo['status'] === 'backlog') ? 'selected' : ''; ?>>Backlog</option>
                                                <option value="planned" <?php echo ($todo['status'] === 'planned') ? 'selected' : ''; ?>>Planned</option>
                                                <option value="in-progress" <?php echo ($todo['status'] === 'in-progress') ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="testing" <?php echo ($todo['status'] === 'testing') ? 'selected' : ''; ?>>Testing</option>
                                                <option value="completed" <?php echo ($todo['status'] === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                                <option value="cancelled" <?php echo ($todo['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?php echo htmlspecialchars($todo['target_version'] ?: 'TBD'); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-info" onclick="viewTodo(<?php echo $todo['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Version History Tab -->
        <div class="tab-pane fade" id="versions" role="tabpanel">
            <div class="card card-dark">
                <div class="card-header">
                    <h5 class="mb-0">Version History</h5>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <?php foreach ($versions as $version): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker bg-primary"></div>
                            <div class="timeline-content">
                                <h6 class="timeline-title">
                                    Version <?php echo htmlspecialchars($version['version']); ?>
                                    <span class="badge bg-<?php echo $version['status'] === 'released' ? 'success' : ($version['status'] === 'development' ? 'warning' : 'info'); ?>">
                                        <?php echo ucfirst($version['status']); ?>
                                    </span>
                                </h6>
                                <p class="timeline-body"><?php echo htmlspecialchars($version['description']); ?></p>
                                <small class="text-muted">Released: <?php echo date('M j, Y', strtotime($version['release_date'])); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Bug Modal -->
<div class="modal fade" id="addBugModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Report Bug</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_bug">
                    
                    <div class="mb-3">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Severity</label>
                                <select name="severity" class="form-select">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Component</label>
                                <select name="component" class="form-select">
                                    <option value="ui">UI</option>
                                    <option value="api">API</option>
                                    <option value="agents">Agents</option>
                                    <option value="firewalls">Firewalls</option>
                                    <option value="database">Database</option>
                                    <option value="authentication">Authentication</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Version Found</label>
                        <input type="text" name="version_found" class="form-control" value="<?php echo htmlspecialchars($current_version); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Bug Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Todo Modal -->
<div class="modal fade" id="addTodoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add Todo Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_todo">
                    
                    <div class="mb-3">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select">
                                    <option value="feature" selected>Feature</option>
                                    <option value="improvement">Improvement</option>
                                    <option value="maintenance">Maintenance</option>
                                    <option value="documentation">Documentation</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Component</label>
                                <select name="component" class="form-select">
                                    <option value="ui">UI</option>
                                    <option value="api">API</option>
                                    <option value="agents">Agents</option>
                                    <option value="firewalls">Firewalls</option>
                                    <option value="database">Database</option>
                                    <option value="authentication">Authentication</option>
                                    <option value="testing">Testing</option>
                                    <option value="documentation">Documentation</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Target Version</label>
                                <input type="text" name="target_version" class="form-control" placeholder="e.g., 1.1.0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Todo Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    margin-bottom: 30px;
}

.timeline-marker {
    position: absolute;
    left: -35px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.timeline-item:not(:last-child):after {
    content: '';
    position: absolute;
    left: -31px;
    top: 17px;
    width: 4px;
    height: calc(100% + 13px);
    background: #6c757d;
}

.timeline-title {
    margin-bottom: 5px;
    color: #fff;
}

.timeline-body {
    margin-bottom: 10px;
    color: #adb5bd;
}
</style>

<script>
function viewBug(id) {
    // TODO: Implement bug detail modal
    alert('Bug details for ID: ' + id);
}

function viewTodo(id) {
    // TODO: Implement todo detail modal
    alert('Todo details for ID: ' + id);
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>