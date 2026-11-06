<?php
/**
 * Integrations: event emission (the outbox write side).
 *
 * integration_emit() is the one function other modules call. It builds the
 * versioned envelope and inserts it into integration_events using the caller's
 * connection, so when the caller has a transaction open the event commits or
 * rolls back with it (transactional outbox). It performs no network I/O, never
 * throws, and returns the event id (or null if the event could not be stored).
 *
 * Envelope (schema opnmanager.event, version 1):
 *   id, type, version, occurred_at (UTC ISO-8601), correlation_id,
 *   origin {kind, destination_id}, customer {id,name}, site {id,name},
 *   firewall {id,hostname}, platform {family,edition}, tags[],
 *   incident {id,type,object_key,severity,previous_severity,status,
 *             first_seen_at,occurrence_count,recurrence_of},
 *   summary (sanitised), details (scrubbed), links {manager}
 * The per-destination "delivery" object (id, idempotency key, attempt) is added
 * at send time. Credentials, keys and raw configuration never enter it.
 *
 * Context keys: incident_id, firewall_id, customer_id, site_id, severity,
 * previous_severity, summary, details[], link_path, dedupe_key,
 * correlation_id, recurrence_of, origin_destination_id, forced_route_id, test.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/../server_identity.php';

if (!function_exists('integration_build_envelope')) {
    function integration_build_envelope(string $type, array $ctx, string $eventId, string $occurredUtc): array {
        $inc = null;
        if (!empty($ctx['incident_id'])) {
            $s = db()->prepare('SELECT * FROM alert_incidents WHERE id = ?');
            $s->execute([(int)$ctx['incident_id']]);
            $inc = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $fwId = $ctx['firewall_id'] ?? ($inc['firewall_id'] ?? null);
        $fw = null;
        if ($fwId) {
            $s = db()->prepare('SELECT id, hostname, customer_id, site_id, platform_family, platform_edition FROM firewalls WHERE id = ?');
            $s->execute([(int)$fwId]);
            $fw = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $custId = $ctx['customer_id'] ?? ($inc['customer_id'] ?? ($fw['customer_id'] ?? null));
        $siteId = $ctx['site_id'] ?? ($inc['site_id'] ?? ($fw['site_id'] ?? null));
        $name = function (string $table, $id) {
            if (!$id) { return null; }
            $s = db()->prepare("SELECT name FROM {$table} WHERE id = ?"); $s->execute([(int)$id]);
            $n = $s->fetchColumn();
            return $n === false ? null : integration_clean_text((string)$n, 120);
        };
        $tags = [];
        if ($fw) {
            $s = db()->prepare('SELECT t.name FROM firewall_tags ft JOIN tags t ON t.id = ft.tag_id WHERE ft.firewall_id = ? ORDER BY t.name');
            $s->execute([(int)$fw['id']]);
            $tags = array_map(fn($n) => integration_clean_text((string)$n, 64), $s->fetchAll(PDO::FETCH_COLUMN));
        }
        $severity = $ctx['severity'] ?? ($inc['severity'] ?? null);
        $summary = $ctx['summary'] ?? ($inc['title'] ?? (INTEGRATION_EVENT_TYPES[$type] ?? $type));
        $summary = integration_clean_text(integration_redact_string((string)$summary), 300);

        if (!empty($ctx['link_path']) && is_string($ctx['link_path']) && $ctx['link_path'][0] === '/' && !str_starts_with($ctx['link_path'], '//')) {
            $path = $ctx['link_path'];
        } elseif ($inc) {
            $path = '/incidents.php?incident=' . (int)$inc['id'];
        } else {
            $path = '/integrations.php';
        }
        $base = '';
        try { $base = opnmgr_server_url(); } catch (Throwable $e) { /* link stays relative */ }

        $env = [
            'schema' => 'opnmanager.event', 'version' => 1,
            'id' => $eventId, 'type' => $type,
            'occurred_at' => $occurredUtc,
            'correlation_id' => $ctx['correlation_id'] ?? ($inc ? 'incident-' . $inc['id'] : $eventId),
            'origin' => ['kind' => $ctx['origin_kind'] ?? 'local', 'destination_id' => $ctx['origin_destination_id'] ?? null],
            'customer' => $custId ? ['id' => (int)$custId, 'name' => $name('customers', $custId)] : null,
            'site' => $siteId ? ['id' => (int)$siteId, 'name' => $name('sites', $siteId)] : null,
            'firewall' => $fw ? ['id' => (int)$fw['id'], 'hostname' => integration_clean_text((string)$fw['hostname'], 128)] : null,
            'platform' => $fw ? ['family' => $fw['platform_family'], 'edition' => $fw['platform_edition']] : null,
            'tags' => $tags,
            'incident' => $inc ? [
                'id' => (int)$inc['id'], 'type' => $inc['alert_type'], 'object_key' => $inc['object_key'],
                'severity' => $severity, 'previous_severity' => $ctx['previous_severity'] ?? null,
                'status' => $inc['status'], 'first_seen_at' => $inc['first_seen_at'], 'occurrence_count' => (int)$inc['occurrence_count'],
                'recurrence_of' => $ctx['recurrence_of'] ?? null,
            ] : null,
            'severity' => $severity,
            'summary' => $summary,
            'details' => integration_scrub((array)($ctx['details'] ?? [])),
            'links' => ['manager' => $base !== '' ? rtrim($base, '/') . $path : $path],
        ];
        if (!empty($ctx['test'])) { $env['test'] = true; }
        return $env;
    }
}

