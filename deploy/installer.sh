#!/bin/bash
#
# OPNManager Deployment Installer
# This script installs OPNManager on a fresh Ubuntu/Debian server
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# Get the primary server URL from environment or parameter
PRIMARY_SERVER="${OPNMANAGER_PRIMARY_SERVER:-opn.agit8or.net}"
DEPLOYMENT_PACKAGE_URL="https://${PRIMARY_SERVER}/api/deployment_download.php"
INSTALL_DIR="/var/www/opnsense"
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS:-$(openssl rand -base64 16)}"
OPNMANAGER_DB_PASS="${OPNMANAGER_DB_PASS:-$(openssl rand -base64 16)}"
OPNMANAGER_ADMIN_PASS="${OPNMANAGER_ADMIN_PASS:-$(openssl rand -base64 16)}"

log_info "OPNManager Deployment Installer"
log_info "Primary Server: $PRIMARY_SERVER"

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
   log_error "This script must be run as root"
fi

# Update system
log_info "Updating system packages..."
apt-get update -qq 2>/dev/null || true
apt-get upgrade -y -qq 2>/dev/null || true

# Install dependencies
log_info "Installing dependencies..."
apt-get install -y -qq php php-fpm php-mysql php-curl php-gd php-xml php-json nginx mysql-server curl wget git unzip openssl openssh-client cron sudo 2>/dev/null

# Create directory structure
log_info "Creating application directory..."
mkdir -p "$INSTALL_DIR"
mkdir -p /var/log/opnsense
mkdir -p /var/www/opnsense/backups
mkdir -p /var/www/opnsense/logs

# Download deployment package
log_info "Downloading deployment package..."
cd /tmp
wget -q "$DEPLOYMENT_PACKAGE_URL" -O opnmanager-package.tar.gz || curl -s "$DEPLOYMENT_PACKAGE_URL" -o opnmanager-package.tar.gz || log_error "Failed to download package"

# Extract package
log_info "Extracting package..."
tar -xzf opnmanager-package.tar.gz -C "$INSTALL_DIR" 2>/dev/null || log_error "Failed to extract"

# Set up MySQL
log_info "Setting up database..."
MYSQL_PASS=$(openssl rand -base64 16)
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_PASS';" 2>/dev/null || true
mysql -u root -p"$MYSQL_PASS" -e "CREATE DATABASE IF NOT EXISTS opnsense_fw;" 2>/dev/null

# Set file permissions
log_info "Setting permissions..."
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"

# Restart services
log_info "Starting services..."
systemctl restart nginx php*-fpm mysql 2>/dev/null || true

# Save credentials
cat > /tmp/opnmanager-install.txt << EOF
OPNManager Installation Complete
Username: admin
Password: $OPNMANAGER_ADMIN_PASS
Database Password: $MYSQL_PASS
EOF

log_info "Installation complete! Access at http://$(hostname -I | awk '{print $1}')/"
