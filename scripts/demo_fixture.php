#!/usr/bin/env php
<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Populate an isolated demo database with fictitious fleet data.
 *
 * This exists so the screenshots in docs/images/github/ can be regenerated
 * without ever pointing a camera at a real customer's fleet. It writes only
 * to a database whose name ends in `_demo`, and only when the environment
 * declares OPNMGR_DEMO=1.
 *
 * What it deliberately does NOT do: it never inserts into `firewall_commands`,
 * `agent_commands`, `request_queue`, `update_campaign_targets.command_id` or
 * `firewall_ssh_keys`. A demo fleet therefore has no path to issuing an
 * instruction to anything, because nothing ever checks in to collect one.
 * Campaign targets are seeded in terminal states only.
 *
 * Every address is from a range reserved for documentation (RFC 5737
 * 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24 and RFC 3849 2001:db8::/32)
 * and every hostname is under a reserved `.example` domain (RFC 6761).
 *
 * Usage:
 *
 *     OPNMGR_DEMO=1 php scripts/demo_fixture.php
 *
 * @since 3.22.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------------------------
// Guards. Each one must pass before a single row is written.
// ---------------------------------------------------------------------------

$demoFlag = getenv('OPNMGR_DEMO') ?: ($_ENV['OPNMGR_DEMO'] ?? '');
if ($demoFlag !== '1') {
    exit("Refusing to run: OPNMGR_DEMO is not 1.\n"
        . "This script only ever populates a throwaway demo database.\n");
}

if (!preg_match('/_demo$/', DB_NAME)) {
    exit("Refusing to run: DB_NAME is '" . DB_NAME . "', which does not end in '_demo'.\n"
        . "This script will not write to an installation database.\n");
}

try {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    exit("Database connection failed: {$e->getMessage()}\n");
}

// A demo database must not contain agent credentials. If it does, it is not a
// demo database and we are pointed at the wrong place.
$credentialled = (int) $db->query(
    "SELECT COUNT(*) FROM firewalls
      WHERE agent_api_key IS NOT NULL OR agent_api_secret IS NOT NULL
         OR ssh_private_key IS NOT NULL"
)->fetchColumn();
if ($credentialled > 0) {
    exit("Refusing to run: this database holds firewall credentials. It is not a demo database.\n");
}

echo "Seeding demo fixture into " . DB_NAME . "\n\n";

// ---------------------------------------------------------------------------
// Clear anything a previous run left behind, so the fixture is reproducible.
// ---------------------------------------------------------------------------

$tables = [
    'alert_incident_events', 'alert_incidents', 'config_drift', 'config_baselines',
    'backups', 'update_campaign_targets', 'update_campaigns', 'maintenance_windows',
    'firewall_gateways', 'firewall_vpn_tunnels', 'firewall_services',
    'firewall_certificates', 'firewall_carp', 'firewall_wan_interfaces',
    'firewall_system_stats', 'firewall_traffic_stats', 'firewall_latency',
    'firewall_speedtest', 'bandwidth_tests',
    'audit_log', 'firewall_tags', 'tags', 'alert_history', 'alert_triggers',
    'firewall_agents', 'firewalls', 'sites', 'customers',
];
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $t) {
    $db->exec("TRUNCATE TABLE `$t`");
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

$now = new DateTimeImmutable('now');
$ts  = static fn(string $mod): string => (new DateTimeImmutable($mod))->format('Y-m-d H:i:s');

// ---------------------------------------------------------------------------
// Customers. Organisational groupings, not accounts - none of these log in.
// ---------------------------------------------------------------------------

$customers = [
    ['Northwind Logistics',  'NWL', 'America/Chicago',  'logistics,priority', 'Dana Whitfield', 'ops@northwind.example'],
    ['Cascade Health Group', 'CHG', 'America/Denver',   'healthcare,hipaa',   'Rowan Vega',     'it@cascadehealth.example'],
    ['Harbor Point Legal',   'HPL', 'America/New_York', 'legal',              'Sam Okafor',     'admin@harborpoint.example'],
    ['Verdant Manufacturing','VDM', 'America/Los_Angeles', 'industrial,ot',   'Alex Brennan',   'noc@verdantmfg.example'],
    ['Brightline Schools',   'BLS', 'America/New_York', 'education,k12',      'Jordan Reyes',   'tech@brightline.example'],
];

$insCustomer = $db->prepare(
    'INSERT INTO customers (name, code, contact_person, email, phone, timezone, tags,
                            is_active, maintenance_window_start, maintenance_window_end,
                            maintenance_window_days, notes)
     VALUES (?,?,?,?,?,?,?,1,?,?,?,?)'
);
$customerIds = [];
foreach ($customers as $i => $c) {
    $insCustomer->execute([
        $c[0], $c[1], $c[4], $c[5], '+1 555 0100',
        $c[2], $c[3],
        '02:00:00', '05:00:00', '0,6',
        'Demo fixture data. Fictitious organisation.',
    ]);
    $customerIds[$c[1]] = (int) $db->lastInsertId();
}
echo "  customers          " . count($customerIds) . "\n";

// ---------------------------------------------------------------------------
// Sites.
// ---------------------------------------------------------------------------

$sites = [
    ['NWL', 'Chicago DC',        'CHI'], ['NWL', 'Memphis Hub',     'MEM'],
    ['NWL', 'Dallas Crossdock',  'DAL'],
    ['CHG', 'Denver Clinic',     'DEN'], ['CHG', 'Boulder Clinic',  'BLD'],
    ['HPL', 'Manhattan Office',  'NYC'],
    ['VDM', 'Fremont Plant',     'FRE'], ['VDM', 'Tacoma Plant',    'TAC'],
    ['BLS', 'District Office',   'DIS'], ['BLS', 'North Campus',    'NOR'],
];

$insSite = $db->prepare(
    'INSERT INTO sites (customer_id, name, code, timezone, is_active, notes)
     VALUES (?,?,?,?,1,?)'
);
$siteIds = [];
foreach ($sites as $s) {
    $insSite->execute([
        $customerIds[$s[0]], $s[1], $s[2], null,
        'Demo fixture data. Fictitious site.',
    ]);
    $siteIds[$s[0] . '/' . $s[2]] = (int) $db->lastInsertId();
}
echo "  sites              " . count($siteIds) . "\n";

// ---------------------------------------------------------------------------
// Firewalls. Reserved documentation addresses and .example hostnames only.
//
// [hostname, cust, site, wan_ip, lan_net, version, agent, status,
//  seconds since check-in, updates, reboot, ring, carp_state]
//
// Check-in ages are in seconds, and the online ones are well inside the
// five-minute window the dashboard treats as live, so a capture run started
// straight after seeding does not show a fleet that has gone stale.
// ---------------------------------------------------------------------------

