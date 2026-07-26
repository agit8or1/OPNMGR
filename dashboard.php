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
/* Dashboard-specific layout */
.dash-top { display: grid; grid-template-columns: 1fr 200px; gap: 16px; margin-bottom: 20px; align-items: start; }
.dash-kpis { display: flex; gap: 10px; flex-wrap: wrap; }
.dash-kpi { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: var(--bg-surface); border: 1px solid var(--border); border-radius: 8px; min-width: 120px; }
.dash-kpi-val { font-size: 1.6rem; font-weight: 700; line-height: 1; color: var(--text-primary); }
.dash-kpi-lbl { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px; }
.dash-kpi-icon { font-size: 1.1rem; }
.dash-refresh { display: flex; align-items: start; padding-top: 4px; }

.dash-grid-section { margin-bottom: 20px; }
.dash-section-hdr { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.dash-section-hdr h6 { margin: 0; font-weight: 600; font-size: 0.9rem; color: var(--text-primary); }
.dash-section-hdr .count { font-size: 0.75rem; color: var(--text-muted); }

.dash-bottom { display: grid; grid-template-columns: 200px 1fr; gap: 16px; }
.dash-chart-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: 8px; padding: 16px; }
.dash-chart-card h6 { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 12px; }
.dash-map-card { background: var(--bg-surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.dash-map-card h6 { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); padding: 12px 16px 0; margin: 0; }
.dash-map-card #networkMap { height: 280px; }

@media (max-width: 991px) {
    .dash-top { grid-template-columns: 1fr; }
    .dash-bottom { grid-template-columns: 1fr; }
}
@media (max-width: 767px) {
    .dash-kpis { flex-direction: column; }
    .dash-kpi { min-width: unset; }
}
</style>

<!-- Top: KPI pills + refresh -->
<div class="dash-top">
  <div class="dash-kpis">
    <a class="dash-kpi" href="/firewalls.php" style="text-decoration:none;color:inherit">
      <span class="dash-kpi-icon" style="color:var(--accent)"><i class="fas fa-network-wired"></i></span>
      <div><div class="dash-kpi-val"><?php echo $total_firewalls ?></div><div class="dash-kpi-lbl">Firewalls</div></div>
    </a>
    <a class="dash-kpi" href="/firewalls.php?status=online" style="text-decoration:none;color:inherit">
      <span class="dash-kpi-icon" style="color:var(--success)"><i class="fas fa-circle"></i></span>
      <div><div class="dash-kpi-val" style="color:var(--success)"><?php echo $online_firewalls ?></div><div class="dash-kpi-lbl">Online</div></div>
    </a>
    <div class="dash-kpi">
      <span class="dash-kpi-icon" style="color:var(--danger)"><i class="fas fa-circle-xmark"></i></span>
      <div><div class="dash-kpi-val" style="color:var(--danger)"><?php echo $offline_firewalls ?></div><div class="dash-kpi-lbl">Offline</div></div>
    </div>
    <a class="dash-kpi" href="/firewalls.php?status=need_updates" style="text-decoration:none;color:inherit">
      <span class="dash-kpi-icon" style="color:var(--warning)"><i class="fas fa-arrow-up-from-bracket"></i></span>
      <div><div class="dash-kpi-val" style="color:var(--warning)"><?php echo $need_updates ?></div><div class="dash-kpi-lbl">Updates</div></div>
    </a>
    <div class="dash-kpi">
      <span class="dash-kpi-icon" style="color:<?php echo $healthColor ?>"><i class="fas fa-heart-pulse"></i></span>
      <div><div class="dash-kpi-val" style="color:<?php echo $healthColor ?>"><?php echo $avg_health ?>%</div><div class="dash-kpi-lbl">Health</div></div>
    </div>
  </div>
  <div class="dash-refresh">
    <select class="form-select form-select-sm" id="autoRefreshSelect" onchange="setAutoRefresh()" style="font-size:0.8rem">
      <option value="0">No Auto Refresh</option>
      <option value="60">Refresh 1 min</option>
      <option value="120">Refresh 2 min</option>
      <option value="300">Refresh 5 min</option>
      <option value="600">Refresh 10 min</option>
    </select>
  </div>
</div>

<!-- Firewall Health Grid -->
<div class="dash-grid-section">
  <div class="dash-section-hdr">
    <i class="fas fa-server" style="color:var(--accent);font-size:0.85rem"></i>
    <h6>Firewall Health</h6>
    <span class="count"><?php echo $total_firewalls ?> device<?php echo $total_firewalls !== 1 ? 's' : '' ?></span>
  </div>

  <?php if (empty($firewalls)): ?>
  <div style="text-align:center;padding:48px 20px;color:var(--text-muted);background:var(--bg-surface);border:1px solid var(--border);border-radius:8px">
    <i class="fas fa-network-wired" style="font-size:2.5rem;margin-bottom:12px;opacity:0.25"></i>
    <p style="font-size:1rem;margin:0">No firewalls configured</p>
    <p style="font-size:0.85rem;margin-top:6px"><a href="/add_firewall_page.php">Add your first firewall</a></p>
  </div>
  <?php else: ?>
  <div class="firewall-grid">
    <?php foreach ($firewalls as $fw):
      $health = $fw['health_score'];
      $healthClass = $health >= 80 ? 'good' : ($health >= 50 ? 'warn' : 'bad');
      $statusClass = $fw['live_status'];
      $checkinText = timeAgo($fw['agent_last_checkin'] ?: $fw['last_checkin']);
      $shortUptime = $fw['uptime'] ?: 'N/A';
      // Shorten uptime display
      if (preg_match('/(\d+)\s*days?/', $shortUptime, $m)) $shortUptime = $m[1] . 'd';
      elseif (preg_match('/(\d+)\s*hours?/', $shortUptime, $m)) $shortUptime = $m[1] . 'h';
    ?>
    <a class="firewall-card" href="/firewall_details.php?id=<?php echo $fw['id'] ?>">
      <div class="fw-header">
        <span class="status-dot <?php echo $statusClass ?>"></span>
        <span class="fw-hostname"><?php echo htmlspecialchars($fw['hostname'] ?: 'Unnamed') ?></span>
        <i class="fas fa-chevron-right" style="margin-left:auto;font-size:0.6rem;color:var(--text-muted)"></i>
      </div>
      <div class="fw-meta">
        <?php if ($fw['wan_ip']): ?><?php echo htmlspecialchars($fw['wan_ip']) ?><?php endif ?>
        <?php if ($fw['customer_name']): ?><span style="margin-left:4px;padding:1px 6px;font-size:0.65rem;background:var(--sidebar-active);border-radius:3px;color:var(--accent)"><?php echo htmlspecialchars($fw['customer_name']) ?></span><?php endif ?>
      </div>
      <div class="fw-divider"></div>
      <div class="fw-stats">
        <div>
          <span class="fw-stat-label">Health</span>
          <div style="display:flex;align-items:center;gap:6px">
            <div class="health-bar" style="flex:1;min-width:40px">
              <div class="health-bar-fill <?php echo $healthClass ?>" style="width:<?php echo $health ?>%"></div>
            </div>
            <span class="fw-stat-value" style="font-size:0.75rem"><?php echo $health ?>%</span>
          </div>
        </div>
        <div>
          <span class="fw-stat-label">Uptime</span>
          <span class="fw-stat-value"><?php echo htmlspecialchars($shortUptime) ?></span>
        </div>
        <div>
          <span class="fw-stat-label">Checkin</span>
          <span class="fw-stat-value"><?php echo $checkinText ?></span>
        </div>
        <div>
          <span class="fw-stat-label">Agent</span>
          <span class="fw-stat-value">v<?php echo htmlspecialchars($fw['agent_version'] ?: '?') ?></span>
        </div>
      </div>
      <?php if ($fw['reboot_required'] || $fw['updates_available']): ?>
      <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:8px">
        <?php if ($fw['reboot_required']): ?><span class="badge" style="background:var(--warning);color:#000;font-size:0.6rem">Reboot</span><?php endif ?>
        <?php if ($fw['updates_available']): ?><span class="badge" style="background:rgba(59,130,246,0.15);color:var(--accent);font-size:0.6rem">Update</span><?php endif ?>
      </div>
      <?php endif ?>
    </a>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>

<!-- Bottom: Small chart + Map -->
<div class="dash-bottom">
  <div class="dash-chart-card">
    <h6>Status</h6>
    <canvas id="statusChart"></canvas>
  </div>
  <div class="dash-map-card">
    <h6>Network Locations</h6>
    <div id="networkMap"></div>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const textColor = isDark ? '#9aa0a6' : '#6b7280';

    // Compact doughnut chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
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
            maintainAspectRatio: true,
            cutout: '60%',
            onClick: function(e, el) {
                if (el.length) {
                    const s = ['online', 'offline', 'need_updates'];
                    window.location.href = '/firewalls.php?status=' + s[el[0].index];
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: textColor, font: { size: 11 }, usePointStyle: true, pointStyleWidth: 6, padding: 10 }
                }
            }
        }
    });

    document.addEventListener('themechange', function(e) {
        const c = e.detail.theme === 'dark' ? '#9aa0a6' : '#6b7280';
        statusChart.options.plugins.legend.labels.color = c;
        statusChart.update();
    });

    // Map
    const map = L.map('networkMap').setView([39.0997, -94.5786], 4);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap', maxZoom: 18
    }).addTo(map);

    const srvIcon = L.divIcon({ className: 'custom-div-icon',
        html: '<div style="background:#3b82f6;width:26px;height:26px;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center"><i class="fas fa-server" style="color:#fff;font-size:11px"></i></div>',
        iconSize: [26,26], iconAnchor: [13,13] });
    const fwOn = L.divIcon({ className: 'custom-div-icon',
        html: '<div style="background:#22c55e;width:20px;height:20px;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center"><i class="fas fa-network-wired" style="color:#fff;font-size:8px"></i></div>',
        iconSize: [20,20], iconAnchor: [10,10] });
    const fwOff = L.divIcon({ className: 'custom-div-icon',
        html: '<div style="background:#ef4444;width:20px;height:20px;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center"><i class="fas fa-network-wired" style="color:#fff;font-size:8px"></i></div>',
        iconSize: [20,20], iconAnchor: [10,10] });

    fetch('/api/get_map_locations.php').then(r => r.json()).then(data => {
        if (!data.success) return;
        const bounds = [];
        if (data.server) {
            bounds.push([data.server.latitude, data.server.longitude]);
            L.marker([data.server.latitude, data.server.longitude], {icon: srvIcon}).addTo(map)
                .bindPopup('<div style="color:#000"><strong>'+data.server.name+'</strong><br><small>'+(data.server.hostname||'')+'</small></div>');
        }
        (data.firewalls||[]).forEach(fw => {
            bounds.push([fw.latitude, fw.longitude]);
            L.marker([fw.latitude, fw.longitude], {icon: fw.status==='online'?fwOn:fwOff}).addTo(map)
                .bindPopup('<div style="color:#000"><strong>'+fw.name+'</strong><br>'+(fw.wan_ip||'')+'<br><a href="/firewall_details.php?id='+fw.id+'">Details</a></div>');
        });
        if (bounds.length > 1) map.fitBounds(bounds, {padding: [30,30]});
    }).catch(err => console.error('Map error:', err));
});

let autoRefreshInterval = null;
function setAutoRefresh() {
    const s = parseInt(document.getElementById('autoRefreshSelect').value);
    if (autoRefreshInterval) { clearInterval(autoRefreshInterval); autoRefreshInterval = null; }
    if (s > 0) { autoRefreshInterval = setInterval(() => location.reload(), s*1000); localStorage.setItem('dashboardAutoRefresh', s); }
    else { localStorage.removeItem('dashboardAutoRefresh'); }
}
document.addEventListener('DOMContentLoaded', function() {
    const s = localStorage.getItem('dashboardAutoRefresh');
    if (s) { document.getElementById('autoRefreshSelect').value = s; setAutoRefresh(); }
});
</script>

<?php require_once 'inc/footer.php'; ?>
