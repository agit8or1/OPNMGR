<!-- AI Scan Widget for Firewall Detail Pages -->
<div class="ai-scan-widget" style="margin-top: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 8px; color: white;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h4 style="margin: 0 0 5px 0;"><i class="fa fa-brain me-2"></i> AI Security Analysis</h4>
                <p style="margin: 0; opacity: 0.9; font-size: 14px;">Analyze this firewall's configuration with AI</p>
            </div>
            <div>
                <button class="btn btn-light" onclick="showAIScanModal(<?= $firewall_id ?>)" style="padding: 10px 20px; font-weight: 600;">
                    <i class="fa fa-play me-2"></i> Run AI Scan
                </button>
            </div>
        </div>
        
        <?php
        // Get last scan info
        $last_scan = $DB->prepare("SELECT * FROM ai_scan_reports WHERE firewall_id = ? ORDER BY created_at DESC LIMIT 1");
        $last_scan->execute([$firewall_id]);
        $last_scan_data = $last_scan->fetch();
        ?>
        
        <?php if ($last_scan_data): ?>
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.2);">
                <div style="display: flex; gap: 20px; font-size: 13px;">
                    <div>
                        <strong>Last Scan:</strong> <?= date('M j, Y', strtotime($last_scan_data['created_at'])) ?>
                    </div>
                    <div>
                        <strong>Grade:</strong> <?= htmlspecialchars($last_scan_data['overall_grade']) ?>
                    </div>
                    <div>
                        <strong>Score:</strong> <?= $last_scan_data['security_score'] ?>/100
                    </div>
                    <div>
                        <a href="/ai_reports.php?report_id=<?= $last_scan_data['id'] ?>" style="color: white; text-decoration: underline;">
                            View Report <i class="fa fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- AI Scan Modal -->
