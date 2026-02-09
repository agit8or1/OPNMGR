#!/bin/bash
# Capture screenshots of OPNsense Manager
# Usage: ./capture_screenshots.sh [base_url] [username] [password]

BASE_URL="${1:-https://opn.agit8or.net}"
USERNAME="${2:-admin}"
PASSWORD="${3}"
SCREENSHOT_DIR="/home/administrator/opnsense/screenshots"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Create screenshots directory
mkdir -p "$SCREENSHOT_DIR"

echo "====================================="
echo "OPNsense Manager Screenshot Capture"
echo "====================================="
echo "Base URL: $BASE_URL"
echo "Screenshot Dir: $SCREENSHOT_DIR"
echo "Timestamp: $TIMESTAMP"
echo ""

# Check if we have chromium/chrome for screenshots
if command -v chromium-browser &> /dev/null; then
    CHROME_BIN="chromium-browser"
elif command -v google-chrome &> /dev/null; then
    CHROME_BIN="google-chrome"
elif command -v chromium &> /dev/null; then
    CHROME_BIN="chromium"
else
    echo "ERROR: Chromium/Chrome not found. Installing chromium-browser..."
    sudo apt-get update && sudo apt-get install -y chromium-browser
    CHROME_BIN="chromium-browser"
fi

echo "Using browser: $CHROME_BIN"
echo ""

# Function to take screenshot using chromium
take_screenshot() {
    local page_name=$1
    local page_path=$2
    local output_file="${SCREENSHOT_DIR}/${page_name}.png"

    echo "Capturing: $page_name ($page_path)"

    $CHROME_BIN \
        --headless \
        --disable-gpu \
        --no-sandbox \
        --disable-dev-shm-usage \
        --window-size=1920,2400 \
        --screenshot="$output_file" \
        "${BASE_URL}${page_path}" \
        2>/dev/null

    if [ -f "$output_file" ]; then
        echo "  ✓ Saved: $output_file"
        return 0
    else
        echo "  ✗ Failed to capture $page_name"
        return 1
    fi
}

# List of pages to screenshot (public/login pages)
echo "Capturing public pages..."
echo ""

take_screenshot "01-login" "/login.php"
sleep 2

# Capture main application pages (will show login redirect or page structure)
echo ""
echo "Capturing main application pages..."
echo ""

take_screenshot "02-dashboard" "/dashboard.php"
sleep 2

take_screenshot "03-firewall-list" "/firewall_list.php"
sleep 2

take_screenshot "04-settings" "/settings.php"
sleep 2

take_screenshot "05-security-scanner" "/security_scan.php"
sleep 2

take_screenshot "06-system-update" "/system_update.php"
sleep 2

take_screenshot "07-support" "/support.php"
sleep 2

take_screenshot "08-about" "/about.php"
sleep 2

echo ""
echo "====================================="
echo "Screenshot capture complete!"
echo "====================================="
echo ""
echo "Screenshots saved to: $SCREENSHOT_DIR"
echo ""
echo "To capture authenticated pages, you'll need to:"
echo "1. Login to the application manually"
echo "2. Use browser dev tools to take screenshots"
echo "3. Or provide session credentials to this script"
echo ""
echo "Files created:"
ls -lh "$SCREENSHOT_DIR"/*.png 2>/dev/null | awk '{print "  " $9 " (" $5 ")"}'
