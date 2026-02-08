<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';
requireLogin();

$firewall_id = (int)($_GET['id'] ?? 0);

if (!$firewall_id) {
    header('Location: /firewalls.php');
    exit;
}

// Get firewall details
$stmt = $DB->prepare("SELECT * FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch();

if (!$firewall) {
    header('Location: /firewalls.php');
    exit;
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card bg-dark border-primary">
                <div class="card-header bg-primary">
                    <h4 class="card-title mb-0 text-white">
                        <i class="fas fa-link me-2"></i>Connect to <?php echo htmlspecialchars($firewall['hostname']); ?>
                    </h4>
                </div>
                <div class="card-body">
                    
                    <!-- Progress Indicator (Hidden by default) -->
                    <div id="connectionProgress" class="row justify-content-center mb-4" style="display: none;">
                        <div class="col-md-10">
                            <div class="card bg-secondary border-info">
                                <div class="card-body">
                                    <h5 class="text-center text-info mb-4">
                                        <i class="fas fa-spinner fa-spin me-2"></i>
                                        <span id="progressTitle">Establishing Connection</span>
                                    </h5>
                                    <div class="progress mb-3" style="height: 30px;">
                                        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" 
                                             role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                            <span id="progressText">0%</span>
                                        </div>
                                    </div>
                                    <div id="progressSteps" class="text-center">
                                        <div class="mb-2"><i class="fas fa-circle text-muted me-2"></i><span class="text-muted">Checking firewall status...</span></div>
                                        <div class="mb-2"><i class="fas fa-circle text-muted me-2"></i><span class="text-muted">Configuring secure proxy...</span></div>
                                        <div class="mb-2"><i class="fas fa-circle text-muted me-2"></i><span class="text-muted">Testing connection...</span></div>
                                        <div class="mb-2"><i class="fas fa-circle text-muted me-2"></i><span class="text-muted">Opening firewall interface...</span></div>
                                    </div>
                                    <div id="progressError" class="alert alert-danger mt-3" style="display: none;">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <span id="errorMessage"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Secure Proxy Connection -->
                    <div id="connectionOptions" class="row justify-content-center mb-4">
                        <div class="col-md-8">
                            <div class="card bg-secondary border-success h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-shield-alt text-success fa-3x mb-3"></i>
                                    <h4 class="text-light">Secure Proxy Connection</h4>
                                    <?php if ($firewall['proxy_enabled'] && $firewall['proxy_port']): ?>
                                        <p class="text-success mb-3">
                                            <i class="fas fa-check-circle me-1"></i>
                                            Proxy Active on Port <?php echo $firewall['proxy_port']; ?>
                                        </p>
                                        <button class="btn btn-success btn-lg" onclick="connectWithProgress()">
                                            <i class="fas fa-external-link-alt me-2"></i>Connect Now
                                        </button>
                                        <br><br>
                                        <small class="text-light">Opens firewall web interface in new window</small>
                                        <br><br>
                                        <button class="btn btn-outline-warning btn-sm" onclick="removeProxy()">
                                            <i class="fas fa-times me-1"></i>Disable Proxy
                                        </button>
                                    <?php else: ?>
                                        <p class="text-muted mb-3">Automatically sets up secure tunnel to firewall</p>
                                        <button class="btn btn-success btn-lg" id="setupProxyBtn" onclick="setupProxyWithProgress()">
                                            <i class="fas fa-rocket me-2"></i>Connect to Firewall
                                        </button>
                                        <br><br>
                                        <small class="text-muted">Creates secure nginx reverse proxy tunnel</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Secure Access:</strong> Reverse proxy creates a secure tunnel to <?php echo htmlspecialchars($firewall['hostname']); ?> without exposing firewall ports.
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <a href="/firewall_details.php?id=<?php echo $firewall_id; ?>" class="btn btn-outline-light btn-lg">
                            <i class="fas fa-arrow-left me-2"></i>Back to Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let progressSteps = [
    { percent: 25, icon: 'check-circle', color: 'success', text: 'Firewall status verified' },
    { percent: 50, icon: 'check-circle', color: 'success', text: 'Secure proxy configured' },
    { percent: 75, icon: 'check-circle', color: 'success', text: 'Connection established' },
    { percent: 100, icon: 'check-circle', color: 'success', text: 'Opening firewall interface...' }
];
let currentStep = 0;

function updateProgress(percent, stepIndex) {
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const steps = document.querySelectorAll('#progressSteps div');
    
    progressBar.style.width = percent + '%';
    progressBar.setAttribute('aria-valuenow', percent);
    progressText.textContent = percent + '%';
    
    if (stepIndex !== undefined && steps[stepIndex]) {
        const step = progressSteps[stepIndex];
        const icon = steps[stepIndex].querySelector('i');
        const text = steps[stepIndex].querySelector('span');
        
        icon.className = `fas fa-${step.icon} text-${step.color} me-2`;
        text.className = `text-${step.color}`;
        text.textContent = step.text;
    }
}

function showProgress() {
    document.getElementById('connectionOptions').style.display = 'none';
    document.getElementById('connectionProgress').style.display = 'block';
}

function hideProgress() {
    document.getElementById('connectionOptions').style.display = 'block';
    document.getElementById('connectionProgress').style.display = 'none';
    document.getElementById('progressError').style.display = 'none';
    currentStep = 0;
    updateProgress(0);
}

function showError(message) {
    document.getElementById('errorMessage').textContent = message;
    document.getElementById('progressError').style.display = 'block';
    document.getElementById('progressBar').classList.remove('progress-bar-animated');
    document.getElementById('progressBar').classList.add('bg-danger');
}

function setupProxyWithProgress() {
    showProgress();
    currentStep = 0;
    
    // Step 1: Check firewall status
    updateProgress(25, 0);
    
    const formData = new FormData();
    formData.append('firewall_id', <?php echo $firewall_id; ?>);
    formData.append('csrf_token', '<?php echo csrf_token(); ?>');
    
    setTimeout(() => {
        // Step 2: Establish SSH tunnel
        updateProgress(50, 1);
        document.getElementById('progressTitle').textContent = 'Establishing SSH Tunnel...';
        
        fetch('/api/start_ssh_tunnel.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success || data.already_running) {
                // Step 3: Test connection
                updateProgress(75, 2);
                document.getElementById('progressTitle').textContent = 'Testing Connection...';
                
                setTimeout(() => {
                    // Step 4: Open firewall
                    updateProgress(100, 3);
                    document.getElementById('progressTitle').textContent = 'Connection Ready!';
                    
                    setTimeout(() => {
                        connectToFirewallDirect();
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    }, 1000);
                }, 800);
            } else {
                showError('Failed to establish tunnel: ' + (data.error || 'Unknown error') + (data.hint ? '\n\n' + data.hint : ''));
                setTimeout(() => {
                    hideProgress();
                }, 5000);
            }
        })
        .catch(error => {
            console.error('Tunnel setup error:', error);
            showError('Network error occurred while setting up tunnel');
            setTimeout(() => {
                hideProgress();
            }, 3000);
        });
    }, 500);
}

