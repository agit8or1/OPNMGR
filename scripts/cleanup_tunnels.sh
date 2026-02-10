#!/bin/bash
# Cleanup expired SSH tunnels and orphaned processes
# Runs every 2 minutes via cron

cd /var/www/opnsense/scripts || exit 1

# Run cleanup - kills expired sessions and orphaned SSH tunnels
php manage_ssh_access.php cleanup >> /var/log/opnsense/tunnel_cleanup.log 2>&1

# Also cleanup orphaned nginx configs
php manage_nginx_tunnel_proxy.php cleanup >> /var/log/opnsense/nginx_cleanup.log 2>&1

# Exit with success
exit 0
