<?php
/**
 * OPNMGR Incident-Based Alerting
 *
 * An incident is one ongoing problem, not one message. It is opened when a
 * condition first becomes true, updated while it persists, and resolved when it
 * clears.
 *
 * This replaces "send an email whenever the condition is true and a 60-minute
 * timer has elapsed", which could not say "still down", never said "back
 * online", and gave nobody anything to acknowledge.
 *
 * Detection and notification are separate decisions:
 *
 *   - Detection always happens. An incident is always recorded, including
 *     during maintenance, because the period you most want a record of is the
 *     one where somebody was working on the box.
 *   - Notification is throttled, backs off as an incident ages, stops once
 *     acknowledged, and is withheld during maintenance - with the suppression
 *     itself recorded as an event.
 *
 * @since 3.15.0
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/maintenance.php';

if (!defined('ALERT_TYPES')) {
    /**
     * Known alert types: severity and a human label.
     *
     * Defined centrally so the UI, the evaluator and the notification layer
     * agree on what exists.
     */
    define('ALERT_TYPES', [
        'firewall.offline'       => ['severity' => 'critical', 'label' => 'Firewall offline'],
        'gateway.down'           => ['severity' => 'critical', 'label' => 'Gateway down'],
        'gateway.degraded'       => ['severity' => 'warning',  'label' => 'Gateway degraded'],
        'gateway.flapping'       => ['severity' => 'warning',  'label' => 'Gateway flapping'],
        'vpn.down'               => ['severity' => 'warning',  'label' => 'VPN tunnel down'],
        'carp.fault'             => ['severity' => 'critical', 'label' => 'CARP/HA fault'],
        'service.stopped'        => ['severity' => 'warning',  'label' => 'Service stopped'],
        'cert.expiring'          => ['severity' => 'warning',  'label' => 'Certificate expiring'],
        'cert.expired'           => ['severity' => 'critical', 'label' => 'Certificate expired'],
        'cpu.high'               => ['severity' => 'warning',  'label' => 'CPU sustained high'],
        'memory.high'            => ['severity' => 'warning',  'label' => 'Memory sustained high'],
        'disk.high'              => ['severity' => 'warning',  'label' => 'Disk space low'],
        'config.drift'           => ['severity' => 'warning',  'label' => 'Configuration drift'],
        'backup.failed'          => ['severity' => 'warning',  'label' => 'Backup failure'],
        'update.failed'          => ['severity' => 'warning',  'label' => 'Update failure'],
        'agent.outdated'         => ['severity' => 'info',     'label' => 'Agent outdated'],
        'agent.auth_failures'    => ['severity' => 'critical', 'label' => 'Repeated agent authentication failures'],
    ]);
}

if (!function_exists('alert_setting')) {
    /**
     * Read an alerting setting with a fallback.
     */
    function alert_setting(string $name, $default) {
        static $cache = null;
        if ($cache === null) {
            try {
                $cache = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Throwable $e) {
                $cache = [];
            }
        }
        $v = $cache[$name] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }
}

if (!function_exists('alert_dedupe_key')) {
    /**
     * Stable key identifying one problem on one object.
     */
    function alert_dedupe_key(?int $firewallId, string $type, ?string $objectKey): string {
        return substr(sprintf('%s|%s|%s', $firewallId ?? 'global', $type, $objectKey ?? '-'), 0, 191);
    }
}

