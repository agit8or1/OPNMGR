# os-opnmanager-agent

OPNManager Agent plugin for OPNsense - enables centralized firewall management.

## Description

This plugin connects OPNsense firewalls to an OPNManager server for centralized
management. It provides:

- **Status Monitoring**: Automatic check-ins reporting firewall health, version,
  and network information
- **Remote Updates**: Trigger OPNsense updates from the central management console
- **Command Execution**: Run administrative commands remotely
- **SSH Key Management**: Automatic deployment of SSH authorized keys for secure
  remote access
- **Update Detection**: Reports available OPNsense updates to the management server

## Installation

### From OPNsense Plugins (when available)

1. Go to **System > Firmware > Plugins**
2. Search for "opnmanager"
3. Click **Install**

### Manual Installation

```sh
fetch -o - https://your-opnmanager-server/plugins/install.sh | sh
```

## Configuration

1. Navigate to **Services > OPNManager Agent**
2. Configure the following settings:
   - **Enable Agent**: Enable/disable the agent service
   - **Server URL**: Your OPNManager server URL (e.g., `https://opnmanager.example.com`)
   - **Firewall ID**: The ID assigned to this firewall in OPNManager
   - **Check-in Interval**: How often to report status (60-3600 seconds)
   - **Allow SSH Key Management**: Let OPNManager deploy SSH keys
   - **Verify SSL Certificate**: Validate server certificate (recommended)
3. Click **Save**
4. Click **Start** to start the agent

## CLI Commands

```sh
# Check service status
configctl opnmanager_agent status

# Start the agent
configctl opnmanager_agent start

# Stop the agent
configctl opnmanager_agent stop

# Restart the agent
configctl opnmanager_agent restart

# Force immediate check-in
configctl opnmanager_agent checkin
```

## Log File

Agent logs are written to `/var/log/opnmanager_agent.log`

## Requirements

- OPNsense 24.1 or later
- Network connectivity to OPNManager server
- Valid Firewall ID from OPNManager

## Security Considerations

- All communication uses HTTPS
- SSL certificate verification is enabled by default
- SSH key management can be disabled if not needed
- The agent runs with minimal required privileges

## Troubleshooting

### Agent not starting

1. Check the log file: `cat /var/log/opnmanager_agent.log`
2. Verify Server URL and Firewall ID are configured
3. Test connectivity: `curl -v https://your-server/agent_checkin.php`

### Check-in failing

1. Verify network connectivity to the server
2. Check if SSL certificate is valid (or disable verification for testing)
3. Confirm Firewall ID is correct in OPNManager

## License

BSD 2-Clause License - see LICENSE file

## Contributing

Contributions are welcome! Please submit pull requests to the OPNsense plugins
repository: https://github.com/opnsense/plugins
