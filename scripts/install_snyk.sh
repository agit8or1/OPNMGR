#!/bin/bash
# Auto-install Node.js, npm, and Snyk
# This script must be run with sudo privileges

set -e

LOG_FILE="/tmp/snyk_install.log"

echo "Starting Node.js and Snyk installation..." > "$LOG_FILE"
echo "Timestamp: $(date)" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"

# Check if already installed
if command -v node &> /dev/null && command -v npm &> /dev/null && command -v snyk &> /dev/null; then
    echo "Node.js, npm, and Snyk are already installed." | tee -a "$LOG_FILE"
    node --version | tee -a "$LOG_FILE"
    npm --version | tee -a "$LOG_FILE"
    snyk --version | tee -a "$LOG_FILE"
    exit 0
fi

# Install Node.js and npm if not present
if ! command -v node &> /dev/null || ! command -v npm &> /dev/null; then
    echo "Installing Node.js and npm..." | tee -a "$LOG_FILE"

    # Add NodeSource repository
    curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - >> "$LOG_FILE" 2>&1

    # Install nodejs (includes npm)
    apt-get install -y nodejs >> "$LOG_FILE" 2>&1

    if [ $? -eq 0 ]; then
        echo "Node.js and npm installed successfully!" | tee -a "$LOG_FILE"
        node --version | tee -a "$LOG_FILE"
        npm --version | tee -a "$LOG_FILE"
    else
        echo "Failed to install Node.js/npm" | tee -a "$LOG_FILE"
        exit 1
    fi
fi

# Install Snyk globally
if ! command -v snyk &> /dev/null; then
    echo "Installing Snyk..." | tee -a "$LOG_FILE"
    npm install -g snyk >> "$LOG_FILE" 2>&1

    if [ $? -eq 0 ]; then
        echo "Snyk installed successfully!" | tee -a "$LOG_FILE"
        snyk --version | tee -a "$LOG_FILE"
    else
        echo "Failed to install Snyk" | tee -a "$LOG_FILE"
        exit 1
    fi
fi

echo "" >> "$LOG_FILE"
echo "Installation complete!" | tee -a "$LOG_FILE"
echo "Node.js version: $(node --version)" | tee -a "$LOG_FILE"
echo "npm version: $(npm --version)" | tee -a "$LOG_FILE"
echo "Snyk version: $(snyk --version)" | tee -a "$LOG_FILE"

exit 0
