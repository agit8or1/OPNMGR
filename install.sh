#!/bin/bash

###############################################################################
# OPNsense Manager - Automated Installer
# Version: 3.0.0
# Description: Complete automated installation script
###############################################################################

set -e  # Exit on error

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
INSTALL_DIR="/var/www/opnsense"
WEB_USER="www-data"
DB_NAME="opnsense_fw"
DB_USER="opnsense_user"
MIN_PHP_VERSION="8.0"
MIN_MYSQL_VERSION="5.7"

###############################################################################
# Helper Functions
###############################################################################

print_header() {
    echo -e "${BLUE}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║          OPNsense Manager - Auto Installer v3.0           ║"
    echo "║                                                            ║"
    echo "║  Centralized OPNsense Firewall Management Platform        ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

print_step() {
    echo -e "${GREEN}[STEP]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

print_info() {
    echo -e "${BLUE}[i]${NC} $1"
}

generate_random_password() {
    openssl rand -base64 24 | tr -d "=+/" | cut -c1-24
}

generate_secret_key() {
    openssl rand -hex 32
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "This script must be run as root"
        exit 1
    fi
}

detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        OS_VERSION=$VERSION_ID
    else
        print_error "Cannot detect OS. /etc/os-release not found"
        exit 1
    fi

    print_info "Detected OS: $OS $OS_VERSION"
}

###############################################################################
# Dependency Installation
###############################################################################

install_dependencies_ubuntu() {
    print_step "Installing dependencies for Ubuntu/Debian..."

    apt-get update
    apt-get install -y \
        nginx \
        php8.1-fpm \
        php8.1-cli \
        php8.1-mysql \
        php8.1-curl \
        php8.1-xml \
        php8.1-mbstring \
        php8.1-zip \
        php8.1-gd \
        mariadb-server \
        mariadb-client \
        curl \
        git \
        sudo \
        fail2ban \
        certbot \
        python3-certbot-nginx \
        openssl \
        unzip

    print_success "Dependencies installed"
}

install_dependencies_centos() {
    print_step "Installing dependencies for CentOS/RHEL..."

    yum install -y epel-release
    yum install -y \
        nginx \
        php \
        php-fpm \
        php-mysqlnd \
        php-curl \
        php-xml \
        php-mbstring \
        php-zip \
        php-gd \
        mariadb-server \
        mariadb \
        curl \
        git \
        sudo \
        fail2ban \
        certbot \
        python3-certbot-nginx \
        openssl \
        unzip

    print_success "Dependencies installed"
}

install_dependencies() {
    case $OS in
        ubuntu|debian)
            install_dependencies_ubuntu
            ;;
        centos|rhel|rocky|almalinux)
            install_dependencies_centos
            ;;
        *)
            print_error "Unsupported OS: $OS"
            exit 1
            ;;
    esac
}

###############################################################################
# Database Setup
###############################################################################

setup_database() {
    print_step "Setting up MySQL/MariaDB database..."

    # Start MySQL service
    systemctl start mariadb || systemctl start mysql
    systemctl enable mariadb || systemctl enable mysql

    # Generate secure passwords
    DB_ROOT_PASSWORD=$(generate_random_password)
    DB_PASSWORD=$(generate_random_password)

    print_info "Securing MySQL installation..."

    # Set root password
    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASSWORD}';" 2>/dev/null || \
        mysqladmin -u root password "${DB_ROOT_PASSWORD}"

    # Create database and user
    mysql -u root -p"${DB_ROOT_PASSWORD}" << EOF
DROP DATABASE IF EXISTS ${DB_NAME};
CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
DROP USER IF EXISTS '${DB_USER}'@'localhost';
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

    print_success "Database created: ${DB_NAME}"
    print_success "Database user created: ${DB_USER}"

    # Import schema
    if [ -f "${INSTALL_DIR}/database/schema.sql" ]; then
        print_info "Importing database schema..."
        mysql -u root -p"${DB_ROOT_PASSWORD}" ${DB_NAME} < "${INSTALL_DIR}/database/schema.sql"
        print_success "Database schema imported"
    fi
}

###############################################################################
# Application Setup
###############################################################################

setup_application() {
    print_step "Setting up application..."

    # Create .env file
    print_info "Creating .env configuration..."

    SESSION_SECRET=$(generate_secret_key)
    ENCRYPTION_KEY=$(generate_secret_key)

    cat > "${INSTALL_DIR}/.env" << EOF
# OPNsense Manager Configuration
# Generated on: $(date)

# Database Configuration
DB_HOST=localhost
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASSWORD}

# Database Root Password (keep secure!)
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}

# Application Settings
APP_ENV=production
APP_DEBUG=false
APP_URL=https://$(hostname -f)
APP_TIMEZONE=UTC

