<?php
/**
 * Integrations: routing rules, suppression gates and the routing preview.
 *
 * A route says "events matching X go to destination D". Routing happens when
 * the outbox is drained; the same function powers the preview so what the
 * operator sees is what the router does.
 *
 * Suppression keeps the semantics of the existing alert pipeline:
 *   - acknowledged / resolved incidents do not produce new alerting
 *     notifications (opened, reopened, severity change, escalation);
 *   - an active maintenance window that suppresses alerts withholds them too.
 *     Ticket destinations still receive updates for a ticket that already
 *     exists. Withheld events are recorded as 'suppressed' deliveries with the
 *     reason, never silently dropped;
 *   - acknowledgement / resolution messages go only to notification
 *     destinations that were told about the incident, so "resolved" never
 *     arrives without an "opened";
 *   - an event caused by inbound sync is never sent back to its origin.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/destinations.php';
require_once __DIR__ . '/../maintenance.php';

if (!defined('INTEGRATION_ALERTING_EVENTS')) {
    define('INTEGRATION_ALERTING_EVENTS', ['incident.opened', 'incident.reopened', 'incident.severity_changed', 'incident.escalated']);
    define('INTEGRATION_FOLLOWUP_EVENTS', ['incident.acknowledged', 'incident.resolved']);
}

if (!function_exists('integration_csv')) {
    function integration_csv(?string $v): array {
        return array_values(array_filter(array_map('trim', explode(',', (string)$v)), fn($x) => $x !== ''));
    }
}

if (!function_exists('integration_schedule_check')) {
    /**
     * Evaluate a route schedule at $ts.
     * schedule: {"timezone":"America/New_York","days":[1..7 (Mon=1)],"start":"08:00","end":"18:00"}
     * @return array{in:bool, next_start:?int}
     */
    function integration_schedule_check(?string $json, int $ts): array {
        $sch = $json ? json_decode($json, true) : null;
        if (!is_array($sch) || empty($sch['start']) || empty($sch['end'])) { return ['in' => true, 'next_start' => null]; }
        try { $tz = new DateTimeZone($sch['timezone'] ?? 'UTC'); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
        $days = array_map('intval', $sch['days'] ?? [1, 2, 3, 4, 5, 6, 7]);
        $toMin = fn($hm) => ((int)substr($hm, 0, 2)) * 60 + (int)substr($hm, 3, 2);
        $s = $toMin($sch['start']); $e = $toMin($sch['end']);
        $in = function (int $t) use ($tz, $days, $s, $e): bool {
            $d = (new DateTime('@' . $t))->setTimezone($tz);
            $m = (int)$d->format('G') * 60 + (int)$d->format('i');
            $dow = (int)$d->format('N');
            if ($s <= $e) { return in_array($dow, $days, true) && $m >= $s && $m < $e; }
            // Overnight window (e.g. 18:00-08:00): after start belongs to today, before end to yesterday's window.
            $prev = $dow === 1 ? 7 : $dow - 1;
            return ($m >= $s && in_array($dow, $days, true)) || ($m < $e && in_array($prev, $days, true));
        };
        if ($in($ts)) { return ['in' => true, 'next_start' => null]; }
        for ($t = $ts - ($ts % 60) + 60, $i = 0; $i < 7 * 24 * 60; $i++, $t += 60) {
            if ($in($t)) { return ['in' => false, 'next_start' => $t]; }
        }
        return ['in' => false, 'next_start' => null];
    }
}

if (!function_exists('integration_route_matches')) {
    /**
     * Pure filter match for a route against an event's facts.
     * @param array $ev event: event_type, customer_id, site_id, severity, forced_route_id
     * @param array $facts alert_type (?string), tag_ids (int[])
     * @return string|null null when it matches, else the reason it does not
     */
    function integration_route_matches(array $route, array $ev, array $facts): ?string {
        $isEscalationRoute = $route['escalate_after_minutes'] !== null && $route['escalate_after_minutes'] !== '';
        if ($ev['event_type'] === 'incident.escalated') {
            if (!$isEscalationRoute || (int)($ev['forced_route_id'] ?? 0) !== (int)$route['id']) { return 'escalation event is for a different route'; }
        } else {
            if ($isEscalationRoute) { return 'escalation-only route'; }
            $types = integration_csv($route['event_types']);
            if ($types && !in_array($ev['event_type'], $types, true)) { return 'event type not selected'; }
        }
        if ($route['customer_id'] !== null && (int)$route['customer_id'] !== (int)($ev['customer_id'] ?? 0)) { return 'different customer'; }
        if ($route['site_id'] !== null && (int)$route['site_id'] !== (int)($ev['site_id'] ?? 0)) { return 'different site'; }
        $sev = integration_csv($route['severities']);
        if ($sev && !in_array((string)($ev['severity'] ?? ''), $sev, true)) { return 'severity not selected'; }
        $types = integration_csv($route['incident_types']);
        if ($types && !in_array((string)($facts['alert_type'] ?? ''), $types, true)) { return 'incident type not selected'; }
        $tags = array_map('intval', integration_csv($route['tag_ids']));
        if ($tags && !array_intersect($tags, array_map('intval', $facts['tag_ids'] ?? []))) { return 'no matching device tag'; }
        return null;
    }
}

