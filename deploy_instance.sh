#!/bin/bash

# OPNsense Management Platform - Customer Instance Deployment Script
# Version: 2.0.0
# Usage: ./deploy_instance.sh <customer_name> <instance_id> [options]

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
MAIN_SERVER="opn.agit8or.net"
BASE_PATH="/var/www/opnsense"
BACKUP_DIR="/var/backups/opnsense"
LOG_FILE="/var/log/opnsense-deploy.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
    log "INFO: $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
    log "WARNING: $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
    log "ERROR: $1"
}

print_header() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE} OPNsense Customer Instance Deployment${NC}"
    echo -e "${BLUE}========================================${NC}"
}

# Help function
show_help() {
    cat << EOF
OPNsense Management Platform - Customer Instance Deployment Script

Usage: $0 <customer_name> <instance_id> [options]

Arguments:
    customer_name    Name of the customer (e.g., "Acme Corp")
    instance_id      Unique instance identifier (e.g., "acme-001")

Options:
    -s, --server     Main server FQDN (default: opn.agit8or.net)
    -p, --path       Installation path (default: /var/www/opnsense)
    -d, --database   Database name (default: opnsense_<instance_id>)
    -u, --db-user    Database username (default: opnsense_user)
    -w, --db-pass    Database password (auto-generated if not provided)
    -b, --backup     Create backup before deployment
    -f, --force      Force deployment without confirmation
    -h, --help       Show this help message

Examples:
    # Basic deployment
    $0 "Acme Corporation" "acme-001"
    
    # Deployment with custom server
    $0 "Tech Solutions" "tech-002" --server "updates.mycompany.com"
    
    # Force deployment with backup
    $0 "Global Inc" "global-003" --backup --force

Configuration:
    The script will create a customer-specific instance with:
    - Dedicated database
    - Customer configuration
    - Version management system
    - Update capability from main server
    - Firewall management interface

EOF
}

# Parse command line arguments
parse_arguments() {
    CUSTOMER_NAME=""
    INSTANCE_ID=""
    CUSTOM_SERVER=""
    CUSTOM_PATH=""
    DB_NAME=""
    DB_USER="opnsense_user"
    DB_PASS=""
    CREATE_BACKUP=false
    FORCE_DEPLOY=false
    
    while [[ $# -gt 0 ]]; do
        case $1 in
            -s|--server)
                CUSTOM_SERVER="$2"
                shift 2
                ;;
            -p|--path)
                CUSTOM_PATH="$2"
                shift 2
                ;;
            -d|--database)
                DB_NAME="$2"
                shift 2
                ;;
            -u|--db-user)
                DB_USER="$2"
                shift 2
                ;;
            -w|--db-pass)
                DB_PASS="$2"
                shift 2
                ;;
            -b|--backup)
                CREATE_BACKUP=true
                shift
                ;;
            -f|--force)
                FORCE_DEPLOY=true
                shift
                ;;
            -h|--help)
                show_help
                exit 0
                ;;
            -*)
                print_error "Unknown option: $1"
                show_help
                exit 1
                ;;
            *)
                if [[ -z "$CUSTOMER_NAME" ]]; then
                    CUSTOMER_NAME="$1"
                elif [[ -z "$INSTANCE_ID" ]]; then
                    INSTANCE_ID="$1"
                else
                    print_error "Too many arguments"
                    show_help
                    exit 1
                fi
                shift
                ;;
        esac
    done
    
    # Validate required arguments
    if [[ -z "$CUSTOMER_NAME" ]] || [[ -z "$INSTANCE_ID" ]]; then
        print_error "Customer name and instance ID are required"
        show_help
        exit 1
    fi
    
    # Set defaults
    [[ -n "$CUSTOM_SERVER" ]] && MAIN_SERVER="$CUSTOM_SERVER"
    [[ -n "$CUSTOM_PATH" ]] && BASE_PATH="$CUSTOM_PATH"
    [[ -z "$DB_NAME" ]] && DB_NAME="opnsense_${INSTANCE_ID//-/_}"
    [[ -z "$DB_PASS" ]] && DB_PASS=$(openssl rand -base64 32)
}