# Security
SESSION_SECRET=${SESSION_SECRET}
ENCRYPTION_KEY=${ENCRYPTION_KEY}
MAX_LOGIN_ATTEMPTS=5
LOGIN_LOCKOUT_TIME=900

# SMTP Configuration (configure later)
SMTP_HOST=
SMTP_PORT=587
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_ENCRYPTION=tls
SMTP_FROM_ADDRESS=noreply@$(hostname -d)
SMTP_FROM_NAME="OPNsense Manager"

# Screenshot Account (optional)
SCREENSHOT_USERNAME=screenshot
SCREENSHOT_PASSWORD=$(generate_random_password)

# Proxy Settings
PROXY_PORT_START=8100
PROXY_PORT_END=8199

# Backup Settings
BACKUP_RETENTION_MONTHS=2

# Feature Flags
ENABLE_2FA=true
ENABLE_AUDIT_LOG=true
EOF

    chmod 600 "${INSTALL_DIR}/.env"
    chown ${WEB_USER}:${WEB_USER} "${INSTALL_DIR}/.env"

    print_success ".env file created"
}

###############################################################################
# Web Server Configuration
###############################################################################

configure_nginx() {
    print_step "Configuring Nginx web server..."

    DOMAIN=$(hostname -f)

    cat > /etc/nginx/sites-available/opnsense-manager << EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    # Redirect to HTTPS
    return 301 https://\$server_name\$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${DOMAIN};

    root ${INSTALL_DIR};
    index index.php index.html;

    # SSL Configuration (will be replaced by certbot)
    ssl_certificate /etc/ssl/certs/ssl-cert-snakeoil.pem;
    ssl_certificate_key /etc/ssl/private/ssl-cert-snakeoil.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    # Security headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Client max body size
    client_max_body_size 50M;

    # PHP handling
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ \.(env|git|sql|log|md)$ {
        deny all;
    }

    # Static files
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
EOF

    # Enable site
    ln -sf /etc/nginx/sites-available/opnsense-manager /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default

    # Test configuration
    nginx -t

    # Restart Nginx
    systemctl restart nginx
    systemctl enable nginx

    print_success "Nginx configured and started"
}

###############################################################################
# Permissions Setup
###############################################################################

setup_permissions() {
    print_step "Setting up file permissions..."

    # Set ownership
    chown -R ${WEB_USER}:${WEB_USER} ${INSTALL_DIR}

    # Set directory permissions
    find ${INSTALL_DIR} -type d -exec chmod 755 {} \;

    # Set file permissions
    find ${INSTALL_DIR} -type f -exec chmod 644 {} \;

    # Set executable permissions for scripts
    find ${INSTALL_DIR} -type f -name "*.sh" -exec chmod 755 {} \;

    # Secure sensitive files
    chmod 600 ${INSTALL_DIR}/.env
    chmod 700 ${INSTALL_DIR}/backups 2>/dev/null || mkdir -p ${INSTALL_DIR}/backups && chmod 700 ${INSTALL_DIR}/backups
    chmod 700 ${INSTALL_DIR}/logs 2>/dev/null || mkdir -p ${INSTALL_DIR}/logs && chmod 700 ${INSTALL_DIR}/logs

    print_success "Permissions configured"
}

###############################################################################
# Create Admin User
###############################################################################

create_admin_user() {
    print_step "Creating administrator account..."

    echo ""
    read -p "Enter admin username [admin]: " ADMIN_USERNAME
    ADMIN_USERNAME=${ADMIN_USERNAME:-admin}

    while true; do
        read -sp "Enter admin password: " ADMIN_PASSWORD
        echo ""
        read -sp "Confirm admin password: " ADMIN_PASSWORD_CONFIRM
        echo ""

        if [ "$ADMIN_PASSWORD" == "$ADMIN_PASSWORD_CONFIRM" ]; then
            break
        else
            print_error "Passwords do not match. Try again."
        fi
    done

    # Hash password
    ADMIN_PASSWORD_HASH=$(php -r "echo password_hash('${ADMIN_PASSWORD}', PASSWORD_DEFAULT);")

    # Insert into database
    mysql -u ${DB_USER} -p"${DB_PASSWORD}" ${DB_NAME} << EOF
INSERT INTO users (username, password_hash, role, created_at)
VALUES ('${ADMIN_USERNAME}', '${ADMIN_PASSWORD_HASH}', 'admin', NOW())
ON DUPLICATE KEY UPDATE password_hash='${ADMIN_PASSWORD_HASH}';
EOF

    print_success "Admin user created: ${ADMIN_USERNAME}"
}

###############################################################################
# SSL Certificate Setup
###############################################################################