if (!function_exists('alert_incident_event')) {
    /**
     * Record a state change on an incident.
     */
    function alert_incident_event(int $incidentId, string $event, ?string $detail = null, ?string $actor = null): void {
        try {
            db()->prepare(
                'INSERT INTO alert_incident_events (incident_id, event, detail, actor) VALUES (?,?,?,?)'
            )->execute([$incidentId, $event, $detail !== null ? substr($detail, 0, 512) : null, $actor]);
        } catch (Throwable $e) {
            error_log('OPNMGR: alert_incident_event failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('alert_raise')) {
    /**
     * Assert that a condition is currently true.
     *
     * Idempotent: calling this every evaluation cycle while a problem persists
     * updates one incident rather than creating many.
     *
     * @param string $type      One of ALERT_TYPES
     * @param array  $opts      firewall_id, object_key, title, detail, metadata, severity
     * @return int Incident id, or 0 on failure
     */
    function alert_raise(string $type, array $opts = []): int {
        if (!isset(ALERT_TYPES[$type])) {
            error_log("OPNMGR: alert_raise called with unknown type '{$type}'");
            return 0;
        }

        $firewallId = isset($opts['firewall_id']) ? (int)$opts['firewall_id'] : null;
        $objectKey  = isset($opts['object_key']) ? substr((string)$opts['object_key'], 0, 128) : null;
        $severity   = $opts['severity'] ?? ALERT_TYPES[$type]['severity'];
        $key        = alert_dedupe_key($firewallId, $type, $objectKey);

        $title  = substr((string)($opts['title'] ?? ALERT_TYPES[$type]['label']), 0, 255);
        $detail = $opts['detail'] ?? null;
        $meta   = isset($opts['metadata']) && is_array($opts['metadata'])
                ? json_encode(audit_scrub_metadata($opts['metadata']))
                : null;

        // Resolve customer/site from the firewall so incidents can be filtered
        // by customer without another join at read time.
        $customerId = $siteId = null;
        if ($firewallId) {
            try {
                $stmt = db()->prepare('SELECT customer_id, site_id FROM firewalls WHERE id = ?');
                $stmt->execute([$firewallId]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $customerId = $row['customer_id'] !== null ? (int)$row['customer_id'] : null;
                    $siteId     = $row['site_id'] !== null ? (int)$row['site_id'] : null;
                }
            } catch (Throwable $e) {
                // non-fatal
            }
        }

        try {
            $find = db()->prepare('SELECT * FROM alert_incidents WHERE dedupe_key = ?');
            $find->execute([$key]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                db()->prepare(
                    'UPDATE alert_incidents
                        SET last_seen_at = NOW(), occurrence_count = occurrence_count + 1,
                            detail = ?, metadata = ?, severity = ?
                      WHERE id = ?'
                )->execute([$detail, $meta, $severity, (int)$existing['id']]);

                return (int)$existing['id'];
            }

            db()->prepare(
                'INSERT INTO alert_incidents
                    (dedupe_key, dedupe_source, alert_type, object_key, severity, status,
                     firewall_id, customer_id, site_id, title, detail, metadata)
                 VALUES (?,?,?,?,?,"open",?,?,?,?,?,?)'
            )->execute([
                $key, $key, $type, $objectKey, $severity,
                $firewallId, $customerId, $siteId, $title, $detail, $meta,
            ]);

            $incidentId = (int)db()->lastInsertId();
            alert_incident_event($incidentId, 'opened', $title);

            audit_log('alert.opened', [
                'actor_type'  => 'system',
                'object_type' => 'incident',
                'object_id'   => (string)$incidentId,
                'firewall_id' => $firewallId,
                'customer_id' => $customerId,
                'site_id'     => $siteId,
                'success'     => false,
                'message'     => $title,
                'metadata'    => ['alert_type' => $type, 'severity' => $severity],
            ]);

            return $incidentId;
        } catch (Throwable $e) {
            error_log('OPNMGR: alert_raise failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('alert_resolve')) {
    /**
     * Assert that a condition is no longer true.
     *
     * Nulls dedupe_key so the same condition recurring later opens a fresh
     * incident instead of colliding with this closed one.
     *
     * @return bool Whether an open incident was actually closed
     */
    function alert_resolve(string $type, ?int $firewallId, ?string $objectKey = null, string $reason = 'condition cleared'): bool {
        $key = alert_dedupe_key($firewallId, $type, $objectKey);

        try {
            $find = db()->prepare('SELECT * FROM alert_incidents WHERE dedupe_key = ?');
            $find->execute([$key]);
            $incident = $find->fetch(PDO::FETCH_ASSOC);

            if (!$incident) {
                return false;
            }

            db()->prepare(
                'UPDATE alert_incidents
                    SET status = "resolved", resolved_at = NOW(), dedupe_key = NULL
                  WHERE id = ?'
            )->execute([(int)$incident['id']]);

            alert_incident_event((int)$incident['id'], 'resolved', $reason);

            audit_log('alert.resolved', [
                'actor_type'  => 'system',
                'object_type' => 'incident',
                'object_id'   => (string)$incident['id'],
                'firewall_id' => $incident['firewall_id'] !== null ? (int)$incident['firewall_id'] : null,
                'message'     => 'Resolved: ' . $incident['title'],
                'metadata'    => ['alert_type' => $type, 'reason' => $reason],
            ]);

            return true;
        } catch (Throwable $e) {
            error_log('OPNMGR: alert_resolve failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('alert_acknowledge')) {
    /**
     * Acknowledge an incident: stop notifying, keep it open.
     */
    function alert_acknowledge(int $incidentId, string $note = ''): array {
        $stmt = db()->prepare(
            'UPDATE alert_incidents
                SET status = "acknowledged", acknowledged_at = NOW(),
                    acknowledged_by = ?, acknowledged_note = ?
              WHERE id = ? AND status = "open"'
        );
        $stmt->execute([
            $_SESSION['username'] ?? 'system',
            substr($note, 0, 255) ?: null,
            $incidentId,
        ]);

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'That incident is not open'];
        }

        alert_incident_event($incidentId, 'acknowledged', $note ?: null, $_SESSION['username'] ?? null);

        audit_log('alert.acknowledged', [
            'object_type' => 'incident',
            'object_id'   => (string)$incidentId,
            'message'     => 'Incident acknowledged',
            'metadata'    => ['note' => substr($note, 0, 255)],
        ]);

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('alert_should_notify')) {
    /**
     * Whether an incident warrants a notification right now.
     *
     * Rules, in order:
     *   - acknowledged or resolved incidents never notify
     *   - maintenance suppresses, and the suppression is recorded
     *   - the first occurrence notifies immediately
     *   - subsequent notifications back off, and stop after a maximum
     *
     * The backoff is what stops "a firewall is offline" arriving every two
     * minutes for a week.
     *
     * @return array{notify:bool, reason:string}
     */
    function alert_should_notify(array $incident): array {
        if (in_array($incident['status'], ['acknowledged', 'resolved'], true)) {
            return ['notify' => false, 'reason' => $incident['status']];
        }

        $firewallId = $incident['firewall_id'] !== null ? (int)$incident['firewall_id'] : null;
        if ($firewallId !== null && maintenance_suppresses_alerts($firewallId)) {
            return ['notify' => false, 'reason' => 'maintenance'];
        }

        $notifyCount = (int)$incident['notify_count'];
        $maxRepeats  = (int)alert_setting('alert_notify_max_repeats', 4);

        if ($notifyCount === 0) {
            return ['notify' => true, 'reason' => 'first occurrence'];
        }
        if ($notifyCount >= $maxRepeats) {
            return ['notify' => false, 'reason' => 'repeat limit reached'];
        }

        $base = max(5, (int)alert_setting('alert_notify_repeat_minutes', 60));
        // Double the interval each time: 60m, 120m, 240m...
        $waitMinutes = $base * (2 ** ($notifyCount - 1));

        $last = $incident['last_notified_at'] ? strtotime($incident['last_notified_at']) : 0;
        if ((time() - $last) < $waitMinutes * 60) {
            return ['notify' => false, 'reason' => 'within backoff window'];
        }

        return ['notify' => true, 'reason' => 'repeat after backoff'];
    }
}

if (!function_exists('alert_mark_notified')) {
    /**
     * Record that a notification was sent.
     */
    function alert_mark_notified(int $incidentId, string $detail = ''): void {
        try {
            db()->prepare(
                'UPDATE alert_incidents
                    SET notify_count = notify_count + 1, last_notified_at = NOW(),
                        suppressed = 0, suppressed_reason = NULL
                  WHERE id = ?'
            )->execute([$incidentId]);
            alert_incident_event($incidentId, 'notified', $detail ?: null);
        } catch (Throwable $e) {
            error_log('OPNMGR: alert_mark_notified failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('alert_mark_suppressed')) {
    /**
     * Record that a notification was withheld, and why.
     *
     * The event is written once per reason change rather than on every
     * evaluation cycle, so a week-long maintenance window does not produce
     * thousands of identical rows.
     */
    function alert_mark_suppressed(int $incidentId, string $reason): void {
        try {
            $stmt = db()->prepare('SELECT suppressed, suppressed_reason FROM alert_incidents WHERE id = ?');
            $stmt->execute([$incidentId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            $changed = !$current
                || (int)$current['suppressed'] !== 1
                || (string)$current['suppressed_reason'] !== $reason;

            db()->prepare('UPDATE alert_incidents SET suppressed = 1, suppressed_reason = ? WHERE id = ?')
                ->execute([substr($reason, 0, 128), $incidentId]);

            if ($changed) {
                alert_incident_event($incidentId, 'suppressed', $reason);
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: alert_mark_suppressed failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('alert_open_incidents')) {
    /**
     * Incidents for the alerts screen.
     *
     * @param array $filters status, severity, firewall_id, customer_id, type
     */
    function alert_open_incidents(array $filters = [], int $limit = 200): array {
        $where  = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'i.status = ?';
            $params[] = $filters['status'];
        } else {
            $where[] = 'i.status IN ("open","acknowledged")';
        }
        foreach (['severity' => 'i.severity', 'alert_type' => 'i.alert_type'] as $k => $col) {
            if (!empty($filters[$k])) {
                $where[]  = "{$col} = ?";
                $params[] = $filters[$k];
            }
        }
        foreach (['firewall_id' => 'i.firewall_id', 'customer_id' => 'i.customer_id'] as $k => $col) {
            if (!empty($filters[$k])) {
                $where[]  = "{$col} = ?";
                $params[] = (int)$filters[$k];
            }
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit    = max(1, min(500, $limit));

        try {
            $stmt = db()->prepare("
                SELECT i.*, f.hostname, c.name AS customer_name, s.name AS site_name
                  FROM alert_incidents i
                  LEFT JOIN firewalls f ON f.id = i.firewall_id
                  LEFT JOIN customers c ON c.id = i.customer_id
                  LEFT JOIN sites s ON s.id = i.site_id
                  {$whereSql}
                 ORDER BY FIELD(i.severity,'critical','warning','info'),
                          i.status = 'acknowledged', i.first_seen_at DESC
                 LIMIT {$limit}
            ");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: alert_open_incidents failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('alert_incident_counts')) {
    /**
     * Counts for the KPI strip.
     */
    function alert_incident_counts(): array {
        $out = ['critical' => 0, 'warning' => 0, 'info' => 0,
                'acknowledged' => 0, 'suppressed' => 0, 'total_open' => 0];
        try {
            $rows = db()->query(
                "SELECT severity, status, suppressed, COUNT(*) n
                   FROM alert_incidents
                  WHERE status IN ('open','acknowledged')
                  GROUP BY severity, status, suppressed"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $n = (int)$r['n'];
                $out['total_open'] += $n;
                if ($r['status'] === 'acknowledged') {
                    $out['acknowledged'] += $n;
                } else {
                    $out[$r['severity']] = ($out[$r['severity']] ?? 0) + $n;
                }
                if ((int)$r['suppressed'] === 1) {
                    $out['suppressed'] += $n;
                }
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: alert_incident_counts failed: ' . $e->getMessage());
        }
        return $out;
    }
}