# Check prerequisites
check_prerequisites() {
    print_status "Checking prerequisites..."
    
    # Check if running as root
    if [[ $EUID -ne 0 ]]; then
        print_error "This script must be run as root"
        exit 1
    fi
    
    # Check required commands
    local commands=("mysql" "php" "wget" "unzip" "git")
    for cmd in "${commands[@]}"; do
        if ! command -v "$cmd" &> /dev/null; then
            print_error "Required command not found: $cmd"
            exit 1
        fi
    done
    
    # Check if Apache/Nginx is running
    if ! systemctl is-active --quiet apache2 && ! systemctl is-active --quiet nginx; then
        print_warning "Neither Apache nor Nginx appears to be running"
    fi
    
    # Check MySQL/MariaDB
    if ! systemctl is-active --quiet mysql && ! systemctl is-active --quiet mariadb; then
        print_error "MySQL/MariaDB is not running"
        exit 1
    fi
    
    print_status "Prerequisites check completed"
}

# Create backup
create_backup() {
    if [[ "$CREATE_BACKUP" == true ]]; then
        print_status "Creating backup..."
        
        local backup_name="opnsense_backup_$(date +%Y%m%d_%H%M%S)"
        local backup_path="$BACKUP_DIR/$backup_name"
        
        mkdir -p "$backup_path"
        
        # Backup files
        if [[ -d "$BASE_PATH" ]]; then
            tar -czf "$backup_path/files.tar.gz" -C "$(dirname "$BASE_PATH")" "$(basename "$BASE_PATH")"
        fi
        
        # Backup database
        if mysql -e "USE opnsense" 2>/dev/null; then
            mysqldump opnsense > "$backup_path/database.sql"
        fi
        
        print_status "Backup created at: $backup_path"
    fi
}

# Setup database
setup_database() {
    print_status "Setting up database: $DB_NAME"
    
    # Create database
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    
    # Create or update user
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    
    # Import database schema
    if [[ -f "$SCRIPT_DIR/database/schema.sql" ]]; then
        mysql "$DB_NAME" < "$SCRIPT_DIR/database/schema.sql"
        print_status "Database schema imported"
    else
        print_warning "Database schema file not found, using existing database"
    fi
}

