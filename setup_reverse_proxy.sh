#!/bin/bash

# OPNsense Firewall Reverse Proxy Setup Script
# This script sets up a reverse proxy tunnel for remote management

set -e

# Configuration
MANAGEMENT_SERVER="opn.agit8or.net"
TUNNEL_PORT="2222"
LOCAL_OPNSENSE_PORT="443"
FIREWALL_ID="$1"

if [ -z "$FIREWALL_ID" ]; then
    echo "Usage: $0 <firewall_id>"
    echo "Example: $0 123"
    exit 1
fi

# Check if we're running on OPNsense
if [ ! -f "/usr/local/etc/opnsense-rc" ]; then
    echo "ERROR: This script must be run on an OPNsense firewall"
    exit 1
fi

echo "=== Setting up Reverse Proxy Tunnel ==="
echo "Management Server: $MANAGEMENT_SERVER"
echo "Firewall ID: $FIREWALL_ID"
echo "Local OPNsense Port: $LOCAL_OPNSENSE_PORT"
echo "Tunnel Port: $TUNNEL_PORT"
echo ""

# Install required packages
echo "Installing required packages..."
pkg update
pkg install -y autossh

# Create tunnel user
if ! id "tunnel" &>/dev/null; then
    echo "Creating tunnel user..."
    pw useradd tunnel -m -s /bin/bash
fi

# Create SSH key for tunnel
if [ ! -f "/home/tunnel/.ssh/id_rsa" ]; then
    echo "Generating SSH key for tunnel..."
    mkdir -p /home/tunnel/.ssh
    chown tunnel:tunnel /home/tunnel/.ssh
    chmod 700 /home/tunnel/.ssh
    su - tunnel -c "ssh-keygen -t rsa -b 4096 -f /home/tunnel/.ssh/id_rsa -N ''"
fi

# Create systemd service
cat > /usr/local/etc/rc.d/opnsense_tunnel << 'EOF'
#!/bin/sh

# PROVIDE: opnsense_tunnel
# REQUIRE: NETWORKING
# KEYWORD: shutdown

. /etc/rc.subr

name="opnsense_tunnel"
rcvar="opnsense_tunnel_enable"

load_rc_config $name

: ${opnsense_tunnel_enable:="NO"}
: ${opnsense_tunnel_server:="opn.agit8or.net"}
: ${opnsense_tunnel_port:="2222"}
: ${opnsense_tunnel_local_port:="443"}
: ${opnsense_tunnel_firewall_id:=""}

command="/usr/local/bin/autossh"
command_args="-M 0 -o 'ServerAliveInterval 30' -o 'ServerAliveCountMax 3' -o 'StrictHostKeyChecking=no' -o 'UserKnownHostsFile=/dev/null' -o 'ExitOnForwardFailure=yes' -R ${opnsense_tunnel_port}:localhost:${opnsense_tunnel_local_port} -N tunnel@${opnsense_tunnel_server}"

run_rc_command "$1"
EOF

chmod +x /usr/local/etc/rc.d/opnsense_tunnel

# Configure the service
cat >> /etc/rc.conf << EOF
opnsense_tunnel_enable="YES"
opnsense_tunnel_server="$MANAGEMENT_SERVER"
opnsense_tunnel_port="$TUNNEL_PORT"
opnsense_tunnel_local_port="$LOCAL_OPNSENSE_PORT"
opnsense_tunnel_firewall_id="$FIREWALL_ID"
EOF

echo ""
echo "=== Reverse Proxy Setup Complete ==="
echo "The tunnel service has been configured."
echo ""
echo "To start the tunnel manually:"
echo "  service opnsense_tunnel start"
echo ""
echo "To enable at boot:"
echo "  service opnsense_tunnel enable"
echo ""
echo "To check status:"
echo "  service opnsense_tunnel status"
echo ""
echo "You can now connect to this firewall through:"
echo "  https://$MANAGEMENT_SERVER:$TUNNEL_PORT"
echo ""
echo "Note: You need to copy the SSH public key to the management server:"
echo "  cat /home/tunnel/.ssh/id_rsa.pub"
echo "  (Add this to ~/.ssh/authorized_keys on the management server for the 'tunnel' user)"
