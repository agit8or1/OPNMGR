<?php
/**
 * Global fleet search.
 *
 * One box that finds a firewall, customer or site across the whole managed
 * fleet. Query syntax is documented on the page itself and parsed in
 * inc/search.php.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/search.php';

require_permission('firewall.view');

$query   = trim($_GET['q'] ?? '');
$results = ['results' => [], 'total' => 0, 'parsed' => [], 'error' => ''];
$orgs    = ['customers' => [], 'sites' => []];

if ($query !== '') {
    $results = search_fleet($query);
    $orgs    = search_customers_and_sites($query);
}

/** Relative "last seen" text for a check-in timestamp. */
function search_last_seen(?string $ts): string {
    if (!$ts) {
        return 'never';
    }
    $delta = time() - strtotime($ts);
    if ($delta < 120)   return 'just now';
    if ($delta < 3600)  return floor($delta / 60) . 'm ago';
    if ($delta < 86400) return floor($delta / 3600) . 'h ago';
    return floor($delta / 86400) . 'd ago';
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0"><i class="fas fa-magnifying-glass me-2"></i>Fleet Search</h4>
        <small class="text-muted">Search every managed firewall, customer and site.</small>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="mb-2">
                <div class="input-group">
                    <input type="text" name="q" class="form-control form-control-lg"
                           placeholder="hostname, IP, customer:Acme, tag:critical, 192.168.22.0/24"
                           value="<?php echo htmlspecialchars($query); ?>" autofocus>
                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-magnifying-glass me-1"></i>Search
                    </button>
                </div>
            </form>
            <div class="small text-muted">
                <strong>Qualifiers:</strong>
                <code>customer:</code> <code>site:</code> <code>tag:</code>
                <code>version:</code> <code>agent:</code> <code>ip:</code>
                <code>interface:</code> <code>vpn:</code> <code>status:</code>
                &nbsp;&middot;&nbsp; quote values with spaces (<code>site:"Jacksonville HQ"</code>)
                &nbsp;&middot;&nbsp; a bare CIDR such as <code>192.168.22.0/24</code> matches addresses inside it
                &nbsp;&middot;&nbsp; terms combine with AND
            </div>
        </div>
    </div>

    <?php if ($results['error']): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($results['error']); ?></div>
    <?php endif; ?>

    <?php if ($query === ''): ?>
        <div class="card"><div class="card-body text-muted">
            <p class="mb-2">Examples:</p>
            <ul class="mb-0 small">
                <li><code>192.168.22.0/24</code> &mdash; every firewall with an address in that network</li>
                <li><code>customer:Acme</code> &mdash; one customer's whole estate</li>
                <li><code>version:26.1</code> &mdash; firewalls on a particular OPNsense release</li>
                <li><code>tag:critical status:offline</code> &mdash; critical firewalls that are down</li>
                <li><code>agent:1.4</code> &mdash; firewalls running an outdated agent</li>
            </ul>
        </div></div>
    <?php else: ?>

        <?php if ($orgs['customers'] || $orgs['sites']): ?>
        <div class="card mb-3">
            <div class="card-header py-2"><strong class="small">Customers &amp; Sites</strong></div>
            <div class="list-group list-group-flush">
                <?php foreach ($orgs['customers'] as $c): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                       href="customers.php?highlight=<?php echo (int)$c['id']; ?>">
                        <span>
                            <i class="fas fa-building me-2 text-muted"></i>
                            <?php echo htmlspecialchars($c['name']); ?>
                            <?php if (!empty($c['code'])): ?>
                                <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($c['code']); ?></span>
                            <?php endif; ?>
                            <?php if (!$c['is_active']): ?>
                                <span class="badge bg-warning text-dark ms-1">Inactive</span>
                            <?php endif; ?>
                        </span>
                        <span class="small text-muted"><?php echo (int)$c['firewall_count']; ?> firewall(s)</span>
                    </a>
                <?php endforeach; ?>
                <?php foreach ($orgs['sites'] as $st): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                       href="customers.php?highlight=<?php echo (int)$st['customer_id']; ?>">
                        <span>
                            <i class="fas fa-location-dot me-2 text-muted"></i>
                            <?php echo htmlspecialchars($st['customer_name']); ?>
                            <i class="fas fa-angle-right mx-1 text-muted"></i>
                            <?php echo htmlspecialchars($st['name']); ?>
                        </span>
                        <span class="small text-muted"><?php echo (int)$st['firewall_count']; ?> firewall(s)</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong class="small">Firewalls</strong>
                <span class="badge bg-secondary"><?php echo number_format($results['total']); ?> match(es)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Firewall</th>
                            <th>Customer / Site</th>
                            <th>WAN</th>
                            <th>LAN</th>
                            <th>OPNsense</th>
                            <th>Agent</th>
                            <th>Tags</th>
                            <th>Last seen</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$results['results']): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">
                            No firewalls match <code><?php echo htmlspecialchars($query); ?></code>.
                        </td></tr>
                    <?php else: foreach ($results['results'] as $fw): ?>
                        <tr>
                            <td>
                                <span class="badge bg-<?php echo $fw['status'] === 'online' ? 'success' : 'danger'; ?> me-1">&nbsp;</span>
                                <a href="firewall_details.php?id=<?php echo (int)$fw['id']; ?>">
                                    <?php echo htmlspecialchars($fw['hostname']); ?>
                                </a>
                            </td>
                            <td class="small">
                                <?php if ($fw['customer_name']): ?>
                                    <?php echo htmlspecialchars($fw['customer_name']); ?>
                                    <?php if ($fw['site_name']): ?>
                                        <span class="text-muted">/ <?php echo htmlspecialchars($fw['site_name']); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="small font-monospace"><?php echo htmlspecialchars($fw['wan_ip'] ?: '—'); ?></td>
                            <td class="small font-monospace"><?php echo htmlspecialchars($fw['lan_ip'] ?: '—'); ?></td>
                            <td class="small"><?php echo htmlspecialchars(substr((string)$fw['version'], 0, 24) ?: '—'); ?></td>
                            <td class="small"><?php echo htmlspecialchars($fw['agent_version'] ?: '—'); ?></td>
                            <td class="small">
                                <?php foreach (array_filter(explode(',', (string)$fw['tag_names'])) as $t): ?>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($t); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars(search_last_seen($fw['last_checkin'])); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($results['total'] > count($results['results'])): ?>
                <div class="card-footer small text-muted">
                    Showing the first <?php echo count($results['results']); ?> of
                    <?php echo number_format($results['total']); ?> matches. Narrow the query to see the rest.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
