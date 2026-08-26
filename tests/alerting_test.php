<?php
/**
 * OPNMGR alerting and maintenance window tests.
 *
 * Run with: php tests/alerting_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/alerting.php';
require_once TEST_ROOT . '/inc/maintenance.php';

$fwId = 0; $custId = 0; $siteId = 0;

register_shutdown_function(function () use (&$fwId, &$custId, &$siteId) {
    try {
        if ($fwId) {
            db()->prepare('DELETE e FROM alert_incident_events e
                             JOIN alert_incidents i ON i.id = e.incident_id
                            WHERE i.firewall_id = ?')->execute([$fwId]);
            foreach (['alert_incidents','maintenance_windows','audit_log','firewall_system_stats'] as $t) {
                $col = $t === 'maintenance_windows' ? null : 'firewall_id';
                if ($col) { db()->prepare("DELETE FROM {$t} WHERE {$col} = ?")->execute([$fwId]); }
            }
            db()->prepare('DELETE FROM maintenance_windows WHERE scope = "firewall" AND scope_id = ?')->execute([$fwId]);
            db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$fwId]);
        }
        if ($siteId) {
            db()->prepare('DELETE FROM maintenance_windows WHERE scope = "site" AND scope_id = ?')->execute([$siteId]);
            db()->prepare('DELETE FROM sites WHERE id = ?')->execute([$siteId]);
        }
        if ($custId) {
            db()->prepare('DELETE FROM maintenance_windows WHERE scope = "customer" AND scope_id = ?')->execute([$custId]);
            db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$custId]);
        }
    } catch (Throwable $e) { /* best effort */ }
});

$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'alert-test';

db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute(['__test_alert_cust__']);
$custId = (int)db()->lastInsertId();
db()->prepare('INSERT INTO sites (customer_id, name) VALUES (?,?)')->execute([$custId, 'Test Site']);
$siteId = (int)db()->lastInsertId();
db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status, customer_id, site_id)
               VALUES (?,?,?,"online",?,?)')
    ->execute(['__test_fw_alert__', '198.51.100.20', hash('md5', 'al' . random_bytes(6)), $custId, $siteId]);
$fwId = (int)db()->lastInsertId();

// ===========================================================================
T::group('Incident lifecycle');

$id1 = alert_raise('gateway.down', [
    'firewall_id' => $fwId, 'object_key' => 'WAN_GW',
    'title' => 'Gateway WAN_GW is down',
]);
T::ok($id1 > 0, 'raising a condition opens an incident');

$id2 = alert_raise('gateway.down', [
    'firewall_id' => $fwId, 'object_key' => 'WAN_GW',
    'title' => 'Gateway WAN_GW is down',
]);
T::eq($id1, $id2, 'raising the same condition again updates the SAME incident, it does not duplicate');

$row = db()->prepare('SELECT * FROM alert_incidents WHERE id = ?');
$row->execute([$id1]);
$inc = $row->fetch(PDO::FETCH_ASSOC);
T::eq(2, (int)$inc['occurrence_count'], 'the occurrence count increments');
T::eq('open', $inc['status'], 'the incident stays open');
T::eq($custId, (int)$inc['customer_id'], 'the customer is resolved from the firewall');
T::eq($siteId, (int)$inc['site_id'], 'the site is resolved from the firewall');

// A different object on the same firewall is a different incident.
$id3 = alert_raise('gateway.down', ['firewall_id' => $fwId, 'object_key' => 'WAN2_GW', 'title' => 'x']);
T::ok($id3 !== $id1, 'a different object gets its own incident');

T::ok(!alert_raise('not.a.real.type', ['firewall_id' => $fwId]), 'an unknown alert type is refused');

T::ok(alert_resolve('gateway.down', $fwId, 'WAN_GW'), 'resolving closes the incident');
$row->execute([$id1]);
$inc = $row->fetch(PDO::FETCH_ASSOC);
T::eq('resolved', $inc['status'], 'status becomes resolved');
T::ok($inc['resolved_at'] !== null, 'resolved_at is stamped');
T::eq(null, $inc['dedupe_key'], 'the dedupe key is released so the condition can recur');

