<?php
/**
 * OPNMGR MSP model tests: roles, customers/sites and fleet search.
 *
 * Run with: php tests/msp_test.php
 * Creates and removes its own fixtures, so it is safe against a live database.
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/permissions.php';
require_once TEST_ROOT . '/inc/customers.php';
require_once TEST_ROOT . '/inc/search.php';

$created = ['customers' => [], 'sites' => [], 'firewalls' => []];

register_shutdown_function(function () use (&$created) {
    foreach ($created['firewalls'] as $id) {
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    foreach ($created['sites'] as $id) {
        db()->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
    }
    foreach ($created['customers'] as $id) {
        db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }
});

/** Pretend to be a signed-in user with the given role. */
function as_role(string $role): void {
    $_SESSION['user_id']  = 999;
    $_SESSION['username'] = 'test-' . $role;
    $_SESSION['role']     = $role;
}

// ===========================================================================
T::group('RBAC capability matrix');

as_role('admin');
T::ok(can('command.raw'),        'admin may run raw shell');
T::ok(can('backup.restore'),     'admin may restore a configuration');
T::ok(can('user.manage'),        'admin may manage users');

as_role('technician');
T::ok(!can('command.raw'),       'technician may NOT run raw shell');
T::ok(!can('backup.restore'),    'technician may NOT restore a configuration');
T::ok(!can('user.manage'),       'technician may NOT manage users');
T::ok(!can('settings.manage'),   'technician may NOT change application settings');
T::ok(can('backup.create'),      'technician may take a backup');
T::ok(can('command.operational'),'technician may run operational commands');
T::ok(can('customer.manage'),    'technician may manage customers');

as_role('readonly');
T::ok(can('firewall.view'),      'read-only may view firewalls');
T::ok(can('audit.view'),         'read-only may read the audit log');
T::ok(!can('backup.create'),     'read-only may NOT take a backup');
T::ok(!can('command.diagnostic'),'read-only may NOT run diagnostics');
T::ok(!can('customer.manage'),   'read-only may NOT change customers');

T::ok(!can('no.such.capability'), 'an unknown capability denies rather than grants');

$_SESSION['role'] = 'superuser';
T::eq('readonly', current_role(), 'an unrecognised session role degrades to read-only');

$_SESSION = [];
T::ok(!can('firewall.view'),     'signed-out users hold no capabilities');

T::eq('command.privileged', risk_to_capability('HIGH'),   'HIGH risk maps to the privileged capability');
T::eq('command.diagnostic', risk_to_capability('LOW'),    'LOW risk maps to the diagnostic capability');
T::eq('command.privileged', risk_to_capability('BOGUS'),  'an unknown risk maps to the most restrictive capability');

T::eq('technician', sanitize_role('user'),      'the pre-3.12 "user" role maps to technician');
T::eq('readonly',   sanitize_role('root'),      'an unknown submitted role falls back to read-only');
T::eq('admin',      sanitize_role('admin'),     'a valid role is preserved');

// ===========================================================================
T::group('Customers and sites');

as_role('admin');

$c1 = customer_save(['name' => '__test_Acme__', 'code' => 'ACME', 'is_active' => 1,
                     'email' => 'ops@example.com', 'timezone' => 'America/New_York']);
T::ok($c1['ok'], 'a customer can be created');
$created['customers'][] = $c1['id'];

$dup = customer_save(['name' => '__test_Acme__']);
T::ok(!$dup['ok'], 'duplicate customer names are rejected');

$bad = customer_save(['name' => '__test_Bad__', 'email' => 'not-an-email']);
T::ok(!$bad['ok'], 'an invalid contact email is rejected');

$noname = customer_save(['name' => '   ']);
T::ok(!$noname['ok'], 'a blank customer name is rejected');

$c2 = customer_save(['name' => '__test_Other__', 'is_active' => 1]);
$created['customers'][] = $c2['id'];

$s1 = site_save(['customer_id' => $c1['id'], 'name' => 'Jacksonville HQ', 'is_active' => 1]);
T::ok($s1['ok'], 'a site can be created under a customer');
$created['sites'][] = $s1['id'];

$orphan = site_save(['customer_id' => 0, 'name' => 'Nowhere']);
T::ok(!$orphan['ok'], 'a site must belong to a customer');

$ghost = site_save(['customer_id' => 999999, 'name' => 'Ghost']);
T::ok(!$ghost['ok'], 'a site cannot reference a customer that does not exist');

