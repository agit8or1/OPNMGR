<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/firewall_health.php';
require_once __DIR__ . '/inc/alerting.php';
require_once __DIR__ . '/inc/maintenance.php';
require_once __DIR__ . '/inc/health.php';
requireLogin();
require_once 'inc/header.php';

// Get firewall statistics
$total_firewalls = 0;
$online_firewalls = 0;
$offline_firewalls = 0;
$need_updates = 0;
$firewalls = [];
$avg_health = 0;

if (db()) {
    try {
        $stmt = db()->query("
            SELECT f.id, f.hostname, f.wan_ip, f.lan_ip, f.status, f.version,
                   f.uptime, f.updates_available, f.last_checkin, f.agent_version,
                   f.reboot_required, f.current_version, f.available_version,
                   f.customer_name,
                   fa.last_checkin as agent_last_checkin
            FROM firewalls f
            LEFT JOIN firewall_agents fa ON f.id = fa.firewall_id AND fa.agent_type = 'primary'
            ORDER BY f.hostname ASC
        ");
        $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total_firewalls = count($firewalls);
        $health_sum = 0;

        foreach ($firewalls as &$fw) {
            $fw['health_score'] = calculateHealthScore($fw);
            $health_sum += $fw['health_score'];
            $fw['live_status'] = 'offline';
            if (!empty($fw['agent_last_checkin'])) {
                $mins = (time() - strtotime($fw['agent_last_checkin'])) / 60;
                if ($mins <= 5) $fw['live_status'] = 'online';
                elseif ($mins <= 1440) $fw['live_status'] = 'stale';
            } elseif ($fw['status'] === 'online' && !empty($fw['last_checkin'])) {
                $mins = (time() - strtotime($fw['last_checkin'])) / 60;
                if ($mins <= 5) $fw['live_status'] = 'online';
                elseif ($mins <= 1440) $fw['live_status'] = 'stale';
            }
            if ($fw['live_status'] === 'online') $online_firewalls++;
            if ($fw['updates_available']) $need_updates++;
        }
        unset($fw);
        $offline_firewalls = $total_firewalls - $online_firewalls;
        $avg_health = $total_firewalls > 0 ? round($health_sum / $total_firewalls) : 0;
    } catch (Exception $e) {
        error_log("Dashboard error: " . $e->getMessage());
    }
}
$healthColor = $avg_health >= 80 ? 'var(--success)' : ($avg_health >= 50 ? 'var(--warning)' : 'var(--danger)');
?>

<style>
/* ── Dashboard layout ─────────────────────────────────────────────── */
.dash-wrapper {
    display: flex;
    flex-direction: column;
    gap: 18px;
    /* no min-height — content determines page length */
}

/* ── KPI row ── */
.dash-toolbar {
    display: flex;
    align-items: center;
    gap: 14px;
}
.dash-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(140px, 1fr));
    gap: 12px;
    flex: 1;
    min-width: 0;
}
.dash-stat {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    transition: border-color 0.15s, box-shadow 0.15s;
    min-width: 0;
}
.dash-stat:hover {
    border-color: rgba(59,130,246,0.25);
    box-shadow: var(--shadow);
    color: inherit;
    text-decoration: none;
}
.dash-stat-icon { font-size: 1.15rem; flex-shrink: 0; }
.dash-stat-body { min-width: 0; }
.dash-stat-val  { font-size: 1.65rem; font-weight: 700; line-height: 1; }
.dash-stat-lbl  { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 3px; }
.dash-refresh-ctl { flex-shrink: 0; }
.dash-refresh-ctl select {
    font-size: 0.8rem;
    padding: 6px 10px;
    border-radius: 6px;
    background: var(--input-bg);
    border: 1px solid var(--input-border);
    color: var(--text-primary);
    min-width: 150px;
}

/* ── Firewall health table ── */
.dash-section-hdr {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}
.dash-section-hdr h6 { margin: 0; font-weight: 600; font-size: 0.95rem; }
.dash-section-hdr .count { font-size: 0.75rem; color: var(--text-muted); }

