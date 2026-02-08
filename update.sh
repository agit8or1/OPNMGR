#!/bin/bash

###############################################################################
# OPNsense Manager - Update System
# Version: 3.0.0
# Description: Automated update mechanism with backup and rollback
###############################################################################

set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
INSTALL_DIR="/var/www/opnsense"
BACKUP_DIR="${INSTALL_DIR}/backups/updates"
UPDATE_SOURCE="https://github.com/YOUR_USERNAME/opnsense-manager"
CURRENT_VERSION_FILE="${INSTALL_DIR}/VERSION"
LOG_FILE="/var/log/opnsense-update.log"

###############################################################################
# Helper Functions
###############################################################################

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

print_header() {
    echo -e "${BLUE}"
    echo "╔══════════════════════════════════════════════════════╗"
    echo "║       OPNsense Manager - Update System v3.0         ║"
    echo "╚══════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

print_success() {
    echo -e "${GREEN}[✓]${NC} $1"
    log "[SUCCESS] $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
    log "[ERROR] $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
    log "[WARNING] $1"
}

print_info() {
    echo -e "${BLUE}[i]${NC} $1"
    log "[INFO] $1"
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "This script must be run as root"
        exit 1
    fi
}

get_current_version() {
    if [ -f "$CURRENT_VERSION_FILE" ]; then
        cat "$CURRENT_VERSION_FILE"
    else
        echo "unknown"
    fi
}

###############################################################################
# Update Check
###############################################################################

check_for_updates() {
    print_info "Checking for updates..."

    CURRENT_VERSION=$(get_current_version)
    print_info "Current version: $CURRENT_VERSION"

    # Fetch latest version from GitHub
    LATEST_VERSION=$(curl -s https://api.github.com/repos/YOUR_USERNAME/opnsense-manager/releases/latest | grep '"tag_name":' | sed -E 's/.*"([^"]+)".*/\1/')

    if [ -z "$LATEST_VERSION" ]; then
        print_error "Could not fetch latest version"
        return 1
    fi

    print_info "Latest version: $LATEST_VERSION"

    if [ "$CURRENT_VERSION" == "$LATEST_VERSION" ]; then
        print_success "Already up to date!"
        return 2
    else
        print_warning "Update available: $CURRENT_VERSION → $LATEST_VERSION"
        return 0
    fi
}

###############################################################################
# Backup System
###############################################################################

create_backup() {
    print_info "Creating backup before update..."

    BACKUP_NAME="backup_$(date +%Y%m%d_%H%M%S)"
    BACKUP_PATH="${BACKUP_DIR}/${BACKUP_NAME}"

    mkdir -p "$BACKUP_PATH"

    # Backup files
    print_info "Backing up application files..."
    rsync -a --exclude='backups' --exclude='logs' --exclude='node_modules' \
        "${INSTALL_DIR}/" "${BACKUP_PATH}/files/"

    # Backup database
    print_info "Backing up database..."
    source "${INSTALL_DIR}/.env"
    mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "${BACKUP_PATH}/database.sql"

    # Save current version
    echo "$(get_current_version)" > "${BACKUP_PATH}/VERSION"

    # Create backup manifest
    cat > "${BACKUP_PATH}/MANIFEST" << EOF
Backup Created: $(date)
Version: $(get_current_version)
Hostname: $(hostname)
User: $(whoami)
EOF

    # Compress backup
    print_info "Compressing backup..."
    tar -czf "${BACKUP_PATH}.tar.gz" -C "$BACKUP_DIR" "$BACKUP_NAME"
    rm -rf "$BACKUP_PATH"

    LAST_BACKUP="${BACKUP_PATH}.tar.gz"
    print_success "Backup created: $LAST_BACKUP"
}

###############################################################################
# Update Process
###############################################################################

download_update() {
    print_info "Downloading update..."

    TEMP_DIR="/tmp/opnsense-update-$$"
    mkdir -p "$TEMP_DIR"

    # Download from GitHub
    DOWNLOAD_URL="https://github.com/YOUR_USERNAME/opnsense-manager/archive/refs/tags/${LATEST_VERSION}.tar.gz"

    curl -L "$DOWNLOAD_URL" -o "${TEMP_DIR}/update.tar.gz"

    # Extract
    tar -xzf "${TEMP_DIR}/update.tar.gz" -C "$TEMP_DIR"

    UPDATE_SOURCE_DIR=$(find "$TEMP_DIR" -maxdepth 1 -type d -name "opnsense-manager-*" | head -1)

    print_success "Update downloaded and extracted"
}

apply_update() {
    print_info "Applying update..."

    # Stop services
    print_info "Stopping services..."
    systemctl stop nginx || true
    systemctl stop php8.1-fpm || systemctl stop php-fpm || true

    # Preserve .env file
    cp "${INSTALL_DIR}/.env" "/tmp/opnsense.env.backup"

    # Copy new files
    print_info "Copying new files..."
    rsync -a --exclude='.env' --exclude='backups' --exclude='logs' \
        "${UPDATE_SOURCE_DIR}/" "${INSTALL_DIR}/"

    # Restore .env
    cp "/tmp/opnsense.env.backup" "${INSTALL_DIR}/.env"
    rm "/tmp/opnsense.env.backup"

    # Update VERSION file
    echo "$LATEST_VERSION" > "$CURRENT_VERSION_FILE"

    print_success "Files updated"
}

run_migrations() {
    print_info "Running database migrations..."

    MIGRATION_DIR="${INSTALL_DIR}/database/migrations"

    if [ -d "$MIGRATION_DIR" ]; then
        for migration in $(ls -1 "$MIGRATION_DIR"/*.sql 2>/dev/null | sort); do
            print_info "Running migration: $(basename $migration)"
            source "${INSTALL_DIR}/.env"
            mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migration" || {
                print_warning "Migration failed (may have already been applied)"
            }
        done
        print_success "Migrations completed"
    else
        print_info "No migrations to run"
    fi
}

update_dependencies() {
    print_info "Updating dependencies..."

    # Update Node.js dependencies if package.json exists
    if [ -f "${INSTALL_DIR}/scripts/package.json" ]; then
        cd "${INSTALL_DIR}/scripts"
        npm install --production
        print_success "Node.js dependencies updated"
    fi

    # Update PHP dependencies if composer.json exists
    if [ -f "${INSTALL_DIR}/composer.json" ]; then
        cd "$INSTALL_DIR"
        composer install --no-dev --optimize-autoloader
        print_success "PHP dependencies updated"
    fi
}

fix_permissions() {
    print_info "Fixing permissions..."

    chown -R www-data:www-data "$INSTALL_DIR"
    chmod 600 "${INSTALL_DIR}/.env"
    chmod -R 755 "${INSTALL_DIR}"
    find "${INSTALL_DIR}" -type f -exec chmod 644 {} \;
    find "${INSTALL_DIR}" -type f -name "*.sh" -exec chmod 755 {} \;

    print_success "Permissions fixed"
}

restart_services() {
    print_info "Restarting services..."

    systemctl start php8.1-fpm || systemctl start php-fpm
    systemctl start nginx

    sleep 2

    # Verify services are running
    if systemctl is-active --quiet nginx && systemctl is-active --quiet php8.1-fpm || systemctl is-active --quiet php-fpm; then
        print_success "Services restarted successfully"
    else
        print_error "Failed to restart services"
        return 1
    fi
}

clear_cache() {
    print_info "Clearing application cache..."

    # Clear PHP opcache
    if [ -f "/var/run/php/php8.1-fpm.sock" ]; then
        systemctl reload php8.1-fpm
    fi

    # Clear application cache
    rm -rf "${INSTALL_DIR}/cache/"* 2>/dev/null || true

    print_success "Cache cleared"
}

###############################################################################
# Rollback System
###############################################################################

rollback_update() {
    print_error "Update failed! Rolling back to previous version..."

    if [ -z "$LAST_BACKUP" ] || [ ! -f "$LAST_BACKUP" ]; then
        print_error "No backup found for rollback"
        return 1
    fi

    # Stop services
    systemctl stop nginx || true
    systemctl stop php8.1-fpm || systemctl stop php-fpm || true

    # Extract backup
    RESTORE_DIR="/tmp/opnsense-restore-$$"
    mkdir -p "$RESTORE_DIR"
    tar -xzf "$LAST_BACKUP" -C "$RESTORE_DIR"

    # Find the backup directory
    BACKUP_EXTRACT=$(find "$RESTORE_DIR" -maxdepth 1 -type d | tail -1)

    # Restore files
    rsync -a --exclude='.env' "${BACKUP_EXTRACT}/files/" "${INSTALL_DIR}/"

    # Restore database
    source "${INSTALL_DIR}/.env"
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "${BACKUP_EXTRACT}/database.sql"

    # Restore version file
    cp "${BACKUP_EXTRACT}/VERSION" "$CURRENT_VERSION_FILE"

    # Cleanup
    rm -rf "$RESTORE_DIR"

    # Restart services
    systemctl start php8.1-fpm || systemctl start php-fpm
    systemctl start nginx

    print_success "Rollback completed"
}

###############################################################################
# Health Check
###############################################################################

verify_update() {
    print_info "Verifying update..."

    # Check web server is responding
    if curl -k -s -o /dev/null -w "%{http_code}" https://localhost | grep -q "200\|301\|302"; then
        print_success "Web server is responding"
    else
        print_error "Web server is not responding"
        return 1
    fi

    # Check database connection
    source "${INSTALL_DIR}/.env"
    if mysql -u "$DB_USER" -p"$DB_PASS" -e "USE $DB_NAME;" 2>/dev/null; then
        print_success "Database connection OK"
    else
        print_error "Database connection failed"
        return 1
    fi

    # Check file integrity
    if [ -f "${INSTALL_DIR}/index.php" ] && [ -f "${INSTALL_DIR}/.env" ]; then
        print_success "Core files present"
    else
        print_error "Core files missing"
        return 1
    fi

    print_success "Update verification passed"
    return 0
}

###############################################################################
# Main Update Process
###############################################################################

perform_update() {
    print_header

    # Pre-update checks
    if ! check_for_updates; then
        return 0
    fi

    echo ""
    read -p "Do you want to proceed with the update? (y/n): " CONFIRM
    if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
        print_info "Update cancelled"
        return 0
    fi

    # Create backup
    create_backup || {
        print_error "Backup failed. Aborting update."
        exit 1
    }

    # Download update
    download_update || {
        print_error "Download failed. Aborting update."
        exit 1
    }

    # Apply update
    apply_update || {
        rollback_update
        exit 1
    }

    # Run migrations
    run_migrations || {
        rollback_update
        exit 1
    }

    # Update dependencies
    update_dependencies || {
        print_warning "Dependency update had issues, but continuing..."
    }

    # Fix permissions
    fix_permissions

    # Restart services
    restart_services || {
        rollback_update
        exit 1
    }

    # Clear cache
    clear_cache

    # Verify update
    if ! verify_update; then
        print_error "Update verification failed"
        read -p "Do you want to rollback? (y/n): " ROLLBACK
        if [[ "$ROLLBACK" =~ ^[Yy]$ ]]; then
            rollback_update
        fi
        exit 1
    fi

    # Success
    echo ""
    print_success "Update completed successfully!"
    print_info "Updated from $(cat "${LAST_BACKUP%.tar.gz}/VERSION" 2>/dev/null || echo "unknown") to $LATEST_VERSION"
    echo ""
}

###############################################################################
# Command Line Interface
###############################################################################

show_usage() {
    echo "Usage: $0 [OPTION]"
    echo ""
    echo "Options:"
    echo "  --check       Check for updates without installing"
    echo "  --update      Perform update (default)"
    echo "  --rollback    Rollback to previous version"
    echo "  --version     Show current version"
    echo "  --help        Show this help message"
    echo ""
}

###############################################################################
# Main
###############################################################################

main() {
    check_root

    case "${1:-}" in
        --check)
            print_header
            check_for_updates
            ;;
        --update|"")
            perform_update
            ;;
        --rollback)
            print_header
            if [ -z "$LAST_BACKUP" ]; then
                # Find most recent backup
                LAST_BACKUP=$(ls -t "${BACKUP_DIR}"/*.tar.gz 2>/dev/null | head -1)
            fi
            rollback_update
            ;;
        --version)
            echo "Current version: $(get_current_version)"
            ;;
        --help)
            show_usage
            ;;
        *)
            print_error "Unknown option: $1"
            show_usage
            exit 1
            ;;
    esac
}

main "$@"
