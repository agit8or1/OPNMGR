#!/usr/bin/env php
<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Alert evaluator.
 *
 * Runs every few minutes. For each condition it asserts the current truth:
 * alert_raise() while the condition holds, alert_resolve() when it clears.
 * Both are idempotent, so running this more often produces the same incidents,
 * not more of them.
 *
 * This is the only place that decides whether something is wrong. Notification
 * is a separate pass at the end, so suppression during maintenance never loses
 * the detection.
 *
 * Usage:
 *   php cron/evaluate_alerts.php [--dry-run] [--verbose]
 *
 * @since 3.15.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/alerting.php';
require_once __DIR__ . '/../inc/maintenance.php';
require_once __DIR__ . '/../inc/firewall_health.php';
require_once __DIR__ . '/../inc/alerts.php';
require_once __DIR__ . '/../inc/config_restore.php';

$dryRun  = in_array('--dry-run', $argv, true);
$verbose = in_array('--verbose', $argv, true) || $dryRun;

$raised = 0;
$resolved = 0;

function say(string $msg): void {
    global $verbose;
    if ($verbose) {
        echo date('Y-m-d H:i:s') . '  ' . $msg . "\n";
    }
}

/** Raise unless this is a dry run. */
function raise(string $type, array $opts): void {
    global $dryRun, $raised;
    $raised++;
    say(sprintf('RAISE  %-22s fw=%s %s', $type, $opts['firewall_id'] ?? '-', $opts['title'] ?? ''));
    if (!$dryRun) {
        alert_raise($type, $opts);
    }
}

/** Resolve unless this is a dry run. */
function resolve(string $type, ?int $fwId, ?string $key = null, string $reason = 'condition cleared'): void {
    global $dryRun, $resolved;
    if ($dryRun) {
        return;
    }
    if (alert_resolve($type, $fwId, $key, $reason)) {
        $resolved++;
        say(sprintf('RESOLVE %-21s fw=%s %s', $type, $fwId ?? '-', $key ?? ''));
    }
}

say('Alert evaluation starting' . ($dryRun ? ' [dry run]' : ''));

// Keep window statuses accurate before anything consults them, and drop any
// cached lookups from a previous pass in this process.
maintenance_reset_cache();
$mw = maintenance_refresh_statuses();
if ($mw['activated'] || $mw['completed']) {
    say("Maintenance windows: {$mw['activated']} activated, {$mw['completed']} completed");
}

// Advance any in-flight restores before evaluating health, so a firewall that
// has just come back is not also reported as offline.
$rr = restore_reconcile();
if ($rr['verified'] || $rr['failed']) {
    say(sprintf('Restores: %d verified, %d failed verification', $rr['verified'], $rr['failed']));
}

$thresholds = health_thresholds();
$cpuLimit   = (float)alert_setting('alert_cpu_threshold', 90);
$memLimit   = (float)alert_setting('alert_memory_threshold', 90);
$diskLimit  = (float)alert_setting('alert_disk_threshold', 90);
$sustained  = (int)alert_setting('alert_sustained_minutes', 15);
$minAgent   = (string)alert_setting('alert_agent_min_version', '1.5.0');

$firewalls = db()->query(
    'SELECT id, hostname, status, last_checkin, alerts_enabled, offline_alert_threshold,
            agent_version, agent_auth_failures, carp_enabled, carp_state,
            last_backup_status, last_backup_error,
            TIMESTAMPDIFF(SECOND, last_checkin, NOW()) AS seconds_since_checkin
       FROM firewalls'
)->fetchAll(PDO::FETCH_ASSOC);

say('Evaluating ' . count($firewalls) . ' firewall(s)');

