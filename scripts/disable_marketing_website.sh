#!/bin/bash

# OPNmanager Marketing Website Disable Script
# This script disables the marketing website service for agent installations

set -e

echo "======================================"
echo "OPNmanager - Disable Marketing Website"
echo "======================================"

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    print_error "This script must be run as root"
    exit 1
fi

# Function to detect OS
detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        VER=$VERSION_ID
    else
        print_error "Cannot detect OS"
        exit 1
    fi
}

# Function to disable marketing website on Ubuntu/Debian
disable_website_debian() {
    print_status "Disabling marketing website on $OS..."
    
    # Stop nginx if running
    if systemctl is-active --quiet nginx; then
        print_status "Stopping nginx service..."
        systemctl stop nginx
    fi
    
    # Disable marketing website configuration
    if [ -f /etc/nginx/sites-enabled/opnmanager-website ]; then
        print_status "Disabling marketing website nginx configuration..."
        rm -f /etc/nginx/sites-enabled/opnmanager-website
        print_success "Marketing website configuration disabled"
    fi
    
    # Remove marketing website files
    if [ -d /var/www/opnmanager-website ]; then
        print_status "Removing marketing website files..."
        rm -rf /var/www/opnmanager-website
        print_success "Marketing website files removed"
    fi
    
    # Test nginx configuration
    if nginx -t >/dev/null 2>&1; then
        print_status "Starting nginx service..."
        systemctl start nginx
        print_success "Nginx restarted successfully"
    else
        print_error "Nginx configuration test failed"
        systemctl start nginx  # Try to start anyway
    fi
}

# Function to disable marketing website on FreeBSD/OPNsense
disable_website_freebsd() {
    print_status "Disabling marketing website on FreeBSD/OPNsense..."
    
    # Stop nginx if running
    if service nginx status >/dev/null 2>&1; then
        print_status "Stopping nginx service..."
        service nginx stop
    fi
    
    # Disable marketing website configuration
    if [ -f /usr/local/etc/nginx/conf.d/opnmanager-website.conf ]; then
        print_status "Disabling marketing website nginx configuration..."
        rm -f /usr/local/etc/nginx/conf.d/opnmanager-website.conf
        print_success "Marketing website configuration disabled"
    fi
    
    # Remove marketing website files
    if [ -d /usr/local/www/opnmanager-website ]; then
        print_status "Removing marketing website files..."
        rm -rf /usr/local/www/opnmanager-website
        print_success "Marketing website files removed"
    fi
    
    # Test nginx configuration
    if nginx -t >/dev/null 2>&1; then
        print_status "Starting nginx service..."
        service nginx start
        print_success "Nginx restarted successfully"
    else
        print_error "Nginx configuration test failed"
        service nginx start  # Try to start anyway
    fi
}

# Function to disable marketing website systemctl method
disable_website_systemctl() {
    print_status "Disabling marketing website service..."
    
    # Stop the marketing website service if it exists
    if systemctl list-units --type=service | grep -q opnmanager-website; then
        systemctl stop opnmanager-website 2>/dev/null || true
        systemctl disable opnmanager-website 2>/dev/null || true
        print_success "Marketing website service disabled"
    fi
    
    # Remove service file if it exists
    if [ -f /etc/systemd/system/opnmanager-website.service ]; then
        rm -f /etc/systemd/system/opnmanager-website.service
        systemctl daemon-reload
        print_success "Marketing website service file removed"
    fi
}

# Main execution
main() {
    print_status "Starting marketing website disable process..."
    
    # Detect operating system
    detect_os
    
    print_status "Detected OS: $OS"
    
    # Disable based on OS
    case $OS in
        ubuntu|debian)
            disable_website_debian
            ;;
        freebsd)
            disable_website_freebsd
            ;;
        *)
            print_warning "Unknown OS: $OS, attempting generic disable..."
            disable_website_systemctl
            disable_website_debian  # Try Debian method as fallback
            ;;
    esac
    
    print_success "Marketing website disable complete!"
    print_status "The marketing website (port 88) has been disabled on this system."
    print_status "Only the main OPNmanager application will be accessible."
}

# Run main function
main "$@"