$fleet = [
    ['fw-chi-edge01.northwind.example',  'NWL','CHI','192.0.2.11',  '10.20.0.0/24','26.7.2','1.6.2','online',   25, 0,0,'production','MASTER'],
    ['fw-chi-edge02.northwind.example',  'NWL','CHI','192.0.2.12',  '10.20.0.0/24','26.7.2','1.6.2','online',   40, 0,0,'production','BACKUP'],
    ['fw-mem-edge01.northwind.example',  'NWL','MEM','192.0.2.21',  '10.21.0.0/24','26.7.2','1.6.2','online',   30, 1,0,'pilot',     null],
    ['fw-dal-edge01.northwind.example',  'NWL','DAL','192.0.2.31',  '10.22.0.0/24','26.1.9','1.6.1','online',   55, 1,1,'production',null],
    ['fw-den-edge01.cascadehealth.example','CHG','DEN','198.51.100.11','10.30.0.0/24','26.7.2','1.6.2','online', 20, 0,0,'canary',    null],
    ['fw-bld-edge01.cascadehealth.example','CHG','BLD','198.51.100.21','10.31.0.0/24','26.7.2','1.6.2','online', 45, 0,0,'production',null],
    ['fw-nyc-edge01.harborpoint.example','HPL','NYC','198.51.100.31','10.40.0.0/24','26.7.2','1.6.2','online',   35, 0,0,'production',null],
    ['fw-fre-edge01.verdantmfg.example', 'VDM','FRE','203.0.113.11','10.50.0.0/24','26.7.2','1.6.2','online',   50, 0,0,'production',null],
    ['fw-tac-edge01.verdantmfg.example', 'VDM','TAC','203.0.113.21','10.51.0.0/24','25.7.11','1.5.6','offline', 2820, 1,0,'production',null],
    ['fw-dis-edge01.brightline.example', 'BLS','DIS','203.0.113.31','10.60.0.0/24','26.7.2','1.6.2','online',   28, 0,0,'production',null],
    ['fw-nor-edge01.brightline.example', 'BLS','NOR','203.0.113.41','10.61.0.0/24','26.7.2','1.6.2','online',   60, 1,0,'pilot',     null],
];

$insFw = $db->prepare(
    'INSERT INTO firewalls
        (hostname, hardware_id, uuid, ip_address, wan_ip, lan_ip, lan_network, ipv6_address,
         customer_id, site_id, customer_name, customer_group, status, last_checkin,
         checkin_interval, opnsense_version, current_version, version, available_version,
         agent_version, updates_available, reboot_required, update_ring, carp_enabled,
         carp_state, wan_interfaces, wan_gateway, uptime, enrolled_at, alerts_enabled,
         api_key_confirmed, agent_signing_supported, last_backup_at, last_backup_status,
         onboarded, web_port, notes)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,1,?,?,1,443,?)'
);

$fwIds = [];
foreach ($fleet as $i => $f) {
    [$host,$cc,$sc,$wan,$lanNet,$ver,$agent,$status,$secs,$upd,$reboot,$ring,$carp] = $f;

    $lanIp   = preg_replace('/\.0\/24$/', '.1', $lanNet);
    $custId  = $customerIds[$cc];
    $siteId  = $siteIds[$cc . '/' . $sc];
    $custRow = array_values(array_filter($customers, static fn($c) => $c[1] === $cc))[0];
    $siteRow = array_values(array_filter($sites, static fn($s) => $s[0] === $cc && $s[2] === $sc))[0];

    $insFw->execute([
        $host,
        substr(hash('md5', 'demo-' . $host), 0, 32),
        sprintf('00000000-0000-4000-8000-%012d', $i + 1),
        $wan, $wan, $lanIp, $lanNet, '2001:db8:' . dechex(1000 + $i) . '::1',
        $custId, $siteId, $custRow[0], $siteRow[1],
        $status,
        $ts("-{$secs} seconds"),
        120,
        $ver, $ver, $ver,
        $upd ? '26.7.3' : $ver,
        $agent, $upd, $reboot, $ring,
        $carp !== null ? 1 : 0, $carp,
        'igc0', preg_replace('/\.\d+$/', '.1', $wan),
        ($status === 'online' ? (14 + $i) . ' days, ' . (2 + $i) . ':1' . $i : '0'),
        $ts('-' . (90 + $i * 3) . ' days'),
        $ts('-' . (6 + ($i % 5)) . ' hours'),
        'success',
        'Demo fixture data. Fictitious firewall; no agent is enrolled against this record.',
    ]);
    $fwIds[$host] = (int) $db->lastInsertId();
}
echo "  firewalls          " . count($fwIds) . "\n";

// Pair the Chicago CARP members with each other.
$a = $fwIds['fw-chi-edge01.northwind.example'];
$b = $fwIds['fw-chi-edge02.northwind.example'];
$db->prepare('UPDATE firewalls SET ha_peer_firewall_id = ?, carp_peer_host = ?, carp_sync_status = ? WHERE id = ?')
   ->execute([$b, 'fw-chi-edge02.northwind.example', 'in sync', $a]);
$db->prepare('UPDATE firewalls SET ha_peer_firewall_id = ?, carp_peer_host = ?, carp_sync_status = ? WHERE id = ?')
   ->execute([$a, 'fw-chi-edge01.northwind.example', 'in sync', $b]);

$ids = array_values($fwIds);

// ---------------------------------------------------------------------------
// Agent registration rows. firewalls.php and the dashboard read check-in state
// from here first and fall back to firewalls.last_checkin, so without these the
// fleet list renders every device as "Never" checked in.
// ---------------------------------------------------------------------------

