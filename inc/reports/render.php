<?php
/**
 * Report rendering: HTML (also the PDF source) and CSV, from a snapshot only.
 *
 * The HTML is built exclusively from escaped values, one inline stylesheet and
 * an optional data: URI logo. It contains no <script>, no <link>, no <iframe>,
 * and no <img> other than the logo, so there is nothing for a PDF renderer to
 * fetch even if a hostname contains markup or a URL.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/collect.php';

if (!function_exists('report_h')) {
    function report_h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('report_fmt_ts')) {
    function report_fmt_ts(?int $ts, string $tz): string {
        if ($ts === null) { return 'n/a'; }
        return (new DateTimeImmutable('@' . $ts))->setTimezone(report_tz($tz))->format('Y-m-d H:i T');
    }
}

if (!function_exists('report_branding')) {
    /** Brand name and logo as a data: URI. The logo is read only from under assets/ and only as an image type. */
    function report_branding(): array {
        $name = (string)report_setting('brand_name', 'OPNManager');
        $logoUri = null;
        $logo = (string)report_setting('logo', '');
        if ($logo !== '' && preg_match('#^/assets/[A-Za-z0-9_./-]+\.(png|jpe?g|gif)$#i', $logo) && !str_contains($logo, '..')) {
            $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
            $path = realpath($base . $logo);
            $root = realpath($base . '/assets');
            if ($path && $root && str_starts_with($path, $root . '/') && filesize($path) < 1000000) {
                $mime = @getimagesize($path)['mime'] ?? '';
                if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                    $logoUri = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
                }
            }
        }
        return ['name' => $name, 'logo' => $logoUri];
    }
}