T::ok(!alert_resolve('gateway.down', $fwId, 'WAN_GW'), 'resolving an already-resolved condition is a no-op');

// The same condition happening again must open a NEW incident, not reopen the
// closed one - otherwise the history of separate outages is lost.
$id4 = alert_raise('gateway.down', ['firewall_id' => $fwId, 'object_key' => 'WAN_GW', 'title' => 'again']);
T::ok($id4 !== $id1, 'the condition recurring opens a fresh incident');

T::group('Acknowledgement');

$ack = alert_acknowledge($id4, 'known issue, engineer dispatched');
T::ok($ack['ok'], 'an open incident can be acknowledged');
$row->execute([$id4]);
$inc = $row->fetch(PDO::FETCH_ASSOC);
T::eq('acknowledged', $inc['status'], 'status becomes acknowledged');
T::eq('alert-test', $inc['acknowledged_by'], 'the acknowledging user is recorded');

$again = alert_acknowledge($id4, 'x');
T::ok(!$again['ok'], 'acknowledging twice is refused');

$decision = alert_should_notify($inc);
T::ok(!$decision['notify'], 'an acknowledged incident does not notify');
T::eq('acknowledged', $decision['reason'], 'and says why');

T::group('Notification throttling');

$fresh = ['id' => $id3, 'status' => 'open', 'firewall_id' => $fwId,
          'notify_count' => 0, 'last_notified_at' => null];
T::ok(alert_should_notify($fresh)['notify'], 'the first occurrence notifies immediately');

$justNotified = array_merge($fresh, ['notify_count' => 1, 'last_notified_at' => date('Y-m-d H:i:s')]);
T::ok(!alert_should_notify($justNotified)['notify'], 'a repeat inside the backoff window is withheld');

$longAgo = array_merge($fresh, ['notify_count' => 1,
                                'last_notified_at' => date('Y-m-d H:i:s', time() - 86400)]);
T::ok(alert_should_notify($longAgo)['notify'], 'a repeat after the backoff window is sent');

$exhausted = array_merge($fresh, ['notify_count' => 99,
                                  'last_notified_at' => date('Y-m-d H:i:s', time() - 86400)]);
T::ok(!alert_should_notify($exhausted)['notify'], 'notification stops after the repeat limit');
T::eq('repeat limit reached', alert_should_notify($exhausted)['reason'], 'and says why');

T::group('Maintenance windows');

T::eq(null, maintenance_active_for($fwId), 'no window means not in maintenance');
T::ok(!maintenance_suppresses_alerts($fwId), 'and alerts are not suppressed');

$bad = maintenance_save(['scope' => 'firewall', 'scope_id' => $fwId,
                         'starts_at' => '2026-01-02 10:00', 'ends_at' => '2026-01-01 10:00']);
T::ok(!$bad['ok'], 'a window ending before it starts is rejected');

$badScope = maintenance_save(['scope' => 'planet', 'scope_id' => 1,
                              'starts_at' => 'now', 'ends_at' => '+1 hour']);
T::ok(!$badScope['ok'], 'an invalid scope is rejected');

$ghost = maintenance_save(['scope' => 'firewall', 'scope_id' => 999999,
                           'starts_at' => 'now', 'ends_at' => '+1 hour']);
T::ok(!$ghost['ok'], 'a window for a firewall that does not exist is rejected');

$tooLong = maintenance_save(['scope' => 'firewall', 'scope_id' => $fwId,
                             'starts_at' => 'now', 'ends_at' => '+60 days']);
T::ok(!$tooLong['ok'], 'an absurdly long window is rejected');

// Scope resolution: customer-level window must cover the firewall.
$custWin = maintenance_save(['scope' => 'customer', 'scope_id' => $custId,
                             'starts_at' => date('Y-m-d H:i:s', time() - 60),
                             'ends_at'   => date('Y-m-d H:i:s', time() + 3600),
                             'reason'    => 'customer-wide work']);
T::ok($custWin['ok'], 'a customer-scoped window can be created');

// maintenance_active_for caches per process; reset it the way a fresh request
// or a new evaluator pass does.
maintenance_reset_cache();
T::ok(maintenance_active_for($fwId) !== null,
      'after a cache reset the customer-scoped window is visible to the firewall');

