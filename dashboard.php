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
        // Get all firewalls with health-relevant data
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
            // Calculate health score
            $fw['health_score'] = calculateHealthScore($fw);
            $health_sum += $fw['health_score'];

            // Determine live status from last checkin
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
?>

<!-- KPI Strip -->
<div class="kpi-strip">
  <div class="kpi-pill">
    <span class="kpi-icon"><i class="fas fa-network-wired"></i></span>
    <div>
      <div class="kpi-value"><a href="/firewalls.php" style="color:inherit;text-decoration:none"><?php echo $total_firewalls ?></a></div>
      <div class="kpi-label">Firewalls</div>
    </div>
  </div>
  <div class="kpi-pill">
    <span class="kpi-icon" style="color:var(--success)"><i class="fas fa-circle"></i></span>
    <div>
      <div class="kpi-value" style="color:var(--success)"><a href="/firewalls.php?status=online" style="color:inherit;text-decoration:none"><?php echo $online_firewalls ?></a></div>
      <div class="kpi-label">Online</div>
    </div>
  </div>
  <div class="kpi-pill">
    <span class="kpi-icon" style="color:var(--danger)"><i class="fas fa-circle-xmark"></i></span>
    <div>
      <div class="kpi-value" style="color:var(--danger)"><?php echo $offline_firewalls ?></div>
      <div class="kpi-label">Offline</div>
    </div>
  </div>
  <div class="kpi-pill">
    <span class="kpi-icon" style="color:var(--warning)"><i class="fas fa-arrow-up-from-bracket"></i></span>
    <div>
      <div class="kpi-value" style="color:var(--warning)"><a href="/firewalls.php?status=need_updates" style="color:inherit;text-decoration:none"><?php echo $need_updates ?></a></div>
      <div class="kpi-label">Updates</div>
    </div>
  </div>
  <div class="kpi-pill">
    <span class="kpi-icon"><i class="fas fa-heart-pulse"></i></span>
    <div>
      <div class="kpi-value"><?php echo $avg_health ?>%</div>
      <div class="kpi-label">Avg Health</div>
    </div>
  </div>
  <div class="kpi-pill ms-auto" style="padding:8px 12px;min-width:auto">
    <select class="form-select form-select-sm" id="autoRefreshSelect" onchange="setAutoRefresh()" style="width:130px;font-size:0.8rem;padding:4px 8px;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary)">
      <option value="0">No Refresh</option>
      <option value="60">1 min</option>
      <option value="120">2 min</option>
      <option value="300">5 min</option>
      <option value="600">10 min</option>
    </select>
  </div>
</div>

<!-- Firewall Health Grid -->
<div class="d-flex align-items-center mb-3">
  <h6 style="margin:0;font-weight:600;color:var(--text-primary)"><i class="fas fa-server me-2" style="color:var(--accent)"></i>Firewall Health</h6>
  <span style="margin-left:8px;font-size:0.8rem;color:var(--text-muted)"><?php echo $total_firewalls ?> device<?php echo $total_firewalls !== 1 ? 's' : '' ?></span>
</div>

<?php if (empty($firewalls)): ?>
<div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
  <i class="fas fa-network-wired" style="font-size:3rem;margin-bottom:16px;opacity:0.3"></i>
  <p style="font-size:1.1rem;margin:0">No firewalls configured</p>
  <p style="font-size:0.85rem;margin-top:8px"><a href="/add_firewall_page.php">Add your first firewall</a></p>
</div>
<?php else: ?>
<div class="firewall-grid">
  <?php foreach ($firewalls as $fw):
    $health = $fw['health_score'];
    $healthClass = $health >= 80 ? 'good' : ($health >= 50 ? 'warn' : 'bad');
    $statusClass = $fw['live_status']; // online, stale, offline
    $checkinText = timeAgo($fw['agent_last_checkin'] ?: $fw['last_checkin']);
  ?>
  <a class="firewall-card" href="/firewall_details.php?id=<?php echo $fw['id'] ?>">
    <div class="fw-header">
      <span class="status-dot <?php echo $statusClass ?>"></span>
      <span class="fw-hostname"><?php echo htmlspecialchars($fw['hostname'] ?: 'Unnamed') ?></span>
      <i class="fas fa-chevron-right" style="margin-left:auto;font-size:0.65rem;color:var(--text-muted)"></i>
    </div>
    <div class="fw-meta">
      <?php if ($fw['wan_ip']): ?>WAN: <?php echo htmlspecialchars($fw['wan_ip']) ?><?php endif ?>
      <?php if ($fw['customer_name']): ?> &middot; <?php echo htmlspecialchars($fw['customer_name']) ?><?php endif ?>
    </div>
    <div class="fw-divider"></div>
    <div class="fw-stats">
      <div>
        <span class="fw-stat-label">Health</span>
        <div style="display:flex;align-items:center;gap:6px">
          <div class="health-bar" style="flex:1;min-width:50px">
            <div class="health-bar-fill <?php echo $healthClass ?>" style="width:<?php echo $health ?>%"></div>
          </div>
          <span class="fw-stat-value"><?php echo $health ?>%</span>
        </div>
      </div>
      <div>
        <span class="fw-stat-label">Uptime</span>
        <span class="fw-stat-value"><?php echo htmlspecialchars($fw['uptime'] ?: 'N/A') ?></span>
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
    <?php if ($fw['reboot_required']): ?>
    <div style="margin-top:8px"><span class="badge" style="background:var(--warning);color:#000;font-size:0.65rem">Reboot Required</span></div>
    <?php endif ?>
    <?php if ($fw['updates_available']): ?>
    <div style="margin-top:<?php echo $fw['reboot_required'] ? '4' : '8' ?>px"><span class="badge" style="background:rgba(59,130,246,0.15);color:var(--accent);font-size:0.65rem">Update Available</span></div>
    <?php endif ?>
  </a>
  <?php endforeach ?>
