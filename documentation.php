<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

include __DIR__ . '/inc/header.php';
?>

<?php include __DIR__ . '/inc/navigation.php'; ?>

<div class="container-fluid">
    <div class="row">
        <!-- Quick Navigation Sidebar -->
        <div class="col-md-3">
            <div class="card card-dark sticky-top" style="top: 20px; max-height: calc(100vh - 40px); overflow-y: auto;">
                <div class="card-header bg-primary">
                    <h6 class="mb-0 text-white"><i class="fas fa-list me-2"></i>Quick Navigation</h6>
                </div>
                <div class="card-body p-2" style="background: #2c3e50;">
                    <div class="nav flex-column nav-pills">
                        <a class="nav-link py-2 mb-1 text-white" href="#getting-started" style="border-radius: 5px; font-size: 14px;">
                            <i class="fas fa-play-circle me-2"></i>Getting Started
                        </a>
                        <a class="nav-link py-2 mb-1 text-white" href="#firewall-management" style="border-radius: 5px; font-size: 14px;">
                            <i class="fas fa-network-wired me-2"></i>Firewall Management
                        </a>
                        <a class="nav-link py-2 mb-1 text-white" href="#agent-installation" style="border-radius: 5px; font-size: 14px;">
                            <i class="fas fa-download me-2"></i>Agent Installation
                        </a>
                        <a class="nav-link py-2 mb-1 text-white" href="#monitoring" style="border-radius: 5px; font-size: 14px;">
                            <i class="fas fa-chart-line me-2"></i>Monitoring
                        </a>
                        <a class="nav-link py-2 mb-1 text-white" href="#updates" style="border-radius: 5px; font-size: 14px;">
                            <i class="fas fa-sync me-2"></i>Updates
                        </a>
                        <a class="nav-link py-2 mb-1 text-white" href="#troubleshooting" style="border-radius: 5px; font-size: 14px;">
                            <i class="fas fa-wrench me-2"></i>Troubleshooting
                        </a>
                        <a class="nav-link py-2 mb-1 text-white" href="#advanced-topics" style="border-radius: 5px; font-size: 14px;">
                            <i class="fas fa-cogs me-2"></i>Advanced Topics
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">

        <!-- Main Content -->
        <div class="col-md-9">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-book me-2"></i>User Documentation
                    </h3>
                    <div class="card-tools">
                        <a href="generate_pdf.php?page=documentation" class="btn btn-sm btn-primary me-2">
                            <i class="fas fa-file-pdf me-1"></i>Download PDF
                        </a>
                        <button class="btn btn-sm btn-primary" onclick="window.print()">
                            <i class="fas fa-print me-1"></i>Print Documentation
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Table of Contents -->
                    <div class="toc-section mb-4">
                        <h4>Table of Contents</h4>
                        <ol class="toc-list">
                            <li><a href="#getting-started">Getting Started</a></li>
                            <li><a href="#firewall-management">Firewall Management</a>
                                <ol>
                                    <li><a href="#viewing-firewalls">Viewing Firewalls</a></li>
                                    <li><a href="#filtering-searching">Filtering and Searching</a></li>
                                    <li><a href="#tagging">Tagging System</a></li>
                                </ol>
                            </li>
                            <li><a href="#agent-installation">Agent Installation</a></li>
                            <li><a href="#monitoring">Monitoring and Status</a></li>
                            <li><a href="#updates">Update Management</a></li>
                            <li><a href="#version-management">Version Management</a></li>
                            <li><a href="#troubleshooting">Troubleshooting</a></li>
                            <li><a href="#api-reference">API Reference</a></li>
                        </ol>
                    </div>

                    <!-- Getting Started -->
                    <section id="getting-started" class="doc-section">
                        <h4><i class="fas fa-rocket me-2"></i>Getting Started</h4>
                        <div class="doc-content">
                            <h5>Overview</h5>
                            <p>The OPNsense Management Platform provides centralized monitoring and management of multiple OPNsense firewalls through intelligent agents. This documentation will guide you through all aspects of using the platform.</p>
                            

                            <h5>First Login</h5>
                            <ol>
                                <li>Navigate to your management server URL</li>
                                <li>Login with your administrator credentials</li>
                                <li>You'll be presented with the main firewall dashboard</li>
                                <li>If no firewalls are visible, proceed to install agents on your OPNsense firewalls</li>
                            </ol>

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Tip:</strong> Start by installing an agent on one firewall to familiarize yourself with the system before deploying to multiple firewalls.
                            </div>
                        </div>
                    </section>

                    <!-- Firewall Management -->
                    <section id="firewall-management" class="doc-section">
                        <h4><i class="fas fa-network-wired me-2"></i>Firewall Management</h4>
                        
                        <h5 id="viewing-firewalls">Viewing Firewalls</h5>
                        <div class="doc-content">
                            <p>The main dashboard displays all managed firewalls in a comprehensive table showing:</p>
                            <ul>
                                <li><strong>Hostname:</strong> The firewall's hostname</li>
                                <li><strong>IP Address:</strong> Primary IP address</li>
                                <li><strong>Customer:</strong> Customer or organization name</li>
                                <li><strong>Status:</strong> Online/Offline status with last check-in time</li>
                                <li><strong>Version:</strong> Current OPNsense version</li>
                                <li><strong>Agent Version:</strong> Installed agent version</li>
                                <li><strong>Uptime:</strong> System uptime in days, hours, and minutes</li>
                                <li><strong>Actions:</strong> Available management actions</li>
                            </ul>
                        </div>

                        <h5 id="filtering-searching">Filtering and Searching</h5>
                        <div class="doc-content">
                            <p>Use the search and filter tools to find specific firewalls:</p>
                            <ul>
                                <li><strong>Search Box:</strong> Search by hostname, IP address, or customer name</li>
                                <li><strong>Status Filter:</strong> Filter by online/offline status</li>
                                <li><strong>Tag Filter:</strong> Filter by assigned tags using the dropdown menu</li>
                                <li><strong>Column Sorting:</strong> Click column headers to sort data</li>
                            </ul>
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Note:</strong> Firewalls are considered offline if they haven't checked in within the last 10 minutes.
                            </div>
                        </div>

                        <h5 id="tagging">Tagging System</h5>
                        <div class="doc-content">
                            <p>Tags help organize firewalls by location, purpose, or any custom criteria:</p>
                            <ul>
                                <li><strong>Assign Tags:</strong> Use the tag management interface to assign tags to firewalls</li>
                                <li><strong>Filter by Tags:</strong> Use the tag filter dropdown to view firewalls with specific tags</li>
                                <li><strong>Multiple Tags:</strong> Firewalls can have multiple tags assigned</li>
                                <li><strong>Bulk Tagging:</strong> Apply tags to multiple firewalls simultaneously</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Agent Installation -->
                    <section id="agent-installation" class="doc-section">
                        <h4><i class="fas fa-robot me-2"></i>Agent Installation</h4>
                        <div class="doc-content">
                            <h5>Automated Installation</h5>
                            <p>The management platform provides automated agent installation scripts:</p>
                            <ol>
                                <li>SSH into your OPNsense firewall as root</li>
                                <li>Run the installation command:
                                    <div class="code-block">
                                        <code>fetch -o - https://your-management-server.com/scripts/tunnel_agent.sh | sh</code>
                                    </div>
                                </li>
                                <li>The agent will automatically:
                                    <ul>
                                        <li>Download and install the latest agent</li>
                                        <li>Configure the management connection</li>
                                        <li>Register with the management platform</li>
                                        <li>Start monitoring and check-in services</li>
                                    </ul>
                                </li>
                            </ol>

                            <h5>Manual Configuration</h5>
                            <p>For custom installations, you can manually configure agents:</p>
                            <ul>
                                <li>Download the agent script manually</li>
                                <li>Modify configuration variables as needed</li>
                                <li>Run the script with custom parameters</li>
                                <li>Verify registration in the management dashboard</li>
                            </ul>

                            <h5>Agent Updates</h5>
                            <p>Agents automatically check for and install updates:</p>
                            <ul>
                                <li><strong>Auto-Update:</strong> Agents self-update to the latest version</li>
                                <li><strong>Update Frequency:</strong> Checks occur every 24 hours</li>
                                <li><strong>Version Tracking:</strong> Current agent versions are displayed in the dashboard</li>
                                <li><strong>Manual Update:</strong> Force updates through the management interface</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Monitoring -->
                    <section id="monitoring" class="doc-section">
                        <h4><i class="fas fa-chart-line me-2"></i>Monitoring and Status</h4>
                        <div class="doc-content">
                            <h5>Real-time Monitoring</h5>
                            <p>The platform provides comprehensive real-time monitoring:</p>
                            <ul>
                                <li><strong>Connection Status:</strong> Live status of firewall connectivity</li>
                                <li><strong>Agent Health:</strong> Agent status and last check-in times</li>
                                <li><strong>System Information:</strong> Uptime, version, and system stats</li>
                                <li><strong>Performance Metrics:</strong> Basic performance indicators</li>
                            </ul>

                            <h5>Status Indicators</h5>
                            <div class="status-examples">
                                <div class="status-item">
                                    <span class="badge bg-success">Online</span>
                                    <span class="text-muted">- Firewall is responding and agent is active</span>
                                </div>
                                <div class="status-item">
                                    <span class="badge bg-danger">Offline</span>
                                    <span class="text-muted">- No response for over 10 minutes</span>
                                </div>
                                <div class="status-item">
                                    <span class="badge bg-warning">Warning</span>
                                    <span class="text-muted">- Issues detected but still responsive</span>
                                </div>
                            </div>

                            <h5>Alerts and Notifications</h5>
                            <p>Configure alerts for important events:</p>
                            <ul>
                                <li><strong>Connection Loss:</strong> Alert when firewalls go offline</li>
                                <li><strong>Agent Issues:</strong> Notifications for agent problems</li>
                                <li><strong>Update Status:</strong> Alerts for successful or failed updates</li>
                                <li><strong>System Events:</strong> Important system event notifications</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Updates -->
                    <section id="updates" class="doc-section">
                        <h4><i class="fas fa-sync me-2"></i>Update Management</h4>
                        <div class="doc-content">
                            <h5>Individual Updates</h5>
                            <p>Update individual firewalls using the update buttons:</p>
                            <ol>
                                <li>Locate the firewall in the dashboard</li>
                                <li>Click the "Update" button in the Actions column</li>
                                <li>Confirm the update operation</li>
                                <li>Monitor progress in the dashboard</li>
                                <li>Review update results and logs</li>
                            </ol>

                            <h5>Bulk Updates</h5>
                            <p>Update multiple firewalls simultaneously:</p>
                            <ol>
                                <li>Use filters to select the firewalls to update</li>
                                <li>Click "Update All Visible" for filtered results</li>
                                <li>Or use "Update All" for all managed firewalls</li>
                                <li>Confirm the bulk operation</li>
                                <li>Monitor progress for each firewall</li>
                            </ol>

                            <h5>Update Scheduling</h5>
                            <p>Schedule updates for maintenance windows:</p>
                            <ul>
                                <li><strong>Maintenance Windows:</strong> Define preferred update times</li>
                                <li><strong>Staggered Updates:</strong> Spread updates across time to avoid network disruption</li>
                                <li><strong>Rollback Planning:</strong> Prepare rollback procedures for critical systems</li>
                                <li><strong>Testing:</strong> Test updates on non-critical systems first</li>
                            </ul>

                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Important:</strong> Always schedule updates during maintenance windows and ensure you have access to the physical firewall in case of issues.
                            </div>
                        </div>
                    </section>

                    <!-- Version Management -->
                    <section id="version-management" class="doc-section">
                        <h4><i class="fas fa-code-branch me-2"></i>Version Management</h4>
                        <div class="doc-content">
                            <h5>Bug Tracking</h5>
                            <p>Report and track bugs in the platform:</p>
                            <ul>
                                <li><strong>Bug Reports:</strong> Create detailed bug reports with severity levels</li>
                                <li><strong>Status Tracking:</strong> Follow bugs from open to resolved</li>
                                <li><strong>Component Tracking:</strong> Organize bugs by system component</li>
                                <li><strong>Resolution Tracking:</strong> Track which version fixes each bug</li>
                            </ul>

                            <h5>Feature Requests</h5>
                            <p>Manage feature requests and development todos:</p>
                            <ul>
                                <li><strong>Todo Lists:</strong> Maintain organized lists of features and improvements</li>
                                <li><strong>Priority Levels:</strong> Assign priority levels to requests</li>
                                <li><strong>Version Planning:</strong> Plan features for specific releases</li>
                                <li><strong>Progress Tracking:</strong> Monitor development progress</li>
                            </ul>

                            <h5>Release Management</h5>
                            <p>Track platform versions and releases:</p>
                            <ul>
                                <li><strong>Version History:</strong> Complete history of all releases</li>
                                <li><strong>Change Logs:</strong> Detailed change logs for each version</li>
                                <li><strong>Release Notes:</strong> Comprehensive release documentation</li>
                                <li><strong>Roadmap:</strong> Future release planning and roadmap</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Troubleshooting -->
                    <section id="troubleshooting" class="doc-section">
                        <h4><i class="fas fa-tools me-2"></i>Troubleshooting</h4>
                        <div class="doc-content">
                            <h5>Common Issues</h5>
                            
                            <div class="troubleshoot-item">
                                <h6>Firewall Shows as Offline</h6>
                                <ol>
                                    <li>Check network connectivity between firewall and management server</li>
                                    <li>Verify agent is running: <code>ps aux | grep tunnel</code></li>
                                    <li>Check agent logs for errors</li>
                                    <li>Restart agent service if necessary</li>
                                    <li>Verify firewall system time is synchronized</li>
                                </ol>
                            </div>

                            <div class="troubleshoot-item">
                                <h6>Agent Installation Fails</h6>
                                <ol>
                                    <li>Verify internet connectivity from firewall</li>
                                    <li>Check DNS resolution for management server</li>
                                    <li>Ensure firewall can reach HTTPS port on management server</li>
                                    <li>Verify SSL certificates are valid</li>
                                    <li>Check firewall rules aren't blocking outbound connections</li>
                                </ol>
                            </div>

                            <div class="troubleshoot-item">
                                <h6>Updates Fail</h6>
                                <ol>
                                    <li>Check firewall internet connectivity</li>
                                    <li>Verify OPNsense update repositories are accessible</li>
                                    <li>Ensure sufficient disk space for updates</li>
                                    <li>Check for package conflicts or locks</li>
                                    <li>Review update logs for specific error messages</li>
                                </ol>
                            </div>

                            <h5>Diagnostic Commands</h5>
                            <p>Useful commands for troubleshooting on the firewall:</p>
                            <div class="code-block">
                                <code># Check agent status<br>
                                ps aux | grep tunnel<br><br>
                                # Check agent logs<br>
                                tail -f /var/log/tunnel_agent.log<br><br>
                                # Test connectivity to management server<br>
                                ping management-server.com<br>
                                fetch -o - https://management-server.com/api/test<br><br>
                                # Check system resources<br>
                                df -h<br>
                                free -h<br>
                                uptime</code>
                            </div>

                            <h5>Log Files</h5>
                            <p>Important log files for troubleshooting:</p>
                            <ul>
                                <li><strong>Agent Logs:</strong> <code>/var/log/tunnel_agent.log</code></li>
                                <li><strong>System Logs:</strong> <code>/var/log/messages</code></li>
                                <li><strong>Update Logs:</strong> <code>/var/log/pkg.log</code></li>
                                <li><strong>Web Server Logs:</strong> <code>/var/log/nginx/</code></li>
                            </ul>
                        </div>
                    </section>

                    <!-- API Reference -->
                    <section id="api-reference" class="doc-section">
                        <h4><i class="fas fa-code me-2"></i>API Reference</h4>
                        <div class="doc-content">
                            <h5>Authentication</h5>
                            <p>API endpoints use session-based authentication. For agent endpoints, no authentication is required.</p>

                            <h5>Agent Endpoints</h5>
                            <div class="api-endpoint">
                                <h6>POST /agent_checkin.php</h6>
                                <p>Agent check-in endpoint for status updates and command retrieval.</p>
                                <div class="code-block">
                                    <code>{<br>
                                    &nbsp;&nbsp;"hostname": "firewall.example.com",<br>
                                    &nbsp;&nbsp;"version": "22.7.2",<br>
                                    &nbsp;&nbsp;"uptime": 123456,<br>
                                    &nbsp;&nbsp;"agent_version": "2.1.0"<br>
                                    }</code>
                                </div>
                            </div>

                            <div class="api-endpoint">
                                <h6>POST /api/command_result.php</h6>
                                <p>Submit command execution results from agents.</p>
                                <div class="code-block">
                                    <code>{<br>
                                    &nbsp;&nbsp;"command_id": 123,<br>
                                    &nbsp;&nbsp;"result": "success",<br>
                                    &nbsp;&nbsp;"output": "Command completed successfully"<br>
                                    }</code>
                                </div>
                            </div>

                            <h5>Management Endpoints</h5>
                            <div class="api-endpoint">
                                <h6>POST /api/update_firewall.php</h6>
                                <p>Queue update command for a specific firewall.</p>
                                <div class="code-block">
                                    <code>{<br>
                                    &nbsp;&nbsp;"firewall_id": 123<br>
                                    }</code>
                                </div>
                            </div>

                            <div class="api-endpoint">
                                <h6>GET /api/system_info.php</h6>
                                <p>Get system information and status.</p>
                                <div class="code-block">
                                    <code>{<br>
                                    &nbsp;&nbsp;"version": "1.2.0",<br>
                                    &nbsp;&nbsp;"uptime": "5 days, 3 hours",<br>
                                    &nbsp;&nbsp;"total_firewalls": 15,<br>
                                    &nbsp;&nbsp;"active_agents": 12<br>
                                    }</code>
                                </div>
                            </div>

                            <h5>Response Codes</h5>
                            <ul>
                                <li><strong>200:</strong> Success</li>
                                <li><strong>400:</strong> Bad Request - Invalid parameters</li>
                                <li><strong>401:</strong> Unauthorized - Authentication required</li>
                                <li><strong>404:</strong> Not Found - Resource doesn't exist</li>
                                <li><strong>500:</strong> Internal Server Error</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Footer -->
                    <div class="doc-footer mt-5 pt-3 border-top">
                        <div class="row">
                            <div class="col-md-6">
                                <small class="text-muted">
                                    Last updated: <?php echo date('M j, Y'); ?><br>
                                    Documentation version: 1.2.0
                                </small>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    For support, please contact your system administrator<br>
                                    or refer to the <a href="changelog.php">change log</a> for recent updates.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Enhanced Navigation Styling */
