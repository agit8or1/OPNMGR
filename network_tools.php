<?php
/**
 * Network Diagnostic Tools
 * Real-time diagnostic tools using direct SSH commands
 * 
 * Tools:
 * - Ping
 * - Traceroute  
 * - Packet Capture (tcpdump)
 * - Live Log Viewer
 * - DNS Lookup
 * - Port Test
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/header.php';

requireLogin();

// Get firewall ID from query string
$firewall_id = isset($_GET['firewall_id']) ? (int)$_GET['firewall_id'] : 0;

// Get firewall details
$firewall = null;
if ($firewall_id > 0) {
    $stmt = db()->prepare("SELECT * FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();
}

// Get all firewalls for dropdown
$stmt = db()->query("SELECT id, hostname, wan_ip FROM firewalls ORDER BY hostname");
$firewalls = $stmt->fetchAll();
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-stethoscope me-2"></i>Network Diagnostic Tools</h2>
            <p class="text-muted">Real-time network diagnostic tools via SSH</p>
        </div>
    </div>

    <!-- Firewall Selection -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-server me-2"></i>Select Firewall</h5>
                    <select class="form-select" id="firewallSelect" onchange="selectFirewall(this.value)">
                        <option value="">-- Select a firewall --</option>
                        <?php foreach ($firewalls as $fw): ?>
                            <option value="<?= $fw['id'] ?>" <?= $fw['id'] == $firewall_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fw['hostname']) ?> (<?= htmlspecialchars($fw['wan_ip']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <?php if ($firewall): ?>
        <div class="col-md-6">
            <div class="card border-primary">
                <div class="card-body">
                    <h5 class="card-title text-primary"><i class="fas fa-check-circle me-2"></i>Selected Firewall</h5>
                    <p class="mb-1"><strong><?= htmlspecialchars($firewall['hostname']) ?></strong></p>
                    <p class="mb-0 text-muted">WAN IP: <?= htmlspecialchars($firewall['wan_ip']) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($firewall): ?>
    <!-- Diagnostic Tools -->
    <div class="row">
        <!-- Ping Tool -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-wifi me-2"></i>Ping
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Target Host/IP</label>
                        <input type="text" class="form-control" id="pingTarget" placeholder="8.8.8.8" value="8.8.8.8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Count</label>
                        <input type="number" class="form-control" id="pingCount" value="4" min="1" max="20">
                    </div>
                    <button class="btn btn-primary w-100" onclick="runPing()">
                        <i class="fas fa-play me-2"></i>Run Ping
                    </button>
                </div>
            </div>
        </div>

        <!-- Traceroute Tool -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-route me-2"></i>Traceroute
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Target Host/IP</label>
                        <input type="text" class="form-control" id="tracerouteTarget" placeholder="8.8.8.8" value="8.8.8.8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Max Hops</label>
                        <input type="number" class="form-control" id="tracerouteHops" value="30" min="1" max="50">
                    </div>
                    <button class="btn btn-success w-100" onclick="runTraceroute()">
                        <i class="fas fa-play me-2"></i>Run Traceroute
                    </button>
                </div>
            </div>
        </div>

        <!-- DNS Lookup Tool -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-search me-2"></i>DNS Lookup
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Domain Name</label>
                        <input type="text" class="form-control" id="dnsTarget" placeholder="google.com" value="google.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Record Type</label>
                        <select class="form-select" id="dnsType">
                            <option value="A">A (IPv4)</option>
                            <option value="AAAA">AAAA (IPv6)</option>
                            <option value="MX">MX (Mail)</option>
                            <option value="TXT">TXT</option>
                            <option value="NS">NS (Name Server)</option>
                            <option value="ANY">ANY</option>
                        </select>
                    </div>
                    <button class="btn btn-info w-100" onclick="runDNSLookup()">
                        <i class="fas fa-play me-2"></i>Run DNS Lookup
                    </button>
                </div>
            </div>
        </div>

        <!-- Port Test Tool -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-warning text-white">
                    <i class="fas fa-door-open me-2"></i>Port Test
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Target Host/IP</label>
                        <input type="text" class="form-control" id="portTestHost" placeholder="google.com" value="google.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-control" id="portTestPort" placeholder="80" value="80" min="1" max="65535">
                    </div>
                    <button class="btn btn-warning w-100" onclick="runPortTest()">
                        <i class="fas fa-play me-2"></i>Test Port
                    </button>
                </div>
            </div>
        </div>

        <!-- Live Logs -->
        <div class="col-md-12 mb-4">
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <i class="fas fa-file-alt me-2"></i>Live Log Viewer
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Log File</label>
                        <select class="form-select" id="logFile">
                            <option value="/var/log/filter.log">Firewall Filter Log</option>
                            <option value="/var/log/system.log">System Log</option>
                            <option value="/var/log/lighttpd.log">Web Server Log</option>
                            <option value="/var/log/dhcpd.log">DHCP Log</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lines to Display</label>
                        <input type="number" class="form-control" id="logLines" value="50" min="10" max="500">
                    </div>
                    <button class="btn btn-danger w-100 mb-2" onclick="viewLog()">
                        <i class="fas fa-eye me-2"></i>View Log
                    </button>
                </div>
            </div>
        </div>

        <!-- Packet Capture -->
        <div class="col-md-12 mb-4">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-network-wired me-2"></i>Packet Capture (tcpdump)
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Interface</label>
                            <select class="form-select" id="captureInterface">
                                <option value="any">Any Interface</option>
                                <option value="igc0">igc0 (WAN)</option>
                                <option value="igc1">igc1</option>
                                <option value="igc2">igc2</option>
                                <option value="igc3">igc3 (LAN)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Packet Count</label>
                            <input type="number" class="form-control" id="captureCount" value="20" min="1" max="100">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Filter (optional)</label>
                        <input type="text" class="form-control" id="captureFilter" placeholder="host 8.8.8.8" value="">
                        <small class="text-muted">Examples: "port 80", "host 8.8.8.8", "icmp"</small>
                    </div>
                    <button class="btn btn-dark w-100" onclick="runPacketCapture()">
                        <i class="fas fa-play me-2"></i>Start Capture
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Output Terminal -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-terminal me-2"></i>Output</span>
                    <button class="btn btn-sm btn-outline-light" onclick="clearOutput()">
                        <i class="fas fa-trash me-1"></i>Clear
                    </button>
                </div>
                <div class="card-body p-0">
                    <div id="outputTerminal" style="background: #1e1e1e; color: #00ff00; font-family: 'Courier New', monospace; font-size: 13px; padding: 20px; min-height: 400px; max-height: 600px; overflow-y: auto; white-space: pre-wrap;"></div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>Please select a firewall to use diagnostic tools.
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const firewallId = <?= $firewall_id ?>;

function selectFirewall(id) {
    if (id) {
        window.location.href = '/network_tools.php?firewall_id=' + id;
    }
}

function addOutput(text, type = 'info') {
    const terminal = document.getElementById('outputTerminal');
    const timestamp = new Date().toLocaleTimeString();
    const color = type === 'error' ? '#ff5555' : type === 'success' ? '#50fa7b' : '#00ff00';
    terminal.innerHTML += `<span style="color: #6272a4">[${timestamp}]</span> <span style="color: ${color}">${text}</span>\n`;
    terminal.scrollTop = terminal.scrollHeight;
}

function clearOutput() {
    document.getElementById('outputTerminal').innerHTML = '';
    addOutput('Terminal cleared', 'info');
}

function runDiagnostic(tool, params) {
    addOutput(`Running ${tool}...`, 'info');
    
    fetch('/api/run_diagnostic.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            firewall_id: firewallId,
            tool: tool,
            params: params
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            addOutput(`\n${data.output}`, 'success');
        } else {
            addOutput(`Error: ${data.error}`, 'error');
        }
    })
    .catch(error => {
        addOutput(`Error: ${error}`, 'error');
    });
}

function runPing() {
    const target = document.getElementById('pingTarget').value;
    const count = document.getElementById('pingCount').value;
    if (!target) {
        addOutput('Please enter a target', 'error');
        return;
    }
    runDiagnostic('ping', {target, count});
}

function runTraceroute() {
    const target = document.getElementById('tracerouteTarget').value;
    const hops = document.getElementById('tracerouteHops').value;
    if (!target) {
        addOutput('Please enter a target', 'error');
        return;
    }
    runDiagnostic('traceroute', {target, hops});
}

function runDNSLookup() {
    const target = document.getElementById('dnsTarget').value;
    const type = document.getElementById('dnsType').value;
    if (!target) {
        addOutput('Please enter a domain', 'error');
        return;
    }
    runDiagnostic('dns', {target, type});
}

function runPortTest() {
    const host = document.getElementById('portTestHost').value;
    const port = document.getElementById('portTestPort').value;
    if (!host || !port) {
        addOutput('Please enter host and port', 'error');
        return;
    }
    runDiagnostic('port', {host, port});
}

function viewLog() {
    const logFile = document.getElementById('logFile').value;
    const lines = document.getElementById('logLines').value;
    runDiagnostic('log', {file: logFile, lines: lines});
}

function runPacketCapture() {
    const iface = document.getElementById('captureInterface').value;
    const count = document.getElementById('captureCount').value;
    const filter = document.getElementById('captureFilter').value;
    runDiagnostic('tcpdump', {interface: iface, count: count, filter: filter});
}

// Initial message
if (firewallId > 0) {
    addOutput('Network diagnostic tools ready. Select a tool to begin.', 'success');
}
</script>

<style>
.card-header {
    font-weight: 600;
}

#outputTerminal::-webkit-scrollbar {
    width: 10px;
}

#outputTerminal::-webkit-scrollbar-track {
    background: #0d1117;
}

#outputTerminal::-webkit-scrollbar-thumb {
    background: #30363d;
    border-radius: 5px;
}

#outputTerminal::-webkit-scrollbar-thumb:hover {
    background: #484f58;
}
</style>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
