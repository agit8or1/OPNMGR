/**
 * Tunnel System Preflight Check
 * Verifies system health before allowing tunnel creation
 */

async function checkTunnelSystemHealth() {
    try {
        const response = await fetch('/api/tunnel_health_check.php');
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Health check failed:', error);
        return {
            healthy: false,
            checks: {},
            errors: ['Failed to perform health check: ' + error.message]
        };
    }
}

async function connectViaOnDemandTunnelWithHealthCheck(firewallId) {
    // Show loading indicator
    const originalButton = event.target;
    const originalText = originalButton.innerHTML;
    originalButton.disabled = true;
    originalButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Checking system...';
    
    // Perform health check
    const health = await checkTunnelSystemHealth();
    
    if (!health.healthy) {
        // System is not healthy - show detailed error
        let errorMessage = '⚠️ TUNNEL SYSTEM HEALTH CHECK FAILED\n\n';
        errorMessage += 'The following issues were detected:\n\n';
        
        health.errors.forEach((error, index) => {
            errorMessage += `${index + 1}. ${error}\n`;
        });
        
        errorMessage += '\n📋 Detailed Status:\n';
        for (const [check, result] of Object.entries(health.checks)) {
            const icon = result.status === 'ok' ? '✅' : '❌';
            errorMessage += `${icon} ${check}: ${result.message}\n`;
        }
        
        errorMessage += '\n⚠️ Cannot create tunnel until these issues are resolved.\n';
        errorMessage += 'Please contact your system administrator.';
        
        alert(errorMessage);
        
        // Restore button
        originalButton.disabled = false;
        originalButton.innerHTML = originalText;
        return false;
    }
    
    // Health check passed - restore button and proceed
    originalButton.disabled = false;
    originalButton.innerHTML = originalText;
    
    // Open the tunnel
    const connectWindow = window.open(
        "/firewall_proxy_ondemand.php?id=" + firewallId,
        "_blank",
        "width=1400,height=900,resizable=yes,scrollbars=yes"
    );
    
    if (!connectWindow) {
        alert("Popup blocked! Please allow popups for this site to use tunnel mode.");
    }
    
    return true;
}

// Show health status indicator (optional - can be called from UI)
async function showTunnelHealthStatus() {
    const health = await checkTunnelSystemHealth();
    
    let statusHtml = '<div style="padding: 15px; background: #2c3e50; border-radius: 8px; margin: 10px 0;">';
    statusHtml += '<h5 style="color: white; margin-bottom: 10px;">🔍 Tunnel System Health</h5>';
    
    for (const [check, result] of Object.entries(health.checks)) {
        const icon = result.status === 'ok' ? '✅' : (result.status === 'warning' ? '⚠️' : '❌');
        const color = result.status === 'ok' ? '#27ae60' : (result.status === 'warning' ? '#f39c12' : '#e74c3c');
        statusHtml += `<div style="color: ${color}; margin: 5px 0;">${icon} <strong>${check}:</strong> ${result.message}</div>`;
    }
    
    statusHtml += '</div>';
    
    return statusHtml;
}