if (!function_exists('report_render_html')) {
    function report_render_html(array $s, ?array $brand = null): string {
        $brand = $brand ?? report_branding();
        $tz = $s['period']['timezone'];
        $h = 'report_h';
        $ts = fn($t) => report_fmt_ts($t, $tz);
        $secs = fn($x) => report_fmt_duration((int)$x);
        $fwName = []; foreach ($s['inventory'] as $f) { $fwName[$f['id']] = $f['hostname']; }
        $nm = fn($id) => $h($fwName[$id] ?? ('#' . $id));
        $sections = $s['sections'];
        $cat = report_sections_catalog();
        $a = $s['availability']; $agg = $a['aggregate'];
        $title = ucfirst($s['scope']['type']) . ' report: ' . $s['scope']['name'];

        $o = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $h($title) . '</title><style>'
           . 'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#1b1f23;margin:24px}'
           . 'h1{font-size:22px;margin:0 0 4px}h2{font-size:15px;border-bottom:2px solid #345;padding-bottom:3px;margin-top:26px;page-break-after:avoid}'
           . 'h3{font-size:13px;margin:14px 0 4px}table{border-collapse:collapse;width:100%;margin:6px 0;page-break-inside:auto}'
           . 'tr{page-break-inside:avoid}th,td{border:1px solid #c7ccd1;padding:3px 6px;text-align:left;vertical-align:top}'
           . 'th{background:#eef1f4}.num{text-align:right}.muted{color:#5a6570}.na{color:#8a6d00;font-style:italic}'
           . '.hdr{display:flex;align-items:center;gap:14px}.logo{max-height:48px}.note{background:#f6f8fa;border-left:4px solid #678;padding:6px 10px;margin:8px 0}'
           . '.ai{background:#fffbe6;border-left:4px solid #b8860b;padding:6px 10px;margin:8px 0}'
           . '.sev-high{color:#a00;font-weight:bold}.sev-medium{color:#a60}.sev-low{color:#345}'
           . '</style></head><body>';
        $o .= '<div class="hdr">';
        if ($brand['logo']) { $o .= '<img class="logo" alt="" src="' . $h($brand['logo']) . '">'; }
        $o .= '<div><h1>' . $h($title) . '</h1><div class="muted">' . $h($brand['name']) . ' &middot; Period '
            . $h($ts($s['period']['start'])) . ' to ' . $h($ts($s['period']['end'])) . ' (' . $h($tz) . ', '
            . $h(rtrim(rtrim(number_format($s['period']['hours'], 2, '.', ''), '0'), '.')) . ' hours) &middot; Generated '
            . $h($ts($s['generated_at'])) . '</div></div></div>';

        $na = fn($v, $f) => $v === null ? '<span class="na">n/a</span>' : $f($v);
        $pct = fn($v, $dp = 3) => $na($v, fn($x) => $h(report_fmt_pct($x, $dp)));

        foreach ($sections as $key) {
            $o .= '<h2>' . $h($cat[$key]) . '</h2>';
            switch ($key) {
            case 'executive_summary':
                $sm = $s['summary'];
                $o .= '<table><tr><th>Firewalls</th><th>Observed availability</th><th>Monitoring coverage</th><th>Confirmed outage</th><th>Open incidents at period end</th><th>Backups stale or missing</th></tr><tr>'
                    . '<td class="num">' . $h($sm['firewalls']) . '</td><td class="num">' . $pct($sm['availability']) . '</td><td class="num">' . $pct($sm['coverage'], 1)
                    . '</td><td class="num">' . $h($secs($sm['confirmed_outage_seconds'])) . '</td><td class="num">' . $h($sm['incidents_open_at_end'])
                    . '</td><td class="num">' . $h($sm['backups_stale_or_missing']) . '</td></tr></table>';
                $o .= '<p class="muted">Availability is device-time-weighted over ' . $h($agg['devices_with_data']) . ' of ' . $h($agg['devices'])
                    . ' firewalls that have measurable time. Monitoring gaps (' . $h($secs($sm['monitoring_gap_seconds'])) . '), planned maintenance ('
                    . $h($secs($sm['maintenance_seconds'])) . ') and unknown time (' . $h($secs($sm['unknown_seconds'])) . ') are excluded and shown separately. This is observed availability, not an SLA figure.</p>';
                $ai = $s['ai'] ?? null;
                if ($ai && ($ai['status'] ?? '') === 'ok') {
                    $o .= '<div class="ai"><strong>AI-generated narrative</strong> (' . $h($ai['provider'] ?? '') . ' ' . $h($ai['model'] ?? '')
                        . '). Generated from the aggregated figures in this report only; verify against the tables.<br>' . nl2br($h($ai['narrative'])) . '</div>';
                }
                foreach ($s['provenance']['exclusions'] as $ex) { $o .= '<div class="note">' . $h($ex) . '</div>'; }
                break;
            case 'inventory':
                $o .= '<table><tr><th>Firewall</th><th>Platform</th><th>Edition</th><th>OS version</th><th>Agent</th><th>Last check-in</th><th>Updates</th></tr>';
                foreach ($s['inventory'] as $f) {
                    $o .= '<tr><td>' . $h($f['hostname']) . '</td><td>' . $h($f['platform']) . '</td><td>' . $h($f['edition'] ?? '') . '</td><td>' . $h($f['os_version'] ?? '')
                        . '</td><td>' . $h($f['agent_version'] ?? '') . '</td><td>' . $h($ts($f['last_checkin'])) . '</td><td>'
                        . ($f['updates_available'] ? 'Available' . ($f['available_version'] ? ' (' . $h($f['available_version']) . ')' : '') : 'None reported') . ($f['reboot_required'] ? ', reboot required' : '') . '</td></tr>';
                }
                $o .= '</table>';
                break;
            case 'coverage':
                $o .= '<table><tr><th>Firewall</th><th>Check-in interval</th><th>Heartbeats in period</th><th>Monitoring gap</th><th>Unknown</th><th>Coverage</th></tr>';
                foreach ($a['per_firewall'] as $id => $r) {
                    $o .= '<tr><td>' . $nm($id) . '</td><td class="num">' . $h($r['thresholds']['interval']) . 's</td><td class="num">' . $h($r['heartbeats']) . '</td><td class="num">'
                        . $h($secs($r['monitoring_gap'])) . '</td><td class="num">' . $h($secs($r['unknown'])) . '</td><td class="num">' . $pct($r['coverage'], 1) . '</td></tr>';
                }
                $o .= '<tr><th>Total</th><td></td><td></td><td class="num">' . $h($secs($agg['monitoring_gap'])) . '</td><td class="num">' . $h($secs($agg['unknown'])) . '</td><td class="num">' . $pct($agg['coverage'], 1) . '</td></tr></table>';
                if ($a['manager_gaps']) {
                    $o .= '<h3>Monitoring gaps (manager recorded no check-ins from any firewall)</h3><table><tr><th>From</th><th>To</th><th>Duration</th></tr>';
                    foreach ($a['manager_gaps'] as $g) { $o .= '<tr><td>' . $h($ts($g['start'])) . '</td><td>' . $h($ts($g['end'])) . '</td><td>' . $h($secs($g['seconds'])) . '</td></tr>'; }
                    $o .= '</table>';
                }
                break;
            case 'availability':
                $o .= '<table><tr><th>Firewall</th><th>Up</th><th>Missed check-in</th><th>Confirmed outage</th><th>Planned maint.</th><th>Monitoring gap</th><th>Unknown</th><th>Not enrolled / future</th><th>Availability</th><th>Continuity</th></tr>';
                foreach ($a['per_firewall'] as $id => $r) {
                    $o .= '<tr><td>' . $nm($id) . '</td><td class="num">' . $h($secs($r['up'])) . '</td><td class="num">' . $h($secs($r['missed_checkin'])) . '</td><td class="num">' . $h($secs($r['confirmed_outage']))
                        . '</td><td class="num">' . $h($secs($r['planned_maintenance'])) . '</td><td class="num">' . $h($secs($r['monitoring_gap'])) . '</td><td class="num">' . $h($secs($r['unknown']))
                        . '</td><td class="num">' . $h($secs($r['not_enrolled'] + $r['future'])) . '</td><td class="num">' . $pct($r['availability']) . '</td><td class="num">' . $pct($r['continuity']) . '</td></tr>';
                }
                $o .= '<tr><th>Fleet (device-time-weighted)</th><td class="num">' . $h($secs($agg['up'])) . '</td><td class="num">' . $h($secs($agg['missed_checkin'])) . '</td><td class="num">' . $h($secs($agg['confirmed_outage']))
                    . '</td><td class="num">' . $h($secs($agg['planned_maintenance'])) . '</td><td class="num">' . $h($secs($agg['monitoring_gap'])) . '</td><td class="num">' . $h($secs($agg['unknown']))
                    . '</td><td class="num">' . $h($secs($agg['not_enrolled'] + $agg['future'])) . '</td><td class="num">' . $pct($agg['availability']) . '</td><td class="num">' . $pct($agg['continuity']) . '</td></tr></table>';
                $rows = [];
                foreach ($a['per_firewall'] as $id => $r) { foreach ($r['events'] as $e) { if (in_array($e['class'], ['confirmed_outage', 'missed_checkin'], true)) { $rows[] = [$id] + $e; } } }
                if ($rows) {
                    $o .= '<h3>Outage and missed check-in periods</h3><table><tr><th>Firewall</th><th>Class</th><th>From</th><th>To</th><th>Duration</th></tr>';
                    foreach (array_slice($rows, 0, 100) as $e) { $o .= '<tr><td>' . $nm($e[0]) . '</td><td>' . $h(str_replace('_', ' ', $e['class'])) . '</td><td>' . $h($ts($e['start'])) . '</td><td>' . $h($ts($e['end'])) . '</td><td>' . $h($secs($e['end'] - $e['start'])) . '</td></tr>'; }
                    $o .= '</table>' . (count($rows) > 100 ? '<p class="muted">First 100 of ' . count($rows) . ' shown; all are in the CSV.</p>' : '');
                }
                break;
            case 'latency':
                $o .= '<table><tr><th>Firewall</th><th>Samples</th><th>Avg ms</th><th>p95 ms</th><th>Max ms</th></tr>';
                foreach ($s['latency']['per_firewall'] as $id => $l) {
                    $o .= '<tr><td>' . $nm($id) . '</td><td class="num">' . $h($l['count']) . '</td><td class="num">' . $na($l['avg'], fn($x) => $h(round($x, 1))) . '</td><td class="num">'
                        . $na($l['p95'], fn($x) => $h(round($x, 1))) . '</td><td class="num">' . $na($l['max'], fn($x) => $h(round($x, 1))) . '</td></tr>';
                }
                $o .= '</table>';
                if ($s['latency']['gateways']) {
                    $o .= '<h3>Gateways</h3><table><tr><th>Firewall</th><th>Gateway</th><th>Transitions</th><th>Down time</th><th>Down episodes</th><th>Max loss % at events</th></tr>';
                    foreach ($s['latency']['gateways'] as $g) {
                        $o .= '<tr><td>' . $nm($g['firewall_id']) . '</td><td>' . $h($g['name']) . '</td><td class="num">' . $h($g['transitions']) . '</td><td class="num">'
                            . ($g['observed'] ? $h($secs($g['down'])) . ($g['ongoing'] ? ' (ongoing)' : '') : '<span class="na">not observed</span>') . '</td><td class="num">'
                            . ($g['observed'] ? $h($g['episodes']) : '<span class="na">n/a</span>') . '</td><td class="num">' . $na($g['loss_max'], fn($x) => $h($x)) . '</td></tr>';
                    }
                    $o .= '</table><p class="muted">Gateway downtime comes from recorded status transitions only. Packet loss is known only at transition events; no continuous loss series is stored.</p>';
                } else {
                    $o .= '<p class="na">No gateway data is recorded for these firewalls.</p>';
                }
                break;
            case 'vpn':
                if (!$s['vpn']['tunnels']) { $o .= '<p class="na">No VPN tunnels are recorded for these firewalls.</p>'; break; }
                $o .= '<table><tr><th>Firewall</th><th>Tunnel</th><th>Type</th><th>Current</th><th>Transitions</th><th>Down time</th></tr>';
                foreach ($s['vpn']['tunnels'] as $t) {
                    $o .= '<tr><td>' . $nm($t['firewall_id']) . '</td><td>' . $h($t['name']) . '</td><td>' . $h($t['type']) . '</td><td>' . $h($t['current_status'] ?? 'unknown') . '</td><td class="num">' . $h($t['transitions'])
                        . '</td><td class="num">' . ($t['observed'] ? $h($secs($t['down'])) : '<span class="na">not observed</span>') . '</td></tr>';
                }
                $o .= '</table>';
                $vi = array_filter($s['incidents'], fn($i) => str_starts_with($i['type'], 'vpn.'));
                if ($vi) { $o .= '<h3>VPN incidents</h3>' . report_html_incidents($vi, $nm, $ts); }
                break;
            case 'maintenance':
                $o .= '<h3>Maintenance windows overlapping the period</h3>';
                if ($s['maintenance']['windows']) {
                    $o .= '<table><tr><th>Scope</th><th>From</th><th>To</th><th>Status</th><th>Reason</th></tr>';
                    foreach ($s['maintenance']['windows'] as $w) { $o .= '<tr><td>' . $h($w['scope']) . '</td><td>' . $h($ts($w['start'])) . '</td><td>' . $h($ts($w['end'])) . '</td><td>' . $h($w['status']) . '</td><td>' . $h($w['reason']) . '</td></tr>'; }
                    $o .= '</table>';
                } else { $o .= '<p class="muted">None.</p>'; }
                $o .= '<h3>Update activity</h3>';
                if ($s['maintenance']['updates']) {
                    $o .= '<table><tr><th>Firewall</th><th>Campaign</th><th>Status</th><th>Before</th><th>After</th></tr>';
                    foreach ($s['maintenance']['updates'] as $u) { $o .= '<tr><td>' . $nm((int)$u['firewall_id']) . '</td><td>' . $h($u['campaign']) . '</td><td>' . $h($u['status']) . '</td><td>' . $h($u['version_before']) . '</td><td>' . $h($u['version_after']) . '</td></tr>'; }
                    $o .= '</table>';
                } else { $o .= '<p class="muted">No update campaign activity in the period.</p>'; }
                break;
            case 'backups':
                $o .= '<table><tr><th>Firewall</th><th>Latest validated backup</th><th>Age</th><th>Fresh (&le; ' . $h($s['provenance']['backup_stale_hours']) . 'h)</th><th>Valid in period</th><th>Failed / unvalidated</th><th>Longest gap</th></tr>';
                foreach ($s['backups'] as $id => $b) {
                    $o .= '<tr><td>' . $nm($id) . '</td><td>' . $h($ts($b['latest_valid'])) . '</td><td>' . ($b['age_seconds'] === null ? '<span class="na">none</span>' : $h($secs($b['age_seconds']))) . '</td><td>'
                        . ($b['fresh'] === null ? '<span class="na">n/a</span>' : ($b['fresh'] ? 'Yes' : 'No')) . '</td><td class="num">' . $h($b['valid_in_period']) . '</td><td class="num">' . $h($b['invalid_in_period'] + $b['failure_incidents'])
                        . '</td><td class="num">' . ($b['longest_gap_seconds'] === null ? '<span class="na">n/a</span>' : $h($secs($b['longest_gap_seconds']))) . '</td></tr>';
                }
                $o .= '</table>';
                break;
            case 'templates':
                if (!$s['templates']['available']) { $o .= '<p class="na">Not available. ' . $h($s['templates']['note']) . '</p>'; break; }
                $o .= '<table><tr><th>Firewall</th><th>Template</th><th>Status</th><th>Exceptions</th></tr>';
                foreach ($s['templates']['rows'] as $r) { $o .= '<tr><td>' . $nm((int)($r['firewall_id'] ?? 0)) . '</td><td>' . $h($r['template'] ?? '') . '</td><td>' . $h($r['status'] ?? 'unknown') . '</td><td class="num">' . $h($r['exceptions'] ?? 0) . '</td></tr>'; }
                $o .= '</table>';
                break;
            case 'config_changes':
                $o .= '<table><tr><th>Firewall</th><th>Drift vs baseline</th><th>Changed items</th><th>Baseline set</th></tr>';
                foreach ($s['inventory'] as $f) {
                    $d = $s['config_changes']['drift'][$f['id']] ?? null;
                    $o .= '<tr><td>' . $h($f['hostname']) . '</td><td>' . ($d ? $h($d['status'] . ($d['ack'] ? ' (acknowledged)' : '')) : '<span class="na">no baseline</span>') . '</td><td class="num">' . ($d ? $h($d['change_count']) : '<span class="na">n/a</span>')
                        . '</td><td>' . ($d && $d['baseline_at'] ? $h($ts((int)$d['baseline_at'])) : '<span class="na">n/a</span>') . '</td></tr>';
                }
                $o .= '</table><h3>Recorded change events</h3>';
                if ($s['config_changes']['events']) {
                    $o .= '<table><tr><th>When</th><th>Firewall</th><th>Event</th><th>Result</th></tr>';
                    foreach (array_slice($s['config_changes']['events'], 0, 100) as $e) { $o .= '<tr><td>' . $h($ts($e['at'])) . '</td><td>' . $nm($e['firewall_id']) . '</td><td>' . $h($e['action']) . '</td><td>' . ($e['success'] ? 'ok' : 'failed') . '</td></tr>'; }
                    $o .= '</table>';
                } else { $o .= '<p class="muted">No change events recorded in the audit log for this period.</p>'; }
                break;
            case 'findings':
                $open = array_filter($s['incidents'], fn($i) => $i['open_at_end']);
                $o .= $open ? report_html_incidents($open, $nm, $ts) : '<p class="muted">No incidents were open at the end of the period.</p>';
                if ($s['scans']) {
                    $o .= '<h3>Latest security scan (informational; findings are not tracked as resolved)</h3><table><tr><th>Firewall</th><th>Scan date</th><th>Grade</th><th>Critical</th><th>High</th></tr>';
                    foreach ($s['scans'] as $id => $c) { $o .= '<tr><td>' . $nm($id) . '</td><td>' . $h($ts((int)$c['t'])) . '</td><td>' . $h($c['overall_grade']) . '</td><td class="num">' . $h($c['crit']) . '</td><td class="num">' . $h($c['high']) . '</td></tr>'; }
                    $o .= '</table>';
                }
                break;
            case 'recommendations':
                if (!$s['recommendations']) { $o .= '<p class="muted">No follow-up actions were triggered by the evidence in this report.</p>'; break; }
                $o .= '<table><tr><th>Priority</th><th>Action</th><th>Evidence</th></tr>';
                foreach ($s['recommendations'] as $r) { $o .= '<tr><td class="sev-' . $h($r['severity']) . '">' . $h($r['severity']) . '</td><td>' . $h($r['text']) . '</td><td>' . $h($r['evidence']) . '</td></tr>'; }
                $o .= '</table><p class="muted">Rules are deterministic and fire only on the evidence shown.</p>';
                break;
            }
        }

        $o .= '<h2>Appendix: method, definitions and data coverage</h2><table><tr><th>Item</th><th>Definition</th></tr>';
        foreach (report_formula_text() as $k => $v) { $o .= '<tr><td>' . $h($k) . '</td><td>' . $h($v) . '</td></tr>'; }
        $p = $s['provenance'];
        $o .= '</table><table><tr><th>Provenance</th><th>Value</th></tr>'
            . '<tr><td>Time range</td><td>' . $h($ts($s['period']['start'])) . ' to ' . $h($ts($s['period']['end'])) . ', timezone ' . $h($tz) . '</td></tr>'
            . '<tr><td>Data used up to</td><td>' . $h($ts($s['period']['effective_end'])) . '</td></tr>'
            . '<tr><td>Earliest telemetry held</td><td>' . $h($ts($p['data_horizon'])) . ' (retention ' . $h($p['telemetry_retention_days']) . ' days)</td></tr>'
            . '<tr><td>Heartbeat sources</td><td>' . $h(implode(', ', $p['heartbeat_sources'])) . '</td></tr>'
            . '<tr><td>Firewalls reporting fleet-wide</td><td>' . $h($p['firewalls_reporting_fleetwide']) . ' (manager-gap threshold ' . $h($p['manager_gap_threshold_seconds']) . 's, ' . ($p['manager_gaps_trusted'] ? 'trusted' : 'not trusted') . ')</td></tr>'
            . '<tr><td>Exclusions</td><td>' . ($p['exclusions'] ? $h(implode(' ', $p['exclusions'])) : 'None') . '</td></tr>'
            . '<tr><td>SLA</td><td>' . $h($p['sla']) . '</td></tr>'
            . '<tr><td>Generator</td><td>' . $h($s['generator']['app_version'] ?? 'unknown') . ', metric definitions ' . $h($s['generator']['metric_definitions']) . '</td></tr></table>';
        return $o . '</body></html>';
    }
}

