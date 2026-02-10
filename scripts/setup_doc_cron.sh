#!/bin/bash

# OPNsense Management Platform - Documentation Auto-Update Cron Job Setup
# This script sets up automatic documentation updates

SCRIPT_DIR="/var/www/opnsense"
CRON_USER="www-data"
CRON_FILE="/etc/cron.d/opnsense-docs"

echo "Setting up OPNsense Management Platform documentation auto-update..."

# Create the cron job file
cat > "$CRON_FILE" << EOF
# OPNsense Management Platform - Documentation Auto-Update
# Updates documentation when changes are made to bugs/todos
# Runs every hour during business hours, and once at night

# Every hour from 8 AM to 6 PM on weekdays
0 8-18 * * 1-5 www-data /usr/bin/php ${SCRIPT_DIR}/inc/doc_auto_update.php > /dev/null 2>&1

# Once daily at 2 AM for comprehensive update
0 2 * * * www-data /usr/bin/php ${SCRIPT_DIR}/inc/doc_auto_update.php > /dev/null 2>&1

# Generate changelog every Sunday at 3 AM
0 3 * * 0 www-data /usr/bin/curl -s -X POST http://localhost/api/generate_changelog.php > /dev/null 2>&1
EOF

# Set proper permissions
chmod 644 "$CRON_FILE"

# Restart cron service
systemctl restart cron

echo "Documentation auto-update cron jobs installed successfully!"
echo "Jobs scheduled:"
echo "  - Hourly updates during business hours (8 AM - 6 PM, Mon-Fri)"
echo "  - Daily comprehensive update (2 AM)"
echo "  - Weekly changelog generation (Sunday 3 AM)"
echo
echo "To manually trigger an update, run:"
echo "  php ${SCRIPT_DIR}/inc/doc_auto_update.php"
echo
echo "To view cron job status:"
echo "  systemctl status cron"
echo "  tail -f /var/log/cron.log"