.dash-fw-wrap {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
}
.dash-fw-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.dash-fw-table thead th {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
    position: sticky;
    top: 0;
    background: var(--bg-surface);
    z-index: 2;
}
.dash-fw-table tbody tr {
    border-bottom: 1px solid var(--border-subtle, rgba(255,255,255,0.03));
    cursor: pointer;
    transition: background 0.1s;
}
.dash-fw-table tbody tr:last-child { border-bottom: none; }
.dash-fw-table tbody tr:hover { background: var(--table-hover, rgba(255,255,255,0.02)); }
.dash-fw-table td {
    padding: 10px 14px;
    vertical-align: middle;
    white-space: nowrap;
}
.dash-fw-table td a { color: inherit; text-decoration: none; }
.dash-fw-table td a:hover { color: var(--accent); }
.dash-fw-name { font-weight: 600; font-size: 0.85rem; }
.dash-fw-ip   { color: var(--text-muted); font-size: 0.8rem; font-family: monospace; }
.dash-fw-cust { font-size: 0.7rem; padding: 1px 7px; background: var(--sidebar-active); border-radius: 3px; color: var(--accent); }
.dash-fw-health-cell { min-width: 120px; }
.dash-fw-health-cell .health-bar { width: 60px; display: inline-block; vertical-align: middle; margin-right: 6px; }
.dash-fw-health-pct { font-weight: 600; font-size: 0.8rem; }
.dash-fw-table .badge { font-size: 0.6rem; padding: 2px 6px; }

/* ── Lower panel: chart + map ── */
.dash-lower {
    display: grid;
    grid-template-columns: minmax(220px, 260px) minmax(0, 1fr);
    gap: 14px;
    height: 300px;       /* fixed height — no infinite stretch */
}
.dash-chart-panel {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.dash-chart-panel h6 { font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin: 0 0 12px; flex-shrink: 0; }
.dash-chart-panel canvas { max-height: 260px; }
.dash-map-panel {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.dash-map-panel h6 { font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); padding: 14px 18px 0; margin: 0; flex-shrink: 0; }
.dash-map-panel #networkMap { flex: 1; min-height: 0; width: 100%; background: var(--bg); }

/* Leaflet controls: keep them visible above the dark chrome and on-theme */
#networkMap .leaflet-control-container { z-index: 500; }
#networkMap .leaflet-bar a {
    background: var(--bg-surface);
    color: var(--text-primary);
    border-bottom-color: var(--border);
    font-weight: 600;
}
#networkMap .leaflet-bar a:hover { background: var(--bg-elevated); color: var(--accent); }
#networkMap .leaflet-bar { border: 1px solid var(--border); box-shadow: 0 1px 4px rgba(0,0,0,.4); }
#networkMap .leaflet-control-attribution {
    background: rgba(0,0,0,.45);
    color: #cbd5e1;
    font-size: 0.6rem;
}
#networkMap .leaflet-control-attribution a { color: #93c5fd; }

