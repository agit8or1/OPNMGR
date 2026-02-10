#!/usr/bin/env php
<?php
/**
 * Migrate hardcoded documentation content to database
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

// Extract content from about.php
$aboutContent = <<<'HTML'
<h2>About OPNManager v2.2.3</h2>
<p class="lead">Centralized management platform for OPNsense firewalls with secure SSH tunnel proxy capabilities.</p>

<h3>System Architecture</h3>
<p>OPNManager uses a dual-agent system to provide secure, NAT-friendly firewall management:</p>
<ul>
    <li><strong>Primary Agent (opnagent.py):</strong> Handles system monitoring, command execution, and backup operations</li>
    <li><strong>Tunnel Agent (tunnel_agent.py):</strong> Maintains persistent SSH reverse tunnels for web UI proxy access</li>
</ul>

<h3>Key Features</h3>
<ul>
    <li>Real-time firewall monitoring and statistics</li>
    <li>Secure SSH tunnel proxy for web UI access</li>
    <li>Automated configuration backups</li>
    <li>Command queue system for remote execution</li>
    <li>Alert notifications (email, Pushover, webhook)</li>
    <li>User authentication with 2FA support</li>
</ul>

<h3>Security</h3>
<p>All communication is secured using SSH tunnels and certificate-based authentication. No firewall credentials are stored on the OPNManager server.</p>
HTML;

$documentationContent = <<<'HTML'
<h2>OPNManager Documentation</h2>
<p class="lead">Complete guide to using OPNManager for centralized firewall management.</p>

<h3>Getting Started</h3>
<ol>
    <li><strong>Add Firewalls:</strong> Navigate to Firewalls → Add Firewall to register your OPNsense systems</li>
    <li><strong>Deploy Agents:</strong> Install the primary and tunnel agents on each firewall</li>
    <li><strong>Verify Connection:</strong> Check the dashboard for firewall online status</li>
    <li><strong>Access Web UI:</strong> Use the tunnel proxy to securely access firewall web interfaces</li>
</ol>

<h3>Features Overview</h3>
<h4>Dashboard</h4>
<p>View system-wide statistics, firewall status, recent alerts, and traffic graphs.</p>

<h4>Firewall Management</h4>
<p>Add, edit, and remove firewalls. Monitor CPU, memory, disk usage, and network interfaces.</p>

<h4>SSH Tunnel Proxy</h4>
<p>Access firewall web UIs through secure SSH tunnels without exposing ports to the internet.</p>

<h4>Configuration Backups</h4>
<p>Automated daily backups with retention policies. Download or restore configurations as needed.</p>

<h4>Command Queue</h4>
<p>Execute remote commands on firewalls through a queued system with progress tracking.</p>

<h4>Alert System</h4>
<p>Configure alert triggers for CPU, memory, disk, firewall status, and certificate expiration.</p>

<h3>Troubleshooting</h3>
<h4>Firewall Shows Offline</h4>
<ul>
    <li>Check if opnagent.py service is running</li>
    <li>Verify network connectivity from firewall to OPNManager</li>
    <li>Check SSH key authentication</li>
</ul>

<h4>Tunnel Connection Failed</h4>
<ul>
    <li>Verify tunnel_agent.py is running</li>
    <li>Check if tunnel port is already in use</li>
    <li>Review nginx tunnel proxy logs</li>
</ul>
HTML;

$featuresContent = <<<'HTML'
<h2>OPNManager Features</h2>
<p class="lead">Comprehensive feature set for enterprise firewall management.</p>

<h3>Core Features</h3>
<div class="row">
    <div class="col-md-6">
        <h4>Centralized Management</h4>
        <ul>
            <li>Manage unlimited OPNsense firewalls from single interface</li>
            <li>Real-time monitoring and alerting</li>
            <li>Bulk operations across multiple firewalls</li>
            <li>Role-based access control</li>
        </ul>
    </div>
    <div class="col-md-6">
        <h4>Security</h4>
        <ul>
            <li>SSH key-based authentication</li>
            <li>Two-factor authentication (2FA)</li>
            <li>Encrypted communication channels</li>
            <li>Audit logging of all actions</li>
        </ul>
    </div>
</div>

<h3>SSH Tunnel Proxy System</h3>
<p>Industry-leading NAT-friendly firewall access:</p>
<ul>
    <li><strong>Reverse SSH Tunnels:</strong> Firewalls initiate connections to OPNManager</li>
    <li><strong>Automatic Reconnection:</strong> Resilient to network interruptions</li>
    <li><strong>Session Management:</strong> 10-second idle detection prevents zombie connections</li>
    <li><strong>Nginx Integration:</strong> High-performance proxy with SSL/TLS termination</li>
</ul>

<h3>Backup & Restore</h3>
<ul>
    <li>Automated daily configuration backups</li>
    <li>Retention policies (count-based or time-based)</li>
    <li>One-click restore functionality</li>
    <li>Backup verification and integrity checks</li>
    <li>System backup includes database, configs, SSH keys, SSL certificates</li>
</ul>

<h3>Monitoring & Alerts</h3>
<ul>
    <li>Real-time CPU, memory, disk, and network monitoring</li>
    <li>Configurable alert triggers with thresholds</li>
    <li>Multiple notification methods (email, Pushover, webhook)</li>
    <li>Alert history and acknowledgment tracking</li>
</ul>

<h3>Command Queue System</h3>
<ul>
    <li>Reliable remote command execution</li>
    <li>Queue status tracking (pending, running, completed, failed)</li>
    <li>Output capture and logging</li>
    <li>Retry logic for failed commands</li>
</ul>

<h3>User Management</h3>
<ul>
    <li>Admin and standard user roles</li>
    <li>Two-factor authentication support</li>
    <li>Session management with automatic timeout</li>
    <li>Activity logging</li>
</ul>
HTML;

$standardsContent = <<<'HTML'
<h2>Development Standards</h2>
<p class="lead">Guidelines and best practices for OPNManager development.</p>

<h3>Code Standards</h3>
<h4>PHP</h4>
<ul>
    <li>PSR-12 coding style with 4-space indentation</li>
    <li>Type declarations for function parameters and return values</li>
    <li>PDO prepared statements for all database queries</li>
    <li>Error logging instead of displaying errors to users</li>
</ul>

<h4>Python (Agents)</h4>
<ul>
    <li>PEP 8 style guide compliance</li>
    <li>Type hints for function signatures</li>
    <li>Comprehensive error handling and logging</li>
    <li>Systemd service integration for reliability</li>
</ul>

<h4>JavaScript</h4>
<ul>
    <li>ES6+ modern syntax</li>
    <li>Bootstrap 5 components and utilities</li>
    <li>Fetch API for AJAX requests</li>
    <li>Chart.js for data visualization</li>
</ul>

<h3>Security Standards</h3>
<ul>
    <li><strong>Authentication:</strong> All pages require login except public API endpoints</li>
    <li><strong>Authorization:</strong> Admin-only functions use requireAdmin() check</li>
    <li><strong>Input Validation:</strong> Sanitize all user input, validate on server side</li>
    <li><strong>SQL Injection:</strong> Use PDO prepared statements exclusively</li>
    <li><strong>XSS Prevention:</strong> Use htmlspecialchars() for all output</li>
    <li><strong>CSRF Protection:</strong> Implement CSRF tokens for state-changing operations</li>
    <li><strong>Password Storage:</strong> Use password_hash() with bcrypt</li>
</ul>

<h3>Database Standards</h3>
<ul>
    <li>InnoDB engine for ACID compliance</li>
    <li>UTF-8 MB4 character set for full Unicode support</li>
    <li>Indexed foreign keys for performance</li>
    <li>Timestamps for audit trails (created_at, updated_at)</li>
    <li>Soft deletes where appropriate (is_deleted flag)</li>
</ul>

<h3>Git Workflow</h3>
<ul>
    <li>Main branch: production-ready code only</li>
    <li>Development branch: integration testing</li>
    <li>Feature branches: feature/description naming</li>
    <li>Commit messages: imperative mood, descriptive</li>
    <li>Code review: all PRs require review before merge</li>
</ul>

<h3>Testing Standards</h3>
<ul>
    <li>Unit tests for critical business logic</li>
    <li>Integration tests for API endpoints</li>
    <li>Manual testing checklist for UI changes</li>
    <li>Test with minimum supported PHP/Python versions</li>
</ul>

<h3>Documentation Standards</h3>
<ul>
    <li>PHPDoc blocks for all functions and classes</li>
    <li>README.md for installation and configuration</li>
    <li>CHANGELOG.md updated with each release</li>
    <li>API documentation for public endpoints</li>
    <li>User documentation separate from technical docs</li>
</ul>
HTML;

// Insert documentation pages
$pages = [
    [
        'page_key' => 'about',
        'title' => 'About OPNManager',
        'content' => $aboutContent,
        'category' => 'general',
        'display_order' => 1
    ],
    [
        'page_key' => 'documentation',
        'title' => 'User Documentation',
        'content' => $documentationContent,
        'category' => 'user',
        'display_order' => 2
    ],
    [
        'page_key' => 'features',
        'title' => 'Features',
        'content' => $featuresContent,
        'category' => 'general',
        'display_order' => 3
    ],
    [
        'page_key' => 'standards',
        'title' => 'Development Standards',
        'content' => $standardsContent,
        'category' => 'development',
        'display_order' => 4
    ]
];

try {
    $stmt = db()->prepare("
        INSERT INTO documentation_pages (page_key, title, content, category, display_order, updated_by)
        VALUES (:page_key, :title, :content, :category, :display_order, 'migration_script')
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            content = VALUES(content),
            category = VALUES(category),
            display_order = VALUES(display_order)
    ");

    foreach ($pages as $page) {
        $stmt->execute($page);
        echo "✓ Migrated: {$page['page_key']}\n";
    }

    echo "\n✅ Successfully migrated " . count($pages) . " documentation pages to database\n";
    echo "Pages can now be updated via the database or through the documentation management interface.\n";

} catch (PDOException $e) {
    echo "❌ Error migrating documentation: " . $e->getMessage() . "\n";
    exit(1);
}
