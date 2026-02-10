<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
include __DIR__ . '/inc/header.php';
?>

<style>
.card {
    background-color: var(--card) !important;
    border: 1px solid var(--border, rgba(255,255,255,0.08)) !important;
    color: var(--text, #f1f5f9) !important;
}
.card-header {
    background: var(--header, linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)) !important;
    color: white !important;
    border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)) !important;
}
.card-body {
    background-color: var(--card) !important;
}
</style>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-activity text-primary"></i> System Health Monitor</h2>
                <div>
                    <span id="lastUpdate" class="text-muted me-3"></span>
                    <button class="btn btn-outline-primary btn-sm" onclick="loadHealthData()">
                        <i class="bi bi-arrow-clockwise" id="refreshIcon"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Health Status Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center" id="cpuCard">
                        <div class="card-body">
                            <i class="bi bi-cpu display-4 text-primary"></i>
                            <h5 class="card-title mt-2">CPU Usage</h5>
                            <h3 class="mb-0" id="cpuUsage">N/A</h3>
                            <small class="text-muted" id="cpuStatus">Not monitored</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center" id="memoryCard">
                        <div class="card-body">
                            <i class="bi bi-memory display-4 text-info"></i>
                            <h5 class="card-title mt-2">Memory</h5>
                            <h3 class="mb-0" id="memoryUsage">N/A</h3>
                            <small class="text-muted" id="memoryStatus">Not monitored</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center" id="diskCard">
                        <div class="card-body">
                            <i class="bi bi-hdd display-4 text-warning"></i>
                            <h5 class="card-title mt-2">Disk Space</h5>
                            <h3 class="mb-0" id="diskUsage">N/A</h3>
                            <small class="text-muted" id="diskStatus">Not monitored</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center" id="loadCard">
                        <div class="card-body">
                            <i class="bi bi-speedometer2 display-4 text-success"></i>
                            <h5 class="card-title mt-2">Load Average</h5>
                            <h3 class="mb-0" id="loadAverage">N/A</h3>
                            <small class="text-muted" id="loadStatus">Not monitored</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Services -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-gear"></i> System Services</h5>
                </div>
                <div class="card-body">
                    <div id="servicesContent">
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading service status...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle"></i> System Information</h5>
                </div>
                <div class="card-body">
                    <div id="systemInfoContent">
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading system information...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Alerts -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Recent Alerts</h5>
                </div>
                <div class="card-body">
                    <div id="alertsContent">
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading alerts...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
        function loadHealthData() {
            // Load system health data
            fetch('/api/health_monitor.php?action=get_system_health')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateHealthDisplay(data);
                    } else {
                        console.error('Health API error:', data);
                    }
                })
                .catch(error => {
                    console.error('Error loading health data:', error);
                });
            
            // Load service status (includes system metrics)
            fetch('/api/health_monitor.php?action=get_service_status')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateSystemMetrics(data.services);
                    } else {
                        console.error('Service status API error:', data);
                    }
                })
                .catch(error => {
                    console.error('Error loading service status:', error);
                });
            
            // Load performance metrics
            fetch('/api/health_monitor.php?action=get_performance_metrics')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateLoadAverage(data.metrics.system_load);
                    } else {
                        console.error('Performance metrics API error:', data);
                    }
                })
                .catch(error => {
                    console.error('Error loading performance metrics:', error);
                });
        }
        
        function updateHealthDisplay(data) {
            // Update System Services section with component data
            var servicesHtml = '';
            if (data.components) {
                servicesHtml = '<div class="row">';
                for (var key in data.components) {
                    var component = data.components[key];
                    var statusClass = component.status === 'healthy' ? 'success' : 
                                     component.status === 'warning' ? 'warning' : 'danger';
                    var borderClass = component.status === 'healthy' ? 'border-success' : 
                                     component.status === 'warning' ? 'border-warning' : 'border-danger';
                    
                    servicesHtml += '<div class="col-md-6 mb-3">';
                    servicesHtml += '<div class="card h-100 ' + borderClass + '">';
                    servicesHtml += '<div class="card-body">';
                    servicesHtml += '<div class="d-flex justify-content-between align-items-center">';
                    servicesHtml += '<h6 class="card-title mb-0">' + key.replace('_', ' ').toUpperCase() + '</h6>';
                    servicesHtml += '<span class="badge bg-' + statusClass + '">' + component.status + '</span>';
                    servicesHtml += '</div>';
                    servicesHtml += '<p class="card-text mt-2 mb-0">' + component.message + '</p>';
                    servicesHtml += '</div></div></div>';
                }
                servicesHtml += '</div>';
            } else {
                servicesHtml = '<div class="alert alert-warning">No service data available</div>';
            }
            document.getElementById('servicesContent').innerHTML = servicesHtml;
            
            // Load and display system information
            fetch('/api/health_monitor.php?action=get_system_info')
                .then(response => response.json())
                .then(sysData => {
                    if (sysData.success) {
                        updateSystemInfo(sysData.system_info);
                    }
                })
                .catch(error => console.error('Error loading system info:', error));
            
            // Load and display recent alerts
            fetch('/api/health_monitor.php?action=get_recent_alerts')
                .then(response => response.json())
                .then(alertData => {
                    if (alertData.success) {
                        updateRecentAlerts(alertData.alerts);
                    }
                })
                .catch(error => console.error('Error loading alerts:', error));
        }
        
        function updateSystemInfo(systemInfo) {
            var infoHtml = '';
            if (systemInfo.server && systemInfo.application) {
                infoHtml += '<div class="row">';
                
                // Server Information
                infoHtml += '<div class="col-md-6">';
                infoHtml += '<h6 class="text-primary"><i class="bi bi-server"></i> Server Information</h6>';
                infoHtml += '<table class="table table-sm">';
                infoHtml += '<tr><td><strong>Hostname:</strong></td><td>' + systemInfo.server.hostname + '</td></tr>';
                infoHtml += '<tr><td><strong>OS:</strong></td><td>' + systemInfo.server.os + '</td></tr>';
                infoHtml += '<tr><td><strong>PHP Version:</strong></td><td>' + systemInfo.server.php_version + '</td></tr>';
                infoHtml += '<tr><td><strong>Uptime:</strong></td><td>' + systemInfo.server.uptime.trim() + '</td></tr>';
                infoHtml += '</table>';
                infoHtml += '</div>';
                
                // Application Information
                infoHtml += '<div class="col-md-6">';
                infoHtml += '<h6 class="text-info"><i class="bi bi-gear"></i> Application Information</h6>';
                infoHtml += '<table class="table table-sm">';
                infoHtml += '<tr><td><strong>Version:</strong></td><td>' + systemInfo.application.version + '</td></tr>';
                infoHtml += '<tr><td><strong>Customer:</strong></td><td>' + systemInfo.application.customer_name + '</td></tr>';
                infoHtml += '<tr><td><strong>Instance ID:</strong></td><td>' + systemInfo.application.instance_id + '</td></tr>';
                infoHtml += '<tr><td><strong>Main Server:</strong></td><td>' + systemInfo.application.main_server + '</td></tr>';
                infoHtml += '</table>';
                infoHtml += '</div>';
                
                infoHtml += '</div>';
            } else {
                infoHtml = '<div class="alert alert-warning">System information not available</div>';
            }
            document.getElementById('systemInfoContent').innerHTML = infoHtml;
        }
        
        function updateRecentAlerts(alerts) {
            var alertsHtml = '';
            if (alerts && alerts.length > 0) {
                alerts.forEach(alert => {
                    var alertClass = alert.severity === 'critical' ? 'danger' : 
                                    alert.severity === 'warning' ? 'warning' : 'info';
                    var icon = alert.severity === 'critical' ? 'exclamation-triangle' : 
                              alert.severity === 'warning' ? 'exclamation-circle' : 'info-circle';
                    
                    alertsHtml += '<div class="alert alert-' + alertClass + ' mb-2">';
                    alertsHtml += '<div class="d-flex align-items-center">';
                    alertsHtml += '<i class="bi bi-' + icon + ' me-2 fs-5"></i>';
                    alertsHtml += '<div class="flex-grow-1">';
                    alertsHtml += '<strong>' + alert.severity.toUpperCase() + '</strong>';
                    alertsHtml += '<div>' + alert.message + '</div>';
                    alertsHtml += '<small class="text-muted">' + alert.timestamp + '</small>';
                    alertsHtml += '</div>';
                    alertsHtml += '</div>';
                    alertsHtml += '</div>';
                });
            } else {
                alertsHtml = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>No recent alerts - all systems healthy</div>';
            }
            document.getElementById('alertsContent').innerHTML = alertsHtml;
        }
        
        function updateSystemMetrics(services) {
            // Update CPU Usage
            if (services.cpu) {
                var cpuElement = document.getElementById('cpuUsage');
                if (cpuElement) {
                    cpuElement.textContent = services.cpu.description.replace('CPU Usage: ', '');
                }
            }
            
            // Update Memory
            if (services.memory) {
                var memElement = document.getElementById('memoryUsage');
                if (memElement) {
                    memElement.textContent = services.memory.description.replace('Memory Usage: ', '');
                }
            }
            
            // Update Disk Space
            if (services.disk) {
                var diskElement = document.getElementById('diskUsage');
                if (diskElement) {
                    diskElement.textContent = services.disk.description.replace('Disk Usage: ', '');
                }
            }
        }
        
        function updateLoadAverage(loadData) {
            var loadElement = document.getElementById('loadAverage');
            if (loadElement && loadData) {
                loadElement.textContent = loadData['1min'] + ' / ' + loadData['5min'] + ' / ' + loadData['15min'];
            }
        }
        
        // Initialize page when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            loadHealthData();
            
            // Auto-refresh every 30 seconds
            setInterval(loadHealthData, 30000);
        });
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>