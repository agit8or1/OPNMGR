<?php
require_once __DIR__ . '/inc/bootstrap.php';
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
    min-height: calc(100vh - 48px - 48px); /* viewport minus header minus padding */
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

/* ── Firewall health grid ── */
.dash-section-hdr {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
}
.dash-section-hdr h6 { margin: 0; font-weight: 600; font-size: 0.95rem; }
.dash-section-hdr .count { font-size: 0.75rem; color: var(--text-muted); }

.dash-fw-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 14px;
}
.dash-fw-card {
    display: flex;
    flex-direction: column;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 18px;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
    min-width: 0;
}
.dash-fw-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
    border-color: var(--accent);
    color: inherit;
    text-decoration: none;
}
.dash-fw-hdr {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}
.dash-fw-hdr .fw-name { font-weight: 600; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.dash-fw-hdr .fa-chevron-right { margin-left: auto; font-size: 0.6rem; color: var(--text-muted); flex-shrink: 0; }
.dash-fw-sub { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dash-fw-sub .cust-tag { margin-left: 6px; padding: 1px 7px; font-size: 0.65rem; background: var(--sidebar-active); border-radius: 3px; color: var(--accent); }
.dash-fw-divider { height: 1px; background: var(--border); margin-bottom: 12px; }
.dash-fw-metrics {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 16px;
    font-size: 0.8rem;
}
.dash-fw-metrics dt { color: var(--text-muted); font-weight: 400; margin: 0; }
.dash-fw-metrics dd { color: var(--text-primary); font-weight: 500; margin: 0; }
.dash-fw-health-row { display: flex; align-items: center; gap: 8px; }
.dash-fw-health-row .health-bar { flex: 1; }
.dash-fw-badges { display: flex; gap: 5px; flex-wrap: wrap; margin-top: auto; padding-top: 10px; }
.dash-fw-badges:empty { display: none; }

/* ── Lower panel: chart + map ── */
.dash-lower {
    display: grid;
    grid-template-columns: minmax(240px, 280px) minmax(0, 1fr);
    gap: 14px;
    flex: 1;            /* stretch to fill remaining viewport height */
    min-height: 340px;
}
.dash-chart-panel {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 18px;
    display: flex;
    flex-direction: column;
}
.dash-chart-panel h6 { font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin: 0 0 12px; }
.dash-chart-panel canvas { flex: 1; min-height: 0; }
.dash-map-panel {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.dash-map-panel h6 { font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); padding: 14px 18px 0; margin: 0; flex-shrink: 0; }
.dash-map-panel #networkMap { flex: 1; min-height: 280px; width: 100%; }

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
    <div class="dash-fw-grid">
      <?php foreach ($firewalls as $fw):
        $health = $fw['health_score'];
        $healthClass = $health >= 80 ? 'good' : ($health >= 50 ? 'warn' : 'bad');
        $statusClass = $fw['live_status'];
        $checkinText = timeAgo($fw['agent_last_checkin'] ?: $fw['last_checkin']);
        $shortUptime = $fw['uptime'] ?: 'N/A';
        if (preg_match('/(\d+)\s*days?/', $shortUptime, $m)) $shortUptime = $m[1] . 'd';
        elseif (preg_match('/(\d+)\s*hours?/', $shortUptime, $m)) $shortUptime = $m[1] . 'h';
      ?>
      <a class="dash-fw-card" href="/firewall_details.php?id=<?php echo $fw['id'] ?>">
        <div class="dash-fw-hdr">
          <span class="status-dot <?php echo $statusClass ?>"></span>
          <span class="fw-name"><?php echo htmlspecialchars($fw['hostname'] ?: 'Unnamed') ?></span>
          <i class="fas fa-chevron-right"></i>
        </div>
        <div class="dash-fw-sub">
          <?php echo htmlspecialchars($fw['wan_ip'] ?: '') ?>
          <?php if ($fw['customer_name']): ?><span class="cust-tag"><?php echo htmlspecialchars($fw['customer_name']) ?></span><?php endif ?>
        </div>
        <div class="dash-fw-divider"></div>
        <dl class="dash-fw-metrics">
          <dt>Health</dt>
          <dd>
            <div class="dash-fw-health-row">
              <div class="health-bar"><div class="health-bar-fill <?php echo $healthClass ?>" style="width:<?php echo $health ?>%"></div></div>
              <span><?php echo $health ?>%</span>
            </div>
          </dd>
          <dt>Uptime</dt>
          <dd><?php echo htmlspecialchars($shortUptime) ?></dd>
          <dt>Last checkin</dt>
          <dd><?php echo $checkinText ?></dd>
          <dt>Agent</dt>
          <dd>v<?php echo htmlspecialchars($fw['agent_version'] ?: '?') ?></dd>
        </dl>
        <div class="dash-fw-badges">
          <?php if ($fw['reboot_required']): ?><span class="badge" style="background:var(--warning);color:#000;font-size:0.6rem">Reboot</span><?php endif ?>
          <?php if ($fw['updates_available']): ?><span class="badge" style="background:rgba(59,130,246,0.15);color:var(--accent);font-size:0.6rem">Update</span><?php endif ?>
        </div>
      </a>
      <?php endforeach ?>
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
    var map = L.map('networkMap', { zoomControl: true }).setView([39.0997, -94.5786], 4);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap', maxZoom: 18
    }).addTo(map);

    // Force Leaflet to recalculate size after flex layout settles
    setTimeout(function(){ map.invalidateSize(); }, 400);
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

    fetch('/api/get_map_locations.php').then(function(r){ return r.json(); }).then(function(data) {
        if (!data.success) return;
        var bounds = [];
        if (data.server) {
            bounds.push([data.server.latitude, data.server.longitude]);
            L.marker([data.server.latitude, data.server.longitude], {icon: srvIcon}).addTo(map)
                .bindPopup('<div style="color:#000"><strong>'+data.server.name+'</strong><br><small>'+(data.server.hostname||'')+'</small></div>');
        }
        (data.firewalls||[]).forEach(function(fw) {
            bounds.push([fw.latitude, fw.longitude]);
            L.marker([fw.latitude, fw.longitude], {icon: fw.status==='online'?fwOn:fwOff}).addTo(map)
                .bindPopup('<div style="color:#000"><strong>'+fw.name+'</strong><br>'+(fw.wan_ip||'')+'<br><a href="/firewall_details.php?id='+fw.id+'">Details</a></div>');
        });
        if (bounds.length > 1) map.fitBounds(bounds, {padding: [30,30]});
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