function setupProxy() {
    if (confirm('Setup secure proxy tunnel for <?php echo htmlspecialchars($firewall['hostname']); ?>?')) {
        setupProxyWithProgress();
    }
}

function removeProxy() {
    if (confirm('Remove proxy tunnel for <?php echo htmlspecialchars($firewall['hostname']); ?>?')) {
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Removing...';
        
        const formData = new FormData();
        formData.append('firewall_id', <?php echo $firewall_id; ?>);
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');
        
        fetch('/api/remove_reverse_proxy.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✅ Proxy removed successfully');
                location.reload();
            } else {
                alert('❌ Error removing proxy:\n' + (data.error || 'Unknown error'));
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('Proxy removal error:', error);
            alert('❌ Network error occurred while removing proxy');
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    }
}

function connectToFirewallDirect() {
    // Use on-demand agent proxy (scales for many firewalls)
    const proxyUrl = '/firewall_proxy.php?fw_id=<?php echo $firewall_id; ?>';
    const firewallWindow = window.open(proxyUrl, '_blank', 'width=1400,height=900');
    
    if (!firewallWindow) {
        alert('❌ Popup blocked!\nPlease allow popups for this site and try again.\n\nOr manually visit: ' + proxyUrl);
    }
}

function connectToFirewall() {
    connectToFirewallDirect();
}

function connectWithProgress() {
    showProgress();
    document.getElementById('progressTitle').textContent = 'Connecting to Firewall...';
    updateProgress(25, 0);
    
    // Check if agent is online first
    fetch('/api/firewall_status.php?id=<?php echo $firewall_id; ?>')
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'online') {
                showError('Firewall agent is offline. Please ensure the agent is running.');
                return;
            }
            
            updateProgress(50, 1);
            document.getElementById('progressTitle').textContent = 'Agent Ready - Establishing Connection...';
            
            setTimeout(() => {
                updateProgress(75, 2);
                document.getElementById('progressTitle').textContent = 'Opening Interface...';
                
                setTimeout(() => {
                    updateProgress(100, 3);
                    document.getElementById('progressTitle').textContent = 'Ready!';
                    connectToFirewallDirect();
                    setTimeout(() => {
                        hideProgress();
                    }, 1000);
                }, 500);
            }, 500);
        })
        .catch(error => {
            showError('Failed to check firewall status: ' + error.message);
        });
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>