$badwin = customer_save(['name' => '__test_Win__', 'maintenance_window_start' => '25:00',
                         'maintenance_window_end' => '26:00']);
T::ok(!$badwin['ok'], 'an out-of-range maintenance window is rejected');

$win = normalize_maintenance_window(['maintenance_window_start' => '22:00',
                                     'maintenance_window_end' => '02:00',
                                     'maintenance_window_days' => '0,6,6']);
T::ok($win['ok'], 'a valid maintenance window is accepted');
T::eq('0,6', $win['days'], 'maintenance days are de-duplicated and sorted');

T::eq('critical,edge', normalize_tags(' Critical , EDGE, critical , '), 'tags are normalised and de-duplicated');
T::eq(null, valid_timezone('Mars/Olympus'), 'an invalid timezone is rejected');

// --- assignment ------------------------------------------------------------
db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status)
               VALUES (?,?,?,"online")')
    ->execute(['__test_fw_msp__', '198.51.100.9', hash('md5', 'msp' . random_bytes(6))]);
$fwId = (int)db()->lastInsertId();
$created['firewalls'][] = $fwId;

$ok = save_firewall_assignment($fwId, $c1['id'], $s1['id']);
T::ok($ok['ok'], 'a firewall can be assigned to a customer and site');

$row = db()->prepare('SELECT customer_id, site_id, customer_name, customer_group FROM firewalls WHERE id = ?');
$row->execute([$fwId]);
$fw = $row->fetch(PDO::FETCH_ASSOC);
T::eq($c1['id'], (int)$fw['customer_id'], 'customer_id is written');
T::eq($s1['id'], (int)$fw['site_id'],     'site_id is written');
T::eq('__test_Acme__', $fw['customer_name'],  'the legacy customer_name column is kept in step');
T::eq('__test_Acme__', $fw['customer_group'], 'the legacy customer_group column is kept in step');

$mismatch = save_firewall_assignment($fwId, $c2['id'], $s1['id']);
T::ok(!$mismatch['ok'], 'a site belonging to another customer is rejected');

$counts = array_column(customer_list(), 'firewall_count', 'name');
T::eq(1, (int)($counts['__test_Acme__'] ?? 0), 'customer_list counts firewalls through customer_id');

// ===========================================================================
T::group('Fleet search');

$p = parse_search_query('customer:Acme site:"Jacksonville HQ" tag:critical 192.168.22.0/24 fw01');
T::eq(3, count($p['fields']), 'three field-qualified terms are parsed');
T::eq('Jacksonville HQ', $p['fields'][1]['value'], 'a quoted value keeps its spaces');
T::eq(['fw01'], $p['text'], 'bare words are kept as free text');
T::eq(['192.168.22.0/24'], $p['cidrs'], 'a bare CIDR is recognised as a range');

[$s, $e] = cidr_to_range('192.168.22.0/24');
T::ok(ip2long('192.168.22.1') >= $s && ip2long('192.168.22.1') <= $e, '/24 contains an address inside it');
$r2 = cidr_to_range('192.168.2.0/24');
T::ok(!(ip2long('192.168.22.1') >= $r2[0] && ip2long('192.168.22.1') <= $r2[1]),
      '192.168.2.0/24 does NOT match 192.168.22.1 (string-prefix bug)');
T::eq(null, cidr_to_range('999.1.1.0/24'), 'a malformed CIDR is rejected');
T::eq(null, cidr_to_range('10.0.0.0/33'),  'an out-of-range prefix length is rejected');

$hit = search_fleet('customer:__test_Acme__');
T::eq(1, $hit['total'], 'search finds a firewall by customer');
T::eq('__test_fw_msp__', $hit['results'][0]['hostname'] ?? '', 'the right firewall is returned');

$miss = search_fleet('customer:__test_NoSuch__');
T::eq(0, $miss['total'], 'a non-matching customer returns nothing');

$and = search_fleet('customer:__test_Acme__ status:offline');
T::eq(0, $and['total'], 'terms combine with AND, not OR');

$empty = search_fleet('   ');
T::eq(0, $empty['total'], 'an empty query returns nothing rather than the whole fleet');

// A quote-injection attempt must be treated as text, not SQL.
$inject = search_fleet("customer:' OR 1=1 --");
T::eq(0, $inject['total'], 'a SQL injection attempt in a search term matches nothing');
T::eq('', $inject['error'], 'and does not error');

exit(T::summary());
