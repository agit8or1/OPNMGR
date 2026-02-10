#!/bin/bash
# Deployment Setup Script
# Run this after extracting the deployment package

echo "=========================================="
echo "OPNManager Deployment Setup"
echo "=========================================="
echo ""

# Check if running as root or with sudo
if [ "$EUID" -ne 0 ]; then 
    echo "Please run with sudo or as root"
    exit 1
fi

# Get instance key from user
echo "Enter your instance license key:"
read -r INSTANCE_KEY

if [ -z "$INSTANCE_KEY" ]; then
    echo "Error: Instance key cannot be empty"
    exit 1
fi

echo ""
echo "Configuring license check-in..."

# Update license key in client file
sed -i "s/YOUR_INSTANCE_KEY_HERE/$INSTANCE_KEY/g" /var/www/opnsense/license_checkin_client.php

echo "✓ License key configured"

# Set up cron job for 4-hour check-ins
CRON_JOB="0 */4 * * * /usr/bin/php /var/www/opnsense/license_checkin_client.php >> /var/log/opnsense_license.log 2>&1"

# Check if cron job already exists
if ! crontab -l 2>/dev/null | grep -q "license_checkin_client.php"; then
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
    echo "✓ Cron job installed (checks every 4 hours)"
else
    echo "✓ Cron job already exists"
fi

# Create log file
touch /var/log/opnsense_license.log
chmod 644 /var/log/opnsense_license.log
echo "✓ Log file created"

# Set proper permissions
chown -R www-data:www-data /var/www/opnsense
chmod -R 755 /var/www/opnsense
echo "✓ Permissions set"

# Test license check-in
echo ""
echo "Testing license check-in..."
php /var/www/opnsense/license_checkin_client.php

echo ""
echo "=========================================="
echo "Setup Complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Configure your database connection in inc/db.php"
echo "2. Set up your web server (nginx/apache)"
echo "3. Configure SSL with ACME/Let's Encrypt"
echo "4. Access the web interface and complete initial setup"
echo ""
echo "License check-ins will occur every 4 hours automatically."
echo "Log file: /var/log/opnsense_license.log"
echo ""
