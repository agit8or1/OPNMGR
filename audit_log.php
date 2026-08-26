<?php
/**
 * Audit Log
 *
 * Searchable view of who did what, to which firewall, from where.
 *
 * Reading the audit trail is available to every staff role, including Read Only:
 * oversight is the point of an audit log, and it never contains credential
 * material (audit_scrub_metadata() strips it before the row is written).
 */

require_once __DIR__ . '/inc/bootstrap.php';

require_permission('audit.view');

// --- filters ---------------------------------------------------------------
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

$f_action   = trim($_GET['action_filter'] ?? '');
$f_user     = trim($_GET['user'] ?? '');
$f_firewall = trim($_GET['firewall'] ?? '');
$f_customer = trim($_GET['customer'] ?? '');
$f_result   = trim($_GET['result'] ?? '');
$f_from     = trim($_GET['from'] ?? '');
$f_to       = trim($_GET['to'] ?? '');
$f_search   = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

if ($f_action !== '') {
    $where[]  = 'a.action = ?';
    $params[] = $f_action;
}
if ($f_user !== '' && ctype_digit($f_user)) {
    $where[]  = 'a.user_id = ?';
    $params[] = (int)$f_user;
}
if ($f_firewall !== '' && ctype_digit($f_firewall)) {
    $where[]  = 'a.firewall_id = ?';
    $params[] = (int)$f_firewall;
}
if ($f_customer !== '' && ctype_digit($f_customer)) {
    $where[]  = 'a.customer_id = ?';
    $params[] = (int)$f_customer;
}
if ($f_result === 'success') {
    $where[] = 'a.success = 1';
} elseif ($f_result === 'failure') {
    $where[] = 'a.success = 0';
}
// Dates are validated rather than interpolated, so a malformed value is ignored
// instead of becoming part of the query.
if ($f_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) {
    $where[]  = 'a.occurred_at >= ?';
    $params[] = $f_from . ' 00:00:00';
}
if ($f_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to)) {
    $where[]  = 'a.occurred_at <= ?';
    $params[] = $f_to . ' 23:59:59';
}
if ($f_search !== '') {
    $where[]  = '(a.message LIKE ? OR a.username LIKE ? OR a.source_ip LIKE ? OR a.object_id LIKE ?)';
    $like     = '%' . $f_search . '%';
    array_push($params, $like, $like, $like, $like);
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- data ------------------------------------------------------------------
// Note: inc/header.php declares its own $rows (the settings map) and is
// included further down, so page-level result sets must not use that name.
$total = 0;
$audit_rows = [];
$actions = [];
$users   = [];
$firewalls = [];
$error = '';

try {
    $count = db()->prepare("SELECT COUNT(*) FROM audit_log a {$where_sql}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $sql = "SELECT a.*, f.hostname AS firewall_hostname
              FROM audit_log a
              LEFT JOIN firewalls f ON a.firewall_id = f.id
              {$where_sql}
             ORDER BY a.occurred_at DESC, a.id DESC
             LIMIT {$per_page} OFFSET {$offset}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $audit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter dropdowns
    $actions   = db()->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    $users     = db()->query('SELECT id, username FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
    $firewalls = db()->query('SELECT id, hostname FROM firewalls ORDER BY hostname')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('audit_log.php: ' . $e->getMessage());
    $error = 'Could not load the audit log.';
}

$total_pages = max(1, (int)ceil($total / $per_page));

/** Bootstrap colour for an action, so the list scans quickly. */
function audit_action_class(string $action, int $success): string {
    if (!$success) {
        return 'danger';
    }
    return match (true) {
        str_starts_with($action, 'auth.')      => 'info',
        str_starts_with($action, 'command.')   => 'warning',
        str_starts_with($action, 'agent.')     => 'primary',
        str_starts_with($action, 'backup.')    => 'success',
        str_starts_with($action, 'authz.')     => 'danger',
        default                                => 'secondary',
    };
}

/** Preserve the current filters when building a page link. */
function audit_page_url(int $page): string {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Audit Log</h4>
            <small class="text-muted">
                Who did what, to which firewall, from where.
                Credentials are never recorded.
            </small>
        </div>
        <span class="badge bg-secondary"><?php echo number_format($total); ?> entries</span>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Action</label>
                    <select name="action_filter" class="form-select form-select-sm">
                        <option value="">All actions</option>
                        <?php foreach ($actions as $a): ?>
                            <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $f_action === $a ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">User</label>
                    <select name="user" class="form-select form-select-sm">
                        <option value="">All users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>" <?php echo $f_user === (string)$u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Firewall</label>
                    <select name="firewall" class="form-select form-select-sm">
                        <option value="">All firewalls</option>
                        <?php foreach ($firewalls as $fw): ?>
                            <option value="<?php echo (int)$fw['id']; ?>" <?php echo $f_firewall === (string)$fw['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fw['hostname']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">Result</label>
                    <select name="result" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option value="success" <?php echo $f_result === 'success' ? 'selected' : ''; ?>>Success</option>
                        <option value="failure" <?php echo $f_result === 'failure' ? 'selected' : ''; ?>>Failure</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_from); ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_to); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="q" class="form-control form-control-sm"
                           placeholder="message, user, IP" value="<?php echo htmlspecialchars($f_search); ?>">
                </div>
                <div class="col-md-1 d-grid gap-1">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-filter me-1"></i>Filter</button>
                    <a class="btn btn-outline-secondary btn-sm" href="audit_log.php">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="white-space:nowrap">When</th>
                        <th>Action</th>
                        <th>Actor</th>
                        <th>Source IP</th>
                        <th>Firewall</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$audit_rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No audit entries match these filters.</td></tr>
                <?php else: foreach ($audit_rows as $r): ?>
                    <tr>
                        <td class="text-nowrap small text-muted">
                            <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($r['occurred_at']))); ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo audit_action_class($r['action'], (int)$r['success']); ?>">
                                <?php echo htmlspecialchars($r['action']); ?>
                            </span>
                            <?php if (!$r['success']): ?>
                                <i class="fas fa-times-circle text-danger ms-1" title="Failed"></i>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php
                            $actor = $r['username'] ?: ucfirst($r['actor_type']);
                            echo htmlspecialchars($actor);
                            ?>
                            <span class="text-muted">(<?php echo htmlspecialchars($r['actor_type']); ?>)</span>
                        </td>
                        <td class="small text-muted"><?php echo htmlspecialchars($r['source_ip'] ?? '—'); ?></td>
                        <td class="small">
                            <?php if ($r['firewall_hostname']): ?>
                                <a href="firewall_details.php?id=<?php echo (int)$r['firewall_id']; ?>">
                                    <?php echo htmlspecialchars($r['firewall_hostname']); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php echo htmlspecialchars($r['message'] ?? ''); ?>
                            <?php if (!empty($r['metadata'])): ?>
                                <details class="d-inline">
                                    <summary class="text-muted" style="cursor:pointer;display:inline">details</summary>
                                    <pre class="small mb-0 mt-1" style="white-space:pre-wrap"><?php
                                        $meta = json_decode($r['metadata'], true);
                                        echo htmlspecialchars(
                                            is_array($meta)
                                                ? json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                                : (string)$r['metadata']
                                        );
                                    ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo htmlspecialchars(audit_page_url($page - 1)); ?>">Previous</a>
            </li>
            <li class="page-item disabled">
                <span class="page-link">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            </li>
            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo htmlspecialchars(audit_page_url($page + 1)); ?>">Next</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
