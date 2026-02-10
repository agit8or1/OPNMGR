<?php
// Development > Features & Standards Documentation
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
$page_title = "Features & Standards";
include 'inc/header.php';

// Get version info
$versionInfo = getVersionInfo();
$changelogEntries = getChangelogEntries(5);
?>

<style>
body {
    background: #1a1d23;
    color: #e0e0e0;
}
.feature-section {
    background: #2d3139;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    border: 1px solid #3a3f4b;
}
.feature-section h2 {
    color: #4fc3f7;
    border-bottom: 3px solid #3498db;
    padding-bottom: 10px;
    margin-bottom: 20px;
}
.feature-section h3 {
    color: #81c784;
    margin-top: 20px;
    margin-bottom: 15px;
}
.feature-section h4 {
    color: #ffb74d;
    margin-top: 15px;
}
.feature-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 10px;
}
.badge-production { background: #27ae60; color: white; }
.badge-beta { background: #f39c12; color: white; }
.badge-planned { background: #95a5a6; color: white; }
.badge-deprecated { background: #e74c3c; color: white; }
.feature-list {
    list-style: none;
    padding-left: 0;
}
.feature-list li {
    padding: 10px 0;
    border-bottom: 1px solid #3a3f4b;
    color: #b0b0b0;
}
.feature-list li:last-child {
    border-bottom: none;
}
.feature-list li strong {
    color: #e0e0e0;
}
.feature-list li p {
    color: #909090;
    margin-top: 5px;
}
.version-tag {
    color: #78909c;
    font-size: 12px;
    font-style: italic;
}
.security-highlight {
    background: #1e3a28;
    border-left: 4px solid #4caf50;
    padding: 15px;
    margin: 15px 0;
    color: #c8e6c9;
}
.security-highlight ul {
    color: #a5d6a7;
}
.highlight-box {
    background: #3a3020;
    border-left: 4px solid #ffc107;
    padding: 15px;
    margin: 15px 0;
    color: #ffe0b2;
}
.highlight-box ul {
    color: #ffcc80;
}
.text-muted {
    color: #90a4ae !important;
}
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1><i class="fas fa-rocket"></i> OPNManager Features & Standards</h1>
            <p class="text-muted">Comprehensive documentation of all features, security measures, and development standards.</p>
            <button class="btn btn-primary btn-lg mb-3" onclick="updateDocumentation()">
                <i class="fas fa-sync-alt me-2"></i>Update Documentation
            </button>
            <div id="updateStatus" class="alert" style="display: none;"></div>
            <p class="text-muted"><strong>Last Updated:</strong> <?= APP_VERSION_DATE ?> | <strong>Version:</strong> <?= APP_VERSION ?></p>
        </div>
    </div>

    <!-- CURRENT FEATURES -->
    <div class="feature-section">
        <h2><i class="fas fa-check-circle text-success"></i> Production Features</h2>
        
        <h3>Core Firewall Management</h3>
        <ul class="feature-list">
            <li>
                <strong>Multi-Firewall Dashboard</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v1.0.0+</span>
                <p>Centralized management of multiple OPNsense firewalls with real-time status monitoring, health scoring (0-100), and quick actions.</p>
            </li>
            <li>
                <strong>Health Score System</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v1.5.0+</span>
                <p>Comprehensive firewall health scoring based on connectivity (35pts), version/updates (25pts), uptime (20pts), and configuration (15pts). Capped at 100 points.</p>
            </li>
            <li>
                <strong>Automated Agent Check-ins</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v3.6.0+</span>
                <p>Lightweight Python agent running on firewalls, checking in every 5 minutes with system stats, interface data, and gateway status.</p>
            </li>
        </ul>

        <h3>Secure Tunnel Proxy System</h3>
        <ul class="feature-list">
            <li>
                <strong>SSH Tunnel with HTTPS Proxy</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v2.2.0+</span>
                <p>Direct nginx HTTPS reverse proxy through SSH tunnels. Architecture: Browser → Nginx HTTPS (port-1) → SSH Tunnel (port) → Firewall:80</p>
                <div class="security-highlight">
                    <strong>Security:</strong> Double encryption layer (HTTPS + SSH), automatic session cleanup (15min idle, 30min max), zero credential storage.
                </div>
            </li>
            <li>
                <strong>Permanent SSH Access</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v2.1.0+</span>
                <p>Single permanent firewall rule "Allow SSH from OPNManager" instead of temporary rules. Enables instant tunnel creation (2-3 seconds vs 5-10 seconds).</p>
            </li>
            <li>
                <strong>Automatic Cleanup & Orphan Detection</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v2.2.0+</span>
                <p>Pre-flight cleanup before tunnel creation, cron job every 5 minutes, sudo permissions for process management.</p>
            </li>
        </ul>

        <h3>Configuration Management</h3>
        <ul class="feature-list">
            <li>
                <strong>Automated Nightly Backups</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v1.0.0+</span>
                <p>Automatic configuration backups at 2 AM daily via direct SSH. Backups stored locally with retention policy.</p>
            </li>
            <li>
                <strong>Manual Backup & Restore</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v1.0.0+</span>
                <p>On-demand configuration backups and one-click restore functionality.</p>
            </li>
            <li>
                <strong>Config Comparison & Diff Viewer</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v1.2.0+</span>
                <p>Side-by-side XML diff viewer for comparing configuration backups.</p>
            </li>
        </ul>

        <h3>Rule Management</h3>
        <ul class="feature-list">
            <li>
                <strong>Firewall Rule Browser</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v1.3.0+</span>
                <p>View all firewall rules with filtering, search, and rule details. Read-only access via API.</p>
            </li>
            <li>
                <strong>Direct SSH Command Execution</strong> <span class="feature-badge badge-production">PRODUCTION</span>
                <span class="version-tag">v2.1.0+</span>
                <p>Execute commands instantly via SSH without queue delays. Used for system updates, package management, and diagnostics.</p>
            </li>
        </ul>
    </div>

    <!-- UPCOMING FEATURES -->
    <div class="feature-section">
        <h2><i class="fas fa-calendar-alt text-warning"></i> Planned Features</h2>
        
        <ul class="feature-list">
            <li>
                <strong>AI-Powered Config Analysis</strong> <span class="feature-badge badge-planned">PLANNED</span>
                <p>ChatGPT/Claude API integration for security analysis, log scanning, and recommendations. Security grading (A-F), incident flagging, GeoIP analysis.</p>
            </li>
            <li>
                <strong>Network Diagnostic Tools</strong> <span class="feature-badge badge-planned">PLANNED</span>
                <p>Built-in ping, traceroute, packet capture, and live log streaming with real-time output display.</p>
            </li>
            <li>
                <strong>Automated WAN Bandwidth Testing</strong> <span class="feature-badge badge-planned">PLANNED</span>
                <p>Scheduled bandwidth tests from firewall with historical graphing and performance tracking.</p>
            </li>
            <li>
                <strong>Data Retention & Purging</strong> <span class="feature-badge badge-planned">PLANNED</span>
                <p>45-day automatic cleanup of old stats, logs, and backups with configurable retention periods.</p>
            </li>
            <li>
                <strong>Count-Based Backup Retention</strong> <span class="feature-badge badge-planned">PLANNED</span>
                <p>Keep last 30-90 backups instead of time-based retention. Configurable min/max backup counts.</p>
            </li>
            <li>
                <strong>Deployment Package Generator</strong> <span class="feature-badge badge-planned">PLANNED</span>
                <p>Generate clean deployment packages excluding primary-server-only features for distributed installations.</p>
            </li>
            <li>
                <strong>Licensing & Update Server</strong> <span class="feature-badge badge-planned">PLANNED</span>
                <p>Central licensing server for deployed instances, firewall count limits, automatic update distribution.</p>
            </li>
        </ul>
    </div>

    <!-- SECURITY STANDARDS -->
    <div class="feature-section">
        <h2><i class="fas fa-shield-alt text-success"></i> Security Standards & Measures</h2>
        
        <div class="security-highlight">
            <h4>Encryption & Transport Security</h4>
            <ul>
                <li><strong>HTTPS Only:</strong> All web traffic encrypted with Let's Encrypt SSL certificates</li>
                <li><strong>SSH Key Authentication:</strong> Password-less SSH access using RSA 4096-bit keys</li>
                <li><strong>Double Encryption:</strong> Tunnel proxy uses both HTTPS and SSH tunnel encryption</li>
                <li><strong>TLS 1.2/1.3:</strong> Modern TLS protocols only, no legacy SSL support</li>
            </ul>
        </div>

        <div class="security-highlight">
            <h4>Authentication & Session Management</h4>
            <ul>
                <li><strong>Password Hashing:</strong> Bcrypt with cost factor 12</li>
                <li><strong>Session Security:</strong> HttpOnly, Secure, SameSite=Lax cookies</li>
                <li><strong>Session Timeout:</strong> 4-hour PHP session timeout, configurable tunnel session limits</li>
                <li><strong>Strict Mode:</strong> PHP session.use_strict_mode enabled</li>
            </ul>
        </div>

        <div class="security-highlight">
            <h4>Access Control</h4>
            <ul>
                <li><strong>Role-Based Access:</strong> Admin-only access to sensitive operations</li>
                <li><strong>Zero Credential Storage:</strong> No firewall passwords stored, SSH keys only</li>
                <li><strong>Sudo Whitelist:</strong> Limited sudo permissions via /etc/sudoers.d/ with specific command paths</li>
                <li><strong>API Authentication:</strong> Separate API credentials for firewall management</li>
            </ul>
        </div>

        <div class="security-highlight">
            <h4>Network Security</h4>
            <ul>
                <li><strong>Firewall Rules:</strong> Single permanent SSH rule with source IP restriction</li>
                <li><strong>Port Isolation:</strong> Tunnel ports (8100-8200) only accessible via HTTPS proxy</li>
                <li><strong>Automatic Cleanup:</strong> Orphaned tunnels killed every 5 minutes</li>
                <li><strong>Session Limits:</strong> Max 30-minute sessions, 15-minute idle timeout</li>
            </ul>
        </div>
    </div>

    <!-- TECHNICAL HIGHLIGHTS -->
    <div class="feature-section">
        <h2><i class="fas fa-cogs text-info"></i> Technical Highlights</h2>
        
        <h3>Architecture</h3>
        <div class="highlight-box">
            <ul>
                <li><strong>Stack:</strong> PHP 8.3-FPM, Nginx 1.24.0, MySQL 8.0, Ubuntu 24.04 LTS</li>
                <li><strong>Agent:</strong> Python 3.x lightweight daemon with systemd service</li>
                <li><strong>Automation:</strong> Cron jobs for backups (2 AM), cleanup (every 5 min), agent checks</li>
                <li><strong>API Integration:</strong> OPNsense REST API for firewall management</li>
            </ul>
        </div>

        <h3>Performance Optimizations</h3>
        <div class="highlight-box">
            <ul>
                <li><strong>Direct SSH:</strong> Eliminated command queue bottleneck (instant vs 30-60s delay)</li>
                <li><strong>Permanent Rules:</strong> Reduced tunnel creation time from 5-10s to 2-3s</li>
                <li><strong>Session Reuse:</strong> Existing active tunnels automatically reused</li>
                <li><strong>Pre-flight Cleanup:</strong> Port conflicts resolved before tunnel creation</li>
            </ul>
        </div>

        <h3>Reliability Features</h3>
        <div class="highlight-box">
            <ul>
                <li><strong>Health Monitoring:</strong> Real-time firewall status with 5-minute check-in intervals</li>
                <li><strong>Automatic Recovery:</strong> Agent self-healing and automatic restart on failure</li>
                <li><strong>Orphan Detection:</strong> Automatic cleanup of stale SSH tunnels and nginx configs</li>
                <li><strong>Error Logging:</strong> Comprehensive logging in /var/www/opnsense/logs/</li>
            </ul>
        </div>
    </div>

    <!-- VERSION HISTORY -->
    <div class="feature-section">
        <h2><i class="fas fa-history text-secondary"></i> Recent Version History</h2>
        
        <ul class="feature-list">
            <?php foreach ($changelogEntries as $entry): ?>
            <li>
                <strong>v<?= htmlspecialchars($entry['version']) ?></strong> <span class="version-tag"><?= htmlspecialchars($entry['date']) ?></span>
                <p><?= htmlspecialchars($entry['title']) ?></p>
                <?php if (!empty($entry['changes'])): ?>
                <ul style="color: #90a4ae; margin-top: 8px;">
                    <?php foreach (array_slice($entry['changes'], 0, 3) as $change): ?>
                    <li style="border: none; padding: 4px 0; margin-left: 15px;"><?= htmlspecialchars($change) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- DOCUMENTATION LINKS -->
    <div class="feature-section">
        <h2><i class="fas fa-book text-primary"></i> Documentation</h2>
        
        <ul class="feature-list">
            <li>
                <strong><a href="/view_doc.php?file=TUNNEL_PROXY_FIX_DOCUMENTATION.md" target="_blank" style="color: #64b5f6;">TUNNEL_PROXY_FIX_DOCUMENTATION.md</a></strong>
                <p>Complete technical documentation of tunnel proxy system</p>
            </li>
            <li>
                <strong><a href="/view_doc.php?file=TUNNEL_PROXY_QUICK_REFERENCE.md" target="_blank" style="color: #64b5f6;">TUNNEL_PROXY_QUICK_REFERENCE.md</a></strong>
                <p>Quick troubleshooting guide and commands</p>
            </li>
            <li>
                <strong><a href="/view_doc.php?file=PERMANENT_SSH_RULE_IMPLEMENTATION.md" target="_blank" style="color: #64b5f6;">PERMANENT_SSH_RULE_IMPLEMENTATION.md</a></strong>
                <p>Details on permanent SSH access implementation</p>
            </li>
            <li>
                <strong><a href="/view_doc.php?file=CHANGELOG.md" target="_blank" style="color: #64b5f6;">CHANGELOG.md</a></strong>
                <p>Complete version history with all changes</p>
            </li>
            <li>
                <strong><a href="/view_doc.php?file=README_TUNNEL_PROXY.md" target="_blank" style="color: #64b5f6;">README_TUNNEL_PROXY.md</a></strong>
                <p>Main index and quick start guide</p>
            </li>
            <li>
                <strong><a href="/view_doc.php?file=TUNNEL_BADGE_ISSUE.md" target="_blank" style="color: #64b5f6;">TUNNEL_BADGE_ISSUE.md</a></strong>
                <p>Technical explanation of badge limitations and future solutions</p>
            </li>
            <li>
                <strong><a href="/view_doc.php?file=FIXES_SUMMARY_v2.2.2.md" target="_blank" style="color: #64b5f6;">FIXES_SUMMARY_v2.2.2.md</a></strong>
                <p>Detailed summary of issues resolved in v2.2.2</p>
            </li>
        </ul>
    </div>
</div>


<script>
function updateDocumentation() {
    const btn = event.target;
    const statusDiv = document.getElementById("updateStatus");
    
    btn.disabled = true;
    btn.innerHTML = "<i class="fas fa-spinner fa-spin me-2"></i>Updating...";
    statusDiv.style.display = "block";
    statusDiv.className = "alert alert-info";
    statusDiv.innerHTML = "Running documentation update script...";
    
    fetch("/api/update_docs.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"}
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusDiv.className = "alert alert-success";
            statusDiv.innerHTML = "<h5>✅ Documentation Updated Successfully!</h5>";
            statusDiv.innerHTML += "<p>" + data.message + "</p>";
            if (data.output) {
                statusDiv.innerHTML += "<pre style="background: #1a1d23; padding: 15px; border-radius: 5px;">" + data.output + "</pre>";
            }
            setTimeout(() => location.reload(), 2000);
        } else {
            statusDiv.className = "alert alert-danger";
            statusDiv.innerHTML = "<strong>Error:</strong> " + (data.error || "Unknown error");
        }
    })
    .catch(error => {
        statusDiv.className = "alert alert-danger";
        statusDiv.innerHTML = "<strong>Error:</strong> " + error;
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = "<i class="fas fa-sync-alt me-2"></i>Update Documentation";
    });
}
</script>
<?php include 'inc/footer.php'; ?>
