<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();

include __DIR__ . '/inc/header.php';

// Clean up expired tokens
$stmt = db()->prepare("DELETE FROM enrollment_tokens WHERE expires_at < NOW()");
$stmt->execute();

// Check if we have a valid existing token
$enrollment_token = null;
$stmt = db()->prepare("SELECT token FROM enrollment_tokens WHERE expires_at > NOW() AND used = FALSE ORDER BY created_at DESC LIMIT 1");
$stmt->execute();
$existing_token = $stmt->fetch();

if ($existing_token) {
    $enrollment_token = $existing_token['token'];
} else {
    // Generate a new token
    $enrollment_token = bin2hex(random_bytes(32));
    
    // Store in database with 24 hour expiration
    $expires_at = date('Y-m-d H:i:s', time() + 86400);
    $stmt = db()->prepare("INSERT INTO enrollment_tokens (token, expires_at) VALUES (?, ?)");
    $stmt->execute([$enrollment_token, $expires_at]);
}

// Keep session for backward compatibility
$_SESSION['enrollment_token'] = $enrollment_token;
$_SESSION['enrollment_created'] = time();

$installer_script = '#!/bin/bash

# OPNsense Firewall Enrollment Script
# This script will enroll your OPNsense firewall with the management panel

set -e

# Configuration
PANEL_URL="' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '"
ENROLLMENT_TOKEN="' . $enrollment_token . '"
FIREWALL_HOSTNAME=$(hostname)
FIREWALL_IP=$(curl -s ifconfig.me || hostname -I | awk \'{print $1}\')

echo "=== OPNsense Firewall Enrollment ==="
echo "Panel URL: $PANEL_URL"
echo "Firewall Hostname: $FIREWALL_HOSTNAME"
echo "Firewall IP: $FIREWALL_IP"
echo ""

# Check if we\'re running on OPNsense
if [ ! -f "/usr/local/etc/opnsense-rc" ]; then
    echo "ERROR: This script must be run on an OPNsense firewall"
    exit 1
fi

echo "Installing required packages..."
pkg update
pkg install -y curl jq wget

echo "Generating unique hardware ID..."

# Generate hardware ID using multiple methods for reliability
HARDWARE_ID=""

# Method 1: Try SMBIOS UUID (most reliable)
if command -v dmidecode >/dev/null 2>&1; then
    UUID=$(dmidecode -s system-uuid 2>/dev/null | tr "[:upper:]" "[:lower:]")
    if [[ $UUID && $UUID != "not available" && $UUID != "not present" ]]; then
        HARDWARE_ID="uuid-$UUID"
    fi
fi

# Method 2: Try primary network interface MAC
if [[ -z "$HARDWARE_ID" ]]; then
    # Get the primary interface (usually the one with default route)
    PRIMARY_IF=$(route -n get default 2>/dev/null | grep interface: | awk "{print \$2}")
    if [[ -n "$PRIMARY_IF" ]]; then
        MAC=$(ifconfig "$PRIMARY_IF" 2>/dev/null | grep ether | awk "{print \$2}" | tr "[:upper:]" "[:lower:]")
        if [[ -n "$MAC" ]]; then
            HARDWARE_ID="mac-$MAC"
        fi
    fi
fi

# Method 3: Try motherboard serial
if [[ -z "$HARDWARE_ID" ]] && command -v dmidecode >/dev/null 2>&1; then
    SERIAL=$(dmidecode -s baseboard-serial-number 2>/dev/null)
    if [[ $SERIAL && $SERIAL != "Not Available" && $SERIAL != "Not Specified" ]]; then
        HARDWARE_ID="mb-$SERIAL"
    fi
fi

# Method 4: Fallback to hostname + MAC
if [[ -z "$HARDWARE_ID" ]]; then
    FIRST_MAC=$(ifconfig | grep ether | head -1 | awk "{print \$2}" | tr "[:upper:]" "[:lower:]")
    if [[ -n "$FIREWALL_HOSTNAME" && -n "$FIRST_MAC" ]]; then
        HARDWARE_ID="host-${FIREWALL_HOSTNAME}-${FIRST_MAC}"
    fi
fi

# Final fallback
if [[ -z "$HARDWARE_ID" ]]; then
    HARDWARE_ID="fallback-$(hostname)-$(date +%s)"
fi

echo "Hardware ID: $HARDWARE_ID"
echo "Enrolling firewall with management panel..."

# Create JSON payload using a here document to avoid escaping issues
JSON_PAYLOAD=$(cat <<EOF
{
    "hostname": "$FIREWALL_HOSTNAME",
    "hardware_id": "$HARDWARE_ID",
    "ip_address": "$FIREWALL_IP",
    "wan_ip": "$FIREWALL_IP",
    "token": "$ENROLLMENT_TOKEN"
}
EOF
)

# Send enrollment request
response=$(curl -s -X POST "$PANEL_URL/enroll_firewall.php" \
    -H "Content-Type: application/json" \
    -d "$JSON_PAYLOAD")

if echo "$response" | jq -e .success >/dev/null 2>&1; then
    echo "✅ Firewall enrolled successfully!"
    echo "You can now manage this firewall from the panel."
else
    echo "❌ Enrollment failed:"
    echo "$response"
    exit 1
fi

echo ""
echo "=== Enrollment Complete ==="
echo "Your firewall has been enrolled and is now being monitored."
echo "Return to the management panel to see your firewall status."
';

$wget_command = 'pkg update && pkg install -y wget && wget -q -O /tmp/opnsense_enroll.sh "https://opn.agit8or.net/enroll_firewall.php?token=' . $enrollment_token . '&action=download" && chmod +x /tmp/opnsense_enroll.sh && /tmp/opnsense_enroll.sh';

?>

<div class="row">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-plus me-2"></i>Add New Firewall
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle me-2"></i>Complete Installation Guide</h6>
                    <p>Follow these detailed steps to enroll your OPNsense firewall with the management panel:</p>

                    <div class="accordion" id="installationAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#step1">
                                    <strong>Step 1:</strong> Download the Enrollment Script
                                </button>
                            </h2>
                            <div id="step1" class="accordion-collapse collapse show" data-bs-parent="#installationAccordion">
                                <div class="accordion-body">
                                    <p>Click the <strong>"Download Script"</strong> button below to download the enrollment script to your computer.</p>
                                    <div class="alert alert-light">
                                        <small><i class="fas fa-file-code me-1"></i> File: <code>opnsense_enroll.sh</code></small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step2">
                                    <strong>Step 2:</strong> Connect to Your OPNsense Firewall
                                </button>
                            </h2>
                            <div id="step2" class="accordion-collapse collapse" data-bs-parent="#installationAccordion">
                                <div class="accordion-body">
                                    <p>Use SSH to connect to your OPNsense firewall:</p>
                                    <div class="bg-light p-3 rounded">
                                        <code>ssh root@YOUR_FIREWALL_IP</code>
                                    </div>
                                    <p class="mt-2 mb-0"><small class="text-muted">Replace <code>YOUR_FIREWALL_IP</code> with your firewall's IP address.</small></p>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step3">
                                    <strong>Step 3:</strong> One-Click Enrollment (Simplest!)
                                </button>
                            </h2>
                            <div id="step3" class="accordion-collapse collapse" data-bs-parent="#installationAccordion">
                                <div class="accordion-body">
                                    <div class="alert alert-success">
                                        <strong>⚡ Ultra Simple Method:</strong> Copy the wget command below and paste it directly into your OPNsense SSH session. That's it!
                                    </div>

                                    <p><strong>Copy this single command</strong> and paste it into your SSH session:</p>
                                    <div class="bg-light p-3 rounded mb-3">
                                        <input type="text" class="form-control" id="wgetCommand" value="<?php echo htmlspecialchars($wget_command); ?>" readonly>
                                    </div>

                                    <div class="d-flex gap-2 mb-3">
                                        <button class="btn btn-success btn-sm" onclick="copyWgetCommand()">
                                            <i class="fas fa-copy me-1"></i>Copy Wget Command
                                        </button>
                                        <button class="btn btn-primary btn-sm" onclick="selectWgetCommand()">
                                            <i class="fas fa-mouse-pointer me-1"></i>Select All
                                        </button>
                                    </div>

                                    <p class="text-muted small">
                                        <i class="fas fa-magic me-1"></i>
                                        This single wget command will install wget first, then download the script, make it executable, and run it automatically with your unique enrollment token.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step4">
                                    <strong>Step 4:</strong> Verify Enrollment
                                </button>
                            </h2>
                            <div id="step4" class="accordion-collapse collapse" data-bs-parent="#installationAccordion">
                                <div class="accordion-body">
                                    <p>After successful enrollment:</p>
                                    <ul>
                                        <li>Return to this management panel</li>
                                        <li>Go to the <strong>Firewalls</strong> page</li>
                                        <li>Your firewall should appear in the list</li>
                                        <li>Status should show as "Online"</li>
                                    </ul>
                                    <div class="alert alert-success">
                                        <small><i class="fas fa-check-circle me-1"></i> Enrollment is complete! Your firewall is now being monitored.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-8">
                        <h6>Enrollment Script</h6>
                        <p>Copy this script to your OPNsense firewall and execute it:</p>

                        <div class="mb-3">
                            <textarea class="form-control" id="installerScript" rows="25" readonly><?php echo htmlspecialchars($installer_script); ?></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-primary" onclick="copyToClipboard()">
                                <i class="fas fa-copy me-1"></i>Copy Script
                            </button>
                            <button class="btn btn-success" onclick="downloadScript()">
                                <i class="fas fa-download me-1"></i>Download Script
                            </button>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <h6>Requirements</h6>
                        <div class="card bg-light">
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li><i class="fas fa-check text-success me-2"></i>OPNsense 23.0+</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Internet connectivity</li>
                                    <li><i class="fas fa-check text-success me-2"></i>SSH access to firewall</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Root or admin privileges</li>
                                </ul>
                            </div>
                        </div>

                        <h6 class="mt-4">Security Notes</h6>
                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-shield-alt me-1"></i>
                                The enrollment token is valid for 24 hours and will be automatically deleted after use.
                            </small>
                        </div>

                        <div class="alert alert-success">
                            <small>
                                <i class="fas fa-lock me-1"></i>
                                All communication is encrypted and secure.
                            </small>
                        </div>
                    </div>
                </div>

                <hr>

                <div class="row">
                    <div class="col-md-6">
                        <h6>Manual Enrollment</h6>
                        <p>If you prefer to add the firewall manually:</p>
                        <a href="/firewalls.php" class="btn btn-outline-primary">
                            <i class="fas fa-plus me-1"></i>Add Firewall Manually
                        </a>
                    </div>

                    <div class="col-md-6">
                        <h6>Need Help?</h6>
                        <p>Having trouble with enrollment?</p>
                        <ul class="small">
                            <li>Ensure your firewall has internet access</li>
                            <li>Check that SSH is enabled on your firewall</li>
                            <li>Verify you have root/admin privileges</li>
                            <li>Check firewall logs for any error messages</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyToClipboard() {
    const scriptText = document.getElementById('installerScript');
    scriptText.select();
    document.execCommand('copy');

    // Show success message
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-success');

    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-primary');
    }, 2000);
}