<div id="aiScanModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999;">
    <div class="modal-content" style="background: #2d3139; margin: 50px auto; padding: 30px; max-width: 600px; border-radius: 8px; color: #e0e0e0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #3498db;">
            <h3 style="margin: 0; color: #4fc3f7;"><i class="fa fa-brain me-2"></i> AI Security Scan</h3>
            <span onclick="closeAIScanModal()" style="cursor: pointer; font-size: 28px; color: #95a5a6;">&times;</span>
        </div>
        
        <div id="scanForm">
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: #81c784; font-weight: 600;">AI Provider *</label>
                <select id="aiProvider" style="width: 100%; padding: 10px; background: #1a1d23; border: 1px solid #3a3f4b; border-radius: 4px; color: #e0e0e0;">
                    <option value="">Select Provider</option>
                    <?php
                    $providers = $DB->query("SELECT * FROM ai_settings WHERE is_active = TRUE ORDER BY provider ASC")->fetchAll();
                    foreach ($providers as $prov):
                    ?>
                        <option value="<?= htmlspecialchars($prov['provider']) ?>">
                            <?= htmlspecialchars(ucfirst($prov['provider'])) ?> (<?= htmlspecialchars($prov['model']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: #81c784; font-weight: 600;">Scan Type *</label>
                <select id="scanType" style="width: 100%; padding: 10px; background: #1a1d23; border: 1px solid #3a3f4b; border-radius: 4px; color: #e0e0e0;">
                    <option value="config_with_logs" selected>Configuration + Log Analysis</option>
                    <option value="config_only">Configuration Only</option>
                </select>
            </div>
            
            <div style="background: rgba(52, 152, 219, 0.1); padding: 15px; border-radius: 4px; border-left: 4px solid #3498db; margin-bottom: 20px;">
                <p style="margin: 0; font-size: 14px;">
                    <i class="fa fa-info-circle me-2"></i>
                    AI will analyze your firewall configuration and recent log files to provide security recommendations, identify active threats, and suggest improvements.
                </p>
            </div>
            
            <button onclick="startAIScan()" style="width: 100%; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; border-radius: 4px; color: white; font-weight: 600; cursor: pointer;">
                <i class="fa fa-play me-2"></i> Start Scan
            </button>
        </div>
        
        <div id="scanProgress" style="display: none;">
            <div style="text-align: center; padding: 40px 0;">
                <i class="fa fa-spinner fa-spin" style="font-size: 48px; color: #4fc3f7; margin-bottom: 20px;"></i>
                <h4 style="color: #4fc3f7; margin-bottom: 10px;">Analyzing Configuration...</h4>
                <p style="color: #95a5a6; margin: 0;">This may take 30-60 seconds</p>
                <div id="scanStatus" style="margin-top: 15px; color: #81c784;"></div>
            </div>
        </div>
        
        <div id="scanResult" style="display: none;"></div>
    </div>
</div>

<style>
.ai-scan-widget .btn-light {
    background: white;
    color: #667eea;
    border: none;
    transition: all 0.3s;
}
.ai-scan-widget .btn-light:hover {
    background: #f8f9fa;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255,255,255,0.3);
}
</style>

<script>
let currentFirewallId = null;

function showAIScanModal(firewallId) {
    currentFirewallId = firewallId;
    document.getElementById('aiScanModal').style.display = 'block';
    document.getElementById('scanForm').style.display = 'block';
    document.getElementById('scanProgress').style.display = 'none';
    document.getElementById('scanResult').style.display = 'none';
}

function closeAIScanModal() {
    document.getElementById('aiScanModal').style.display = 'none';
}

function startAIScan() {
    const provider = document.getElementById('aiProvider').value;
    const scanType = document.getElementById('scanType').value;
    
    if (!provider) {
        alert('Please select an AI provider');
        return;
    }
    
    // Show progress
    document.getElementById('scanForm').style.display = 'none';
    document.getElementById('scanProgress').style.display = 'block';
    
    // Call API
    fetch('/api/ai_scan.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            firewall_id: currentFirewallId,
            provider: provider,
            scan_type: scanType
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success
            document.getElementById('scanProgress').style.display = 'none';
            document.getElementById('scanResult').style.display = 'block';
            document.getElementById('scanResult').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <i class="fa fa-check-circle" style="font-size: 64px; color: #27ae60; margin-bottom: 20px;"></i>
                    <h4 style="color: #27ae60; margin-bottom: 15px;">Scan Complete!</h4>
                    <div style="background: #1a1d23; padding: 20px; border-radius: 8px; margin: 20px 0;">
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; text-align: left;">
                            <div>
                                <div style="color: #95a5a6; font-size: 12px;">Grade</div>
                                <div style="font-size: 24px; font-weight: bold; color: #4fc3f7;">${data.data.grade}</div>
                            </div>
                            <div>
                                <div style="color: #95a5a6; font-size: 12px;">Score</div>
                                <div style="font-size: 24px; font-weight: bold; color: #4fc3f7;">${data.data.score}/100</div>
                            </div>
                            <div>
                                <div style="color: #95a5a6; font-size: 12px;">Risk Level</div>
                                <div style="font-size: 18px; font-weight: bold; color: #f39c12; text-transform: uppercase;">${data.data.risk_level}</div>
                            </div>
                            <div>
                                <div style="color: #95a5a6; font-size: 12px;">Findings</div>
                                <div style="font-size: 18px; font-weight: bold; color: #e67e22;">${data.data.findings_count}</div>
                            </div>
                        </div>
                    </div>
                    <a href="/ai_reports.php?report_id=${data.data.report_id}" style="display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 4px; font-weight: 600; margin-top: 10px;">
                        <i class="fa fa-file-alt me-2"></i> View Full Report
                    </a>
                </div>
            `;
        } else {
            // Show error
            document.getElementById('scanProgress').style.display = 'none';
            document.getElementById('scanResult').style.display = 'block';
            document.getElementById('scanResult').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <i class="fa fa-exclamation-triangle" style="font-size: 64px; color: #e74c3c; margin-bottom: 20px;"></i>
                    <h4 style="color: #e74c3c; margin-bottom: 15px;">Scan Failed</h4>
                    <p style="color: #95a5a6;">${data.error}</p>
                    <button onclick="closeAIScanModal(); showAIScanModal(currentFirewallId);" style="padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer; margin-top: 10px;">
                        Try Again
                    </button>
                </div>
            `;
        }
    })
    .catch(error => {
        document.getElementById('scanProgress').style.display = 'none';
        document.getElementById('scanResult').style.display = 'block';
        document.getElementById('scanResult').innerHTML = `
            <div style="text-align: center; padding: 20px;">
                <i class="fa fa-times-circle" style="font-size: 64px; color: #e74c3c; margin-bottom: 20px;"></i>
                <h4 style="color: #e74c3c;">Error</h4>
                <p style="color: #95a5a6;">${error.message}</p>
            </div>
        `;
    });
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.id === 'aiScanModal') {
        closeAIScanModal();
    }
}
</script>