# Download and install files
install_files() {
    print_status "Installing application files..."
    
    # Create base directory
    mkdir -p "$BASE_PATH"
    
    # Copy files from current directory if this is a local deployment
    if [[ -f "$SCRIPT_DIR/firewalls.php" ]]; then
        print_status "Copying files from local installation..."
        cp -r "$SCRIPT_DIR"/* "$BASE_PATH/"
    else
        # Download from main server
        print_status "Downloading latest files from $MAIN_SERVER..."
        local temp_dir=$(mktemp -d)
        
        # Download latest package
        if wget -q "https://$MAIN_SERVER/releases/latest.zip" -O "$temp_dir/latest.zip"; then
            unzip -q "$temp_dir/latest.zip" -d "$temp_dir"
            cp -r "$temp_dir/opnsense"/* "$BASE_PATH/"
            rm -rf "$temp_dir"
        else
            print_error "Failed to download files from main server"
            exit 1
        fi
    fi
    
    # Set proper permissions
    chown -R www-data:www-data "$BASE_PATH"
    chmod -R 755 "$BASE_PATH"
    chmod -R 644 "$BASE_PATH"/*.php
    chmod 600 "$BASE_PATH/config"/*.json
}

# Configure instance
configure_instance() {
    print_status "Configuring instance for customer: $CUSTOMER_NAME"
    
    # Create database configuration
    cat > "$BASE_PATH/inc/config.php" << EOF
<?php
// Database configuration for instance: $INSTANCE_ID
\$db_host = 'localhost';
\$db_name = '$DB_NAME';
\$db_user = '$DB_USER';
\$db_pass = '$DB_PASS';

// Instance configuration
\$instance_id = '$INSTANCE_ID';
\$customer_name = '$CUSTOMER_NAME';
\$main_server = '$MAIN_SERVER';

// PDO Connection
try {
    \$pdo = new PDO("mysql:host=\$db_host;dbname=\$db_name;charset=utf8mb4", \$db_user, \$db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException \$e) {
    die('Database connection failed: ' . \$e->getMessage());
}
?>
EOF
    
    # Update instance configuration
    cat > "$BASE_PATH/config/instance.json" << EOF
{
    "customer_name": "$CUSTOMER_NAME",
    "instance_id": "$INSTANCE_ID",
    "main_server": "$MAIN_SERVER",
    "deployment_date": "$(date -I)",
    "version": "2.0.0",
    "admin_contact": "admin@customer.com",
    "features": {
        "version_management": true,
        "firewall_management": true,
        "auto_updates": false,
        "backup_enabled": true
    },
    "settings": {
        "update_check_interval": 3600,
        "backup_retention_days": 30,
        "log_level": "info"
    }
}
EOF
    
    # Set proper permissions
    chmod 600 "$BASE_PATH/inc/config.php"
    chmod 600 "$BASE_PATH/config/instance.json"
}

# Create initial admin user
create_admin_user() {
    print_status "Creating initial admin user..."
    
    local admin_pass=$(openssl rand -base64 16)
    local admin_hash=$(php -r "echo password_hash('$admin_pass', PASSWORD_DEFAULT);")
    
    # Insert admin user
    mysql "$DB_NAME" -e "
        INSERT INTO users (username, password_hash, email, role, created_at) 
        VALUES ('admin', '$admin_hash', 'admin@$INSTANCE_ID.local', 'admin', NOW())
        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);
    "
    
    # Save credentials
    cat > "$BASE_PATH/admin_credentials.txt" << EOF
OPNsense Management Platform - Initial Admin Credentials

Customer: $CUSTOMER_NAME
Instance: $INSTANCE_ID
Deployment Date: $(date)

Admin Username: admin
Admin Password: $admin_pass

Database Details:
- Name: $DB_NAME
- User: $DB_USER
- Pass: $DB_PASS

IMPORTANT: Please change the admin password after first login!
This file should be deleted after the credentials are securely stored.
EOF
    
    chmod 600 "$BASE_PATH/admin_credentials.txt"
    
    print_status "Admin credentials saved to: $BASE_PATH/admin_credentials.txt"
}

# Setup web server configuration
setup_webserver() {
    print_status "Setting up web server configuration..."
    
    # Apache configuration
    if systemctl is-active --quiet apache2; then
        cat > "/etc/apache2/sites-available/${INSTANCE_ID}.conf" << EOF
<VirtualHost *:80>
    ServerName ${INSTANCE_ID}.local
    DocumentRoot $BASE_PATH
    
    <Directory $BASE_PATH>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/${INSTANCE_ID}_error.log
    CustomLog \${APACHE_LOG_DIR}/${INSTANCE_ID}_access.log combined
</VirtualHost>
EOF
        
        a2ensite "${INSTANCE_ID}.conf"
        systemctl reload apache2
        print_status "Apache virtual host configured"
    fi
    
    # Nginx configuration (if Apache is not active)
    if systemctl is-active --quiet nginx && ! systemctl is-active --quiet apache2; then
        cat > "/etc/nginx/sites-available/${INSTANCE_ID}" << EOF
server {
    listen 80;
    server_name ${INSTANCE_ID}.local;
    root $BASE_PATH;
    index index.php index.html;
    
    location / {
        try_files \$uri \$uri/ =404;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
    }
    
    access_log /var/log/nginx/${INSTANCE_ID}_access.log;
    error_log /var/log/nginx/${INSTANCE_ID}_error.log;
}
EOF
        
        ln -sf "/etc/nginx/sites-available/${INSTANCE_ID}" "/etc/nginx/sites-enabled/"
        systemctl reload nginx
        print_status "Nginx server block configured"
    fi
}

# Test deployment
test_deployment() {
    print_status "Testing deployment..."
    
    # Test database connection
    if mysql "$DB_NAME" -e "SELECT 1" &>/dev/null; then
        print_status "Database connection: OK"
    else
        print_error "Database connection: FAILED"
        return 1
    fi
    
    # Test PHP files
    if php -l "$BASE_PATH/index.php" &>/dev/null; then
        print_status "PHP syntax: OK"
    else
        print_error "PHP syntax: FAILED"
        return 1
    fi
    
    # Test web access (if possible)
    if command -v curl &>/dev/null; then
        if curl -s "http://localhost$BASE_PATH" &>/dev/null; then
            print_status "Web access: OK"
        else
            print_warning "Web access: Could not test (server may not be configured)"
        fi
    fi
    
    print_status "Deployment test completed successfully"
}

# Register instance with main server
register_instance() {
    print_status "Registering instance with main server..."
    
    local registration_data=$(cat << EOF
{
    "instance_id": "$INSTANCE_ID",
    "customer_name": "$CUSTOMER_NAME",
    "version": "2.0.0",
    "deployment_date": "$(date -I)",
    "server_info": {
        "hostname": "$(hostname)",
        "ip_address": "$(hostname -I | awk '{print $1}')",
        "os": "$(lsb_release -d -s 2>/dev/null || echo 'Unknown')"
    }
}
EOF
)
    
    if curl -s -X POST \
        -H "Content-Type: application/json" \
        -d "$registration_data" \
        "https://$MAIN_SERVER/api/instances/register.php" &>/dev/null; then
        print_status "Instance registered successfully"
    else
        print_warning "Could not register with main server (may not be available)"
    fi
}

# Main deployment function
main() {
    print_header
    log "Starting deployment for customer: $CUSTOMER_NAME, instance: $INSTANCE_ID"
    
    # Parse arguments
    parse_arguments "$@"
    
    # Show configuration
    print_status "Deployment Configuration:"
    echo "  Customer Name: $CUSTOMER_NAME"
    echo "  Instance ID: $INSTANCE_ID"
    echo "  Main Server: $MAIN_SERVER"
    echo "  Installation Path: $BASE_PATH"
    echo "  Database Name: $DB_NAME"
    echo "  Database User: $DB_USER"
    echo "  Create Backup: $CREATE_BACKUP"
    echo ""
    
    # Confirm deployment
    if [[ "$FORCE_DEPLOY" != true ]]; then
        read -p "Continue with deployment? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            print_status "Deployment cancelled by user"
            exit 0
        fi
    fi
    
    # Run deployment steps
    check_prerequisites
    create_backup
    setup_database
    install_files
    configure_instance
    create_admin_user
    setup_webserver
    test_deployment
    register_instance
    
    # Show completion message
    print_header
    print_status "Deployment completed successfully!"
    echo ""
    echo -e "${GREEN}Instance Details:${NC}"
    echo "  Customer: $CUSTOMER_NAME"
    echo "  Instance ID: $INSTANCE_ID"
    echo "  Installation Path: $BASE_PATH"
    echo "  Database: $DB_NAME"
    echo ""
    echo -e "${YELLOW}Next Steps:${NC}"
    echo "1. Review admin credentials: $BASE_PATH/admin_credentials.txt"
    echo "2. Configure DNS/hosts file for: ${INSTANCE_ID}.local"
    echo "3. Access the platform: http://${INSTANCE_ID}.local"
    echo "4. Change the default admin password"
    echo "5. Delete the credentials file after setup"
    echo ""
    echo -e "${BLUE}Support:${NC}"
    echo "- Documentation: https://$MAIN_SERVER/docs/"
    echo "- Updates: Available through the admin panel"
    echo "- Log file: $LOG_FILE"
    
    log "Deployment completed successfully for $CUSTOMER_NAME ($INSTANCE_ID)"
}

# Run main function with all arguments
main "$@"