foreach ($firewalls as $fw) {
    $id = (int)$fw['id'];
    $host = $fw['hostname'];

    // A firewall with alerting switched off is still evaluated for display
    // elsewhere, but produces no incidents.
    if ((int)$fw['alerts_enabled'] !== 1) {
        continue;
    }

    // --- offline ------------------------------------------------------------
    $threshold = max(60, (int)$fw['offline_alert_threshold']);
    $silent    = $fw['last_checkin'] === null ? null : (int)$fw['seconds_since_checkin'];

    if ($silent !== null && $silent > $threshold) {
        raise('firewall.offline', [
            'firewall_id' => $id,
            'title'       => sprintf('%s has not checked in for %d minutes', $host, (int)round($silent / 60)),
            'detail'      => sprintf('Last check-in %s. Alert threshold is %d minutes.',
                                     $fw['last_checkin'], (int)round($threshold / 60)),
            'metadata'    => ['seconds_since_checkin' => $silent],
        ]);
    } else {
        resolve('firewall.offline', $id, null, 'firewall is checking in again');
    }

    // Everything below depends on the agent having reported recently; stale
    // health from an offline firewall should not generate its own incidents.
    $stale = $silent === null || $silent > 3600;

    // --- gateways -----------------------------------------------------------
    $gws = db()->prepare('SELECT * FROM firewall_gateways WHERE firewall_id = ?');
    $gws->execute([$id]);
    foreach ($gws->fetchAll(PDO::FETCH_ASSOC) as $gw) {
        $name = $gw['name'];
        $sev  = health_gateway_severity($gw);

        if (!$stale && $sev === 'critical') {
            raise('gateway.down', [
                'firewall_id' => $id, 'object_key' => $name,
                'title'  => sprintf('Gateway %s is down on %s', $name, $host),
                'detail' => sprintf('Status %s, loss %s%%', $gw['status'], $gw['loss_percent'] ?? '?'),
            ]);
            resolve('gateway.degraded', $id, $name, 'superseded by gateway down');
        } elseif (!$stale && $sev === 'warning') {
            raise('gateway.degraded', [
                'firewall_id' => $id, 'object_key' => $name,
                'title'  => sprintf('Gateway %s is degraded on %s', $name, $host),
                'detail' => sprintf('Latency %s ms, loss %s%% (thresholds %s ms / %s%%)',
                                    $gw['latency_ms'] ?? '?', $gw['loss_percent'] ?? '?',
                                    $thresholds['gw_latency'], $thresholds['gw_loss']),
            ]);
            resolve('gateway.down', $id, $name, 'gateway is no longer down');
        } else {
            resolve('gateway.down', $id, $name, 'gateway recovered');
            resolve('gateway.degraded', $id, $name, 'gateway recovered');
        }
    }

    // --- VPN ----------------------------------------------------------------
    $vpns = db()->prepare('SELECT * FROM firewall_vpn_tunnels WHERE firewall_id = ? AND enabled = 1');
    $vpns->execute([$id]);
    foreach ($vpns->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $key = $t['vpn_type'] . '/' . $t['name'];
        $up  = in_array(strtolower((string)$t['status']), ['up', 'connected'], true);

        if (!$stale && !$up) {
            raise('vpn.down', [
                'firewall_id' => $id, 'object_key' => $key,
                'title'  => sprintf('%s tunnel %s is down on %s', ucfirst($t['vpn_type']), $t['name'], $host),
                'detail' => sprintf('Status %s. Peer %s.', $t['status'], $t['peer'] ?: 'unknown'),
            ]);
        } else {
            resolve('vpn.down', $id, $key, 'tunnel is up');
        }
    }

    // --- CARP ---------------------------------------------------------------
    if (!$stale && (int)$fw['carp_enabled'] === 1 && $fw['carp_state'] === 'INIT') {
        raise('carp.fault', [
            'firewall_id' => $id,
            'title'  => sprintf('CARP on %s is not settled', $host),
            'detail' => 'One or more virtual IPs are in INIT rather than MASTER or BACKUP.',
        ]);
    } else {
        resolve('carp.fault', $id, null, 'CARP has settled');
    }

    // --- services -----------------------------------------------------------
    $svcs = db()->prepare('SELECT * FROM firewall_services WHERE firewall_id = ? AND enabled = 1');
    $svcs->execute([$id]);
    foreach ($svcs->fetchAll(PDO::FETCH_ASSOC) as $svc) {
        if (!$stale && (int)$svc['running'] === 0) {
            raise('service.stopped', [
                'firewall_id' => $id, 'object_key' => $svc['name'],
                'title'  => sprintf('Service %s is stopped on %s', $svc['name'], $host),
                'detail' => $svc['description'] ?: null,
            ]);
        } else {
            resolve('service.stopped', $id, $svc['name'], 'service is running');
        }
    }

    // --- certificates -------------------------------------------------------
    $certs = db()->prepare(
        'SELECT * FROM firewall_certificates WHERE firewall_id = ? AND days_remaining IS NOT NULL'
    );
    $certs->execute([$id]);
    foreach ($certs->fetchAll(PDO::FETCH_ASSOC) as $cert) {
        $days = (int)$cert['days_remaining'];
        $name = $cert['name'] ?: $cert['refid'];

        if ($days < 0) {
            raise('cert.expired', [
                'firewall_id' => $id, 'object_key' => $cert['refid'],
                'title'  => sprintf('Certificate %s on %s expired %d days ago', $name, $host, abs($days)),
                'detail' => sprintf('Issuer %s, expired %s', $cert['issuer'] ?: 'unknown', $cert['not_after']),
            ]);
            resolve('cert.expiring', $id, $cert['refid'], 'certificate has now expired');
        } elseif ($days <= $thresholds['cert_medium']) {
            raise('cert.expiring', [
                'firewall_id' => $id, 'object_key' => $cert['refid'],
                'severity' => $days <= $thresholds['cert_critical'] ? 'critical' : 'warning',
                'title'  => sprintf('Certificate %s on %s expires in %d days', $name, $host, $days),
                'detail' => sprintf('Issuer %s, expires %s', $cert['issuer'] ?: 'unknown', $cert['not_after']),
            ]);
        } else {
            resolve('cert.expiring', $id, $cert['refid'], 'certificate renewed or no longer near expiry');
            resolve('cert.expired', $id, $cert['refid'], 'certificate renewed');
        }
    }

    // --- sustained resource pressure ----------------------------------------
    // "Sustained" matters: a single spike during a backup is not an incident.
    $stats = db()->prepare(
        'SELECT AVG(memory_percent) AS mem, AVG(disk_percent) AS disk,
                AVG(cpu_load_5min) AS cpu, COUNT(*) AS samples
           FROM firewall_system_stats
          WHERE firewall_id = ? AND recorded_at >= (NOW() - INTERVAL ? MINUTE)'
    );
    $stats->execute([$id, $sustained]);
    $s = $stats->fetch(PDO::FETCH_ASSOC);

    if ($s && (int)$s['samples'] >= 2) {
        foreach ([
            ['memory.high', (float)$s['mem'],  $memLimit,  'Memory'],
            ['disk.high',   (float)$s['disk'], $diskLimit, 'Disk'],
        ] as [$type, $value, $limit, $label]) {
            if ($value >= $limit) {
                raise($type, [
                    'firewall_id' => $id,
                    'title'  => sprintf('%s on %s averaged %.0f%% over %d minutes', $label, $host, $value, $sustained),
                    'detail' => sprintf('Threshold %.0f%%, %d samples.', $limit, (int)$s['samples']),
                ]);
            } else {
                resolve($type, $id, null, 'usage back below threshold');
            }
        }

        if ($s['cpu'] !== null && (float)$s['cpu'] >= $cpuLimit) {
            raise('cpu.high', [
                'firewall_id' => $id,
                'title'  => sprintf('CPU load on %s averaged %.2f over %d minutes', $host, (float)$s['cpu'], $sustained),
                'detail' => sprintf('Threshold %.0f.', $cpuLimit),
            ]);
        } else {
            resolve('cpu.high', $id, null, 'CPU load back below threshold');
        }
    }

    // --- backup failure -----------------------------------------------------
    if ($fw['last_backup_status'] === 'failed') {
        raise('backup.failed', [
            'firewall_id' => $id,
            'title'  => sprintf('Configuration backup failed on %s', $host),
            'detail' => $fw['last_backup_error'] ?: null,
        ]);
    } else {
        resolve('backup.failed', $id, null, 'a backup has since succeeded');
    }

    // --- agent version ------------------------------------------------------
    if (!empty($fw['agent_version']) && version_compare($fw['agent_version'], $minAgent, '<')) {
        raise('agent.outdated', [
            'firewall_id' => $id,
            'title'  => sprintf('%s is running agent %s (minimum %s)', $host, $fw['agent_version'], $minAgent),
        ]);
    } else {
        resolve('agent.outdated', $id, null, 'agent has been updated');
    }

    // --- repeated authentication failures -----------------------------------
    // Someone attempting to impersonate an agent is a security event, not an
    // operational one.
    if ((int)$fw['agent_auth_failures'] >= 5) {
        raise('agent.auth_failures', [
            'firewall_id' => $id,
            'title'  => sprintf('%d consecutive agent authentication failures for %s',
                                (int)$fw['agent_auth_failures'], $host),
            'detail' => 'Repeated failures can indicate a misconfigured agent or an impersonation attempt.',
        ]);
    } else {
        resolve('agent.auth_failures', $id, null, 'agent authenticated successfully');
    }
}

