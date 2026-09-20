<?php
/**
 * Which alerts a firewall - or one object on it - is allowed to raise.
 *
 * Below `firewalls.alerts_enabled` there was nothing: either every condition for
 * every object, or silence. So a tunnel that is down by design, or a circuit
 * whose latency is simply what it is, could only be quietened by muting the
 * whole firewall - which hid everything that did matter along with it.
 *
 * A policy row is an override, resolved most-specific-first:
 *
 *   1. (type, firewall, object)   this one tunnel
 *   2. (type, firewall, *)        no certificate alerts on this firewall
 *   3. (type, *, *)               nobody anywhere wants this condition
 *   4. nothing stored             enabled, with the built-in threshold
 *
 * The absence of a row means "behave as before", so the feature is inert until
 * someone uses it.
 */

require_once __DIR__ . '/bootstrap.php';

if (!defined('ALERT_POLICY_ANY_FIREWALL')) { define('ALERT_POLICY_ANY_FIREWALL', 0); }
if (!defined('ALERT_POLICY_ANY_OBJECT')) { define('ALERT_POLICY_ANY_OBJECT', ''); }

/**
 * Every condition the evaluator can raise, with what it means and whether it
 * is about one object or the firewall as a whole.
 *
 * `object` conditions can be muted for a single tunnel, gateway or service;
 * `firewall` conditions only for the firewall. The UI is generated from this,
 * so a condition added here appears there without further work - and one that
 * is never listed here cannot be configured at all, which is why the test
 * asserts this list against the raise() calls in the evaluator.
 */
function alert_policy_catalogue(): array
{
    return [
        'firewall.offline'      => ['label' => 'Firewall not checking in',      'scope' => 'firewall', 'threshold' => 'minutes'],
        'agent.outdated'        => ['label' => 'Agent behind published version','scope' => 'firewall', 'threshold' => null],
        'agent.auth_failures'   => ['label' => 'Agent authentication failures', 'scope' => 'firewall', 'threshold' => 'count'],
        'config.drift'          => ['label' => 'Configuration drift',           'scope' => 'firewall', 'threshold' => null],
        'backup.failed'         => ['label' => 'Backup failed',                 'scope' => 'firewall', 'threshold' => null],
        'carp.fault'            => ['label' => 'CARP fault',                   'scope' => 'firewall', 'threshold' => null],
        'cpu.high'              => ['label' => 'CPU usage high',                'scope' => 'firewall', 'threshold' => 'percent'],
        'memory.high'           => ['label' => 'Memory usage high',             'scope' => 'firewall', 'threshold' => 'percent'],
        'disk.high'             => ['label' => 'Disk usage high',               'scope' => 'firewall', 'threshold' => 'percent'],
        'job.stale'             => ['label' => 'Scheduled job not running',     'scope' => 'firewall', 'threshold' => null],
        'speedtest.slow'        => ['label' => 'Speed test below expected',     'scope' => 'firewall', 'threshold' => 'mbps'],
        'latency.high'          => ['label' => 'Latency above expected',        'scope' => 'firewall', 'threshold' => 'ms'],
        'cert.expiring'         => ['label' => 'Certificate expiring',          'scope' => 'object',   'threshold' => 'days'],
        'cert.expired'          => ['label' => 'Certificate expired',           'scope' => 'object',   'threshold' => null],
        'vpn.down'              => ['label' => 'VPN tunnel down',               'scope' => 'object',   'threshold' => null],
        'service.stopped'       => ['label' => 'Service stopped',               'scope' => 'object',   'threshold' => null],
        'gateway.down'          => ['label' => 'Gateway down',                  'scope' => 'object',   'threshold' => null],
        'gateway.degraded'      => ['label' => 'Gateway degraded',              'scope' => 'object',   'threshold' => null],
        'gateway.flapping'      => ['label' => 'Gateway flapping',              'scope' => 'object',   'threshold' => 'count'],
    ];
}

/**
 * Every policy row, keyed by scope.
 *
 * Held in a static because the evaluator resolves a policy for each condition on
 * each object on each firewall, and that is a lot of identical queries. Passing
 * $reset rebuilds it - a caller that writes policy mid-run must see its own write.
 */
function alert_policy_rows(bool $reset = false): array
{
    static $cache = null;
    if ($reset) { $cache = null; }
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT * FROM alert_policies')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[$row['alert_type'] . '|' . (int) $row['firewall_id'] . '|' . $row['object_key']] = $row;
            }
        } catch (Throwable $e) {
            // No table yet (migration not applied): behave as before.
            $cache = [];
        }
    }
    return $cache;
}

/** The row that applies to a scope, most specific first. */
function alert_policy_resolve(string $type, ?int $firewallId = null, ?string $objectKey = null): ?array
{
    $cache = alert_policy_rows();

    $fw  = (int) ($firewallId ?? 0);
    $obj = (string) ($objectKey ?? '');

    $candidates = [];
    if ($fw !== 0 && $obj !== '') { $candidates[] = "{$type}|{$fw}|{$obj}"; }
    if ($fw !== 0)                { $candidates[] = "{$type}|{$fw}|"; }
    $candidates[] = "{$type}|0|";

    foreach ($candidates as $key) {
        if (isset($cache[$key])) { return $cache[$key]; }
    }
    return null;
}

