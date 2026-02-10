#!/bin/bash

# Real OPNmanager GUI Screenshot Capture
# This script captures actual screenshots of the OPNmanager interface

# Configuration
USERNAME="screenshot"
PASSWORD="Screenshot2025!"
BASE_URL="http://localhost"
OUTPUT_DIR="/var/www/opnmanager-website/assets/images"
TEMP_DIR="/tmp/screenshots"

echo "Creating real OPNmanager GUI screenshots..."

# Create directories
mkdir -p "$SCREENSHOT_DIR" "$TEMP_DIR"

# Install dependencies if needed
if ! command -v wkhtmltoimage &> /dev/null; then
    echo "Installing wkhtmltopdf for screenshots..."
    apt-get update && apt-get install -y wkhtmltopdf
fi

# Function to create a session and get cookies
create_session() {
    echo "Creating authenticated session..."
    
    # First, get the login page to extract any CSRF tokens
    curl -c "$TEMP_DIR/cookies.txt" -s "$BASE_URL/login.php" > "$TEMP_DIR/login.html"
    
    # Login with credentials (using default admin credentials)
    curl -b "$TEMP_DIR/cookies.txt" -c "$TEMP_DIR/cookies.txt" \
         -d "username=admin&password=admin&submit=Login" \
         -X POST -s "$BASE_URL/login.php" > "$TEMP_DIR/login_result.html"
    
    # Check if login was successful
    if grep -q "dashboard\|firewalls\|logout" "$TEMP_DIR/login_result.html"; then
        echo "✓ Login successful"
        return 0
    else
        echo "✗ Login failed"
        return 1
    fi
}

# Function to take screenshot with authentication
take_authenticated_screenshot() {
    local page="$1"
    local output_file="$2"
    local description="$3"
    local width=${4:-1200}
    local height=${5:-800}
    
    echo "Capturing: $description"
    echo "Page: $page"
    echo "Output: $output_file"
    
    # Use wkhtmltoimage with cookies
    wkhtmltoimage \
        --width $width \
        --height $height \
        --quality 90 \
        --enable-local-file-access \
        --load-error-handling ignore \
        --cookie-jar "$TEMP_DIR/cookies.txt" \
        "$BASE_URL/$page" \
        "$output_file" 2>/dev/null
    
    if [ -f "$output_file" ] && [ -s "$output_file" ]; then
        echo "✓ Screenshot saved: $output_file"
        return 0
    else
        echo "✗ Failed to capture screenshot"
        return 1
    fi
}

# Alternative approach using puppeteer/chromium with authentication
take_screenshot_puppeteer() {
    local page="$1"
    local output_file="$2"
    local description="$3"
    
    echo "Capturing with Puppeteer: $description"
    
    # Create a Node.js script for authenticated screenshots
    cat > "$TEMP_DIR/screenshot.js" << 'EOF'
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
    });
    
    const page = await browser.newPage();
    await page.setViewport({ width: 1200, height: 800 });
    
    try {
        // Navigate to login page
        await page.goto(process.argv[2] + '/login.php', { waitUntil: 'networkidle2' });
        
        // Fill login form
        await page.type('input[name="username"]', 'admin');
        await page.type('input[name="password"]', 'admin');
        await page.click('input[type="submit"], button[type="submit"]');
        
        // Wait for navigation after login
        await page.waitForNavigation({ waitUntil: 'networkidle2' });
        
        // Navigate to target page
        await page.goto(process.argv[2] + '/' + process.argv[3], { waitUntil: 'networkidle2' });
        
        // Take screenshot
        await page.screenshot({ path: process.argv[4], fullPage: false });
        
        console.log('Screenshot saved: ' + process.argv[4]);
    } catch (error) {
        console.error('Error:', error);
    }
    
    await browser.close();
})();
EOF

    # Check if Node.js and puppeteer are available
    if command -v node &> /dev/null && npm list puppeteer &> /dev/null; then
        node "$TEMP_DIR/screenshot.js" "$BASE_URL" "$page" "$output_file"
    else
        echo "Puppeteer not available, trying alternative method..."
        return 1
    fi
}

# Try direct curl approach to capture HTML and convert
capture_html_screenshot() {
    local page="$1"
    local output_file="$2"
    local description="$3"
    
    echo "Capturing HTML screenshot: $description"
    
    # Get the page HTML with authentication
    curl -b "$TEMP_DIR/cookies.txt" -s "$BASE_URL/$page" > "$TEMP_DIR/page.html"
    
    # Check if we got actual page content
    if grep -q "<!DOCTYPE\|<html\|<body" "$TEMP_DIR/page.html"; then
        # Use wkhtmltoimage on the local HTML file
        wkhtmltoimage \
            --width 1200 \
            --height 800 \
            --quality 90 \
            "$TEMP_DIR/page.html" \
            "$output_file" 2>/dev/null
        
        if [ -f "$output_file" ] && [ -s "$output_file" ]; then
            echo "✓ HTML screenshot saved: $output_file"
            return 0
        fi
    fi
    
    return 1
}

# Main execution
main() {
    echo "Starting real GUI screenshot capture..."
    
    # Try to create authenticated session
    if create_session; then
        echo "Session created successfully"
    else
        echo "Failed to create session, trying direct capture..."
    fi
    
    # Array of pages to capture
    declare -a pages=(
        "dashboard.php|dashboard-real.png|Main Dashboard|1200|800"
        "firewalls.php|firewall-management-real.png|Firewall Management|1200|800"
        "updates.php|update-management-real.png|Update Management|1200|800"
        "settings.php|settings-interface.png|Settings Interface|1200|800"
        "login.php|login-interface.png|Login Interface|1200|600"
    )
    
    # Capture screenshots
    for page_info in "${pages[@]}"; do
        IFS='|' read -r page filename description width height <<< "$page_info"
        
        # Try multiple methods
        if take_authenticated_screenshot "$page" "$SCREENSHOT_DIR/$filename" "$description" "$width" "$height"; then
            continue
        elif capture_html_screenshot "$page" "$SCREENSHOT_DIR/$filename" "$description"; then
            continue
        elif take_screenshot_puppeteer "$page" "$SCREENSHOT_DIR/$filename" "$description"; then
            continue
        else
            echo "✗ All methods failed for $description"
        fi
        
        sleep 1
    done
    
    # Cleanup
    rm -rf "$TEMP_DIR"
    
    echo "Screenshot capture complete!"
    echo "Files created:"
    ls -la "$SCREENSHOT_DIR"/*.png 2>/dev/null | grep -E "(dashboard|firewall|update|settings|login)-.*\.png" || echo "No screenshots created"
}

# Run main function
main