/* ── Responsive ── */
@media (max-width: 1200px) {
    .dash-stats { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 900px) {
    .dash-lower { grid-template-columns: 1fr; min-height: auto; }
    .dash-stats { grid-template-columns: repeat(2, 1fr); }
    .dash-toolbar { flex-wrap: wrap; }
}
@media (max-width: 600px) {
    .dash-stats { grid-template-columns: 1fr; }
    .dash-fw-grid { grid-template-columns: 1fr; }
    .dash-toolbar { flex-direction: column; align-items: stretch; }
    .dash-refresh-ctl select { width: 100%; }
}
</style>

<div class="dash-wrapper">

<?php
// Operational roll-ups for the KPI strip. Each tile links to the page that can
// actually resolve it, so the strip is a triage surface rather than decoration.
$health_summary = health_fleet_summary();
$incident_counts = alert_incident_counts();
$in_maintenance = count(maintenance_firewalls_in_window());

try {
    $reboot_required = (int)db()->query('SELECT COUNT(*) FROM firewalls WHERE reboot_required = 1')->fetchColumn();
} catch (Throwable $e) { $reboot_required = 0; }

try {
    $drift_count = (int)db()->query(
        "SELECT COUNT(*) FROM config_drift WHERE status = 'drifted' AND acknowledged_at IS NULL"
    )->fetchColumn();
} catch (Throwable $e) { $drift_count = 0; }

try {
    $backup_failures = (int)db()->query(
        "SELECT COUNT(*) FROM firewalls WHERE last_backup_status = 'failed'"
    )->fetchColumn();
} catch (Throwable $e) { $backup_failures = 0; }
?>
  <!-- ── KPI ROW + REFRESH ── -->
  <div class="dash-toolbar">
    <div class="dash-stats">
      <a class="dash-stat" href="/firewalls.php">
        <span class="dash-stat-icon" style="color:var(--accent)"><i class="fas fa-network-wired"></i></span>
        <div class="dash-stat-body"><div class="dash-stat-val"><?php echo $total_firewalls ?></div><div class="dash-stat-lbl">Firewalls</div></div>
      </a>
      <a class="dash-stat" href="/firewalls.php?status=online">
        <span class="dash-stat-icon" style="color:var(--success)"><i class="fas fa-circle"></i></span>
        <div class="dash-stat-body"><div class="dash-stat-val" style="color:var(--success)"><?php echo $online_firewalls ?></div><div class="dash-stat-lbl">Online</div></div>
      </a>
      <div class="dash-stat">
        <span class="dash-stat-icon" style="color:var(--danger)"><i class="fas fa-circle-xmark"></i></span>
        <div class="dash-stat-body"><div class="dash-stat-val" style="color:var(--danger)"><?php echo $offline_firewalls ?></div><div class="dash-stat-lbl">Offline</div></div>
      </div>
      <a class="dash-stat" href="/firewalls.php?status=need_updates">
        <span class="dash-stat-icon" style="color:var(--warning)"><i class="fas fa-arrow-up-from-bracket"></i></span>
        <div class="dash-stat-body"><div class="dash-stat-val" style="color:var(--warning)"><?php echo $need_updates ?></div><div class="dash-stat-lbl">Updates</div></div>
      </a>
      <div class="dash-stat">
        <span class="dash-stat-icon" style="color:<?php echo $healthColor ?>"><i class="fas fa-heart-pulse"></i></span>
        <div class="dash-stat-body"><div class="dash-stat-val" style="color:<?php echo $healthColor ?>"><?php echo $avg_health ?>%</div><div class="dash-stat-lbl">Avg Health</div></div>
      </div>

      <?php
      // Only surface a tile when it has something to say. A row of zeros is
      // noise, and the point of the strip is that a non-zero number means
      // somebody should look at something.
      $operational_tiles = [
          ['n' => $reboot_required,                    'lbl' => 'Reboot Req',   'icon' => 'fa-power-off',      'colour' => 'var(--danger)',  'href' => '/fleet_updates.php'],
          ['n' => $health_summary['gateways_down'],    'lbl' => 'GW Down',      'icon' => 'fa-road-circle-xmark','colour' => 'var(--danger)', 'href' => '/firewall_health.php'],
          ['n' => $health_summary['vpn_down'],         'lbl' => 'VPN Down',     'icon' => 'fa-shield-halved',  'colour' => 'var(--danger)',  'href' => '/firewall_health.php'],
          ['n' => $drift_count,                        'lbl' => 'Drift',        'icon' => 'fa-code-compare',   'colour' => 'var(--warning)', 'href' => '/config_drift.php'],
          ['n' => $backup_failures,                    'lbl' => 'Backup Fail',  'icon' => 'fa-database',       'colour' => 'var(--warning)', 'href' => '/firewalls.php'],
          ['n' => $health_summary['certs_expiring'] + $health_summary['certs_expired'],
                                                       'lbl' => 'Certs',        'icon' => 'fa-certificate',    'colour' => 'var(--warning)', 'href' => '/firewall_health.php'],
          ['n' => $incident_counts['critical'],        'lbl' => 'Critical',     'icon' => 'fa-bell',           'colour' => 'var(--danger)',  'href' => '/incidents.php?severity=critical'],
          ['n' => $in_maintenance,                     'lbl' => 'Maintenance',  'icon' => 'fa-screwdriver-wrench', 'colour' => 'var(--text-muted)', 'href' => '/maintenance.php'],
      ];
      foreach ($operational_tiles as $tile):
          if ((int)$tile['n'] <= 0) continue; ?>
        <a class="dash-stat" href="<?php echo htmlspecialchars($tile['href']); ?>">
          <span class="dash-stat-icon" style="color:<?php echo $tile['colour']; ?>"><i class="fas <?php echo $tile['icon']; ?>"></i></span>
          <div class="dash-stat-body">
            <div class="dash-stat-val" style="color:<?php echo $tile['colour']; ?>"><?php echo (int)$tile['n']; ?></div>
            <div class="dash-stat-lbl"><?php echo htmlspecialchars($tile['lbl']); ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="dash-refresh-ctl">
      <select id="autoRefreshSelect" onchange="setAutoRefresh()">
        <option value="0">No Auto Refresh</option>
        <option value="60">Refresh 1 min</option>
        <option value="120">Refresh 2 min</option>
        <option value="300">Refresh 5 min</option>
        <option value="600">Refresh 10 min</option>
      </select>
    </div>
  </div>

  <!-- ── FIREWALL HEALTH GRID ── -->
  <div>
    <div class="dash-section-hdr">
      <i class="fas fa-server" style="color:var(--accent);font-size:0.85rem"></i>
      <h6>Firewall Health</h6>
      <span class="count"><?php echo $total_firewalls ?> device<?php echo $total_firewalls !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($firewalls)): ?>
    <div style="text-align:center;padding:48px 20px;color:var(--text-muted);background:var(--bg-surface);border:1px solid var(--border);border-radius:8px">
      <i class="fas fa-network-wired" style="font-size:2.5rem;margin-bottom:12px;opacity:0.25"></i>
      <p style="margin:0">No firewalls configured</p>
      <p style="font-size:0.85rem;margin-top:6px"><a href="/add_firewall_page.php">Add your first firewall</a></p>
    </div>
    <?php else: ?>
    <div class="dash-fw-wrap" <?php if ($total_firewalls > 15): ?>style="max-height:420px;overflow-y:auto"<?php endif ?>>
      <table class="dash-fw-table">
        <thead>
          <tr>
            <th>Status</th>
            <th>Firewall</th>
            <th>WAN IP</th>
            <th>Customer</th>
            <th>Health</th>
            <th>Uptime</th>
            <th>Checkin</th>
            <th>Agent</th>
            <th>Flags</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($firewalls as $fw):
            $health = $fw['health_score'];
            $healthClass = $health >= 80 ? 'good' : ($health >= 50 ? 'warn' : 'bad');
            $statusClass = $fw['live_status'];
            $checkinText = timeAgo($fw['agent_last_checkin'] ?: $fw['last_checkin']);
            $shortUptime = $fw['uptime'] ?: 'N/A';
            if (preg_match('/(\d+)\s*days?/', $shortUptime, $m)) $shortUptime = $m[1] . 'd';
            elseif (preg_match('/(\d+)\s*hours?/', $shortUptime, $m)) $shortUptime = $m[1] . 'h';
          ?>
          <tr onclick="window.location='/firewall_details.php?id=<?php echo $fw['id'] ?>'">
            <td><span class="status-dot <?php echo $statusClass ?>"></span></td>
            <td><a href="/firewall_details.php?id=<?php echo $fw['id'] ?>" class="dash-fw-name"><?php echo htmlspecialchars($fw['hostname'] ?: 'Unnamed') ?></a></td>
            <td class="dash-fw-ip"><?php echo htmlspecialchars($fw['wan_ip'] ?: '-') ?></td>
            <td><?php if ($fw['customer_name']): ?><span class="dash-fw-cust"><?php echo htmlspecialchars($fw['customer_name']) ?></span><?php else: ?>-<?php endif ?></td>
            <td class="dash-fw-health-cell">
              <div class="health-bar"><div class="health-bar-fill <?php echo $healthClass ?>" style="width:<?php echo $health ?>%"></div></div>
              <span class="dash-fw-health-pct"><?php echo $health ?>%</span>
            </td>
            <td><?php echo htmlspecialchars($shortUptime) ?></td>
            <td><?php echo $checkinText ?></td>
            <td>v<?php echo htmlspecialchars($fw['agent_version'] ?: '?') ?></td>
            <td>
              <?php if ($fw['reboot_required']): ?><span class="badge" style="background:var(--warning);color:#000">Reboot</span><?php endif ?>
              <?php if ($fw['updates_available']): ?><span class="badge" style="background:rgba(59,130,246,0.15);color:var(--accent)">Update</span><?php endif ?>
            </td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>
  </div>

  <!-- ── LOWER PANEL: chart + map ── -->
  <div class="dash-lower">
    <div class="dash-chart-panel">
      <h6>Status Distribution</h6>
      <canvas id="statusChart"></canvas>
    </div>
    <div class="dash-map-panel">
      <h6>Network Locations</h6>
      <div id="networkMap"></div>
    </div>
  </div>