.nav-link:hover {
    background-color: rgba(52, 152, 219, 0.2) !important;
    color: #3498db !important;
    transform: translateX(5px);
    transition: all 0.3s ease;
}

.nav-link.active {
    background-color: #3498db !important;
    color: white !important;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

/* Smooth scrolling */
html {
    scroll-behavior: smooth;
}

/* Custom scrollbar for navigation */
.card-body::-webkit-scrollbar {
    width: 6px;
}

.card-body::-webkit-scrollbar-track {
    background: #34495e;
    border-radius: 3px;
}

.card-body::-webkit-scrollbar-thumb {
    background: #3498db;
    border-radius: 3px;
}

.card-body::-webkit-scrollbar-thumb:hover {
    background: #2980b9;
}

/* Better spacing and typography */
.doc-section {
    margin-bottom: 3rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.doc-section:last-child {
    border-bottom: none;
}

.doc-section h4 {
    color: #fff;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid #007bff;
    padding-bottom: 0.5rem;
}

.doc-section h5 {
    color: #adb5bd;
    margin-top: 2rem;
    margin-bottom: 1rem;
}

.doc-section h6 {
    color: #ced4da;
    margin-bottom: 0.5rem;
}

.doc-content {
    margin-bottom: 2rem;
}

.toc-list {
    padding-left: 1.5rem;
}

.toc-list a {
    color: #adb5bd;
    text-decoration: none;
}

.toc-list a:hover {
    color: #fff;
    text-decoration: underline;
}

.code-block {
    background: #1e1e1e;
    border: 1px solid #333;
    border-radius: 0.375rem;
    padding: 1rem;
    margin: 1rem 0;
    overflow-x: auto;
}

.code-block code {
    color: #f8f8f2;
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
    white-space: pre;
}

.troubleshoot-item {
    background: rgba(255, 193, 7, 0.1);
    border-left: 4px solid #ffc107;
    padding: 1rem;
    margin: 1rem 0;
    border-radius: 0 0.375rem 0.375rem 0;
}

.api-endpoint {
    background: rgba(13, 202, 240, 0.1);
    border-left: 4px solid #0dcaf0;
    padding: 1rem;
    margin: 1rem 0;
    border-radius: 0 0.375rem 0.375rem 0;
}

.status-examples {
    margin: 1rem 0;
}

.status-item {
    display: block;
    margin-bottom: 0.5rem;
}

.nav-pills .nav-sm .nav-link {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

@media print {
    .navbar, .card-header, .col-md-3, .btn {
        display: none !important;
    }
    
    .col-md-9 {
        width: 100% !important;
        max-width: 100% !important;
    }
    
    .card {
        border: none !important;
        background: white !important;
    }
    
    .card-body {
        padding: 0 !important;
        color: black !important;
    }
    
    .doc-section h4, .doc-section h5, .doc-section h6 {
        color: black !important;
    }
}
</style>

<script>
// Smooth scrolling for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Highlight current section in quick navigation
function updateActiveSection() {
    const sections = document.querySelectorAll('.doc-section');
    const navLinks = document.querySelectorAll('.nav-sm .nav-link');
    
    let currentSection = '';
    
    sections.forEach(section => {
        const rect = section.getBoundingClientRect();
        if (rect.top <= 100 && rect.bottom >= 100) {
            currentSection = section.id;
        }
    });
    
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href') === '#' + currentSection) {
            link.classList.add('active');
        }
    });
}

window.addEventListener('scroll', updateActiveSection);
document.addEventListener('DOMContentLoaded', updateActiveSection);
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>