if (!function_exists('integration_event_facts_for_routing')) {
    function integration_event_facts_for_routing(array $ev): array {
        $facts = ['alert_type' => null, 'tag_ids' => [], 'incident' => null];
        if (!empty($ev['incident_id'])) {
            $s = db()->prepare('SELECT * FROM alert_incidents WHERE id = ?');
            $s->execute([(int)$ev['incident_id']]);
            $inc = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            $facts['incident'] = $inc;
            $facts['alert_type'] = $inc['alert_type'] ?? null;
        }
        if (!empty($ev['firewall_id'])) {
            $s = db()->prepare('SELECT tag_id FROM firewall_tags WHERE firewall_id = ?');
            $s->execute([(int)$ev['firewall_id']]);
            $facts['tag_ids'] = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }
        return $facts;
    }
}

if (!function_exists('integration_gate')) {
    /**
     * Suppression decision for one (event, destination).
     * @return string|null reason it is withheld, or null to deliver
     */
    function integration_gate(array $dest, array $ev, ?array $incident): ?string {
        if (!$dest['enabled']) { return 'destination disabled'; }
        if (!empty($ev['origin_destination_id']) && (int)$ev['origin_destination_id'] === (int)$dest['id']) {
            return 'origin: not echoed back to the system that caused it';
        }
        $type = $ev['event_type'];
        $isTicket = $dest['type'] === 'ticket_webhook';
        if (in_array($type, INTEGRATION_ALERTING_EVENTS, true) && $incident) {
            if ($incident['status'] === 'acknowledged') { return 'incident acknowledged'; }
            if ($incident['status'] === 'resolved') { return 'incident resolved'; }
            $fw = $incident['firewall_id'] !== null ? (int)$incident['firewall_id'] : null;
            if ($fw !== null && maintenance_suppresses_alerts($fw)) {
                $hasLink = $isTicket && integration_ticket_exists((int)$dest['id'], (int)$incident['id']);
                if (!$hasLink || $type === 'incident.opened' || $type === 'incident.reopened' || $type === 'incident.escalated') {
                    return 'maintenance window active';
                }
            }
        }
        if (in_array($type, INTEGRATION_FOLLOWUP_EVENTS, true) && !$isTicket && $incident) {
            $s = db()->prepare("SELECT 1 FROM integration_deliveries WHERE destination_id = ? AND incident_id = ?
                                  AND status = 'succeeded' AND event_type IN ('incident.opened','incident.reopened','incident.severity_changed','incident.escalated') LIMIT 1");
            $s->execute([(int)$dest['id'], (int)$incident['id']]);
            if (!$s->fetchColumn()) { return 'no prior notification for this incident at this destination'; }
        }
        return null;
    }
}

if (!function_exists('integration_ticket_exists')) {
    function integration_ticket_exists(int $destId, int $incidentId): bool {
        $s = db()->prepare("SELECT 1 FROM integration_ticket_links WHERE active_key = ? AND external_id IS NOT NULL");
        $s->execute([$destId . ':' . $incidentId]);
        return (bool)$s->fetchColumn();
    }
}

if (!function_exists('integration_routing_decisions')) {
    /**
     * What would happen to this event. One entry per destination (first
     * matching route wins), including non-matching routes for the preview.
     *
     * @param array $ev event facts (event_type, customer_id, site_id, firewall_id, incident_id, severity,
     *                  origin_destination_id, forced_route_id)
     * @return array[] each: route, destination, matched, reason, verdict (deliver|suppress|defer|skip), detail, defer_until
     */
    function integration_routing_decisions(array $ev, ?int $now = null, ?array $factsOverride = null): array {
        $now = $now ?? time();
        $facts = $factsOverride ?? integration_event_facts_for_routing($ev);
        $incident = $facts['incident'] ?? null;
        $out = [];
        $seen = [];
        $routes = db()->query('SELECT * FROM integration_routes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($routes as $r) {
            $row = ['route' => $r, 'destination' => null, 'matched' => false, 'reason' => '', 'verdict' => 'skip', 'detail' => '', 'defer_until' => null];
            $dest = integration_destination_load((int)$r['destination_id']);
            $row['destination'] = $dest;
            if (!$dest) { $row['reason'] = 'destination missing'; $out[] = $row; continue; }
            if (!$r['enabled']) { $row['reason'] = 'route disabled'; $out[] = $row; continue; }
            if ($dest['customer_id'] !== null && !empty($ev['customer_id']) && (int)$dest['customer_id'] !== (int)$ev['customer_id']) {
                // A customer-bound destination never receives another customer's events.
                $row['reason'] = 'destination belongs to a different customer'; $out[] = $row; continue;
            }
            if ($dest['customer_id'] !== null && empty($ev['customer_id'])) {
                $row['reason'] = 'destination is customer-bound; event has no customer'; $out[] = $row; continue;
            }
            $why = integration_route_matches($r, $ev, $facts);
            if ($why !== null) { $row['reason'] = $why; $out[] = $row; continue; }
            $row['matched'] = true;
            if (isset($seen[$dest['id']])) { $row['reason'] = 'destination already covered by an earlier route'; $out[] = $row; continue; }
            $seen[$dest['id']] = true;

            $sch = integration_schedule_check($r['schedule_json'], $now);
            if (!$sch['in']) {
                if ($r['schedule_outside'] === 'defer' && $sch['next_start']) {
                    $row['verdict'] = 'defer'; $row['defer_until'] = $sch['next_start'];
                    $row['detail'] = 'outside schedule; deferred to ' . gmdate('Y-m-d H:i', $sch['next_start']) . ' UTC';
                } else {
                    $row['verdict'] = 'suppress'; $row['detail'] = 'outside route schedule';
                }
                // Deferred deliveries are still gated at send time.
                $out[] = $row; continue;
            }
            $gate = integration_gate($dest, $ev, $incident);
            if ($gate !== null) { $row['verdict'] = 'suppress'; $row['detail'] = $gate; }
            else { $row['verdict'] = 'deliver'; $row['detail'] = 'will be sent'; }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('integration_route_save')) {
    function integration_route_save(array $in, ?int $id, string $actor): array {
        $name = trim((string)($in['name'] ?? ''));
        $destId = (int)($in['destination_id'] ?? 0);
        if ($name === '') { return ['ok' => false, 'error' => 'Name is required', 'id' => 0]; }
        if (!integration_destination_load($destId)) { return ['ok' => false, 'error' => 'Choose a destination', 'id' => 0]; }
        $evTypes = array_values(array_intersect((array)($in['event_types'] ?? []), array_keys(INTEGRATION_EVENT_TYPES)));
        $sevs = array_values(array_intersect((array)($in['severities'] ?? []), ['info', 'warning', 'critical']));
        $types = array_values(array_intersect(array_map('trim', integration_csv(is_array($in['incident_types'] ?? '') ? implode(',', $in['incident_types']) : (string)($in['incident_types'] ?? ''))),
                              array_keys(defined('ALERT_TYPES') ? ALERT_TYPES : [])));
        $tags = array_map('intval', (array)($in['tag_ids'] ?? []));
        $sched = null;
        if (!empty($in['sched_enabled'])) {
            $tz = (string)($in['sched_timezone'] ?? 'UTC');
            try { new DateTimeZone($tz); } catch (Throwable $e) { return ['ok' => false, 'error' => 'Unknown timezone', 'id' => 0]; }
            if (!preg_match('/^\d\d:\d\d$/', (string)($in['sched_start'] ?? '')) || !preg_match('/^\d\d:\d\d$/', (string)($in['sched_end'] ?? ''))) {
                return ['ok' => false, 'error' => 'Schedule times must be HH:MM', 'id' => 0];
            }
            $sched = json_encode(['timezone' => $tz, 'days' => array_values(array_filter(array_map('intval', (array)($in['sched_days'] ?? [1, 2, 3, 4, 5])), fn($d) => $d >= 1 && $d <= 7)),
                                  'start' => $in['sched_start'], 'end' => $in['sched_end']]);
        }
        $esc = ($in['escalate_after_minutes'] ?? '') === '' ? null : max(1, min(10080, (int)$in['escalate_after_minutes']));
        $cust = ($in['customer_id'] ?? '') === '' ? null : (int)$in['customer_id'];
        $site = ($in['site_id'] ?? '') === '' ? null : (int)$in['site_id'];
        $vals = [$name, empty($in['disabled']) ? 1 : 0, $destId, implode(',', $evTypes), $cust, $site, implode(',', $sevs),
                 implode(',', $types), implode(',', $tags), $sched, ($in['schedule_outside'] ?? 'skip') === 'defer' ? 'defer' : 'skip', $esc];
        if ($id) {
            db()->prepare('UPDATE integration_routes SET name=?, enabled=?, destination_id=?, event_types=?, customer_id=?, site_id=?, severities=?,
                incident_types=?, tag_ids=?, schedule_json=?, schedule_outside=?, escalate_after_minutes=? WHERE id=?')->execute(array_merge($vals, [$id]));
        } else {
            db()->prepare('INSERT INTO integration_routes (name, enabled, destination_id, event_types, customer_id, site_id, severities,
                incident_types, tag_ids, schedule_json, schedule_outside, escalate_after_minutes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge($vals, [$actor]));
            $id = (int)db()->lastInsertId();
        }
        audit_log('integration.route_saved', ['object_type' => 'integration_route', 'object_id' => (string)$id, 'customer_id' => $cust,
            'message' => "Route '{$name}' saved"]);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }
}