if (!function_exists('integration_emit')) {
    /**
     * Record an event in the outbox. Never throws, never touches the network.
     *
     * @return string|null event id; with a dedupe_key already seen, the
     *                     original event's id (emission is idempotent)
     */
    function integration_emit(string $eventType, array $context): ?string {
        try {
            if (!isset(INTEGRATION_EVENT_TYPES[$eventType])) {
                error_log("OPNMGR integrations: unknown event type '{$eventType}'");
                return null;
            }
            // Inbound-sync origin set by integration_with_origin(): the event is
            // never delivered back to the destination that caused it.
            if (!isset($context['origin_destination_id']) && !empty($GLOBALS['OPNMGR_INTEGRATION_ORIGIN'])) {
                $context['origin_kind'] = $GLOBALS['OPNMGR_INTEGRATION_ORIGIN']['kind'];
                $context['origin_destination_id'] = $GLOBALS['OPNMGR_INTEGRATION_ORIGIN']['destination_id'];
            }
            $dedupe = isset($context['dedupe_key']) ? substr((string)$context['dedupe_key'], 0, 191) : null;
            if ($dedupe !== null) {
                $s = db()->prepare('SELECT event_id FROM integration_events WHERE dedupe_key = ?');
                $s->execute([$dedupe]);
                if ($existing = $s->fetchColumn()) { return (string)$existing; }
            }
            $eventId = integration_uuid();
            $utc = gmdate('Y-m-d\TH:i:s\Z');
            $env = integration_build_envelope($eventType, $context, $eventId, $utc);
            db()->prepare('INSERT INTO integration_events
                (event_id, event_type, event_version, dedupe_key, occurred_at, customer_id, site_id, firewall_id, incident_id,
                 severity, origin_kind, origin_destination_id, forced_route_id, correlation_id, envelope_json)
                VALUES (?,?,1,?,UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?)')
              ->execute([
                $eventId, $eventType, $dedupe,
                $env['customer']['id'] ?? null, $env['site']['id'] ?? null, $env['firewall']['id'] ?? null,
                $env['incident']['id'] ?? null, $env['severity'],
                $env['origin']['kind'], $env['origin']['destination_id'],
                $context['forced_route_id'] ?? null, $env['correlation_id'],
                json_encode($env, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            return $eventId;
        } catch (Throwable $e) {
            // A race on dedupe_key lands here; the earlier event stands.
            if (!empty($dedupe)) {
                try {
                    $s = db()->prepare('SELECT event_id FROM integration_events WHERE dedupe_key = ?');
                    $s->execute([$dedupe]);
                    if ($x = $s->fetchColumn()) { return (string)$x; }
                } catch (Throwable $e2) { /* fall through */ }
            }
            error_log('OPNMGR integrations: emit failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('integration_with_origin')) {
    /** Run $fn so that events emitted inside carry an inbound origin. */
    function integration_with_origin(string $kind, ?int $destinationId, callable $fn) {
        $prev = $GLOBALS['OPNMGR_INTEGRATION_ORIGIN'] ?? null;
        $GLOBALS['OPNMGR_INTEGRATION_ORIGIN'] = ['kind' => $kind, 'destination_id' => $destinationId];
        try { return $fn(); }
        finally {
            if ($prev === null) { unset($GLOBALS['OPNMGR_INTEGRATION_ORIGIN']); } else { $GLOBALS['OPNMGR_INTEGRATION_ORIGIN'] = $prev; }
        }
    }
}

if (!function_exists('integration_legacy_email_owner')) {
    /** Lightweight copy so the evaluator needs only this file: who owns incident email. */
    function integration_legacy_email_owner(): string {
        return integration_setting('integrations_legacy_email_owner', 'legacy') === 'integrations' ? 'integrations' : 'legacy';
    }
}
