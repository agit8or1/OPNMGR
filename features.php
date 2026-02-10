<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

// Get feature statistics
$stmt = db()->query("SELECT COUNT(*) as total_firewalls FROM firewalls");
$firewall_count = $stmt->fetchColumn();

$stmt = db()->query("SELECT COUNT(*) as active_agents FROM firewalls f JOIN firewall_agents fa ON f.id = fa.firewall_id WHERE fa.last_checkin > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
$active_agents = $stmt->fetchColumn();

$stmt = db()->query("SELECT COUNT(*) as completed_features FROM todos WHERE status = 'completed'");
$completed_features = $stmt->fetchColumn();

include __DIR__ . '/inc/header.php';
?>

<?php include __DIR__ . '/inc/navigation.php'; ?>

<div class="container-fluid">
    <div class="row">
        <!-- Administration Sidebar -->
        <div class="col-md-3">
            <?php include __DIR__ . '/inc/sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-star me-2"></i>Platform Features
                    </h3>
                    <div class="card-tools">
                        <a href="generate_pdf.php?page=features" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-file-pdf me-1"></i>Download PDF
                        </a>
                        <span class="badge bg-success"><?php echo $completed_features; ?> Features Implemented</span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Feature Categories -->
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="card bg-primary">
                                <div class="card-body text-center">
                                    <i class="fas fa-network-wired fa-2x mb-2"></i>
                                    <h5>Firewall Management</h5>
                                    <p class="small">Centralized monitoring and control</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-info">
                                <div class="card-body text-center">
                                    <i class="fas fa-robot fa-2x mb-2"></i>
                                    <h5>Agent System</h5>
                                    <p class="small">Automated monitoring and updates</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-success">
                                <div class="card-body text-center">
                                    <i class="fas fa-code-branch fa-2x mb-2"></i>
                                    <h5>Version Control</h5>
                                    <p class="small">Release and change management</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Core Features -->
                    <div class="feature-section">
                        <h4><i class="fas fa-fire me-2"></i>Core Features</h4>
                        
                        <div class="feature-grid">
                            <!-- Firewall Management -->
                            <div class="feature-card">
                                <div class="feature-header">
                                    <i class="fas fa-network-wired feature-icon"></i>
                                    <h5>Centralized Firewall Management</h5>
                                    <span class="badge bg-success">Production Ready</span>
                                </div>
                                <div class="feature-content">
                                    <ul>
                                        <li><strong>Multi-Firewall Dashboard:</strong> Monitor multiple OPNsense firewalls from a single interface</li>
                                        <li><strong>Real-time Status:</strong> Live monitoring of firewall health and connectivity</li>
                                        <li><strong>Tag-based Filtering:</strong> Organize firewalls by location, purpose, or custom tags</li>
                                        <li><strong>Bulk Operations:</strong> Perform updates and actions across multiple firewalls</li>
                                        <li><strong>Custom Grouping:</strong> Organize by customer, location, or function</li>
                                    </ul>
                                </div>
                                <div class="feature-stats">
                                    <small class="text-muted"><?php echo $firewall_count; ?> firewalls currently managed</small>
                                </div>
                            </div>

                            <!-- Agent System -->
                            <div class="feature-card">
                                <div class="feature-header">
                                    <i class="fas fa-robot feature-icon"></i>
                                    <h5>Intelligent Agent System</h5>
                                    <span class="badge bg-success">Production Ready</span>
                                </div>
                                <div class="feature-content">
                                    <ul>
                                        <li><strong>Auto-Discovery:</strong> Agents automatically register with the management platform</li>
                                        <li><strong>Command Queuing:</strong> Queue and execute commands on remote firewalls</li>
                                        <li><strong>Health Monitoring:</strong> Continuous monitoring of firewall system health</li>
                                        <li><strong>Auto-Updates:</strong> Agents self-update to ensure latest functionality</li>
                                        <li><strong>Secure Communication:</strong> Encrypted tunnels for secure management</li>
                                    </ul>
                                </div>
                                <div class="feature-stats">
                                    <small class="text-muted"><?php echo $active_agents; ?> agents currently active</small>
                                </div>
                            </div>

                            <!-- Update Management -->
                            <div class="feature-card">
                                <div class="feature-header">
                                    <i class="fas fa-sync feature-icon"></i>
                                    <h5>Update Management</h5>
                                    <span class="badge bg-success">Production Ready</span>
                                </div>
                                <div class="feature-content">
                                    <ul>
                                        <li><strong>One-Click Updates:</strong> Update individual or multiple firewalls with a single click</li>
                                        <li><strong>Update Scheduling:</strong> Schedule updates for maintenance windows</li>
                                        <li><strong>Rollback Support:</strong> Safe update process with rollback capabilities</li>
                                        <li><strong>Progress Tracking:</strong> Real-time update progress monitoring</li>
                                        <li><strong>Notification System:</strong> Alerts for successful or failed updates</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Security Features -->
                            <div class="feature-card">
                                <div class="feature-header">
                                    <i class="fas fa-shield-alt feature-icon"></i>
                                    <h5>Security & Access Control</h5>
                                    <span class="badge bg-success">Production Ready</span>
                                </div>
                                <div class="feature-content">
                                    <ul>
                                        <li><strong>User Authentication:</strong> Secure login system with session management</li>
                                        <li><strong>Role-based Access:</strong> Admin and user roles with appropriate permissions</li>
                                        <li><strong>CSRF Protection:</strong> Protection against cross-site request forgery</li>
                                        <li><strong>Encrypted Tunnels:</strong> All firewall communication through secure tunnels</li>
                                        <li><strong>Audit Logging:</strong> Complete audit trail of all administrative actions</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Version Management -->
                            <div class="feature-card">
                                <div class="feature-header">
                                    <i class="fas fa-code-branch feature-icon"></i>
                                    <h5>Version & Release Management</h5>
                                    <span class="badge bg-success">Production Ready</span>
                                </div>
                                <div class="feature-content">
                                    <ul>
                                        <li><strong>Bug Tracking:</strong> Comprehensive bug reporting and tracking system</li>
                                        <li><strong>Feature Planning:</strong> Todo list and feature request management</li>
                                        <li><strong>Release Planning:</strong> Version planning and release management</li>
                                        <li><strong>Change Logs:</strong> Automatic generation of detailed change logs</li>
                                        <li><strong>Documentation:</strong> Integrated documentation and feature tracking</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- API & Integration -->
                            <div class="feature-card">
                                <div class="feature-header">
                                    <i class="fas fa-plug feature-icon"></i>
                                    <h5>API & Integration</h5>
                                    <span class="badge bg-info">Available</span>
                                </div>
                                <div class="feature-content">
                                    <ul>
                                        <li><strong>RESTful API:</strong> Complete REST API for all platform functions</li>
                                        <li><strong>Agent API:</strong> Dedicated API endpoints for agent communication</li>
                                        <li><strong>Command API:</strong> Remote command execution via API</li>
                                        <li><strong>Status API:</strong> Real-time status and health check endpoints</li>
                                        <li><strong>Webhook Support:</strong> Integration with external monitoring systems</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Features -->
                    <div class="feature-section mt-5">
                        <h4><i class="fas fa-star me-2"></i>Advanced Features</h4>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="feature-list">
                                    <h6><i class="fas fa-chart-line me-2"></i>Monitoring & Analytics</h6>
                                    <ul>
                                        <li>Real-time connectivity monitoring</li>
                                        <li>Agent heartbeat tracking</li>
                                        <li>System uptime monitoring</li>
                                        <li>Performance metrics collection</li>
                                        <li>Historical data analysis</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-list">
                                    <h6><i class="fas fa-cogs me-2"></i>System Administration</h6>
                                    <ul>
                                        <li>Database management tools</li>
                                        <li>Log file management</li>
                                        <li>Backup and restore functionality</li>
                                        <li>System health monitoring</li>
                                        <li>Configuration management</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Coming Soon -->
                    <div class="feature-section mt-5">
                        <h4><i class="fas fa-rocket me-2"></i>Coming Soon</h4>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="coming-soon-card">
                                    <h6><i class="fas fa-bell me-2"></i>Enhanced Notifications</h6>
                                    <p class="small text-muted">Email and SMS notifications for system events, alerts, and status changes.</p>
                                    <span class="badge bg-warning">Planned</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="coming-soon-card">
                                    <h6><i class="fas fa-chart-pie me-2"></i>Advanced Analytics</h6>
                                    <p class="small text-muted">Detailed analytics dashboard with charts, graphs, and trend analysis.</p>
                                    <span class="badge bg-warning">Planned</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="coming-soon-card">
                                    <h6><i class="fas fa-mobile-alt me-2"></i>Mobile Interface</h6>
                                    <p class="small text-muted">Mobile-responsive interface for firewall management on the go.</p>
                                    <span class="badge bg-info">In Development</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Technical Specifications -->
                    <div class="feature-section mt-5">
                        <h4><i class="fas fa-microchip me-2"></i>Technical Specifications</h4>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-dark table-sm">
                                    <tr>
                                        <th>Platform:</th>
                                        <td>PHP 8.3 + MySQL + nginx</td>
                                    </tr>
                                    <tr>
                                        <th>Supported OS:</th>
                                        <td>Ubuntu 20.04+, FreeBSD 13+</td>
                                    </tr>
                                    <tr>
                                        <th>Database:</th>
                                        <td>MySQL 8.0+ / MariaDB 10.5+</td>
                                    </tr>
                                    <tr>
                                        <th>Web Server:</th>
                                        <td>nginx 1.18+ (recommended)</td>
                                    </tr>
                                    <tr>
                                        <th>Agent Support:</th>
                                        <td>FreeBSD 13+ (OPNsense)</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-dark table-sm">
                                    <tr>
                                        <th>Concurrent Firewalls:</th>
                                        <td>Unlimited (tested with 50+)</td>
                                    </tr>
                                    <tr>
                                        <th>Agent Protocol:</th>
                                        <td>HTTPS/JSON with SSH tunnels</td>
                                    </tr>
                                    <tr>
                                        <th>Update Frequency:</th>
                                        <td>Real-time (2-minute intervals)</td>
                                    </tr>
                                    <tr>
                                        <th>Data Retention:</th>
                                        <td>Configurable (default: 90 days)</td>
                                    </tr>
                                    <tr>
                                        <th>Backup Support:</th>
                                        <td>MySQL dumps + file backups</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.feature-card {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 0.5rem;
    padding: 1.5rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.feature-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.feature-icon {
    font-size: 1.5rem;
    color: #007bff;
}

.feature-header h5 {
    margin: 0;
    flex: 1;
}

.feature-content ul {
    margin-bottom: 1rem;
}

.feature-content li {
    margin-bottom: 0.5rem;
}

.feature-stats {
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    padding-top: 1rem;
    margin-top: 1rem;
}

.feature-section {
    margin-bottom: 3rem;
}

.feature-section h4 {
    margin-bottom: 1.5rem;
    color: #fff;
    border-bottom: 2px solid #007bff;
    padding-bottom: 0.5rem;
}

.feature-list {
    background: rgba(255, 255, 255, 0.05);
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1rem;
}

.coming-soon-card {
    background: rgba(255, 255, 255, 0.05);
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1rem;
    text-align: center;
}

.coming-soon-card h6 {
    margin-bottom: 0.5rem;
}
</style>

<?php include __DIR__ . '/inc/footer.php'; ?>