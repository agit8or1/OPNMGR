#!/bin/bash

# OPNmanager GUI Screenshot Capture with Proper Authentication
# Created: September 16, 2025
# Purpose: Capture real authenticated screenshots of OPNmanager interface

set -e

# Configuration
USERNAME="screenshot"
PASSWORD="Screenshot2025!"
BASE_URL="http://localhost"
OUTPUT_DIR="/var/www/opnmanager-website/assets/images"
COOKIE_JAR="/tmp/opnmanager_cookies.txt"

# Ensure output directory exists
mkdir -p "$OUTPUT_DIR"

echo "=== OPNmanager Screenshot Capture ==="
echo "Using credentials: $USERNAME / $PASSWORD"
echo "Output directory: $OUTPUT_DIR"
echo ""

# Clean up old cookies
rm -f "$COOKIE_JAR"

# Function to authenticate and get session cookie
authenticate() {
    echo "Authenticating with OPNmanager..."
    
    # First, get the login page to establish session
    curl -s -c "$COOKIE_JAR" "$BASE_URL/login.php" > /dev/null
    
    # Submit login form
    local login_response=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
        -d "username=$USERNAME" \
        -d "password=$PASSWORD" \
        -L "$BASE_URL/login.php")
    
    # Check if login was successful by looking for redirect or dashboard content
    if echo "$login_response" | grep -q "dashboard\|firewall\|OPNmanager Dashboard" ; then
        echo "✓ Authentication successful"
        return 0
    else
        echo "✗ Authentication failed"
        echo "Response preview:"
        echo "$login_response" | head -5
        return 1
    fi
}

# Function to capture authenticated page
capture_page() {
    local page_name="$1"
    local page_url="$2"
    local output_file="$3"
    
    echo "Capturing: $page_name"
    echo "URL: $BASE_URL/$page_url"
    echo "Output: $output_file"
    
    # Use wkhtmltoimage with cookies for authenticated capture
    wkhtmltoimage \
        --width 1920 \
        --height 1080 \
        --quality 95 \
        --javascript-delay 3000 \
        --cookie-jar "$COOKIE_JAR" \
        --load-error-handling ignore \
        --load-media-error-handling ignore \
        "$BASE_URL/$page_url" \
        "$output_file" 2>/dev/null
    
    if [[ -f "$output_file" && $(stat -f%z "$output_file" 2>/dev/null || stat -c%s "$output_file" 2>/dev/null) -gt 10000 ]]; then
        echo "✓ Screenshot saved: $output_file"
        chmod 644 "$output_file"
        chown www-data:www-data "$output_file" 2>/dev/null || true
    else
        echo "✗ Screenshot failed or too small"
    fi
    echo ""
}

# Main execution
main() {
    # Authenticate first
    if ! authenticate; then
        echo "Authentication failed, cannot capture authenticated pages"
        exit 1
    fi
    
    echo ""
    echo "Capturing authenticated screenshots..."
    echo ""
    
    # Capture main dashboard
    capture_page "Main Dashboard" "dashboard.php" "$OUTPUT_DIR/dashboard-real.png"
    
    # Capture firewall management
    capture_page "Firewall Management" "firewalls.php" "$OUTPUT_DIR/firewall-management-real.png"
    
    # Capture update management  
    capture_page "Update Management" "updates.php" "$OUTPUT_DIR/update-management-real.png"
    
    # Capture settings/configuration
    capture_page "Settings Interface" "settings.php" "$OUTPUT_DIR/settings-interface-real.png"
    
    # Also capture users page for completeness
    capture_page "User Management" "users.php" "$OUTPUT_DIR/user-management-real.png"
    
    echo "Screenshot capture complete!"
    echo ""
    echo "Files created:"
    ls -la "$OUTPUT_DIR"/*-real.png 2>/dev/null || echo "No real screenshots found"
    
    # Clean up
    rm -f "$COOKIE_JAR"
}

# Run main function
main "$@"