</div><!-- /dash-wrapper -->

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var textColor = isDark ? '#9aa0a6' : '#6b7280';

    // Doughnut chart
    var statusCtx = document.getElementById('statusChart').getContext('2d');
    var statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Online', 'Offline', 'Updates'],
            datasets: [{
                data: [<?php echo $online_firewalls ?>, <?php echo $offline_firewalls ?>, <?php echo $need_updates ?>],
                backgroundColor: ['#22c55e', '#ef4444', '#f59e0b'],
                borderWidth: 0, spacing: 2, borderRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            onClick: function(e, el) {
                if (el.length) {
                    var s = ['online', 'offline', 'need_updates'];
                    window.location.href = '/firewalls.php?status=' + s[el[0].index];
                }
            },
            plugins: {
                legend: { position: 'bottom', labels: { color: textColor, font: { size: 11, weight: '500' }, usePointStyle: true, pointStyleWidth: 7, padding: 12 } }
            }
        }
    });

    document.addEventListener('themechange', function(e) {
        var c = e.detail.theme === 'dark' ? '#9aa0a6' : '#6b7280';
        statusChart.options.plugins.legend.labels.color = c;
        statusChart.update();
    });

    // Leaflet map
    var map = L.map('networkMap', {
        zoomControl: false,
        scrollWheelZoom: true,
        minZoom: 2,
        maxZoom: 16,
        worldCopyJump: true
    }).setView([39.0997, -94.5786], 4);

    // Colour basemap (CARTO Voyager) — readable labels, no washed-out grey
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap, &copy; CARTO',
        subdomains: 'abcd', maxZoom: 16
    }).addTo(map);

    L.control.zoom({ position: 'topright' }).addTo(map);

    // "Fit all" control so you can always get back to every site
    var FitAll = L.Control.extend({
        options: { position: 'topright' },
        onAdd: function() {
            var c = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
            var a = L.DomUtil.create('a', '', c);
            a.href = '#'; a.title = 'Fit all locations'; a.innerHTML = '&#9678;';
            L.DomEvent.on(a, 'click', function(e) {
                L.DomEvent.stop(e);
                fitAllMarkers();
            });
            return c;
        }
    });
    map.addControl(new FitAll());

    var mapBounds = [];
    function fitAllMarkers() {
        if (mapBounds.length > 1) {
            map.fitBounds(mapBounds, { padding: [40, 40], maxZoom: 9 });
        } else if (mapBounds.length === 1) {
            map.setView(mapBounds[0], 9);
        }
    }

    // Force Leaflet to recalculate size after flex layout settles
    setTimeout(function(){ map.invalidateSize(); fitAllMarkers(); }, 400);
    window.addEventListener('resize', function(){ map.invalidateSize(); });

    var srvIcon = L.divIcon({ className: 'custom-div-icon',
        html: '<div style="background:#3b82f6;width:26px;height:26px;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center"><i class="fas fa-server" style="color:#fff;font-size:11px"></i></div>',
        iconSize: [26,26], iconAnchor: [13,13] });
    var fwOn = L.divIcon({ className: 'custom-div-icon',
        html: '<div style="background:#22c55e;width:20px;height:20px;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center"><i class="fas fa-network-wired" style="color:#fff;font-size:8px"></i></div>',
        iconSize: [20,20], iconAnchor: [10,10] });
    var fwOff = L.divIcon({ className: 'custom-div-icon',
        html: '<div style="background:#ef4444;width:20px;height:20px;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center"><i class="fas fa-network-wired" style="color:#fff;font-size:8px"></i></div>',
        iconSize: [20,20], iconAnchor: [10,10] });

    // Nudge markers that land on the same pixel so none are hidden underneath
    function spread(key, lat, lon, seen) {
        var k = lat.toFixed(2) + ',' + lon.toFixed(2);
        var n = seen[k] = (seen[k] || 0) + 1;
        if (n === 1) return [lat, lon];
        var ang = (n - 1) * (Math.PI / 3), r = 0.035 * Math.ceil(n / 6);
        return [lat + r * Math.sin(ang), lon + r * Math.cos(ang)];
    }

    fetch('/api/get_map_locations.php').then(function(r){ return r.json(); }).then(function(data) {
        if (!data.success) return;
        var seen = {};
        if (data.server) {
            var sp = spread('srv', +data.server.latitude, +data.server.longitude, seen);
            mapBounds.push(sp);
            L.marker(sp, {icon: srvIcon, zIndexOffset: 1000}).addTo(map)
                .bindPopup('<div style="color:#000"><strong>'+data.server.name+'</strong><br><small>'+(data.server.hostname||'')+'</small></div>');
        }
        (data.firewalls||[]).forEach(function(fw) {
            var p = spread(fw.id, +fw.latitude, +fw.longitude, seen);
            mapBounds.push(p);
            var where = [fw.city, fw.country].filter(Boolean).join(', ');
            L.marker(p, {icon: fw.status==='online'?fwOn:fwOff}).addTo(map)
                .bindPopup('<div style="color:#000"><strong>'+fw.name+'</strong>'
                    + (where ? '<br><small>'+where+'</small>' : '')
                    + '<br>'+(fw.wan_ip||'')
                    + '<br><a href="/firewall_details.php?id='+fw.id+'">Details</a></div>');
        });
        fitAllMarkers();
    }).catch(function(err){ console.error('Map error:', err); });
});

var autoRefreshInterval = null;
function setAutoRefresh() {
    var s = parseInt(document.getElementById('autoRefreshSelect').value);
    if (autoRefreshInterval) { clearInterval(autoRefreshInterval); autoRefreshInterval = null; }
    if (s > 0) { autoRefreshInterval = setInterval(function(){ location.reload(); }, s*1000); localStorage.setItem('dashboardAutoRefresh', s); }
    else { localStorage.removeItem('dashboardAutoRefresh'); }
}
document.addEventListener('DOMContentLoaded', function() {
    var s = localStorage.getItem('dashboardAutoRefresh');
    if (s) { document.getElementById('autoRefreshSelect').value = s; setAutoRefresh(); }
});
</script>

<?php require_once 'inc/footer.php'; ?>
