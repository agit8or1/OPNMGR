#!/bin/bash

###############################################################################
# Command Injection Fix Script
# Automatically fixes escapeshellarg issues in PHP files
###############################################################################

set -e

INSTALL_DIR="/home/administrator/opnsense"
BACKUP_DIR="${INSTALL_DIR}/backups/security_fixes_$(date +%Y%m%d_%H%M%S)"

echo "=== Command Injection Fix Script ==="
echo "Creating backup in: $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"

# List of files with command injection vulnerabilities
FILES_TO_FIX=(
    "api/start_ssh_tunnel.php"
    "api/setup_reverse_proxy.php"
    "api/remove_reverse_proxy.php"
    "tunnel_daemon.php"
)

for FILE in "${FILES_TO_FIX[@]}"; do
    FULL_PATH="${INSTALL_DIR}/${FILE}"

    if [ -f "$FULL_PATH" ]; then
        echo "Processing: $FILE"

        # Backup original
        cp "$FULL_PATH" "${BACKUP_DIR}/$(basename $FILE).bak"

        # Note: Manual fixes required - this script creates documentation
        echo "  - Backed up to: ${BACKUP_DIR}/$(basename $FILE).bak"
        echo "  - Manual review required"
    else
        echo "  - File not found: $FULL_PATH"
    fi
done

echo ""
echo "=== Fix Documentation ==="
cat > "${BACKUP_DIR}/FIX_INSTRUCTIONS.md" << 'EOF'
# Command Injection Fixes Required

## Files to Fix:

### 1. api/start_ssh_tunnel.php
**Lines 44, 73**: Add escapeshellarg() around variables

**Before:**
```php
$tunnel_check = shell_exec("ps aux | grep 'ssh.*{$firewall['wan_ip']}' | grep -v grep");
```

**After:**
```php
$safe_ip = escapeshellarg($firewall['wan_ip']);
$tunnel_check = shell_exec("ps aux | grep " . escapeshellarg("ssh.*{$firewall['wan_ip']}") . " | grep -v grep");
```

### 2. api/setup_reverse_proxy.php
**Lines 114, 123, 135**: Escape file paths

**Before:**
```php
shell_exec("sudo bash -c 'mv {$temp_file} {$config_file}' 2>&1");
```

**After:**
```php
$safe_temp = escapeshellarg($temp_file);
$safe_config = escapeshellarg($config_file);
shell_exec("sudo bash -c 'mv {$safe_temp} {$safe_config}' 2>&1");
```

### 3. api/remove_reverse_proxy.php
**Line 50**: Escape file paths

**After:**
```php
$safe_link = escapeshellarg($link_file);
$safe_config = escapeshellarg($config_file);
shell_exec("sudo bash -c 'rm -f {$safe_link} {$safe_config}' 2>&1");
```

### 4. tunnel_daemon.php
**Line 122, 130-132**: Validate and escape tunnel port and IP

**Add validation:**
```php
// Validate tunnel port is numeric
if (!is_numeric($tunnel_port) || $tunnel_port < 1024 || $tunnel_port > 65535) {
    error_log("Invalid tunnel port: {$tunnel_port}");
    continue;
}

// Validate IP address
if (!filter_var($wan_ip, FILTER_VALIDATE_IP)) {
    error_log("Invalid WAN IP: {$wan_ip}");
    continue;
}

// Escape for shell
$safe_port = escapeshellarg($tunnel_port);
$safe_ip = escapeshellarg($wan_ip);
```

## General Rules:

1. **Always use escapeshellarg()** for variables in shell commands
2. **Validate input** before using in commands:
   - IP addresses: `filter_var($ip, FILTER_VALIDATE_IP)`
   - Ports: `is_numeric()` and range check
   - File paths: `realpath()` and check for directory traversal
3. **Prefer PHP functions** over shell commands when possible
4. **Never use user input** directly in shell commands

## Testing:

After fixes, test with:
- Special characters in inputs: `; rm -rf /`
- Command injection attempts: `$(cat /etc/passwd)`
- Path traversal: `../../etc/passwd`

EOF

echo "Fix instructions created: ${BACKUP_DIR}/FIX_INSTRUCTIONS.md"
echo ""
echo "Backup location: $BACKUP_DIR"
echo "Please review and apply fixes manually, then test thoroughly."
