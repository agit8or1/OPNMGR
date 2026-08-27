<?php
/**
 * Fleet Configuration Search.
 *
 * Answers operational questions across every stored configuration backup.
 * Deterministic: the same query always produces the same answer, and no model
 * sits in the path. AI may explain a result set elsewhere; it is never required
 * to produce one.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/config_search.php';

require_permission('config.search');

$query  = trim($_GET['q'] ?? '');
$result = ['results' => [], 'scanned' => 0, 'skipped' => 0, 'error' => '', 'kind' => ''];
$elapsed = 0.0;

if ($query !== '') {
    $t0 = microtime(true);
    $result = config_search_fleet($query);
    $elapsed = microtime(true) - $t0;
}

$checks = config_search_checks();

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-file-magnifying-glass me-2"></i>Configuration Search</h4>
        <small class="text-muted">
            Searches the newest stored configuration backup of every firewall. Matches configuration
            meaning rather than XML formatting.
        </small>
    </div>

    <div class="card mb-3"><div class="card-body">
        <form method="get" class="mb-2">
            <div class="input-group">
                <input type="text" name="q" class="form-control form-control-lg"
                       placeholder="8.8.8.8   192.168.50.0/24   check:ssh_on_wan"
                       value="<?php echo htmlspecialchars($query); ?>" autofocus>
                <button class="btn btn-primary"><i class="fas fa-magnifying-glass me-1"></i>Search</button>
            </div>
        </form>
        <div class="small text-muted">
            <strong>Named checks:</strong>
            <?php foreach ($checks as $key => $c): ?>
                <a href="?q=<?php echo urlencode('check:' . $key); ?>"
                   class="badge bg-secondary text-decoration-none me-1"
                   title="<?php echo htmlspecialchars($c['describe']); ?>"><?php echo htmlspecialchars($key); ?></a>
            <?php endforeach; ?>
            <br class="d-block mt-1">
            A bare address such as <code>8.8.8.8</code> matches literally anywhere in the config;
            a CIDR such as <code>192.168.50.0/24</code> matches any address inside the range.
        </div>
    </div></div>

    <?php if ($result['error']): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($result['error']); ?></div>
    <?php endif; ?>

    <?php if ($query !== '' && !$result['error']): ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="small text-muted">
                Scanned <?php echo (int)$result['scanned']; ?> configuration(s)
                <?php if ($result['skipped']): ?>
                    &middot; <?php echo (int)$result['skipped']; ?> firewall(s) had no usable backup
                <?php endif; ?>
                &middot; <?php echo number_format($elapsed, 2); ?>s
            </div>
            <span class="badge bg-secondary"><?php echo count($result['results']); ?> firewall(s) matched</span>
        </div>

        <?php if ($query !== '' && !empty($checks[substr($query, 6)]) && str_starts_with($query, 'check:')): ?>
            <div class="alert alert-info py-2 small">
                <strong><?php echo htmlspecialchars($checks[substr($query, 6)]['label']); ?></strong> &mdash;
                <?php echo htmlspecialchars($checks[substr($query, 6)]['describe']); ?>
            </div>
        <?php endif; ?>

        <?php if (!$result['results']): ?>
            <div class="card"><div class="card-body text-muted">
                No configuration matched <code><?php echo htmlspecialchars($query); ?></code>.
                <?php if ($result['skipped']): ?>
                    <br><small><?php echo (int)$result['skipped']; ?> firewall(s) were skipped because no
                    readable configuration backup exists for them yet.</small>
                <?php endif; ?>
            </div></div>
        <?php else: ?>
            <?php foreach ($result['results'] as $r): ?>
            <div class="card mb-2">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span>
                        <a href="firewall_details.php?id=<?php echo (int)$r['firewall_id']; ?>">
                            <strong><?php echo htmlspecialchars($r['hostname']); ?></strong></a>
                        <span class="text-muted small ms-2"><?php echo htmlspecialchars($r['customer_name'] ?: 'Unassigned'); ?></span>
                    </span>
                    <span class="small text-muted">
                        <?php echo (int)$r['match_count']; ?> match(es)
                        &middot; config from <?php echo htmlspecialchars((string)$r['backup_at']); ?>
                    </span>
                </div>
                <div class="card-body py-2">
                    <ul class="list-unstyled mb-0 small font-monospace">
                        <?php foreach ($r['matches'] as $m): ?>
                            <li class="text-truncate"><?php echo htmlspecialchars($m); ?></li>
                        <?php endforeach; ?>
                        <?php if ($r['match_count'] > count($r['matches'])): ?>
                            <li class="text-muted">… and <?php echo $r['match_count'] - count($r['matches']); ?> more</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
