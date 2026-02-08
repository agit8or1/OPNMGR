#!/bin/sh

# Start/Install OPNsense Updater Service
echo "Starting OPNsense Updater Service..."

# Create updater script if it doesn't exist
cat > /usr/local/bin/opnsense_updater.sh << 'EOF'
#!/bin/sh

# OPNsense Updater Daemon v2.0
MANAGEMENT_SERVER="opn.agit8or.net"
FIREWALL_ID="21"
UPDATER_VERSION="2.0"
CHECKIN_INTERVAL=120  # 2 minutes

# Main updater loop
while true; do
    echo "$(date): Updater checking in..."
    
    # Check in with management server
    RESPONSE=$(curl -s -X POST "https://$MANAGEMENT_SERVER/updater_checkin.php" \
        -H "Content-Type: application/json" \
        -d "{\"firewall_id\":$FIREWALL_ID,\"updater_version\":\"$UPDATER_VERSION\"}" \
        --max-time 30 2>/dev/null)
    
    if [ $? -eq 0 ]; then
        echo "$(date): Checkin successful"
        
        # Check for pending commands
        if echo "$RESPONSE" | grep -q "pending_commands"; then
            echo "$(date): Commands received, processing..."
            # In a full implementation, would parse and execute commands here
        fi
    else
        echo "$(date): Checkin failed"
    fi
    
    # Wait before next checkin
    sleep $CHECKIN_INTERVAL
done
EOF

chmod +x /usr/local/bin/opnsense_updater.sh

# Create service file
cat > /etc/rc.d/opnsense_updater << 'EOF'
#!/bin/sh
#
# PROVIDE: opnsense_updater
# REQUIRE: NETWORKING
# KEYWORD: shutdown
#

. /etc/rc.subr

name="opnsense_updater"
rcvar="opnsense_updater_enable"
command="/usr/local/bin/opnsense_updater.sh"
command_interpreter="/bin/sh"
pidfile="/var/run/opnsense_updater.pid"
start_cmd="opnsense_updater_start"
stop_cmd="opnsense_updater_stop"

opnsense_updater_start() {
    echo "Starting OPNsense Updater..."
    daemon -p $pidfile $command
}

opnsense_updater_stop() {
    echo "Stopping OPNsense Updater..."
    if [ -f $pidfile ]; then
        kill $(cat $pidfile)
        rm -f $pidfile
    fi
}

load_rc_config $name
run_rc_command "$1"
EOF

chmod +x /etc/rc.d/opnsense_updater

# Enable and start the service
sysrc opnsense_updater_enable="YES"
service opnsense_updater start

echo "OPNsense Updater service installed and started"
echo "Service will check in every 2 minutes at https://opn.agit8or.net/updater_checkin.php"