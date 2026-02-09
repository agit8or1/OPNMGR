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
                                    <h6 class="mb-0 text-light">Recent Activity</h6>
                                </div>
                                <div class="card-body">
                                    <div class="list-group list-group-flush">
                                        <div class="list-group-item bg-transparent border-secondary">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-light">System Status</h6>
                                                <small class="text-success">Active</small>
                                            </div>
                                            <p class="mb-1 text-muted small">All systems operational</p>
                                        </div>
                                        <div class="list-group-item bg-transparent border-secondary">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-light">Last Check-in</h6>
                                                <small class="text-muted">Just now</small>
                                            </div>
                                            <p class="mb-1 text-muted small"><?php echo $online_firewalls; ?> firewall(s) reporting</p>
                                        </div>
                                        <div class="list-group-item bg-transparent border-secondary">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-light">Updates Available</h6>
                                                <small class="text-warning"><?php echo $need_updates; ?> pending</small>
                                            </div>
                                            <p class="mb-1 text-muted small">Review available updates</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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
