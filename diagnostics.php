<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();

include __DIR__ . '/inc/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="fas fa-stethoscope me-2"></i>System Diagnostics
    </h4>
</div>

<div class="row g-4">
    <!-- Agent Status -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-robot me-2"></i>Agent Status
                </h5>
            </div>
            <div class="card-body">
                <?php
                $agents = db()->query("
                    SELECT 
                        f.id,
                        f.hostname,
                        f.ip_address,
                        fa.last_checkin,
                        fa.agent_version,
                        TIMESTAMPDIFF(MINUTE, fa.last_checkin, NOW()) as minutes_ago,
                        CASE 
                            WHEN fa.last_checkin IS NULL THEN 'Never checked in'
                            WHEN fa.last_checkin < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'Offline'
                            ELSE 'Online'
                        END as status
                    FROM firewalls f 
                    LEFT JOIN firewall_agents fa ON f.id = fa.firewall_id 
                    ORDER BY fa.last_checkin DESC
                ")->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Firewall</th>
                                <th>Last Checkin</th>
                                <th>Minutes Ago</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $agent): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($agent['hostname']); ?></td>
                                <td>
                                    <?php if ($agent['last_checkin']): ?>
                                        <?php echo date('Y-m-d H:i:s', strtotime($agent['last_checkin'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $agent['minutes_ago'] ?? 'N/A'; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $agent['status'] === 'Online' ? 'success' : 'danger'; ?>">
                                        <?php echo $agent['status']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Proxy Status -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-network-wired me-2"></i>Proxy Status
                </h5>
            </div>
            <div class="card-body">
                <?php
                $proxies = db()->query("
                    SELECT 
                        id,
                        hostname,
                        ip_address,
                        proxy_port,
                        proxy_enabled
                    FROM firewalls 
                    WHERE proxy_port IS NOT NULL 
                    ORDER BY proxy_port
                ")->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Firewall</th>
                                <th>Target IP</th>
                                <th>Port</th>
                                <th>Test</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($proxies as $proxy): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($proxy['hostname']); ?></td>
                                <td><?php echo htmlspecialchars($proxy['ip_address']); ?></td>
                                <td><?php echo $proxy['proxy_port']; ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary test-connection" 
                                            data-target="<?php echo htmlspecialchars($proxy['ip_address']); ?>"
                                            data-port="<?php echo $proxy['proxy_port']; ?>">
                                        Test
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Connection Test -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-plug me-2"></i>Connection Tests
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <button class="btn btn-primary" onclick="testAgentEndpoint()">
                    <i class="fas fa-robot me-2"></i>Test Agent Endpoint
                </button>
            </div>
            <div class="col-md-4">
                <button class="btn btn-warning" onclick="testSSLCerts()">
                    <i class="fas fa-certificate me-2"></i>Test SSL Certificates
                </button>
            </div>
            <div class="col-md-4">
                <button class="btn btn-info" onclick="testNginxConfig()">
                    <i class="fas fa-server me-2"></i>Test Nginx Config
                </button>
            </div>
        </div>
        
        <div class="mt-3">
            <h6>Test Results:</h6>
            <div id="test-results" class="border rounded p-3 bg-light" style="min-height: 200px; font-family: monospace; white-space: pre-wrap;"></div>
        </div>
    </div>
</div>

<script>
function appendResult(message) {
    const results = document.getElementById('test-results');
    results.textContent += new Date().toLocaleTimeString() + ': ' + message + '\n';
    results.scrollTop = results.scrollHeight;
}

function testAgentEndpoint() {
    appendResult('Testing agent checkin endpoint...');
    
    fetch('/agent_checkin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'firewall_id=21&agent_version=2.0&hostname=test&wan_ip=127.0.0.1&opnsense_version=test'
    })
    .then(response => response.text())
    .then(data => {
        appendResult('Agent endpoint response: ' + data);
    })
    .catch(error => {
        appendResult('Agent endpoint error: ' + error);
    });
}

function testSSLCerts() {
    appendResult('Testing SSL certificates...');
    
    fetch('/api/test_ssl.php')
    .then(response => response.text())
    .then(data => {
        appendResult('SSL test result: ' + data);
    })
    .catch(error => {
        appendResult('SSL test error: ' + error);
    });
}

function testNginxConfig() {
    appendResult('Testing nginx configuration...');
    
    fetch('/api/test_nginx.php')
    .then(response => response.text())
    .then(data => {
        appendResult('Nginx test result: ' + data);
    })
    .catch(error => {
        appendResult('Nginx test error: ' + error);
    });
}

document.querySelectorAll('.test-connection').forEach(button => {
    button.addEventListener('click', function() {
        const target = this.dataset.target;
        const port = this.dataset.port;
        appendResult(`Testing connection to ${target} via proxy port ${port}...`);
        
        // Test if we can reach the proxy port
        fetch(`https://opn.agit8or.net:${port}`, {
            method: 'HEAD',
            mode: 'no-cors'
        })
        .then(() => {
            appendResult(`Proxy port ${port} is accessible`);
        })
        .catch(error => {
            appendResult(`Proxy port ${port} connection failed: ${error}`);
        });
    });
});
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>