function copyWgetCommand() {
    const wgetInput = document.getElementById('wgetCommand');
    wgetInput.select();
    document.execCommand('copy');

    // Show success message
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
    btn.classList.remove('btn-success');
    btn.classList.add('btn-primary');

    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-success');
    }, 2000);
}

function selectWgetCommand() {
    const wgetInput = document.getElementById('wgetCommand');
    wgetInput.select();
}

function downloadScript() {
    const scriptContent = document.getElementById('installerScript').value;
    const blob = new Blob([scriptContent], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);

    const a = document.createElement('a');
    a.href = url;
    a.download = 'opnsense_enroll.sh';
    document.body.appendChild(a);
    a.click();

    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
}

// Auto-refresh page when token is about to expire (after 23 hours)
const tokenCreated = <?php echo $_SESSION['enrollment_created']; ?>;
const tokenAge = Date.now() / 1000 - tokenCreated;
const timeUntilExpiry = 82800 - tokenAge; // 23 hours - current age

if (timeUntilExpiry > 0) {
    // Set a timeout to refresh the page 5 minutes before expiry
    const refreshTime = Math.max(timeUntilExpiry - 300, 60); // At least 1 minute from now
    setTimeout(() => {
        console.log('Refreshing page to generate new enrollment token...');
        window.location.reload();
    }, refreshTime * 1000);
    
    // Show countdown in console for debugging
    console.log(`Enrollment token will auto-refresh in ${Math.round(refreshTime / 60)} minutes`);
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