// --- configuration drift ------------------------------------------------------
$drift = db()->query(
    'SELECT d.*, f.hostname FROM config_drift d
       JOIN firewalls f ON f.id = d.firewall_id
      WHERE f.alerts_enabled = 1'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($drift as $d) {
    $id = (int)$d['firewall_id'];
    if ($d['status'] === 'drifted' && $d['acknowledged_at'] === null) {
        $sections = json_decode((string)$d['sections_changed'], true) ?: [];
        $names = array_merge($sections['modified'] ?? [], $sections['added'] ?? [], $sections['removed'] ?? []);
        raise('config.drift', [
            'firewall_id' => $id,
            'title'  => sprintf('Configuration on %s differs from its baseline', $d['hostname']),
            'detail' => $names ? 'Sections changed: ' . implode(', ', array_slice($names, 0, 8)) : null,
            'metadata' => ['sections' => $names],
        ]);
    } else {
        resolve('config.drift', $id, null,
                $d['status'] === 'match' ? 'configuration matches the baseline' : 'drift acknowledged');
    }
}

// --- gateway flapping ---------------------------------------------------------
foreach (health_gateway_flapping() as $flap) {
    raise('gateway.flapping', [
        'firewall_id' => (int)$flap['firewall_id'],
        'object_key'  => $flap['gateway_name'],
        'title'  => sprintf('Gateway %s on %s changed state %d times in 24 hours',
                            $flap['gateway_name'], $flap['hostname'], (int)$flap['transitions']),
        'detail' => 'A flapping gateway is often more disruptive than one cleanly down.',
    ]);
}