/** Reset the resolution cache. A caller that writes policy must see its write. */
function alert_policy_flush_cache(): void
{
    alert_policy_rows(true);
}

/**
 * May this condition be raised for this firewall and object?
 *
 * Nothing stored means yes: the policy table only ever takes alerting away.
 */
function alert_policy_allows(string $type, ?int $firewallId = null, ?string $objectKey = null): bool
{
    $row = alert_policy_resolve($type, $firewallId, $objectKey);
    return $row === null ? true : ((int) $row['enabled'] === 1);
}

/**
 * The threshold for this scope, or $default when none is set.
 *
 * A threshold is per-scope for the same reason enablement is: a 100 Mbit branch
 * and a 1 Gbit datacentre circuit are both "slow" at different numbers, and one
 * global figure makes the condition useless for one of them.
 */
function alert_policy_threshold(string $type, ?int $firewallId, ?string $objectKey, $default)
{
    $row = alert_policy_resolve($type, $firewallId, $objectKey);
    if ($row === null || $row['threshold'] === null || trim((string) $row['threshold']) === '') {
        return $default;
    }
    return is_numeric($row['threshold']) ? $row['threshold'] + 0 : $row['threshold'];
}

/**
 * Conditions that stay silent until someone asks for them.
 *
 * "Slow" and "high" are site-specific: a 100 Mbit branch and a gigabit
 * datacentre circuit are both slow at different numbers, and one global figure
 * would be wrong for one of them - so wrong that it would either never fire or
 * fire constantly. These conditions therefore require an explicit policy row
 * with a threshold before they raise anything.
 *
 * This is also the cautious default for a new condition on an installation that
 * has already been flooded once: adding a check that fires everywhere the moment
 * it ships is how that happens.
 */
function alert_policy_is_opt_in(string $type): bool
{
    return in_array($type, ['speedtest.slow', 'latency.high'], true);
}

/**
 * Has someone deliberately turned this condition on for this scope, and to what
 * threshold? Returns null when it should stay silent.
 */
function alert_policy_opt_in_threshold(string $type, ?int $firewallId, ?string $objectKey = null): ?float
{
    $row = alert_policy_resolve($type, $firewallId, $objectKey);
    if ($row === null || (int) $row['enabled'] !== 1) { return null; }
    $t = $row['threshold'];
    if ($t === null || trim((string) $t) === '' || !is_numeric($t)) { return null; }
    return (float) $t;
}

/** Store or update one policy row. */
function alert_policy_set(string $type, ?int $firewallId, ?string $objectKey,
                          bool $enabled, ?string $threshold = null, ?string $note = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO alert_policies (alert_type, firewall_id, object_key, enabled, threshold, note)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), threshold = VALUES(threshold),
                                 note = VALUES(note), updated_at = NOW()'
    );
    $stmt->execute([
        $type,
        (int) ($firewallId ?? 0),
        (string) ($objectKey ?? ''),
        $enabled ? 1 : 0,
        ($threshold === null || trim($threshold) === '') ? null : trim($threshold),
        ($note === null || trim($note) === '') ? null : trim($note),
    ]);
    alert_policy_flush_cache();
}

/** Remove a policy row, returning that scope to whatever it inherits. */
function alert_policy_clear(string $type, ?int $firewallId, ?string $objectKey): void
{
    $stmt = db()->prepare(
        'DELETE FROM alert_policies WHERE alert_type = ? AND firewall_id = ? AND object_key = ?'
    );
    $stmt->execute([$type, (int) ($firewallId ?? 0), (string) ($objectKey ?? '')]);
    alert_policy_flush_cache();
}

/** Policy rows for one firewall, plus the global rows it inherits. */
function alert_policy_for_firewall(int $firewallId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM alert_policies WHERE firewall_id IN (0, ?) ORDER BY firewall_id DESC, alert_type, object_key'
    );
    $stmt->execute([$firewallId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The objects a per-object condition can be scoped to on this firewall.
 *
 * "Selectable per tunnel" needs the tunnels listed by the same key the evaluator
 * raises them under, or a policy would be written against a name that never
 * matches.
 */
function alert_policy_objects(string $type, int $firewallId): array
{
    $queries = [
        'vpn.down'         => 'SELECT name AS k, CONCAT(vpn_type, " - ", name) AS label FROM firewall_vpn_tunnels WHERE firewall_id = ? AND enabled = 1 ORDER BY name',
        'service.stopped'  => 'SELECT name AS k, name AS label FROM firewall_services WHERE firewall_id = ? AND enabled = 1 ORDER BY name',
        'gateway.down'     => 'SELECT name AS k, CONCAT(name, " (", COALESCE(interface, "?"), ")") AS label FROM firewall_gateways WHERE firewall_id = ? ORDER BY name',
        'gateway.degraded' => 'SELECT name AS k, CONCAT(name, " (", COALESCE(interface, "?"), ")") AS label FROM firewall_gateways WHERE firewall_id = ? ORDER BY name',
        'gateway.flapping' => 'SELECT name AS k, CONCAT(name, " (", COALESCE(interface, "?"), ")") AS label FROM firewall_gateways WHERE firewall_id = ? ORDER BY name',
    ];
    if (!isset($queries[$type])) { return []; }
    try {
        $stmt = db()->prepare($queries[$type]);
        $stmt->execute([$firewallId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
