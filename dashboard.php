<?php
require_once 'inc/auth.php';
requireLogin();
require_once 'inc/db.php';
require_once 'inc/header.php';

// Get firewall statistics
$total_firewalls = 0;
$online_firewalls = 0;
$offline_firewalls = 0;
$need_updates = 0;
$recent_checkins = 0;

if ($DB) {
    try {
        $stmt = $DB->query('SELECT COUNT(*) as total FROM firewalls');
        $total_firewalls = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $DB->query('SELECT COUNT(*) as online FROM firewalls WHERE status = "online"');
        $online_firewalls = $stmt->fetch(PDO::FETCH_ASSOC)['online'];
        
        // Count firewalls that haven't checked in recently (last 24 hours)
        $stmt = $DB->query('SELECT COUNT(*) as recent FROM firewalls WHERE last_checkin > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        $recent_checkins = $stmt->fetch(PDO::FETCH_ASSOC)['recent'];
        
        // Count firewalls that need updates (no version or old version)
        $stmt = $DB->query('SELECT COUNT(*) as updates FROM firewalls WHERE version IS NULL OR version < "24.7"');
        $need_updates = $stmt->fetch(PDO::FETCH_ASSOC)['updates'];
        
        $offline_firewalls = $total_firewalls - $online_firewalls;
    } catch (Exception $e) {
        // Handle database errors gracefully
    }
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <div>
                            <small class="text-light fw-bold mb-0">
                                <i class="fas fa-chart-pie me-1"></i>Dashboard
                            </small>
                        </div>
                        <div class="ms-auto">
                            <select class="form-select form-select-sm" id="autoRefreshSelect" onchange="setAutoRefresh()" style="width: 150px;">
                                <option value="0">No Auto Refresh</option>
                                <option value="60">Refresh 1 min</option>
                                <option value="120">Refresh 2 min</option>
                                <option value="180">Refresh 3 min</option>
                                <option value="300">Refresh 5 min</option>
                                <option value="600">Refresh 10 min</option>
                            </select>
                        </div>
                    </div>                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="card card-ghost p-3">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h3 class="text-light mb-1"><a href="/firewalls.php" class="text-light text-decoration-none"><?php echo $total_firewalls; ?></a></h3>
                                        <p class="text-light mb-0" style="font-size: 0.9rem; font-weight: 500;">Total Firewalls</p>
                                    </div>
                                    <div class="text-primary">
                                        <i class="fas fa-network-wired fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-ghost p-3">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h3 class="text-success mb-1"><a href="/firewalls.php?status=online" class="text-success text-decoration-none"><?php echo $online_firewalls; ?></a></h3>
                                        <p class="text-light mb-0" style="font-size: 0.9rem; font-weight: 500;">Online</p>
                                    </div>
                                    <div class="text-success">
                                        <i class="fas fa-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-ghost p-3">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h3 class="text-warning mb-1"><a href="/firewalls.php?updates=needed" class="text-warning text-decoration-none"><?php echo $need_updates; ?></a></h3>
                                        <p class="text-light mb-0" style="font-size: 0.9rem; font-weight: 500;">Need Updates</p>
                                    </div>
                                    <div class="text-warning">
                                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-ghost p-3">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h3 class="text-danger mb-1"><?php echo $offline_firewalls; ?></h3>
                                        <p class="text-light mb-0" style="font-size: 0.9rem; font-weight: 500;">Offline</p>
                                    </div>
                                    <div class="text-danger">
                                        <i class="fas fa-times-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Charts Section -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card card-ghost">
                                <div class="card-header">
                                    <h6 class="mb-0 text-light">Firewall Status Distribution</h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="statusChart" width="400" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card card-ghost">
                                <div class="card-header">
                                    <h6 class="mb-0 text-light">Network Locations</h6>
                                </div>
                                <div class="card-body">
                                    <div id="networkMap" style="height: 400px; border-radius: 4px; overflow: hidden; position: relative;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Fix Leaflet map container to prevent tile fragmentation */
#networkMap {
    overflow: hidden !important;
    position: relative !important;
    width: 100% !important;
    height: 400px !important;
    clip-path: inset(0) !important;
}
#networkMap * {
    box-sizing: border-box !important;
}
#networkMap .leaflet-container {
    background: #1a1f2e !important;
    width: 100% !important;
    height: 100% !important;
    overflow: hidden !important;
}
#networkMap .leaflet-pane,
#networkMap .leaflet-map-pane,
#networkMap .leaflet-tile-pane,
#networkMap .leaflet-overlay-pane,
#networkMap .leaflet-marker-pane {
    clip-path: inset(0) !important;
    overflow: hidden !important;
}
#networkMap .leaflet-tile-container {
    overflow: hidden !important;
    transform: none !important;
}
#networkMap .leaflet-tile {
    image-rendering: auto !important;
    display: block !important;
}
/* Ensure card-body contains the map properly */
.card-body:has(#networkMap) {
    padding: 0 !important;
    overflow: hidden !important;
    position: relative !important;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Status Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Online', 'Offline', 'Need Updates'],
            datasets: [{
                data: [<?php echo $online_firewalls; ?>, <?php echo $offline_firewalls; ?>, <?php echo $need_updates; ?>],
                backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            onClick: function(event, activeElements) {
                if (activeElements.length > 0) {
                    const index = activeElements[0].index;
                    const labels = ['online', 'offline', 'need_updates'];
                    const status = labels[index];
                    window.location.href = '/firewalls.php?status=' + status;
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#ffffff',
                        font: {
                            size: 14,
                            weight: 'bold'
                        },
                        usePointStyle: true,
                        padding: 20
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#ffffff'
                    }
                },
                y: {
                    ticks: {
                        color: '#ffffff'
                    }
                }
            }
        }
    });

    // Initialize Network Map with delay to ensure container is ready
    setTimeout(function() {
        const map = L.map('networkMap', {
            zoomControl: true,
            attributionControl: true,
            scrollWheelZoom: true,
            dragging: true,
            touchZoom: true,
            doubleClickZoom: true,
            boxZoom: true,
            tap: true,
            keyboard: true,
            zoomAnimation: true,
            fadeAnimation: true,
            markerZoomAnimation: true
        }).setView([39.0997, -94.5786], 4);

        // Add OpenStreetMap tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 18,
            minZoom: 2,
            tileSize: 256,
            updateWhenIdle: false,
            updateWhenZooming: false,
            keepBuffer: 2
        }).addTo(map);

        // Force map to invalidate size after render
        setTimeout(function() {
            map.invalidateSize();
        }, 100);

        // Define custom icons
        const serverIcon = L.divIcon({
        className: 'custom-div-icon',
        html: '<div style="background-color: #3b82f6; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; display: flex; align-items: center; justify-content: center;"><i class="fas fa-server" style="color: white; font-size: 14px;"></i></div>',
        iconSize: [30, 30],
        iconAnchor: [15, 15]
    });

    const firewallOnlineIcon = L.divIcon({
        className: 'custom-div-icon',
        html: '<div style="background-color: #10b981; width: 24px; height: 24px; border-radius: 50%; border: 2px solid white; display: flex; align-items: center; justify-content: center;"><i class="fas fa-network-wired" style="color: white; font-size: 10px;"></i></div>',
        iconSize: [24, 24],
        iconAnchor: [12, 12]
    });

    const firewallOfflineIcon = L.divIcon({
        className: 'custom-div-icon',
        html: '<div style="background-color: #ef4444; width: 24px; height: 24px; border-radius: 50%; border: 2px solid white; display: flex; align-items: center; justify-content: center;"><i class="fas fa-network-wired" style="color: white; font-size: 10px;"></i></div>',
        iconSize: [24, 24],
        iconAnchor: [12, 12]
    });

    // Fetch location data and add markers
    fetch('/api/get_map_locations.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Add server marker
                if (data.server) {
                    const serverMarker = L.marker([data.server.latitude, data.server.longitude], {
                        icon: serverIcon
                    }).addTo(map);

                    const serverLocation = data.server.city && data.server.country
                        ? `<small>${data.server.city}, ${data.server.country}</small><br>`
                        : '';
                    const serverIp = data.server.ip ? `<small>IP: ${data.server.ip}</small><br>` : '';

                    serverMarker.bindPopup(`
                        <div style="color: #000;">
                            <strong>${data.server.name}</strong><br>
                            <small>${data.server.hostname}</small><br>
                            ${serverLocation}
                            ${serverIp}
                            <span class="badge bg-success">Online</span>
                        </div>
                    `);
                }

                // Add firewall markers
                const bounds = [[data.server.latitude, data.server.longitude]];
                data.firewalls.forEach(fw => {
                    const icon = fw.status === 'online' ? firewallOnlineIcon : firewallOfflineIcon;
                    const statusBadge = fw.status === 'online'
                        ? '<span class="badge bg-success">Online</span>'
                        : '<span class="badge bg-danger">Offline</span>';

                    const marker = L.marker([fw.latitude, fw.longitude], {
                        icon: icon
                    }).addTo(map);

                    const fwLocation = fw.city && fw.country
                        ? `<small>${fw.city}, ${fw.country}</small><br>`
                        : '';

                    marker.bindPopup(`
                        <div style="color: #000;">
                            <strong>${fw.name}</strong><br>
                            <small>${fw.hostname}</small><br>
                            ${fwLocation}
                            ${fw.wan_ip ? '<small>IP: ' + fw.wan_ip + '</small><br>' : ''}
                            ${statusBadge}
                            <br><a href="/firewall_details.php?id=${fw.id}" class="btn btn-sm btn-primary mt-2">View Details</a>
                        </div>
                    `);

                    bounds.push([fw.latitude, fw.longitude]);
                });

                // Fit map to show all markers if there are multiple locations
                if (bounds.length > 1) {
                    map.fitBounds(bounds, { padding: [50, 50] });
                }
            }
        })
        .catch(error => {
            console.error('Error loading map data:', error);
        });
    }, 250); // End setTimeout for map initialization
});

// Auto-refresh functionality
let autoRefreshInterval = null;

function setAutoRefresh() {
    const select = document.getElementById('autoRefreshSelect');
    const seconds = parseInt(select.value);
    
    // Clear existing interval
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
    
    // Set new interval if selected
    if (seconds > 0) {
        autoRefreshInterval = setInterval(() => {
            location.reload();
        }, seconds * 1000);
        
        // Store preference in localStorage
        localStorage.setItem('dashboardAutoRefresh', seconds);
    } else {
        localStorage.removeItem('dashboardAutoRefresh');
    }
}

// Load auto-refresh preference on page load
document.addEventListener('DOMContentLoaded', function() {
    const savedRefresh = localStorage.getItem('dashboardAutoRefresh');
    if (savedRefresh) {
        const select = document.getElementById('autoRefreshSelect');
        select.value = savedRefresh;
        setAutoRefresh();
    }
});
</script>

<?php require_once 'inc/footer.php'; ?>