// Also prove the scope resolution in SQL, independent of the helper.
$stmt = db()->prepare(
    'SELECT COUNT(*) FROM maintenance_windows m JOIN firewalls f ON f.id = ?
      WHERE m.status IN ("scheduled","active") AND NOW() BETWEEN m.starts_at AND m.ends_at
        AND ((m.scope="firewall" AND m.scope_id=f.id)
          OR (m.scope="site" AND f.site_id IS NOT NULL AND m.scope_id=f.site_id)
          OR (m.scope="customer" AND f.customer_id IS NOT NULL AND m.scope_id=f.customer_id))'
);
$stmt->execute([$fwId]);
T::ok((int)$stmt->fetchColumn() > 0, 'a customer-scoped window covers the customer\'s firewalls');

T::ok(in_array($fwId, maintenance_firewalls_in_window(), true),
      'the firewall appears in the in-maintenance list');

$cancel = maintenance_cancel($custWin['id']);
T::ok($cancel['ok'], 'a window can be cancelled');
T::ok(!in_array($fwId, maintenance_firewalls_in_window(), true),
      'a cancelled window no longer covers the firewall');
T::ok(!maintenance_cancel($custWin['id'])['ok'], 'cancelling twice is refused');

// Status transitions.
db()->prepare('INSERT INTO maintenance_windows (scope, scope_id, starts_at, ends_at, status)
               VALUES ("firewall", ?, ?, ?, "scheduled")')
    ->execute([$fwId, date('Y-m-d H:i:s', time() - 120), date('Y-m-d H:i:s', time() + 600)]);
$activeId = (int)db()->lastInsertId();
db()->prepare('INSERT INTO maintenance_windows (scope, scope_id, starts_at, ends_at, status)
               VALUES ("firewall", ?, ?, ?, "active")')
    ->execute([$fwId, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600)]);
$doneId = (int)db()->lastInsertId();

maintenance_refresh_statuses();
$st = db()->prepare('SELECT status FROM maintenance_windows WHERE id = ?');
$st->execute([$activeId]);
T::eq('active', $st->fetchColumn(), 'a scheduled window whose time has come becomes active');
$st->execute([$doneId]);
T::eq('completed', $st->fetchColumn(), 'an active window past its end becomes completed');

T::group('Suppression is recorded, not discarded');

maintenance_reset_cache();
$suppressible = ['id' => $id3, 'status' => 'open', 'firewall_id' => $fwId,
                 'notify_count' => 0, 'last_notified_at' => null];
$d = alert_should_notify($suppressible);
T::ok(!$d['notify'], 'an active maintenance window withholds notification');
T::eq('maintenance', $d['reason'], 'and the reason is maintenance');

alert_mark_suppressed($id3, 'maintenance window active');
$row->execute([$id3]);
$inc = $row->fetch(PDO::FETCH_ASSOC);
T::eq(1, (int)$inc['suppressed'], 'the incident is flagged suppressed');
T::eq('open', $inc['status'], 'but it remains OPEN - the problem was not discarded');

$ev = db()->prepare('SELECT event FROM alert_incident_events WHERE incident_id = ? ORDER BY id');
$ev->execute([$id3]);
$events = $ev->fetchAll(PDO::FETCH_COLUMN);
T::ok(in_array('opened', $events, true),     'the open event is recorded');
T::ok(in_array('suppressed', $events, true), 'the suppression is recorded as an event');

// Repeated suppression must not spam the event log.
$before = count($events);
alert_mark_suppressed($id3, 'maintenance window active');
alert_mark_suppressed($id3, 'maintenance window active');
$ev->execute([$id3]);
T::eq($before, count($ev->fetchAll(PDO::FETCH_COLUMN)),
      'repeating the same suppression reason does not add duplicate events');

T::group('Counts');

$counts = alert_incident_counts();
T::ok($counts['total_open'] >= 1, 'open incidents are counted');
T::ok(array_key_exists('acknowledged', $counts), 'acknowledged incidents are counted separately');

exit(T::summary());
