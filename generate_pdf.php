<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
requireLogin();

// Check if page parameter is provided
$page = $_GET['page'] ?? '';
$allowed_pages = ['about', 'features', 'changelog', 'documentation'];

if (!in_array($page, $allowed_pages)) {
    http_response_code(400);
    die('Invalid page specified');
}

// Get current version and platform info for footer
$stmt = $pdo->query("SELECT version, description, release_date FROM platform_versions WHERE status = 'released' ORDER BY created_at DESC LIMIT 1");
$current_version = $stmt->fetch(PDO::FETCH_ASSOC);

// Generate the PDF content using HTML/CSS that browsers can print to PDF
$html_content = generatePageContent($page, $pdo, $current_version);

// Instead of trying to generate a PDF directly, we'll create a print-friendly HTML page
// that users can save as PDF using their browser's "Print to PDF" feature
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPNsense Management Platform - <?php echo ucfirst($page); ?></title>
    <style>
        @media print {
            body { -webkit-print-color-adjust: exact; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            font-size: 12px; 
            line-height: 1.6; 
            margin: 0;
            padding: 20px;
            color: #333;
            background: white;
        }
        
        .header {
            border-bottom: 3px solid #007bff;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        
        .header h1 { 
            color: #007bff; 
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        
        .header .subtitle {
            color: #6c757d;
            font-size: 14px;
            margin-top: 5px;
        }
        
        h1 { 
            color: #212529; 
            border-bottom: 2px solid #dee2e6; 
            padding-bottom: 8px;
            margin-top: 30px;
            margin-bottom: 20px;
            font-size: 24px;
        }
        
        h2 { 
            color: #495057; 
            margin-top: 25px;
            margin-bottom: 15px;
            font-size: 20px;
        }
        
        h3 { 
            color: #6c757d; 
            margin-top: 20px;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        table { 
            border-collapse: collapse; 
            width: 100%; 
            margin: 15px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        table, th, td { 
            border: 1px solid #dee2e6; 
        }
        
        th, td { 
            padding: 12px 15px; 
            text-align: left; 
            vertical-align: top;
        }
        
        th { 
            background-color: #f8f9fa; 
            font-weight: 600;
            color: #495057;
        }
        
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        ul, ol { 
            margin: 15px 0; 
            padding-left: 30px;
        }
        
        li { 
            margin: 8px 0; 
            line-height: 1.5;
        }
        
        .version-section {
            margin: 25px 0;
            padding: 20px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            background-color: #f8f9fa;
        }
        
        .version-section h2 {
            margin-top: 0;
            color: #007bff;
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 10px;
            color: #6c757d;
        }
        
        .btn-container {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            margin: 0 10px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover {
            background-color: #0056b3;
        }
        
        .btn-secondary {
            background-color: #6c757d;
        }
        
        .btn-secondary:hover {
            background-color: #545b62;
        }
        
        .alert {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            border-left: 4px solid #007bff;
            background-color: #e7f3ff;
        }
        
        .content-section {
            margin-bottom: 40px;
        }
        
        .toc {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .toc h3 {
            margin-top: 0;
            color: #495057;
        }
        
        .toc ul {
            list-style-type: none;
            padding-left: 0;
        }
        
        .toc li {
            margin: 5px 0;
        }
        
        .toc a {
            color: #007bff;
            text-decoration: none;
        }
        
        .toc a:hover {
            text-decoration: underline;
        }
    </style>
    <script>
        function printPDF() {
            window.print();
        }
        
        function downloadHTML() {
            const content = document.documentElement.outerHTML;
            const blob = new Blob([content], { type: 'text/html' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'opnsense_<?php echo $page; ?>_<?php echo date('Y-m-d'); ?>.html';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    </script>
</head>
<body>
    <div class="header">
        <h1>🛡️ OPNsense Management Platform</h1>
        <div class="subtitle">
            <?php echo ucfirst(str_replace('_', ' ', $page)); ?> Documentation
            | Generated on <?php echo date('F j, Y \a\t g:i A'); ?>
        </div>
    </div>

    <div class="btn-container no-print">
        <button onclick="printPDF()" class="btn">
            📄 Save as PDF (Ctrl+P)
        </button>
        <button onclick="downloadHTML()" class="btn btn-secondary">
            💾 Download HTML
        </button>
        <a href="javascript:window.history.back()" class="btn btn-secondary">
            ← Back to Platform
        </a>
    </div>

    <div class="content-section">
        <?php echo $html_content; ?>
    </div>

    <div class="footer">
        <p><strong>OPNsense Management Platform</strong> | 
        Version <?php echo htmlspecialchars($current_version['version'] ?? '2.0.0'); ?> | 
        Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
        <p>© 2025 OPNsense Management Platform | For internal use only</p>
    </div>
</body>
</html>

<?php
function generatePageContent($page, $pdo, $current_version) {
    switch ($page) {
        case 'about':
            return generateAboutContent($pdo, $current_version);
        case 'features':
            return generateFeaturesContent($pdo);
        case 'changelog':
            return generateChangelogContent($pdo);
        case 'documentation':
            return generateDocumentationContent();
        default:
            return '<p>Invalid page requested.</p>';
    }
}

function generateAboutContent($pdo, $current_version) {
    // Get system stats
    $stmt = $pdo->query("SELECT COUNT(*) as total_firewalls FROM firewalls");
    $firewall_count = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as open_bugs FROM bugs WHERE status NOT IN ('resolved', 'closed')");
    $open_bugs = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as pending_todos FROM todos WHERE status NOT IN ('completed', 'cancelled')");
    $pending_todos = $stmt->fetchColumn() ?: 0;
    
    ob_start();
    ?>
    <div class="toc">
        <h3>📋 Table of Contents</h3>
        <ul>
            <li><a href="#platform-info">Platform Information</a></li>
            <li><a href="#system-stats">System Statistics</a></li>
            <li><a href="#core-features">Core Features</a></li>
            <li><a href="#tech-specs">Technical Specifications</a></li>
        </ul>
    </div>

    <div id="platform-info">
        <h1>📋 Platform Information</h1>
        <table>
            <tr><th style="width: 200px;">Property</th><th>Value</th></tr>
            <tr><td><strong>Current Version</strong></td><td><?php echo htmlspecialchars($current_version['version'] ?? '2.0.0'); ?></td></tr>
            <tr><td><strong>Release Date</strong></td><td><?php echo date('F j, Y', strtotime($current_version['release_date'] ?? 'now')); ?></td></tr>
            <tr><td><strong>Status</strong></td><td>Production Ready</td></tr>
            <tr><td><strong>Description</strong></td><td><?php echo htmlspecialchars($current_version['description'] ?? 'OPNsense Management Platform for centralized firewall monitoring and administration'); ?></td></tr>
        </table>
    </div>

    <div id="system-stats">
        <h1>📊 System Statistics</h1>
        <table>
            <tr><th style="width: 200px;">Metric</th><th>Count</th><th>Description</th></tr>
            <tr><td><strong>Total Firewalls</strong></td><td><?php echo $firewall_count; ?></td><td>Number of managed firewall instances</td></tr>
            <tr><td><strong>Open Issues</strong></td><td><?php echo $open_bugs; ?></td><td>Bug reports requiring attention</td></tr>
            <tr><td><strong>Pending Tasks</strong></td><td><?php echo $pending_todos; ?></td><td>Development tasks in progress</td></tr>
        </table>
    </div>

    <div id="core-features">
        <h1>🚀 Core Features</h1>
        <ul>
            <li><strong>Centralized Firewall Monitoring:</strong> Unified dashboard for managing multiple OPNsense firewalls</li>
            <li><strong>Real-time Status Monitoring:</strong> Live health checks with configurable refresh intervals</li>
            <li><strong>Automated Backup Management:</strong> Scheduled configuration backups with restore capabilities</li>
            <li><strong>Version Control System:</strong> Comprehensive change tracking and release management</li>
            <li><strong>Bug Tracking:</strong> Integrated issue tracking and resolution workflow</li>
            <li><strong>Customer Instance Deployment:</strong> Automated deployment system for multiple customers</li>
            <li><strong>Sequential Update Management:</strong> Centralized update distribution with dependency validation</li>
            <li><strong>Documentation System:</strong> Integrated help system with PDF export capabilities</li>
        </ul>
    </div>

    <div id="tech-specs">
        <h1>⚙️ Technical Specifications</h1>
        
        <h2>Backend Technology</h2>
        <ul>
            <li><strong>Language:</strong> PHP 7.4+ with modern practices</li>
            <li><strong>Database:</strong> MySQL 8.0+ or MariaDB 10.5+</li>
            <li><strong>Architecture:</strong> MVC pattern with modular components</li>
            <li><strong>Security:</strong> PDO prepared statements, CSRF protection, session management</li>
        </ul>

        <h2>Frontend Technology</h2>
        <ul>
            <li><strong>Framework:</strong> Bootstrap 5 with responsive design</li>
            <li><strong>Icons:</strong> Font Awesome 6 for consistent iconography</li>
            <li><strong>Theme:</strong> Dark theme optimized for operations centers</li>
            <li><strong>JavaScript:</strong> Vanilla JS with modern ES6+ features</li>
        </ul>

        <h2>Infrastructure Requirements</h2>
        <ul>
            <li><strong>Web Server:</strong> Apache 2.4+ or Nginx 1.18+</li>
            <li><strong>PHP Extensions:</strong> pdo_mysql, curl, json, openssl</li>
            <li><strong>Minimum Memory:</strong> 2GB RAM recommended</li>
            <li><strong>Storage:</strong> 10GB minimum for system and backups</li>
        </ul>
    </div>
    <?php
    return ob_get_clean();
}

function generateFeaturesContent($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) as completed_features FROM todos WHERE status = 'completed'");
    $completed_features = $stmt->fetchColumn() ?: 0;
    
    ob_start();
    ?>
    <div class="alert">
        <strong>📈 Implementation Status:</strong> <?php echo $completed_features; ?> features have been successfully implemented and deployed.
    </div>

    <div class="toc">
        <h3>🗂️ Feature Categories</h3>
        <ul>
            <li><a href="#firewall-mgmt">Firewall Management</a></li>
            <li><a href="#backup-recovery">Backup & Recovery</a></li>
            <li><a href="#monitoring">Monitoring & Alerting</a></li>
            <li><a href="#version-mgmt">Version Management</a></li>
            <li><a href="#customer-mgmt">Customer Management</a></li>
            <li><a href="#security">Security Features</a></li>
            <li><a href="#api">API Integration</a></li>
        </ul>
    </div>

    <div id="firewall-mgmt">
        <h1>🔥 Firewall Management</h1>
        <table>
            <tr><th>Feature</th><th>Description</th><th>Benefits</th></tr>
            <tr><td><strong>Multi-Firewall Dashboard</strong></td><td>Centralized view of all managed firewalls with real-time status</td><td>Single pane of glass for operations</td></tr>
            <tr><td><strong>Agent Management</strong></td><td>Install and manage monitoring agents on OPNsense firewalls</td><td>Automated deployment and updates</td></tr>
            <tr><td><strong>Bulk Operations</strong></td><td>Perform actions on multiple firewalls simultaneously</td><td>Improved operational efficiency</td></tr>
            <tr><td><strong>Custom Filtering</strong></td><td>Filter firewalls by customer, status, version, or location</td><td>Quick access to relevant systems</td></tr>
            <tr><td><strong>Health Monitoring</strong></td><td>CPU, memory, disk usage, and service monitoring</td><td>Proactive issue detection</td></tr>
        </table>
    </div>

    <div id="backup-recovery">
        <h1>💾 Backup & Recovery</h1>
        <table>
            <tr><th>Feature</th><th>Description</th><th>Benefits</th></tr>
            <tr><td><strong>Automated Backups</strong></td><td>Schedule regular configuration backups</td><td>Consistent data protection</td></tr>
            <tr><td><strong>On-Demand Backups</strong></td><td>Create backups before major changes</td><td>Risk mitigation for changes</td></tr>
            <tr><td><strong>Backup Verification</strong></td><td>Ensure backup integrity and completeness</td><td>Reliable recovery capability</td></tr>
            <tr><td><strong>Quick Restore</strong></td><td>One-click configuration restore</td><td>Rapid disaster recovery</td></tr>
            <tr><td><strong>Backup History</strong></td><td>Track and manage multiple backup versions</td><td>Point-in-time recovery options</td></tr>
        </table>
    </div>

    <div id="monitoring">
        <h1>📊 Monitoring & Alerting</h1>
        <table>
            <tr><th>Feature</th><th>Description</th><th>Benefits</th></tr>
            <tr><td><strong>Real-time Dashboard</strong></td><td>Live system overview with key metrics</td><td>Immediate visibility into system health</td></tr>
            <tr><td><strong>Custom Alerts</strong></td><td>Configurable alert thresholds and notifications</td><td>Proactive issue management</td></tr>
            <tr><td><strong>Email Notifications</strong></td><td>Automated alert delivery via email</td><td>24/7 monitoring without constant watching</td></tr>
            <tr><td><strong>Status Indicators</strong></td><td>Color-coded status for quick assessment</td><td>Rapid visual problem identification</td></tr>
            <tr><td><strong>Performance Metrics</strong></td><td>Historical performance data collection</td><td>Trend analysis and capacity planning</td></tr>
        </table>
    </div>

    <div id="version-mgmt">
        <h1>🔧 Version Management</h1>
        <table>
            <tr><th>Feature</th><th>Description</th><th>Benefits</th></tr>
            <tr><td><strong>Change Tracking</strong></td><td>Comprehensive logging of all system changes</td><td>Full audit trail and accountability</td></tr>
            <tr><td><strong>Bug Management</strong></td><td>Track, assign, and resolve issues systematically</td><td>Improved software quality</td></tr>
            <tr><td><strong>Feature Planning</strong></td><td>Todo and task management for development</td><td>Organized development workflow</td></tr>
            <tr><td><strong>Release Management</strong></td><td>Structured version release processes</td><td>Controlled and predictable updates</td></tr>
            <tr><td><strong>Documentation Integration</strong></td><td>Built-in documentation with PDF export</td><td>Always up-to-date documentation</td></tr>
        </table>
    </div>

    <div id="customer-mgmt">
        <h1>👥 Customer Management</h1>
        <table>
            <tr><th>Feature</th><th>Description</th><th>Benefits</th></tr>
            <tr><td><strong>Multi-Tenant Support</strong></td><td>Separate customer environments and data</td><td>Secure customer isolation</td></tr>
            <tr><td><strong>Instance Deployment</strong></td><td>Automated setup of customer-specific instances</td><td>Rapid customer onboarding</td></tr>
            <tr><td><strong>Update Distribution</strong></td><td>Centralized update management across customers</td><td>Consistent platform versions</td></tr>
            <tr><td><strong>Custom Branding</strong></td><td>Customer-specific interface customization</td><td>White-label deployment options</td></tr>
            <tr><td><strong>Resource Management</strong></td><td>Per-customer resource allocation and monitoring</td><td>Fair resource distribution</td></tr>
        </table>
    </div>

    <div id="security">
        <h1>🔐 Security Features</h1>
        <table>
            <tr><th>Feature</th><th>Description</th><th>Benefits</th></tr>
            <tr><td><strong>Role-Based Access</strong></td><td>Admin, user, and viewer role hierarchies</td><td>Principle of least privilege</td></tr>
            <tr><td><strong>Session Management</strong></td><td>Secure authentication with timeout protection</td><td>Unauthorized access prevention</td></tr>
            <tr><td><strong>Audit Logging</strong></td><td>Comprehensive activity and access logging</td><td>Security forensics and compliance</td></tr>
            <tr><td><strong>Data Encryption</strong></td><td>Secure data transmission and storage</td><td>Data protection in transit and at rest</td></tr>
            <tr><td><strong>Input Validation</strong></td><td>Comprehensive input sanitization and validation</td><td>Protection against injection attacks</td></tr>
        </table>
    </div>

    <div id="api">
        <h1>🔌 API Integration</h1>
        <table>
            <tr><th>Feature</th><th>Description</th><th>Benefits</th></tr>
            <tr><td><strong>RESTful APIs</strong></td><td>Modern API architecture with JSON responses</td><td>Easy integration with external systems</td></tr>
            <tr><td><strong>Update APIs</strong></td><td>Automated update distribution endpoints</td><td>Seamless platform maintenance</td></tr>
            <tr><td><strong>Monitoring APIs</strong></td><td>Real-time data access for external monitoring</td><td>Integration with existing monitoring tools</td></tr>
            <tr><td><strong>Authentication APIs</strong></td><td>Secure API access with token-based auth</td><td>Controlled programmatic access</td></tr>
            <tr><td><strong>Webhook Support</strong></td><td>Event-driven notifications to external systems</td><td>Real-time integration capabilities</td></tr>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

function generateChangelogContent($pdo) {
    // Get all versions with their changes
    $stmt = $pdo->query("
        SELECT pv.*, 
               (SELECT COUNT(*) FROM bugs WHERE version_fixed = pv.version) as bugs_fixed,
               (SELECT COUNT(*) FROM todos WHERE target_version = pv.version AND status = 'completed') as features_completed
        FROM platform_versions pv 
        ORDER BY pv.created_at DESC
        LIMIT 10
    ");
    $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get changelog entries
    $stmt = $pdo->query("
        SELECT * FROM change_log 
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $change_entries = [];
    while ($entry = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $change_entries[$entry['version']][] = $entry;
    }
    
    ob_start();
    ?>
    <div class="alert">
        <strong>📜 Complete Change History:</strong> This document contains the detailed change history for the OPNsense Management Platform, including all releases, features, and bug fixes.
    </div>

    <div class="toc">
        <h3>📅 Recent Versions</h3>
        <ul>
            <?php foreach (array_slice($versions, 0, 5) as $version): ?>
            <li><a href="#version-<?php echo str_replace('.', '-', $version['version']); ?>">Version <?php echo htmlspecialchars($version['version']); ?></a> - <?php echo ucfirst($version['status']); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php foreach ($versions as $index => $version): ?>
    <div class="version-section" id="version-<?php echo str_replace('.', '-', $version['version']); ?>">
        <h1>📦 Version <?php echo htmlspecialchars($version['version']); ?></h1>
        
        <table style="margin-bottom: 20px;">
            <tr><th style="width: 150px;">Property</th><th>Value</th></tr>
            <tr><td><strong>Status</strong></td><td><?php echo ucfirst($version['status']); ?></td></tr>
            <tr><td><strong>Release Date</strong></td><td><?php echo $version['release_date'] ? date('F j, Y', strtotime($version['release_date'])) : 'Not released'; ?></td></tr>
            <tr><td><strong>Features Added</strong></td><td><?php echo $version['features_completed']; ?> features</td></tr>
            <tr><td><strong>Bugs Fixed</strong></td><td><?php echo $version['bugs_fixed']; ?> issues</td></tr>
        </table>

        <p><strong>Description:</strong> <?php echo htmlspecialchars($version['description'] ?: 'No description available'); ?></p>
        
        <?php if (isset($change_entries[$version['version']])): ?>
        <h2>🔄 Changes in This Version</h2>
        <table>
            <tr><th>Type</th><th>Component</th><th>Change</th><th>Author</th></tr>
            <?php foreach ($change_entries[$version['version']] as $entry): ?>
            <tr>
                <td>
                    <?php
                    $badges = [
                        'feature' => '✨ Feature',
                        'bugfix' => '🐛 Bug Fix',
                        'improvement' => '🔧 Improvement',
                        'security' => '🔐 Security',
                        'update_applied' => '📥 Update',
                        'note' => '📝 Note'
                    ];
                    echo $badges[$entry['change_type']] ?? ucfirst($entry['change_type']);
                    ?>
                </td>
                <td><?php echo htmlspecialchars($entry['component']); ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($entry['title']); ?></strong><br>
                    <em><?php echo htmlspecialchars($entry['description']); ?></em>
                </td>
                <td><?php echo htmlspecialchars($entry['author']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p><em>No detailed changes recorded for this version.</em></p>
        <?php endif; ?>
    </div>
    
    <?php if ($index < count($versions) - 1): ?>
    <div class="page-break"></div>
    <?php endif; ?>
    
    <?php endforeach; ?>
    <?php
    return ob_get_clean();
}

function generateDocumentationContent() {
    ob_start();
    ?>
    <div class="toc">
        <h3>📚 Documentation Sections</h3>
        <ul>
            <li><a href="#getting-started">Getting Started</a></li>
            <li><a href="#firewall-management">Firewall Management</a></li>
            <li><a href="#backup-management">Backup Management</a></li>
            <li><a href="#monitoring">Monitoring & Alerts</a></li>
            <li><a href="#update-management">Update Management</a></li>
            <li><a href="#troubleshooting">Troubleshooting</a></li>
            <li><a href="#api-reference">API Reference</a></li>
        </ul>
    </div>

    <div id="getting-started">
        <h1>🚀 Getting Started</h1>
        
        <h2>System Requirements</h2>
        <table>
            <tr><th>Component</th><th>Requirement</th><th>Notes</th></tr>
            <tr><td>Server OS</td><td>Ubuntu 20.04+ or CentOS 8+</td><td>Linux-based server required</td></tr>
            <tr><td>Database</td><td>MySQL 8.0+ or MariaDB 10.5+</td><td>Full UTF-8 support required</td></tr>
            <tr><td>Web Server</td><td>Apache 2.4+ or Nginx 1.18+</td><td>HTTPS recommended for production</td></tr>
            <tr><td>PHP</td><td>PHP 7.4+ with extensions</td><td>pdo_mysql, curl, json, openssl required</td></tr>
            <tr><td>Firewall</td><td>OPNsense 22.1+</td><td>Running on FreeBSD</td></tr>
        </table>

        <h2>Installation Steps</h2>
        <ol>
            <li><strong>Server Preparation:</strong> Install and configure your server with the required components</li>
            <li><strong>Database Setup:</strong> Create the database and import the schema</li>
            <li><strong>File Installation:</strong> Deploy the application files to your web server</li>
            <li><strong>Configuration:</strong> Configure database connections and system settings</li>
            <li><strong>First Login:</strong> Access the web interface and complete initial setup</li>
        </ol>

        <div class="alert">
            <strong>💡 Quick Start Tip:</strong> Use the automated deployment script for faster setup. Run <code>./deploy_instance.sh "Customer Name" "instance-id"</code> for automated deployment.
        </div>
    </div>

    <div id="firewall-management">
        <h1>🔥 Firewall Management</h1>
        
        <h2>Adding Firewalls</h2>
        <ol>
            <li>Click <strong>"Add New Firewall"</strong> on the main dashboard</li>
            <li>Enter firewall hostname and IP address</li>
            <li>Configure SSH connection details (username/password or private key)</li>
            <li>Set customer information and location details</li>
            <li>Click <strong>"Save"</strong> to add the firewall</li>
        </ol>

        <h2>Installing Agents</h2>
        <ol>
            <li>Select the firewall from the dashboard</li>
            <li>Click <strong>"Install Agent"</strong> in the actions menu</li>
            <li>Wait for automatic installation and configuration (2-3 minutes)</li>
            <li>Verify the firewall status changes to "Online"</li>
        </ol>

        <h2>Status Indicators</h2>
        <table>
            <tr><th>Status</th><th>Indicator</th><th>Description</th></tr>
            <tr><td>Online</td><td>🟢 Green</td><td>Firewall responding normally</td></tr>
            <tr><td>Warning</td><td>🟡 Yellow</td><td>Minor issues detected, operational</td></tr>
            <tr><td>Offline</td><td>🔴 Red</td><td>Firewall not responding</td></tr>
            <tr><td>Maintenance</td><td>🔵 Blue</td><td>Scheduled maintenance mode</td></tr>
        </table>
    </div>

    <div id="backup-management">
        <h1>💾 Backup Management</h1>
        
        <h2>Creating Backups</h2>
        <ul>
            <li><strong>Manual Backup:</strong> Click "Backup Now" for immediate configuration backup</li>
            <li><strong>Scheduled Backup:</strong> Configure automatic daily or weekly backups</li>
            <li><strong>Pre-Update Backup:</strong> Automatic backup creation before system updates</li>
        </ul>

        <h2>Restoring from Backup</h2>
        <ol>
            <li>Navigate to the firewall's backup section</li>
            <li>Select the backup file you want to restore</li>
            <li>Click <strong>"Restore"</strong> and confirm the action</li>
            <li>Monitor the restoration progress</li>
            <li>Verify firewall configuration after restore completes</li>
        </ol>

        <div class="alert">
            <strong>⚠️ Important:</strong> Always verify backup integrity before relying on them for recovery. Test restores in non-production environments when possible.
        </div>
    </div>

    <div id="monitoring">
        <h1>📊 Monitoring & Alerts</h1>
        
        <h2>Dashboard Overview</h2>
        <p>The main dashboard provides real-time information about all managed firewalls including:</p>
        <ul>
            <li>Current status and health indicators</li>
            <li>System uptime and performance metrics</li>
            <li>Version information and update availability</li>
            <li>Recent activity and alert notifications</li>
        </ul>

        <h2>Configuring Alerts</h2>
        <ol>
            <li>Access the <strong>Settings → Alerts</strong> section</li>
            <li>Configure thresholds for CPU, memory, disk usage</li>
            <li>Set up email notification recipients</li>
            <li>Define alert escalation procedures</li>
            <li>Test alert delivery to verify configuration</li>
        </ol>
    </div>

    <div id="update-management">
        <h1>🔄 Update Management</h1>
        
        <h2>Checking for Updates</h2>
        <ol>
            <li>Navigate to <strong>Administration → Updates</strong></li>
            <li>Click <strong>"Check for Updates"</strong></li>
            <li>Review available updates in sequential order</li>
            <li>Note that updates must be applied in the order shown</li>
        </ol>

        <h2>Applying Updates</h2>
        <ol>
            <li>Only the next required update can be applied</li>
            <li>Click <strong>"Apply Update"</strong> for the first update in the list</li>
            <li>Monitor the update progress carefully</li>
            <li>Restart services if required by the update</li>
            <li>Verify the update was applied successfully</li>
            <li>Proceed to the next update if available</li>
        </ol>

        <div class="alert">
            <strong>📋 Update Policy:</strong> Updates are distributed from the main server (opn.agit8or.net) and must be applied sequentially to maintain system integrity.
        </div>
    </div>

    <div id="troubleshooting">
        <h1>🔧 Troubleshooting</h1>
        
        <h2>Common Issues</h2>
        <table>
            <tr><th>Issue</th><th>Symptoms</th><th>Solution</th></tr>
            <tr><td>Firewall Shows Offline</td><td>Red status indicator, no response</td><td>Check network connectivity and SSH access credentials</td></tr>
            <tr><td>Agent Installation Fails</td><td>Installation timeout or error messages</td><td>Verify SSH credentials and firewall accessibility</td></tr>
            <tr><td>Backup Operation Fails</td><td>Backup job shows failed status</td><td>Check disk space on both firewall and management server</td></tr>
            <tr><td>Update Download Fails</td><td>Update check returns errors</td><td>Ensure network connectivity to update server</td></tr>
            <tr><td>Performance Issues</td><td>Slow dashboard loading</td><td>Check server resources and database performance</td></tr>
        </table>

        <h2>Log File Locations</h2>
        <table>
            <tr><th>Component</th><th>Log Location</th><th>Purpose</th></tr>
            <tr><td>Application</td><td>/var/log/opnsense-deploy.log</td><td>General application events</td></tr>
            <tr><td>Web Server</td><td>/var/log/apache2/ or /var/log/nginx/</td><td>HTTP access and error logs</td></tr>
            <tr><td>Database</td><td>/var/log/mysql/error.log</td><td>Database errors and warnings</td></tr>
            <tr><td>PHP</td><td>/var/log/php7.4-fpm.log</td><td>PHP runtime errors</td></tr>
        </table>

        <h2>Performance Optimization</h2>
        <ul>
            <li><strong>Database:</strong> Regularly optimize database tables and update statistics</li>
            <li><strong>Caching:</strong> Enable PHP OPcache for improved performance</li>
            <li><strong>Monitoring:</strong> Adjust refresh intervals based on environment needs</li>
            <li><strong>Cleanup:</strong> Regularly clean old logs and backup files</li>
        </ul>
    </div>

    <div id="api-reference">
        <h1>🔌 API Reference</h1>
        
        <h2>Authentication</h2>
        <p>All API endpoints require authentication using session cookies or API tokens.</p>
        
        <h2>Update Management APIs</h2>
        <table>
            <tr><th>Endpoint</th><th>Method</th><th>Description</th></tr>
            <tr><td>/api/updates/check.php</td><td>POST</td><td>Check for available updates</td></tr>
            <tr><td>/api/updates/download.php</td><td>POST</td><td>Download and apply updates</td></tr>
            <tr><td>/api/instances/register.php</td><td>POST</td><td>Register new customer instance</td></tr>
        </table>

        <h2>Firewall Management APIs</h2>
        <table>
            <tr><th>Endpoint</th><th>Method</th><th>Description</th></tr>
            <tr><td>/api/firewalls/list.php</td><td>GET</td><td>List all managed firewalls</td></tr>
            <tr><td>/api/firewalls/status.php</td><td>GET</td><td>Get firewall status information</td></tr>
            <tr><td>/api/firewalls/backup.php</td><td>POST</td><td>Initiate firewall backup</td></tr>
        </table>

        <div class="alert">
            <strong>🔐 Security Note:</strong> All API communications should use HTTPS in production environments. API rate limiting is recommended to prevent abuse.
        </div>
    </div>
    <?php
    return ob_get_clean();
}

?>