</div>
<?php endif ?>

<!-- Charts + Map Row -->
<div class="row g-3 mt-3">
  <div class="col-md-5">
    <div class="card">
      <div class="card-body">
        <h6 style="font-weight:600;font-size:0.85rem;color:var(--text-secondary);margin-bottom:16px">Status Distribution</h6>
        <canvas id="statusChart" height="220"></canvas>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="card">
      <div class="card-body" style="padding:0;overflow:hidden;border-radius:8px">
        <h6 style="font-weight:600;font-size:0.85rem;color:var(--text-secondary);padding:16px 16px 0">Network Locations</h6>
        <div id="networkMap" style="height:340px;width:100%"></div>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Theme-aware chart colors
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const textColor = isDark ? '#9aa0a6' : '#6b7280';

    // Status doughnut chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Online', 'Offline', 'Updates'],
            datasets: [{
                data: [<?php echo $online_firewalls ?>, <?php echo $offline_firewalls ?>, <?php echo $need_updates ?>],
                backgroundColor: ['#22c55e', '#ef4444', '#f59e0b'],
                borderWidth: 0,
                spacing: 2,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            cutout: '65%',
            onClick: function(event, activeElements) {
                if (activeElements.length > 0) {
                    const labels = ['online', 'offline', 'need_updates'];
                    window.location.href = '/firewalls.php?status=' + labels[activeElements[0].index];
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: textColor,
                        font: { size: 12, weight: '500' },
                        usePointStyle: true,
                        pointStyleWidth: 8,
                        padding: 16
                    }
                }
            }
        }
    });

    // Update chart colors on theme change
    document.addEventListener('themechange', function(e) {
        const c = e.detail.theme === 'dark' ? '#9aa0a6' : '#6b7280';
        statusChart.options.plugins.legend.labels.color = c;
        statusChart.update();
    });

    // Network map
    const map = L.map('networkMap').setView([39.0997, -94.5786], 4);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 18
    }).addTo(map);

    const serverIcon = L.divIcon({
        className: 'custom-div-icon',
        html: '<div style="background:#3b82f6;width:28px;height:28px;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center"><i class="fas fa-server" style="color:#fff;font-size:12px"></i></div>',
        iconSize: [28, 28], iconAnchor: [14, 14]
    });
    const fwOnIcon = L.divIcon({
        className: 'custom-div-icon',
        html: '<div style="background:#22c55e;width:22px;height:22px;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center"><i class="fas fa-network-wired" style="color:#fff;font-size:9px"></i></div>',
        iconSize: [22, 22], iconAnchor: [11, 11]
    });
    const fwOffIcon = L.divIcon({
        className: 'custom-div-icon',
        html: '<div style="background:#ef4444;width:22px;height:22px;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center"><i class="fas fa-network-wired" style="color:#fff;font-size:9px"></i></div>',
        iconSize: [22, 22], iconAnchor: [11, 11]
    });

    fetch('/api/get_map_locations.php')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const bounds = [];
            if (data.server) {
                bounds.push([data.server.latitude, data.server.longitude]);
                L.marker([data.server.latitude, data.server.longitude], {icon: serverIcon}).addTo(map)
                    .bindPopup('<div style="color:#000"><strong>' + data.server.name + '</strong><br><small>' + (data.server.hostname || '') + '</small></div>');
            }
            (data.firewalls || []).forEach(fw => {
                bounds.push([fw.latitude, fw.longitude]);
                L.marker([fw.latitude, fw.longitude], {icon: fw.status === 'online' ? fwOnIcon : fwOffIcon}).addTo(map)
                    .bindPopup('<div style="color:#000"><strong>' + fw.name + '</strong><br>' + (fw.wan_ip || '') + '<br><a href="/firewall_details.php?id=' + fw.id + '">Details</a></div>');
            });
            if (bounds.length > 1) map.fitBounds(bounds, {padding: [40, 40]});
        })
        .catch(err => console.error('Map error:', err));
});

// Auto-refresh
let autoRefreshInterval = null;
function setAutoRefresh() {
    const seconds = parseInt(document.getElementById('autoRefreshSelect').value);
    if (autoRefreshInterval) { clearInterval(autoRefreshInterval); autoRefreshInterval = null; }
    if (seconds > 0) {
        autoRefreshInterval = setInterval(() => location.reload(), seconds * 1000);
        localStorage.setItem('dashboardAutoRefresh', seconds);
    } else {
        localStorage.removeItem('dashboardAutoRefresh');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    const saved = localStorage.getItem('dashboardAutoRefresh');
    if (saved) { document.getElementById('autoRefreshSelect').value = saved; setAutoRefresh(); }
});
</script>

<?php require_once 'inc/footer.php'; ?>
