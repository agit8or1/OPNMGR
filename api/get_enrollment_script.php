<?php
/**
 * Simple Enrollment Script Generator
 * Returns a minimal shell script for firewall enrollment
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$token = $_GET['token'] ?? '';

if (!$token || strlen($token) < 32) {
    http_response_code(400);
    echo "# Error: Invalid or missing enrollment token\n";
    exit;
}

// Verify token exists and is not expired
$stmt = db()->prepare("SELECT id FROM enrollment_tokens WHERE token = ? AND expires_at > NOW() AND used = FALSE");
$stmt->execute([$token]);
if (!$stmt->fetch()) {
    http_response_code(400);
    echo "# Error: Token expired or invalid\n";
    exit;
}

$panel_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

// Output simple enrollment script
echo <<<'SCRIPT'
#!/bin/sh
# OPNsense Enrollment Script - Simple Version

set -e

echo "=== OPNsense Firewall Enrollment ==="

# Configuration
PANEL_URL="PANEL_URL_PLACEHOLDER"
TOKEN="TOKEN_PLACEHOLDER"

# Get firewall info
HOSTNAME=$(hostname 2>/dev/null || echo "opnsense")
WAN_IP=$(curl -s -4 ifconfig.me 2>/dev/null || echo "unknown")

# Get hardware ID from primary interface MAC
HARDWARE_ID=$(ifconfig 2>/dev/null | grep ether | head -1 | awk '{print $2}' 2>/dev/null || echo "unknown")

echo "Firewall: $HOSTNAME"
echo "IP: $WAN_IP"
echo "Hardware ID: $HARDWARE_ID"

# Build JSON payload (simple approach without jq)
JSON="{\"hostname\":\"$HOSTNAME\",\"hardware_id\":\"$HARDWARE_ID\",\"ip_address\":\"$WAN_IP\",\"wan_ip\":\"$WAN_IP\",\"token\":\"$TOKEN\"}"

# Send enrollment request
echo "Sending enrollment request..."
RESPONSE=$(curl -s -X POST "$PANEL_URL/enroll_firewall.php" \
    -H "Content-Type: application/json" \
    -d "$JSON" 2>/dev/null)

# Check if successful
if echo "$RESPONSE" | grep -q '"success".*true' 2>/dev/null; then
    echo "✓ Enrollment successful!"
    echo ""
    echo "Your firewall has been enrolled with the management panel."
    exit 0
else
    echo "✗ Enrollment may have failed. Response:"
    echo "$RESPONSE"
    exit 1
fi
SCRIPT;

// Replace placeholders
$output = str_replace('PANEL_URL_PLACEHOLDER', $panel_url, ob_get_clean() ?: '');
$output = str_replace('TOKEN_PLACEHOLDER', $token, $output);

// Output without buffering
echo str_replace('SCRIPT;', '', $output);
?>
