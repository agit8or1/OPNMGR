<?php
require_once __DIR__ . '/inc/db.php';

// Handle script download requests
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['token'])) {
    $token = $_GET['token'];
    $panel_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    
    $script = '#!/bin/bash
set -e

PANEL_URL="' . $panel_url . '"
ENROLLMENT_TOKEN="' . $token . '"

echo "=== OPNsense Firewall Enrollment ==="
echo "Hostname: $(hostname)"
echo "Firewall IP: $(ifconfig | grep "inet " | grep -v "127.0.0.1" | head -1 | awk "{print $2}")"

# Generate hardware ID from MAC address
HARDWARE_ID="mac-$(ifconfig | grep ether | head -1 | awk "{print $2}")"
FIREWALL_HOSTNAME=$(hostname)
FIREWALL_IP=$(ifconfig | grep "inet " | grep -v "127.0.0.1" | head -1 | awk "{print $2}")

echo "Hardware ID: $HARDWARE_ID"
echo "Enrolling firewall with management panel..."

# Send enrollment request
response=$(curl -s -X POST "$PANEL_URL/enroll_firewall.php" \
    -H "Content-Type: application/json" \
    -d "{\"hostname\":\"$FIREWALL_HOSTNAME\",\"hardware_id\":\"$HARDWARE_ID\",\"ip_address\":\"$FIREWALL_IP\",\"wan_ip\":\"$FIREWALL_IP\",\"token\":\"$ENROLLMENT_TOKEN\"}")

# Check response
if echo "$response" | grep -q success; then
    echo "✅ Enrollment successful!"
    echo ""
    echo "=== Enrollment Complete ==="
    echo "Your firewall has been registered with the management panel."
    echo "It will check in automatically within the next 2 minutes."
else
    echo "❌ Enrollment failed!"
    echo "Response: $response"
    exit 1
fi
';
    
    // Return the script
    header('Content-Type: application/x-shellscript');
    header('Content-Disposition: attachment; filename="opnsense_enroll.sh"');
    echo $script;
    exit;
}

// Handle enrollment callback (POST from firewall)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }
    
    $hostname = $input['hostname'] ?? null;
    $hardware_id = $input['hardware_id'] ?? null;
    $ip_address = $input['ip_address'] ?? null;
    $wan_ip = $input['wan_ip'] ?? null;
    $token = $input['token'] ?? null;
    
    if (!$hostname || !$hardware_id || !$ip_address || !$token) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    try {
        // Verify token
        $stmt = $DB->prepare("SELECT id FROM enrollment_tokens WHERE token = ? AND expires_at > NOW() AND used = FALSE");
        $stmt->execute([$token]);
        $token_record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$token_record) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired enrollment token']);
            exit;
        }
        
        // Check if firewall exists
        $stmt = $DB->prepare("SELECT id FROM firewalls WHERE hardware_id = ? OR ip_address = ?");
        $stmt->execute([$hardware_id, $ip_address]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing
            $stmt = $DB->prepare("UPDATE firewalls SET hostname = ?, wan_ip = ?, status = 'online', last_checkin = NOW() WHERE id = ?");
            $stmt->execute([$hostname, $wan_ip, $existing['id']]);
            $firewall_id = $existing['id'];
        } else {
            // Create new
            $stmt = $DB->prepare("INSERT INTO firewalls (hostname, hardware_id, ip_address, wan_ip, status, customer_name, last_checkin) VALUES (?, ?, ?, ?, 'online', 'AGIT8OR', NOW())");
            $stmt->execute([$hostname, $hardware_id, $ip_address, $wan_ip]);
            $firewall_id = $DB->lastInsertId();
        }
        
        // Mark token as used
        $stmt = $DB->prepare("UPDATE enrollment_tokens SET used = TRUE WHERE token = ?");
        $stmt->execute([$token]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Firewall enrolled successfully',
            'firewall_id' => $firewall_id
        ]);
        
    } catch (Exception $e) {
        error_log("enroll_firewall_new.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
    }
    exit;
}

// Show usage page if accessed directly
?>
<!DOCTYPE html>
<html>
<head>
    <title>OPNsense Enrollment</title>
</head>
<body>
    <h1>OPNsense Firewall Enrollment</h1>
    <p>This endpoint handles OPNsense firewall enrollment.</p>
    <p>Use: <code>enroll_firewall.php?action=download&token=YOUR_TOKEN</code> to download the enrollment script.</p>
</body>
</html>