setup_ssl() {
    print_step "Setting up SSL certificate..."

    echo ""
    read -p "Do you want to obtain a Let's Encrypt SSL certificate? (y/n) [n]: " SETUP_SSL

    if [[ "$SETUP_SSL" =~ ^[Yy]$ ]]; then
        read -p "Enter email address for Let's Encrypt: " LETSENCRYPT_EMAIL
        read -p "Enter domain name [$(hostname -f)]: " SSL_DOMAIN
        SSL_DOMAIN=${SSL_DOMAIN:-$(hostname -f)}

        print_info "Obtaining SSL certificate from Let's Encrypt..."
        certbot --nginx -d ${SSL_DOMAIN} --non-interactive --agree-tos -m ${LETSENCRYPT_EMAIL}

        # Set up auto-renewal
        systemctl enable certbot.timer
        systemctl start certbot.timer

        print_success "SSL certificate obtained and configured"
    else
        print_warning "Skipping SSL setup. Using self-signed certificate."
        print_warning "You can set up SSL later using: certbot --nginx"
    fi
}

###############################################################################
# Firewall Configuration
###############################################################################

configure_firewall() {
    print_step "Configuring firewall..."

    if command -v ufw &> /dev/null; then
        ufw allow 22/tcp comment 'SSH'
        ufw allow 80/tcp comment 'HTTP'
        ufw allow 443/tcp comment 'HTTPS'
        ufw allow 8100:8199/tcp comment 'OPNsense Proxy Ports'
        ufw --force enable
        print_success "UFW firewall configured"
    elif command -v firewall-cmd &> /dev/null; then
        firewall-cmd --permanent --add-service=ssh
        firewall-cmd --permanent --add-service=http
        firewall-cmd --permanent --add-service=https
        firewall-cmd --permanent --add-port=8100-8199/tcp
        firewall-cmd --reload
        print_success "Firewalld configured"
    else
        print_warning "No firewall detected. Please configure manually."
    fi
}

###############################################################################
# Cron Jobs Setup
###############################################################################

setup_cron_jobs() {
    print_step "Setting up scheduled tasks..."

    # Create cron script
    cat > /etc/cron.d/opnsense-manager << 'EOF'
# OPNsense Manager Scheduled Tasks

# Nightly backup (2 AM)
0 2 * * * www-data php /var/www/opnsense/scripts/nightly_backup.php >> /var/log/opnsense-backup.log 2>&1

# Cleanup old logs (3 AM)
0 3 * * * www-data php /var/www/opnsense/scripts/cleanup_logs.php >> /var/log/opnsense-cleanup.log 2>&1

# Health check (every 5 minutes)
*/5 * * * * www-data php /var/www/opnsense/scripts/health_check.php >> /var/log/opnsense-health.log 2>&1

# Update check (daily at 4 AM)
0 4 * * * root /var/www/opnsense/update.sh --check >> /var/log/opnsense-update.log 2>&1
EOF

    chmod 644 /etc/cron.d/opnsense-manager

    print_success "Scheduled tasks configured"
}

###############################################################################
# Installation Summary
###############################################################################

print_summary() {
    echo ""
    echo -e "${GREEN}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║              Installation Completed Successfully!         ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    echo ""
    print_info "Installation Summary:"
    echo "  • Installation Directory: ${INSTALL_DIR}"
    echo "  • Database Name: ${DB_NAME}"
    echo "  • Database User: ${DB_USER}"
    echo "  • Web Server: Nginx"
    echo "  • PHP Version: $(php -v | head -n1 | cut -d' ' -f2)"
    echo "  • Admin Username: ${ADMIN_USERNAME}"
    echo ""
    print_info "Important Information:"
    echo "  • Configuration file: ${INSTALL_DIR}/.env"
    echo "  • Database root password saved in .env"
    echo "  • Access URL: https://$(hostname -f)"
    echo ""
    print_warning "Next Steps:"
    echo "  1. Configure SMTP settings in .env for email alerts"
    echo "  2. Review firewall rules: ufw status"
    echo "  3. Set up Fail2Ban: systemctl status fail2ban"
    echo "  4. Configure backups location"
    echo "  5. Test the application: https://$(hostname -f)"
    echo ""
    print_info "For documentation, visit: ${INSTALL_DIR}/documentation.php"
    echo ""
    print_warning "SECURITY: Save the database passwords from ${INSTALL_DIR}/.env"
    echo ""
}

###############################################################################
# Main Installation Flow
###############################################################################

main() {
    print_header

    # Pre-flight checks
    check_root
    detect_os

    echo ""
    read -p "Continue with installation? (y/n): " CONFIRM
    if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
        print_info "Installation cancelled"
        exit 0
    fi

    # Installation steps
    install_dependencies
    setup_database
    setup_application
    configure_nginx
    setup_permissions
    create_admin_user
    setup_ssl
    configure_firewall
    setup_cron_jobs

    # Summary
    print_summary

    print_success "Installation complete! You can now access OPNsense Manager"
}

# Run installation
main "$@"