$insAgent = $db->prepare(
    'INSERT INTO firewall_agents
        (firewall_id, agent_version, agent_type, last_checkin, status, latency_ms,
         wan_ip, lan_ip, lan_gateway, ipv6_address, opnsense_version)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ($fleet as $n => $f) {
    [$host,$cc,$sc,$wan,$lanNet,$ver,$agent,$status,$secs] = $f;
    $lanIp = preg_replace('/\.0\/24$/', '.1', $lanNet);
    $insAgent->execute([
        $ids[$n], $agent, 'primary', $ts("-{$secs} seconds"), $status,
        round(8 + ($n * 3) % 22 + 0.4, 2),
        $wan, $lanIp, $lanIp, '2001:db8:' . dechex(1000 + $n) . '::1', $ver,
    ]);
}
echo "  agent registrations " . count($fleet) . "\n";

// ---------------------------------------------------------------------------
// Health telemetry: gateways, VPN tunnels, services, certificates, CARP.
// ---------------------------------------------------------------------------

$insGw = $db->prepare(
    'INSERT INTO firewall_gateways
        (firewall_id, name, interface, address, monitor, status, latency_ms,
         stddev_ms, loss_percent, is_default, gateway_group, priority)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ($ids as $n => $fid) {
    $base = 8 + ($n * 3) % 22;
    $insGw->execute([$fid, 'WAN_DHCP', 'igc0', '192.0.2.1', '192.0.2.1', 'online',
        $base + 0.4, 1.1, 0.0, 1, 'WAN_GROUP', 255]);
    // One site is on a degraded backup link; one has a down secondary.
    if ($n === 3) {
        $insGw->execute([$fid, 'WAN2_LTE', 'igc1', '192.0.2.2', '192.0.2.2', 'delay',
            148.7, 24.6, 2.4, 0, 'WAN_GROUP', 254]);
    } elseif ($n === 8) {
        $insGw->execute([$fid, 'WAN2_LTE', 'igc1', '192.0.2.2', '192.0.2.2', 'down',
            null, null, 100.0, 0, 'WAN_GROUP', 254]);
    } elseif ($n % 3 === 0) {
        $insGw->execute([$fid, 'WAN2_FIBER', 'igc1', '192.0.2.2', '192.0.2.2', 'online',
            $base + 4.2, 0.9, 0.0, 0, 'WAN_GROUP', 254]);
    }
}

$insVpn = $db->prepare(
    'INSERT INTO firewall_vpn_tunnels
        (firewall_id, vpn_type, name, peer, endpoint, status, enabled,
         latest_handshake, connected_since, rx_bytes, tx_bytes)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ($ids as $n => $fid) {
    $insVpn->execute([$fid, 'wireguard', 'wg-hub', 'hub-' . $n, 'vpn.hq.example:51820',
        $n === 8 ? 'down' : 'up', 1,
        $n === 8 ? $ts('-3 hours') : $ts('-2 minutes'),
        $ts('-' . (5 + $n) . ' days'),
        1_200_000_000 + $n * 91_000_000, 840_000_000 + $n * 63_000_000]);
    if ($n % 2 === 0) {
        $insVpn->execute([$fid, 'ipsec', 'ipsec-partner', 'partner-gw',
            'gw.partner.example', 'up', 1, null, $ts('-' . (2 + $n) . ' days'),
            310_000_000, 270_000_000]);
    }
}

$insSvc = $db->prepare(
    'INSERT INTO firewall_services (firewall_id, name, description, running, enabled)
     VALUES (?,?,?,?,?)'
);
$services = [
    ['unbound', 'Unbound DNS'], ['dpinger', 'Gateway monitoring'],
    ['openssh', 'Secure Shell'], ['ntpd', 'Network Time'],
    ['suricata', 'Intrusion Detection'], ['wireguard', 'WireGuard VPN'],
];
foreach ($ids as $n => $fid) {
    foreach ($services as $si => $s) {
        // One firewall has a genuinely stopped IDS engine; everything else is up.
        $running = ($n === 3 && $s[0] === 'suricata') ? 0 : 1;
        $insSvc->execute([$fid, $s[0], $s[1], $running, 1]);
    }
}

$insCert = $db->prepare(
    'INSERT INTO firewall_certificates
        (firewall_id, refid, name, issuer, subject, cert_type, not_before, not_after,
         days_remaining, in_use)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
foreach ($ids as $n => $fid) {
    // A couple of near-expiry certificates so the warning states are visible.
    $days = [11, 64, 190, 6, 240, 133, 88, 155, 27, 201, 176][$n];
    $insCert->execute([
        $fid, substr(hash('md5', "cert-$fid"), 0, 13),
        'webgui-' . $n, "Let's Encrypt R3",
        'CN=' . array_keys($fwIds)[$n], 'server',
        $ts('-' . (90 - 0) . ' days'), $ts("+{$days} days"), $days, 'Web GUI',
    ]);
}

$insCarp = $db->prepare(
    'INSERT INTO firewall_carp (firewall_id, vhid, interface, address, state, advskew, advbase)
     VALUES (?,?,?,?,?,?,?)'
);
$insCarp->execute([$a, '1', 'igc0', '192.0.2.10', 'MASTER', 0, 1]);
$insCarp->execute([$a, '2', 'igc1', '10.20.0.1',  'MASTER', 0, 1]);
$insCarp->execute([$b, '1', 'igc0', '192.0.2.10', 'BACKUP', 100, 1]);
$insCarp->execute([$b, '2', 'igc1', '10.20.0.1',  'BACKUP', 100, 1]);
echo "  health telemetry   gateways, VPN, services, certificates, CARP\n";

// ---------------------------------------------------------------------------
// Interfaces, system stats and traffic history.
// ---------------------------------------------------------------------------

$insIf = $db->prepare(
    'INSERT INTO firewall_wan_interfaces
        (firewall_id, interface_name, status, ip_address, netmask, gateway, media,
         rx_packets, rx_errors, rx_bytes, tx_packets, tx_errors, tx_bytes)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ($fleet as $n => $f) {
    $fid = $ids[$n];
    $insIf->execute([$fid, 'igc0', $f[7] === 'offline' ? 'down' : 'up', $f[3],
        '255.255.255.0', preg_replace('/\.\d+$/', '.1', $f[3]),
        '1000baseT <full-duplex>',
        48_000_000 + $n * 3_100_000, 0, 612_000_000_000 + $n * 21_000_000_000,
        41_000_000 + $n * 2_700_000, 0, 388_000_000_000 + $n * 17_000_000_000]);
    $insIf->execute([$fid, 'igc1', 'up', '10.' . (20 + $n) . '.0.1',
        '255.255.255.0', null, '1000baseT <full-duplex>',
        52_000_000, 0, 501_000_000_000, 49_000_000, 0, 476_000_000_000]);
}

$insStat = $db->prepare(
    'INSERT INTO firewall_system_stats
        (firewall_id, recorded_at, cpu_load_1min, cpu_load_5min, cpu_load_15min,
         memory_total_mb, memory_used_mb, memory_percent,
         disk_total_gb, disk_used_gb, disk_percent)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);
$insTraffic = $db->prepare(
    'INSERT INTO firewall_traffic_stats
        (firewall_id, recorded_at, wan_interface, bytes_in, bytes_out, packets_in, packets_out)
     VALUES (?,?,?,?,?,?,?)'
);
$insLat = $db->prepare(
    'INSERT INTO firewall_latency (firewall_id, latency_ms, measured_at) VALUES (?,?,?)'
);

// 24 hours at 10-minute resolution, so the charts have a real shape.
foreach ($ids as $n => $fid) {
    $rxAcc = 612_000_000_000 + $n * 21_000_000_000;
    $txAcc = 388_000_000_000 + $n * 17_000_000_000;
    for ($m = 144; $m >= 0; $m--) {
        $when = $ts('-' . ($m * 10) . ' minutes');
        // A daily shape: quiet overnight, busy through the working day.
        $hour  = (int) (new DateTimeImmutable($when))->format('G');
        $duty  = ($hour >= 7 && $hour <= 19) ? 1.0 : 0.28;
        $wobble = (sin($m / 7.0 + $n) + 1.3) / 2.0;

        $insStat->execute([
            $fid, $when,
            round(0.18 + 0.55 * $duty * $wobble, 2),
            round(0.20 + 0.48 * $duty * $wobble, 2),
            round(0.22 + 0.40 * $duty * $wobble, 2),
            8192, (int) round(2100 + 900 * $duty * $wobble),
            round((2100 + 900 * $duty * $wobble) / 8192 * 100, 2),
            120, 22 + $n % 7, round((22 + $n % 7) / 120 * 100, 2),
        ]);

        $rxDelta = (int) round((9_000_000 + 41_000_000 * $duty * $wobble));
        $txDelta = (int) round((4_000_000 + 23_000_000 * $duty * $wobble));
        $rxAcc += $rxDelta;
        $txAcc += $txDelta;
        $insTraffic->execute([$fid, $when, 'igc0', $rxAcc, $txAcc,
            (int) ($rxAcc / 1200), (int) ($txAcc / 1400)]);

        if ($m % 3 === 0) {
            $insLat->execute([$fid, round(8 + ($n * 3) % 22 + 4 * $wobble, 2), $when]);
        }
    }
}
echo "  telemetry history  24h of stats, traffic and latency per firewall\n";

// ---------------------------------------------------------------------------
// Bandwidth test history. The firewall detail chart reads `bandwidth_tests`,
// which is also what agent_checkin.php writes when an agent returns a speedtest
// result - so this is the table the live path actually uses. (`firewall_speedtest`
// is written by api/agent_speedtest_result.php but nothing renders it.) Without
// these rows the detail page draws an empty chart, which is worse than no panel.
// ---------------------------------------------------------------------------

$insSpeed = $db->prepare(
    'INSERT INTO bandwidth_tests
        (firewall_id, test_type, test_status, download_speed, upload_speed,
         latency, test_server, test_duration, tested_at)
     VALUES (?,?,\'completed\',?,?,?,?,?,?)'
);
$sites = ['Dallas, TX', 'Chicago, IL', 'Denver, CO', 'Seattle, WA', 'Atlanta, GA'];
foreach ($ids as $n => $fid) {
    // Four-hourly for a week: a believable circuit with a little variance.
    $baseDown = [940, 940, 500, 300, 940, 600, 940, 1000, 200, 500, 500][$n] ?? 500;
    $baseUp   = (int) round($baseDown * (($n % 3 === 0) ? 1.0 : 0.22));
    for ($h = 42; $h >= 0; $h--) {
        $jitter = (sin($h / 3.0 + $n) + 1) / 2;          // 0..1
        $peak   = ($h % 6 === 0) ? 0.82 : 1.0;           // periodic contention
        $insSpeed->execute([
            $fid,
            $h === 0 ? 'manual' : 'scheduled',
            round($baseDown * (0.86 + 0.14 * $jitter) * $peak, 1),
            round($baseUp   * (0.88 + 0.12 * $jitter) * $peak, 1),
            round(6 + ($n * 2) % 18 + 5 * $jitter, 1),
            'iperf3 ' . $sites[$n % count($sites)],
            8,
            $ts('-' . ($h * 4) . ' hours'),
        ]);
    }
}
echo "  bandwidth tests    " . (count($ids) * 43) . " results over 7 days\n";

// ---------------------------------------------------------------------------
// Configuration backups. Real OPNsense-shaped XML is written to disk, because
// configuration drift and fleet configuration search both parse the stored
// file - seeding rows alone gives a drift page that cannot diff and a search
// that returns nothing.
//
// The generated configuration is deliberately varied: most firewalls are clean,
// and a few carry findings the named checks in inc/config_search.php actually
// detect, so search results are real rather than staged.
// ---------------------------------------------------------------------------

$storeRoot = getenv('OPNMGR_DEMO_BACKUP_DIR')
    ?: (sys_get_temp_dir() . '/opnmgr-demo-backups');
if (!is_dir($storeRoot) && !mkdir($storeRoot, 0700, true) && !is_dir($storeRoot)) {
    exit("Cannot create demo backup directory {$storeRoot}\n");
}

/**
 * Build an OPNsense-shaped configuration document.
 *
 * @param array $fw      One row of the $fleet table.
 * @param bool  $drifted Emit the post-change variant.
 * @param string $stamp  Value for the <revision> block, which OPNsense rewrites
 *                       on every save and drift detection must therefore ignore.
 */
$makeConfig = static function (array $fw, bool $drifted, string $stamp): string {
    [$host, $cc, $sc, $wan, $lanNet] = $fw;
    $short  = explode('.', $host)[0];
    $domain = substr($host, strlen($short) + 1);
    $lanIp  = preg_replace('/\.0\/24$/', '.1', $lanNet);
    $lanNet3 = preg_replace('/\.0\/24$/', '', $lanNet);

    // A handful of firewalls carry real findings for the named checks.
    $sshFromAny   = ($short === 'fw-dal-edge01');   // ssh_open_to_world
    $anyAny       = ($short === 'fw-dal-edge01');   // any_any_pass
    $passwordAuth = ($short === 'fw-tac-edge01');   // password_auth_ssh
    $guiOnWan     = ($short === 'fw-nor-edge01');   // webgui_on_wan

    $rules = [];
    $rules[] = ['pass', 'lan', 'LAN to any', ['any' => ''], ['any' => ''], null, false];
    $rules[] = ['block', 'wan', 'Default deny inbound', ['any' => ''], ['any' => ''], null, false];
    $rules[] = ['pass', 'wan', 'IPsec from partner gateway', ['address' => '203.0.113.200'],
                ['network' => 'wanip'], '500', false];

    if ($sshFromAny) {
        $rules[] = ['pass', 'wan', 'Temporary SSH for vendor - REMOVE', ['any' => ''],
                    ['network' => 'wanip'], '22', false];
    } else {
        $rules[] = ['pass', 'wan', 'SSH from management network', ['network' => '198.51.100.0/24'],
                    ['network' => 'wanip'], '22', false];
    }
    if ($anyAny) {
        $rules[] = ['pass', 'wan', 'Troubleshooting rule left enabled', ['any' => ''], ['any' => ''], null, false];
    }
    if ($guiOnWan) {
        $rules[] = ['pass', 'wan', 'Web GUI for remote admin', ['network' => '198.51.100.0/24'],
                    ['network' => 'wanip'], '443', false];
    }
    $rules[] = ['pass', 'lan', 'Permit DNS to resolver', ['network' => 'lan'],
                ['address' => $lanIp], '53', false];

    if ($drifted) {
        // The changes the drift page is meant to surface.
        if ($short === 'fw-dal-edge01') {
            $rules[] = ['pass', 'wan', 'NEW: RDP forward for accounting', ['any' => ''],
                        ['network' => 'wanip'], '3389', false];
            $rules[] = ['pass', 'lan', 'NEW: guest VLAN to internet', ['network' => 'opt1'],
                        ['any' => ''], null, false];
        } elseif ($short === 'fw-tac-edge01') {
            $rules[] = ['pass', 'wan', 'NEW: SNMP from monitoring host', ['address' => '198.51.100.50'],
                        ['network' => 'wanip'], '161', false];
        }
    }

    $x  = "<?xml version=\"1.0\"?>\n<opnsense>\n";
    // Rewritten on every save. Drift must ignore this or every firewall drifts.
    $x .= "  <revision>\n    <time>{$stamp}</time>\n"
        . "    <description>/usr/local/etc/rc.filter_configure made changes</description>\n"
        . "    <username>root@" . htmlspecialchars($lanIp, ENT_XML1) . "</username>\n  </revision>\n";

    $x .= "  <system>\n";
    $x .= "    <hostname>" . htmlspecialchars($short, ENT_XML1) . "</hostname>\n";
    $x .= "    <domain>" . htmlspecialchars($domain, ENT_XML1) . "</domain>\n";
    $x .= "    <timezone>Etc/UTC</timezone>\n";
    $x .= "    <dnsserver>9.9.9.9</dnsserver>\n    <dnsserver>149.112.112.112</dnsserver>\n";
    $x .= "    <webgui>\n      <protocol>https</protocol>\n      <port>443</port>\n    </webgui>\n";
    $x .= "    <ssh>\n      <enabled>enabled</enabled>\n"
        . "      <passwordauth>" . ($passwordAuth ? '1' : '0') . "</passwordauth>\n    </ssh>\n";
    $x .= "  </system>\n";

    $x .= "  <interfaces>\n";
    $x .= "    <wan>\n      <if>igc0</if>\n      <descr>WAN</descr>\n"
        . "      <ipaddr>" . htmlspecialchars($wan, ENT_XML1) . "</ipaddr>\n      <subnet>24</subnet>\n"
        . "      <gateway>WAN_DHCP</gateway>\n    </wan>\n";
    $x .= "    <lan>\n      <if>igc1</if>\n      <descr>LAN</descr>\n"
        . "      <ipaddr>" . htmlspecialchars($lanIp, ENT_XML1) . "</ipaddr>\n      <subnet>24</subnet>\n    </lan>\n";
    if ($drifted && $short === 'fw-tac-edge01') {
        // A whole interface added since the baseline.
        $x .= "    <opt1>\n      <if>igc2</if>\n      <descr>GUEST</descr>\n"
            . "      <ipaddr>" . htmlspecialchars($lanNet3, ENT_XML1) . ".200.1</ipaddr>\n"
            . "      <subnet>24</subnet>\n    </opt1>\n";
    }
    $x .= "  </interfaces>\n";

    $x .= "  <filter>\n";
    foreach ($rules as $r) {
        [$type, $iface, $descr, $src, $dst, $port, $disabled] = $r;
        $x .= "    <rule>\n";
        $x .= "      <type>{$type}</type>\n";
        $x .= "      <interface>{$iface}</interface>\n";
        $x .= "      <ipprotocol>inet</ipprotocol>\n";
        $x .= "      <descr>" . htmlspecialchars($descr, ENT_XML1) . "</descr>\n";
        if ($disabled) { $x .= "      <disabled>1</disabled>\n"; }
        $x .= "      <source>\n";
        foreach ($src as $k => $v) {
            $x .= $v === '' ? "        <{$k}/>\n"
                            : "        <{$k}>" . htmlspecialchars((string)$v, ENT_XML1) . "</{$k}>\n";
        }
        $x .= "      </source>\n      <destination>\n";
        foreach ($dst as $k => $v) {
            $x .= $v === '' ? "        <{$k}/>\n"
                            : "        <{$k}>" . htmlspecialchars((string)$v, ENT_XML1) . "</{$k}>\n";
        }
        if ($port !== null) { $x .= "        <port>{$port}</port>\n"; }
        $x .= "      </destination>\n    </rule>\n";
    }
    $x .= "  </filter>\n";

    $x .= "  <nat>\n    <outbound>\n      <mode>automatic</mode>\n    </outbound>\n  </nat>\n";

    $dhcpTo = $drifted && $short === 'fw-nor-edge01' ? '199' : '150';
    $x .= "  <dhcpd>\n    <lan>\n      <enable>1</enable>\n      <range>\n"
        . "        <from>" . htmlspecialchars($lanNet3, ENT_XML1) . ".100</from>\n"
        . "        <to>" . htmlspecialchars($lanNet3, ENT_XML1) . ".{$dhcpTo}</to>\n"
        . "      </range>\n    </lan>\n  </dhcpd>\n";

    $x .= "  <unbound>\n    <enable>1</enable>\n  </unbound>\n";
    $x .= "</opnsense>\n";
    return $x;
};

$insBackup = $db->prepare(
    'INSERT INTO backups
        (firewall_id, backup_file, description, created_at, backup_type, file_size,
         storage_path, checksum_sha256, validated, uploaded_at, source_filename)
     VALUES (?,?,?,?,?,?,?,?,1,?,?)'
);

$backupIds = [];        // newest backup per firewall
$baselineBackup = [];   // the 7-day-old one, promoted to baseline

// Which firewalls have changed since their baseline.
$driftIdx = [3, 8, 10];

foreach ($fleet as $n => $f) {
    $fid = $ids[$n];
    $dir = $storeRoot . '/fw-' . $fid;
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }

    for ($d = 14; $d >= 0; $d--) {
        $when = $ts("-{$d} days -" . (2 + $n % 3) . ' hours');
        $name = 'config-' . (new DateTimeImmutable($when))->format('Ymd-His') . '.xml';
        $path = $dir . '/' . $name;

        // Drifted firewalls changed 1-3 days ago; everything before that, and
        // every other firewall, is the baseline configuration.
        $changedDaysAgo = [3 => 1, 8 => 3, 10 => 2][$n] ?? null;
        $isDrifted = $changedDaysAgo !== null && $d <= $changedDaysAgo;

        $xml = $makeConfig($f, $isDrifted, (new DateTimeImmutable($when))->format('U'));
        file_put_contents($path, $xml);

        $insBackup->execute([
            $fid, $name, 'Scheduled nightly backup', $when, 'automated',
            strlen($xml), $path, hash('sha256', $xml), $when, $name,
        ]);
        $bid = (int) $db->lastInsertId();
        if ($d === 0) { $backupIds[$fid] = $bid; }
        if ($d === 7) { $baselineBackup[$fid] = $bid; }
    }
}
echo "  backups            " . (count($fleet) * 15) . " real config files under {$storeRoot}\n";

// ---------------------------------------------------------------------------
// Baselines and drift, computed by the application rather than fabricated.
// ---------------------------------------------------------------------------

// bootstrap_agent gives us db() with no session or auth, which is what the
// drift helpers expect. It connects to the same DB_NAME the guards above
// already checked.
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/config_drift.php';

$drifted = 0;
foreach ($fleet as $n => $f) {
    $fid = $ids[$n];
    $set = drift_set_baseline($fid, $baselineBackup[$fid], 'Approved after quarterly review');
    if (!$set['ok']) {
        echo "    ! baseline for {$f[0]}: {$set['error']}\n";
        continue;
    }
    $ev = drift_evaluate($fid);
    if (($ev['status'] ?? '') === 'drifted') { $drifted++; }
}

// The app stamps "now" on a baseline it has just been given and on drift it has
// just noticed. Backdate both to when the fixture says they happened, so the
// page shows a realistic baseline age rather than "1m" across the fleet.
$db->prepare('UPDATE config_baselines SET set_at = ?')->execute([$ts('-7 days')]);
$changedAt = [3 => '-1 days', 8 => '-3 days', 10 => '-2 days'];
foreach ($changedAt as $idx => $ago) {
    $db->prepare('UPDATE config_drift SET first_detected_at = ? WHERE firewall_id = ?')
       ->execute([$ts($ago), $ids[$idx]]);
}

// Approving eleven baselines writes eleven audit entries in the same second,
// which would otherwise be the entire first page of the audit log. Move them to
// when the fixture says the approval happened.
$db->prepare("UPDATE audit_log SET occurred_at = ? WHERE action = 'drift.baseline.set'")
   ->execute([$ts('-7 days')]);

// One drifted firewall is acknowledged, to show that state too.
drift_acknowledge($ids[10], 'Planned DHCP scope change, ticket NWL-4417');
$db->prepare("UPDATE audit_log SET occurred_at = ? WHERE action = 'drift.acknowledge'")
   ->execute([$ts('-10 hours')]);
$db->prepare('UPDATE config_drift SET acknowledged_at = ? WHERE firewall_id = ?')
   ->execute([$ts('-10 hours'), $ids[10]]);

echo "  drift              evaluated by the app: {$drifted} drifted\n";

// ---------------------------------------------------------------------------
// Update campaign. Seeded in terminal states - no command rows are created,
// so nothing here can dispatch to anything.
// ---------------------------------------------------------------------------

$db->prepare(
    'INSERT INTO update_campaigns
        (name, description, target_version, operation, status, current_ring,
         auto_progress, reboot_if_required, respect_maintenance, ha_safe,
         created_by_user_id, created_by_username, created_at, started_at)
     VALUES (?,?,?,?,?,?,0,1,1,1,1,?,?,?)'
)->execute([
    'OPNsense 26.7.3 rollout',
    'Quarterly firmware rollout across the managed fleet.',
    '26.7.3', 'install', 'running', 'pilot',
    'demo', $ts('-2 days'), $ts('-2 days'),
]);
$campaignId = (int) $db->lastInsertId();

$insTarget = $db->prepare(
    'INSERT INTO update_campaign_targets
        (campaign_id, firewall_id, ring, status, hold_reason, version_before,
         version_after, dispatched_at, completed_at, result)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
foreach ($fleet as $n => $f) {
    $fid  = $ids[$n];
    $ring = $f[11];
    if ($ring === 'canary') {
        $insTarget->execute([$campaignId, $fid, $ring, 'succeeded', null, '26.7.2',
            '26.7.3', $ts('-2 days'), $ts('-2 days'), 'Updated and rebooted; agent re-checked in.']);
    } elseif ($ring === 'pilot') {
        $insTarget->execute([$campaignId, $fid, $ring, $n === 2 ? 'succeeded' : 'dispatched',
            null, '26.7.2', $n === 2 ? '26.7.3' : null, $ts('-4 hours'),
            $n === 2 ? $ts('-3 hours') : null, $n === 2 ? 'Updated and rebooted.' : null]);
    } elseif ($f[7] === 'offline') {
        $insTarget->execute([$campaignId, $fid, $ring, 'pending',
            'Firewall has not checked in since ' . $ts('-47 minutes'),
            '25.7.11', null, null, null, null]);
    } elseif ($f[12] === 'BACKUP') {
        $insTarget->execute([$campaignId, $fid, $ring, 'holding',
            'HA partner fw-chi-edge01 must complete first', '26.7.2', null, null, null, null]);
    } else {
        $insTarget->execute([$campaignId, $fid, $ring, 'pending', null, '26.7.2',
            null, null, null, null]);
    }
}
echo "  update campaign    1 running, rings seeded in terminal states\n";

// Firewall id, customer, site and hostname for a fleet index. Defined here
// because the AI scan seeding below is now the first consumer; the incident
// seeding further down uses the same closure.
$fwMeta = static function (int $idx) use ($ids, $fleet, $customerIds, $siteIds) {
    $f = $fleet[$idx];
    return [$ids[$idx], $customerIds[$f[1]], $siteIds[$f[1] . '/' . $f[2]], $f[0]];
};

// ---------------------------------------------------------------------------
// AI security scans.
//
// The fixture seeded no scan data at all, so the AI report screens could not be
// photographed from the demo environment - and they are not the sort of thing to
// photograph from a real fleet, since a report is a list of a firewall's actual
// weaknesses next to its actual addresses.
//
// Findings below are written against the documentation addresses this fixture
// already uses. Severities follow the same rule the live prompt states: critical
// means reachable from the internet and administrative, low means a hardening
// gap with no path in.
// ---------------------------------------------------------------------------

$insReport = $db->prepare(
    'INSERT INTO ai_scan_reports
        (firewall_id, config_snapshot_id, scan_type, provider, model, overall_grade,
         security_score, risk_level, summary, recommendations, concerns, improvements,
         full_report, scan_duration, prompt_tokens, completion_tokens, total_tokens, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$insFinding = $db->prepare(
    'INSERT INTO ai_scan_findings
        (report_id, source, category, severity, title, description, recommendation, affected_rules)
     VALUES (?,?,?,?,?,?,?,?)'
);
$insLogAnalysis = $db->prepare(
    'INSERT INTO log_analysis_results
        (report_id, log_type, lines_analyzed, active_threats, suspicious_ips,
         blocked_attempts, failed_auth_attempts, anomaly_score, threat_level)
     VALUES (?,?,?,?,?,?,?,?,?)'
);

// [fw index, grade, score, risk, hours ago, scan type, summary, [findings], [logs]]
$scans = [
    [3, 'D', 62, 'high', 2.5, 'config_with_logs',
     'The firewall is reachable and fully patched, and its rule set is mostly sound: SSH is restricted to two management addresses, bogon and private-range blocking are enabled on WAN, and outbound NAT is unremarkable. Two administrative interfaces are published to the internet without source restriction, which is what drives the grade. Ordinary service publishing - web and mail for the site - is intentional and is not treated as a concern.',
     [
        ['critical', 'management_exposure', 'Web GUI reachable from any internet source',
         'An enabled WAN rule permits any source to the firewall itself on 443, and the GUI listens on all interfaces. Anyone who can reach the address can reach the login page.',
         'Restrict the rule to your management prefixes, or move administration behind the VPN.',
         "wan | pass TCP any -> (self):443 | HTTPS Allow\nsystem | webgui protocol=https port=443"],
        ['critical', 'management_exposure', 'Hypervisor management interface published to the internet',
         'Port 8006 is forwarded from the WAN address to an internal host with no source restriction.',
         'Restrict the forward to known addresses, or publish it through the VPN instead.',
         'wan | pass TCP any -> 10.22.0.40:8006 | pve host'],
        ['high', 'remote_access', 'SSH password authentication enabled',
         'The daemon permits password authentication. SSH is restricted to two management addresses, so this is not internet-facing, but a stolen password would be sufficient on its own from either of them.',
         'Set PasswordAuthentication to no and rely on keys.',
         'system | sshd passwordauth=1 permitrootlogin=1'],
        ['medium', 'access_control', 'Single-source WAN exception reaches any destination',
         'One WAN rule permits a specific source address to any internal destination on any port.',
         'Narrow the destination to the hosts that address actually needs.',
         'wan | pass any 203.0.113.77 -> any | vendor access'],
        ['low', 'dns', 'DNSSEC validation disabled',
         'Neither resolver validates DNSSEC. The resolver is bound to the LAN and is not reachable from the internet, so this is a hardening gap rather than an exposure.',
         'Enable DNSSEC validation in the resolver.',
         'unbound | dnssec=0'],
        ['low', 'vpn_access_control', 'VPN interface policy permits any-to-any',
         'The WireGuard interface passes all traffic from connected peers. Peers are admitted by key, so this is open to people who already hold one rather than to the internet.',
         'Narrow the interface policy to the networks peers actually need.',
         'wireguard | pass any any -> any'],
     ],
     [['filter', 412, 18, 0, 'low'], ['system', 96, 0, 0, 'low'], ['resolver', 240, 0, 0, 'low']],
    ],
    [0, 'A', 94, 'low', 6.0, 'config_only',
     'A tight configuration. Administration is reachable only from the management prefixes, the GUI is HTTPS with a current certificate, DNSSEC validation is on, and the single published service is an intentional web listener. What remains is preference rather than exposure.',
     [
        ['low', 'service_hardening', 'WAN responds to ICMP echo from any source',
         'The firewall answers pings from the internet. This reveals that the address is live and nothing more.',
         'Leave it if you use it for monitoring; otherwise restrict it to your monitoring hosts.',
         'wan | pass ICMP any -> (self) | Allow ping'],
        ['low', 'monitoring', 'Intrusion detection is not enabled',
         'Suricata is installed but not started. This is optional hardening and does not affect the grade.',
         'Enable it if you want signature-based detection on this circuit.',
         'service | suricata status=stopped'],
        ['info', 'access_control', 'SSH restricted to management addresses',
         'Port 22 is reachable only from two known prefixes, which is what the rest of this report assumes.',
         'No action needed.',
         'wan | pass TCP 192.0.2.0/24 -> (self):22 | management'],
     ],
     [],
    ],
    [8, 'C', 71, 'medium', 30.0, 'config_only',
     'This firewall has not checked in for some time and the configuration read is the last one collected. The rule set publishes two services intentionally; the concerns are an expired certificate still bound to the web GUI and an administrative interface reachable from a broad source range rather than from named hosts.',
     [
        ['high', 'certificates', 'Web GUI is using an expired certificate',
         'The certificate bound to the GUI expired eleven days ago. Administrators are being trained to click through the warning.',
         'Renew and rebind the certificate.',
         'system | webgui ssl-certref=webgui-8 expired'],
        ['medium', 'management_exposure', 'Administration reachable from a broad source range',
         'The management rule permits a /16 rather than the specific hosts that need it.',
         'Narrow the source to the addresses actually used for administration.',
         'wan | pass TCP 203.0.113.0/16 -> (self):443 | admin'],
        ['low', 'dns', 'DNSSEC validation disabled', 'Resolver hardening, not an exposure.',
         'Enable DNSSEC validation.', 'unbound | dnssec=0'],
     ],
     [],
    ],
];

$scanCount = 0;
$findingCount = 0;
foreach ($scans as $scan) {
    [$idx, $grade, $score, $risk, $ageH, $type, $summary, $findings, $logs] = $scan;
    [$fid, , , ] = $fwMeta($idx);

    $recs = [];
    foreach ($findings as $f) {
        if ($f[0] === 'info') { continue; }
        $recs[] = $f[2] . ' - ' . $f[4];
    }
    // Titles, not category slugs: "Key Concerns" is read by a person, and
    // "management_exposure" twice over says less than the two sentences it
    // stands for.
    $concerns = [];
    foreach ($findings as $f) {
        if (in_array($f[0], ['critical', 'high'], true)) {
            $concerns[] = strtoupper($f[0]) . ': ' . $f[2];
        }
    }

    $promptTokens = 38000 + ($idx * 1700);
    $completion   = 1800 + ($idx * 90);

    $insReport->execute([
        $fid, null, $type, 'openai', 'gpt-5.5', $grade, $score, $risk,
        $summary,
        implode("\n", $recs),
        $concerns ? implode("\n", $concerns) : 'No critical or high severity concerns were raised.',
        'Optional hardening is listed in the findings below and does not affect the grade.',
        $summary,
        14 + $idx,
        $promptTokens, $completion, $promptTokens + $completion,
        $ts('-' . $ageH . ' hours'),
    ]);
    $reportId = (int) $db->lastInsertId();
    $scanCount++;

    foreach ($findings as $f) {
        $insFinding->execute([$reportId, 'config', $f[1], $f[0], $f[2], $f[3], $f[4], $f[5]]);
        $findingCount++;
    }
    foreach ($logs as $l) {
        [$logType, $lines, $blocked, $failedAuth, $level] = $l;
        $insLogAnalysis->execute([
            $reportId, $logType, $lines, json_encode([]), json_encode([]),
            $blocked, $failedAuth, $level === 'low' ? 0.12 : 0.55, $level,
        ]);
    }
}
echo "  ai scans           {$scanCount} reports, {$findingCount} findings\n";

// ---------------------------------------------------------------------------
// Incidents.
// ---------------------------------------------------------------------------

$insInc = $db->prepare(
    'INSERT INTO alert_incidents
        (dedupe_key, dedupe_source, alert_type, object_key, severity, status,
         firewall_id, customer_id, site_id, title, detail, metadata,
         first_seen_at, last_seen_at, resolved_at, occurrence_count,
         acknowledged_at, acknowledged_by, acknowledged_note, notify_count,
         last_notified_at, suppressed, suppressed_reason)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$insEvent = $db->prepare(
    'INSERT INTO alert_incident_events (incident_id, event, detail, actor, occurred_at)
     VALUES (?,?,?,?,?)'
);


$incidents = [
    // [fw index, type, object, severity, status, title, detail, age hours, count]
    [8,  'firewall.offline',   null,        'critical', 'open',
     'Firewall has not checked in', 'No agent check-in for 47 minutes. Last seen on OPNsense 25.7.11.', 0.8, 24],
    [8,  'vpn.tunnel.down',    'wg-hub',    'critical', 'open',
     'WireGuard tunnel wg-hub is down', 'No handshake for 3 hours.', 3.0, 18],
    [3,  'gateway.degraded',   'WAN2_LTE',  'warning',  'acknowledged',
     'Gateway WAN2_LTE is degraded', 'Latency 148.7 ms, loss 2.4% over the last 15 minutes.', 9.0, 52],
    [3,  'service.stopped',    'suricata',  'warning',  'open',
     'Service suricata is not running', 'Configured but reported stopped on the last three check-ins.', 5.0, 3],
    [3,  'certificate.expiring','webgui-3', 'warning',  'open',
     'Certificate webgui-3 expires in 6 days', 'Web GUI certificate. Renew before expiry.', 26.0, 2],
    [0,  'certificate.expiring','webgui-0', 'warning',  'open',
     'Certificate webgui-0 expires in 11 days', 'Web GUI certificate. Renew before expiry.', 14.0, 1],
    [3,  'config.drift',       null,        'warning',  'open',
     'Configuration has drifted from baseline', '4 changes across filter and nat since the approved baseline.', 30.0, 1],
    [6,  'firewall.reboot_required', null,  'info',     'resolved',
     'Reboot required after update', 'Cleared when the firewall reported a new uptime.', 40.0, 1],
    [1,  'agent.outdated',     null,        'info',     'resolved',
     'Agent below minimum supported version', 'Agent self-updated to 1.6.2.', 60.0, 1],
];

$opened = 0;
foreach ($incidents as $inc) {
    [$idx, $type, $obj, $sev, $status, $title, $detail, $ageH, $count] = $inc;
    [$fid, $cid, $sid, $host] = $fwMeta($idx);

    $first = $ts('-' . $ageH . ' hours');
    $resolved = $status === 'resolved' ? $ts('-' . ($ageH - 2) . ' hours') : null;
    $key = $status === 'resolved' ? null : "$fid:$type:" . ($obj ?? '-');

    $insInc->execute([
        $key, "$fid:$type:" . ($obj ?? '-'), $type, $obj, $sev, $status,
        $fid, $cid, $sid, $title, $detail,
        json_encode(['hostname' => $host, 'demo' => true]),
        $first,
        $status === 'resolved' ? $resolved : $ts('-2 minutes'),
        $resolved, $count,
        $status === 'acknowledged' ? $ts('-6 hours') : null,
        $status === 'acknowledged' ? 'demo' : null,
        $status === 'acknowledged' ? 'Carrier ticket raised, ETA 48h' : null,
        $status === 'resolved' ? 2 : 3,
        $ts('-30 minutes'),
        0, null,
    ]);
    $incId = (int) $db->lastInsertId();
    $opened++;

    $insEvent->execute([$incId, 'opened', $title, null, $first]);
    $insEvent->execute([$incId, 'notified', 'Notified 1 recipient', null, $first]);
    if ($status === 'acknowledged') {
        $insEvent->execute([$incId, 'acknowledged', 'Carrier ticket raised, ETA 48h', 'demo', $ts('-6 hours')]);
    }
    if ($status === 'resolved') {
        $insEvent->execute([$incId, 'resolved', 'Condition cleared', null, $resolved]);
    } else {
        $insEvent->execute([$incId, 'updated', "Still present after {$count} checks", null, $ts('-2 minutes')]);
    }
}
echo "  incidents          {$opened} with event trails\n";

// ---------------------------------------------------------------------------
// Maintenance windows and an audit trail.
// ---------------------------------------------------------------------------

$insWin = $db->prepare(
    'INSERT INTO maintenance_windows
        (scope, scope_id, starts_at, ends_at, reason, status, suppress_alerts,
         created_by_user_id, created_by_username)
     VALUES (?,?,?,?,?,?,1,1,?)'
);
$insWin->execute(['customer', $customerIds['NWL'], $ts('+2 days'), $ts('+2 days +4 hours'),
    'Quarterly firmware rollout, production ring', 'scheduled', 'demo']);
$insWin->execute(['site', $siteIds['CHG/DEN'], $ts('-1 hour'), $ts('+3 hours'),
    'Clinic network cutover', 'active', 'demo']);
$insWin->execute(['firewall', $ids[8], $ts('-6 days'), $ts('-6 days +2 hours'),
    'ISP circuit replacement', 'completed', 'demo']);

$insAudit = $db->prepare(
    'INSERT INTO audit_log
        (occurred_at, actor_type, user_id, username, source_ip, action, object_type,
         object_id, firewall_id, customer_id, site_id, success, message, metadata)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$auditActions = [
    ['user','demo','config.baseline.set','firewall','Baseline approved after quarterly review',1],
    ['user','demo','incident.acknowledge','incident','Acknowledged gateway degradation',1],
    ['user','demo','campaign.ring.advance','campaign','Advanced rollout from canary to pilot',1],
    ['user','demo','backup.download','backup','Downloaded configuration backup',1],
    ['agent',null,'agent.checkin','firewall','Agent check-in accepted',1],
    ['user','demo','auth.login','user','Signed in',1],
    ['user','demo','command.raw','firewall','Raw shell refused: capability not held',0],
    ['system',null,'backup.schedule.run','system','Nightly backup completed for 11 firewalls',1],
];
foreach ($auditActions as $i => $act) {
    $insAudit->execute([
        $ts('-' . ($i * 37 + 12) . ' minutes'), $act[0],
        $act[1] ? 1 : null, $act[1], '192.0.2.200',
        $act[2], $act[3], (string) ($ids[$i % count($ids)]),
        $ids[$i % count($ids)], $customerIds['NWL'], null,
        $act[5], $act[4], json_encode(['demo' => true]),
    ]);
}
echo "  maintenance/audit  3 windows, " . count($auditActions) . " audit entries\n";

// ---------------------------------------------------------------------------
// MSP staff accounts. Three roles, so the capability model is visible.
// Passwords are a fixed throwaway string; this database is never an install.
// ---------------------------------------------------------------------------

$db->prepare("DELETE FROM users WHERE username <> 'demo'")->execute();

$staff = [
    ['r.okonkwo',  'Rina',   'Okonkwo',  'admin',      'Network Operations Lead'],
    ['t.lindqvist','Tomas',  'Lindqvist','technician', 'Senior Network Engineer'],
    ['p.mensah',   'Priya',  'Mensah',   'technician', 'Network Engineer'],
    ['j.calder',   'Jules',  'Calder',   'readonly',   'Service Desk'],
];
$insUser = $db->prepare(
    'INSERT INTO users (username, password, email, first_name, last_name, role,
                        timezone, is_active, last_login, created_at)
     VALUES (?,?,?,?,?,?,?,1,?,?)'
);
foreach ($staff as $i => $u) {
    $insUser->execute([
        $u[0],
        password_hash('demo-fixture-only-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        $u[0] . '@example.com', $u[1], $u[2], $u[3],
        'America/New_York',
        $ts('-' . (2 + $i * 7) . ' hours'),
        $ts('-' . (120 + $i * 30) . ' days'),
    ]);
}
echo "  staff accounts     " . (count($staff) + 1) . " across admin/technician/readonly\n";

// ---------------------------------------------------------------------------
// Tags.
// ---------------------------------------------------------------------------

$tags = [
    ['critical-site', '#ef4444'], ['ha-pair', '#8b5cf6'], ['lte-backup', '#f59e0b'],
    ['pci-scope', '#10b981'], ['remote-hands', '#3b82f6'], ['hipaa', '#06b6d4'],
    ['24x7-support', '#ec4899'], ['fiber-primary', '#22c55e'], ['dual-wan', '#a855f7'],
    ['k12-filtering', '#eab308'], ['ot-network', '#f97316'], ['legacy-hardware', '#64748b'],
];
$insTag = $db->prepare('INSERT INTO tags (name, color) VALUES (?,?)');
$tagIds = [];
foreach ($tags as $t) { $insTag->execute($t); $tagIds[$t[0]] = (int) $db->lastInsertId(); }

$insFwTag = $db->prepare('INSERT IGNORE INTO firewall_tags (firewall_id, tag_id) VALUES (?,?)');
$tagPlan = [
    0  => ['critical-site', 'ha-pair', 'fiber-primary', '24x7-support'],
    1  => ['critical-site', 'ha-pair', 'fiber-primary'],
    2  => ['lte-backup', 'dual-wan'],
    3  => ['lte-backup', 'remote-hands', 'dual-wan'],
    4  => ['pci-scope', 'critical-site', 'hipaa', '24x7-support'],
    5  => ['pci-scope', 'hipaa'],
    6  => ['critical-site', 'fiber-primary'],
    7  => ['pci-scope', 'ot-network'],
    8  => ['remote-hands', 'ot-network', 'legacy-hardware'],
    9  => ['critical-site', 'k12-filtering'],
    10 => ['k12-filtering'],
];
foreach ($tagPlan as $idx => $names) {
    foreach ($names as $n) { $insFwTag->execute([$ids[$idx], $tagIds[$n]]); }
}
echo "  tags               " . count($tags) . " applied across the fleet\n";

// ---------------------------------------------------------------------------
// Alert configuration and notification history.
// ---------------------------------------------------------------------------

$triggers = [
    ['Firewall offline',        'firewall_down',  'No agent check-in within the threshold', 1, '5',  5,  2],
    ['Gateway packet loss',     'gateway_loss',   'Sustained loss on a monitored gateway',   1, '2',  15, 5],
    ['Certificate expiring',    'cert_expiring',  'Certificate within the warning window',   1, '30', null, 1440],
    ['Disk usage high',         'low_disk',       'Root filesystem above the threshold',     1, '85', 30, 15],
    ['Configuration drift',     'config_changed', 'Current config differs from baseline',    1, null, null, 60],
    ['Backup failed',           'backup_failed',  'Scheduled backup did not complete',       1, null, null, 60],
    ['Agent below minimum',     'agent_outdated', 'Agent older than the supported minimum',  0, null, null, 1440],
];
$insTrig = $db->prepare(
    'INSERT INTO alert_triggers (trigger_name, trigger_type, description, enabled,
                                 threshold_value, threshold_duration, check_interval)
     VALUES (?,?,?,?,?,?,?)'
);
foreach ($triggers as $t) { $insTrig->execute($t); }

$insHist = $db->prepare(
    'INSERT INTO alert_history (alert_level, alert_type, firewall_id, subject, message,
                                recipients_count, notification_method, sent_at, status)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$history = [
    ['critical','firewall_offline', 8, 'fw-tac-edge01 has not checked in',
     'No agent check-in for 47 minutes.', 3, 'email', '-40 minutes', 'sent'],
    ['critical','vpn_down',         8, 'WireGuard tunnel wg-hub is down',
     'No handshake for 3 hours.', 3, 'both', '-2 hours', 'sent'],
    ['warning','gateway_loss',      3, 'WAN2_LTE degraded on fw-dal-edge01',
     'Latency 148.7 ms, loss 2.4%.', 2, 'email', '-8 hours', 'sent'],
    ['warning','cert_expiring',     3, 'Certificate webgui-3 expires in 6 days',
     'Renew before expiry.', 2, 'email', '-26 hours', 'sent'],
    ['warning','config_changed',    3, 'Configuration drift on fw-dal-edge01',
     '4 changes across filter and nat.', 2, 'email', '-30 hours', 'sent'],
    ['info','backup_completed',  null, 'Nightly backup completed',
     '11 of 11 firewalls backed up.', 1, 'email', '-6 hours', 'sent'],
    ['warning','cert_expiring',     0, 'Certificate webgui-0 expires in 11 days',
     'Renew before expiry.', 2, 'email', '-14 hours', 'partial'],
];
foreach ($history as $h) {
    $insHist->execute([$h[0], $h[1], $h[2] === null ? null : $ids[$h[2]], $h[3], $h[4],
        $h[5], $h[6], $ts($h[7]), $h[8]]);
}
echo "  alerting           " . count($triggers) . " triggers, " . count($history) . " notifications\n";

// ---------------------------------------------------------------------------
// Verify the fixture issued no commands.
// ---------------------------------------------------------------------------

$commandRows = 0;
foreach (['firewall_commands', 'agent_commands', 'request_queue'] as $t) {
    $commandRows += (int) $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

echo "\nCommand-queue rows after seeding: {$commandRows} (must be 0)\n";
if ($commandRows !== 0) {
    exit("FAILED: the fixture created command rows.\n");
}
echo "Demo fixture complete.\n";
