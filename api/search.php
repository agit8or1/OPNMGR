<?php
/**
 * Fleet search API — powers the header typeahead.
 *
 * GET /api/search.php?q=...&limit=8
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/search.php';

header('Content-Type: application/json');

require_permission('firewall.view');

$query = trim($_GET['q'] ?? '');
$limit = max(1, min(20, (int)($_GET['limit'] ?? 8)));

if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'query' => $query, 'firewalls' => [], 'customers' => [], 'sites' => []]);
    exit;
}

$fleet = search_fleet($query, $limit);
$orgs  = search_customers_and_sites($query, 5);

echo json_encode([
    'success' => true,
    'query'   => $query,
    'total'   => $fleet['total'],
    'firewalls' => array_map(fn($f) => [
        'id'       => (int)$f['id'],
        'hostname' => $f['hostname'],
        'status'   => $f['status'],
        'customer' => $f['customer_name'],
        'site'     => $f['site_name'],
        'wan_ip'   => $f['wan_ip'],
        'url'      => 'firewall_details.php?id=' . (int)$f['id'],
    ], $fleet['results']),
    'customers' => array_map(fn($c) => [
        'id'    => (int)$c['id'],
        'name'  => $c['name'],
        'code'  => $c['code'],
        'count' => (int)$c['firewall_count'],
        'url'   => 'customers.php?highlight=' . (int)$c['id'],
    ], $orgs['customers']),
    'sites' => array_map(fn($s) => [
        'id'       => (int)$s['id'],
        'name'     => $s['name'],
        'customer' => $s['customer_name'],
        'count'    => (int)$s['firewall_count'],
        'url'      => 'customers.php?highlight=' . (int)$s['customer_id'],
    ], $orgs['sites']),
], JSON_UNESCAPED_SLASHES);
