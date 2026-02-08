#!/bin/bash

# OPNmanager GUI Screenshot Generator
# This script captures real screenshots of the OPNmanager interface

SCREENSHOT_DIR="/var/www/opnmanager-website/assets/images"
BASE_URL="http://localhost"

echo "Starting OPNmanager GUI screenshot capture..."

# Create virtual display
export DISPLAY=:99
Xvfb :99 -screen 0 1920x1080x24 &
XVFB_PID=$!
sleep 2

# Function to take a screenshot
take_screenshot() {
    local url="$1"
    local output_file="$2"
    local description="$3"
    
    echo "Capturing: $description"
    echo "URL: $url"
    echo "Output: $output_file"
    
    # Use chromium to take screenshot
    /snap/bin/chromium --headless --no-sandbox --disable-gpu --disable-dev-shm-usage \
        --window-size=1920,1080 --screenshot="$output_file" "$url" 2>/dev/null
    
    if [ -f "$output_file" ]; then
        echo "✓ Screenshot saved: $output_file"
    else
        echo "✗ Failed to capture screenshot"
    fi
    
    sleep 2
}

# Take screenshots of different interface components
echo "Taking OPNmanager interface screenshots..."

# 1. Login page
take_screenshot "$BASE_URL/login.php" "$SCREENSHOT_DIR/login-interface.png" "Login Interface"

# 2. Dashboard (we'll need to create a session, but let's try direct access first)
take_screenshot "$BASE_URL/dashboard.php" "$SCREENSHOT_DIR/dashboard-real.png" "Main Dashboard"

# 3. Firewalls page
take_screenshot "$BASE_URL/firewalls.php" "$SCREENSHOT_DIR/firewalls-management.png" "Firewall Management"

# 4. Update management
take_screenshot "$BASE_URL/updates.php" "$SCREENSHOT_DIR/update-management-real.png" "Update Management"

# 5. Settings page
take_screenshot "$BASE_URL/settings.php" "$SCREENSHOT_DIR/settings-interface.png" "Settings Interface"

# Kill virtual display
kill $XVFB_PID

echo "Screenshot capture complete!"
echo "Screenshots saved to: $SCREENSHOT_DIR"
ls -la "$SCREENSHOT_DIR"/*.png | grep -E "(login|dashboard|firewalls|update|settings)"