say(sprintf('Detection complete: %d raised/updated, %d resolved', $raised, $resolved));

// ---------------------------------------------------------------------------
// Notification pass
// ---------------------------------------------------------------------------
if ($dryRun) {
    say('Dry run: skipping notification');
    exit(0);
}

$notified = 0;
$suppressed = 0;

$open = db()->query(
    'SELECT i.*, f.hostname FROM alert_incidents i
       LEFT JOIN firewalls f ON f.id = i.firewall_id
      WHERE i.status = "open"'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($open as $incident) {
    $decision = alert_should_notify($incident);

    if (!$decision['notify']) {
        // Only maintenance counts as suppression worth recording; a backoff
        // window is normal pacing, not something being withheld.
        if ($decision['reason'] === 'maintenance') {
            alert_mark_suppressed((int)$incident['id'], 'maintenance window active');
            $suppressed++;
        }
        continue;
    }

    $subject = sprintf('[%s] %s', strtoupper($incident['severity']), $incident['title']);
    $body    = generate_alert_email_html(
        $incident['severity'],
        ALERT_TYPES[$incident['alert_type']]['label'] ?? 'Alert',
        nl2br(htmlspecialchars((string)($incident['detail'] ?? $incident['title']))),
        $incident['hostname'] ?? '',
        array_filter([
            'Firewall'   => $incident['hostname'] ?? null,
            'Alert type' => $incident['alert_type'],
            'Severity'   => strtoupper($incident['severity']),
            'First seen' => $incident['first_seen_at'],
            'Occurrences'=> $incident['occurrence_count'],
        ])
    );

    try {
        $result = send_email_alert(
            $incident['severity'],
            $incident['alert_type'],
            $subject,
            $body,
            $incident['firewall_id'] !== null ? (int)$incident['firewall_id'] : null
        );
        if (!empty($result['success'])) {
            alert_mark_notified((int)$incident['id'], $decision['reason']);
            $notified++;
        } else {
            say('Notification failed for incident ' . $incident['id'] . ': '
                . implode(', ', $result['errors'] ?? []));
        }
    } catch (Throwable $e) {
        error_log('OPNMGR: notification failed for incident ' . $incident['id'] . ': ' . $e->getMessage());
    }
}

say(sprintf('Notification complete: %d sent, %d suppressed by maintenance', $notified, $suppressed));
exit(0);