if (!function_exists('report_html_incidents')) {
    function report_html_incidents(array $inc, callable $nm, callable $ts): string {
        $o = '<table><tr><th>Firewall</th><th>Type</th><th>Severity</th><th>Title</th><th>Opened</th><th>Resolved</th></tr>';
        foreach ($inc as $i) {
            $o .= '<tr><td>' . $nm($i['firewall_id']) . '</td><td>' . report_h($i['type']) . '</td><td>' . report_h($i['severity']) . '</td><td>' . report_h($i['title'])
                . '</td><td>' . report_h($ts($i['first_seen'])) . '</td><td>' . ($i['resolved_at'] && !$i['open_at_end'] ? report_h($ts($i['resolved_at'])) : 'open') . '</td></tr>';
        }
        return $o . '</table>';
    }
}

if (!function_exists('report_csv_cell')) {
    /** Neutralise spreadsheet formula injection from hostnames and titles. */
    function report_csv_cell($v): string {
        $v = (string)$v;
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) { $v = "'" . $v; }
        return $v;
    }
}

if (!function_exists('report_render_csv')) {
    /** Long-format CSV: table,firewall,key,start,end,value,unit,detail. */
    function report_render_csv(array $s): string {
        $fh = fopen('php://temp', 'w+');
        $tz = $s['period']['timezone'];
        $put = function (array $row) use ($fh) { fputcsv($fh, array_map('report_csv_cell', $row), ',', '"', '\\'); };
        $put(['table', 'firewall', 'key', 'start', 'end', 'value', 'unit', 'detail']);
        $put(['meta', '', 'period', report_fmt_ts($s['period']['start'], $tz), report_fmt_ts($s['period']['end'], $tz), $tz, 'timezone', 'generated ' . report_fmt_ts($s['generated_at'], $tz)]);
        $nm = []; foreach ($s['inventory'] as $f) { $nm[$f['id']] = $f['hostname']; }
        $ts = fn($t) => $t === null ? '' : report_fmt_ts($t, $tz);
        foreach ($s['availability']['per_firewall'] as $id => $r) {
            foreach (['up', 'missed_checkin', 'confirmed_outage', 'planned_maintenance', 'monitoring_gap', 'unknown', 'not_enrolled', 'future'] as $k) {
                $put(['availability', $nm[$id] ?? $id, $k, '', '', $r[$k], 'seconds', '']);
            }
            $put(['availability', $nm[$id] ?? $id, 'availability', '', '', $r['availability'] === null ? 'n/a' : round($r['availability'] * 100, 4), 'percent', 'device measurable seconds ' . $r['measurable']]);
            $put(['availability', $nm[$id] ?? $id, 'coverage', '', '', $r['coverage'] === null ? 'n/a' : round($r['coverage'] * 100, 2), 'percent', '']);
            foreach ($r['events'] as $e) { $put(['availability_event', $nm[$id] ?? $id, $e['class'], $ts($e['start']), $ts($e['end']), $e['end'] - $e['start'], 'seconds', '']); }
        }
        $g = $s['availability']['aggregate'];
        $put(['availability', 'ALL (device-time-weighted)', 'availability', '', '', $g['availability'] === null ? 'n/a' : round($g['availability'] * 100, 4), 'percent', '']);
        foreach ($s['availability']['manager_gaps'] as $m) { $put(['monitoring_gap', '', 'manager_no_checkins', $ts($m['start']), $ts($m['end']), $m['seconds'], 'seconds', '']); }
        foreach ($s['latency']['per_firewall'] as $id => $l) {
            foreach (['count', 'avg', 'p50', 'p95', 'max'] as $k) { $put(['latency', $nm[$id] ?? $id, $k, '', '', $l[$k] === null ? 'n/a' : round((float)$l[$k], 2), $k === 'count' ? 'samples' : 'ms', '']); }
        }
        foreach ($s['latency']['gateways'] as $gw) {
            $put(['gateway', $nm[$gw['firewall_id']] ?? $gw['firewall_id'], $gw['name'], '', '', $gw['observed'] ? $gw['down'] : 'n/a', 'down_seconds', 'transitions ' . $gw['transitions'] . '; max loss ' . ($gw['loss_max'] ?? 'n/a')]);
        }
        foreach ($s['vpn']['tunnels'] as $t) {
            $put(['vpn', $nm[$t['firewall_id']] ?? $t['firewall_id'], $t['type'] . ':' . $t['name'], '', '', $t['observed'] ? $t['down'] : 'n/a', 'down_seconds', 'current ' . ($t['current_status'] ?? 'unknown')]);
        }
        foreach ($s['incidents'] as $i) {
            $put(['incident', $nm[$i['firewall_id']] ?? $i['firewall_id'], $i['type'], $ts($i['first_seen']), $i['open_at_end'] ? '' : $ts($i['resolved_at']), $i['severity'], 'severity', $i['title']]);
        }
        foreach ($s['backups'] as $id => $b) {
            $put(['backup', $nm[$id] ?? $id, 'latest_valid', $ts($b['latest_valid']), '', $b['age_seconds'] ?? 'n/a', 'age_seconds', 'fresh ' . var_export($b['fresh'], true)]);
            $put(['backup', $nm[$id] ?? $id, 'valid_in_period', '', '', $b['valid_in_period'], 'count', '']);
        }
        foreach ($s['config_changes']['events'] as $e) { $put(['config_change', $nm[$e['firewall_id']] ?? $e['firewall_id'], $e['action'], $ts($e['at']), '', $e['success'] ? 'ok' : 'failed', '', '']); }
        rewind($fh);
        return (string)stream_get_contents($fh);
    }
}
