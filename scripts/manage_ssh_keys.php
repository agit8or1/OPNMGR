<?php
/**
 * SSH Key Manager for Firewalls
 * Automatically generates, deploys, and manages SSH keys for firewall access
 */

require_once(__DIR__ . '/../inc/bootstrap_agent.php');
require_once(__DIR__ . '/../inc/logging.php');

if (!function_exists('get_firewall_by_id')) {
    function get_firewall_by_id($firewall_id) {
                $stmt = db()->prepare("SELECT * FROM firewalls WHERE id = ?");
        $stmt->execute([$firewall_id]);
        return $stmt->fetch();
    }
}

function update_firewall_ssh_key($firewall_id, $private_key_base64, $public_key) {
        $stmt = db()->prepare("UPDATE firewalls SET ssh_private_key = ?, ssh_public_key = ? WHERE id = ?");
    return $stmt->execute([$private_key_base64, $public_key, $firewall_id]);
}

if (!function_exists('queue_command')) {
function queue_command($firewall_id, $command, $description) {
        $stmt = db()->prepare("INSERT INTO firewall_commands (firewall_id, command, description, status) VALUES (?, ?, ?, 'pending')");
    $stmt->execute([$firewall_id, $command, $description]);
    return db()->lastInsertId();
}
}

if (!function_exists('wait_for_command')) {
function wait_for_command($command_id, $timeout = 120) {
        $start = time();
    
    while (time() - $start < $timeout) {
        $stmt = db()->prepare("SELECT status, result FROM firewall_commands WHERE id = ?");
        $stmt->execute([$command_id]);
        $result = $stmt->fetch();
        
        if ($result && $result['status'] === 'completed') {
            return ['success' => $result['result'] === 'success', 'result' => $result['result']];
        } elseif ($result && $result['status'] === 'failed') {
            return ['success' => false, 'result' => $result['result']];
        }
        
        sleep(5);
    }
    
    return ['success' => false, 'result' => 'timeout'];
}
}

function test_ssh_key($firewall_ip, $key_file) {
    $test_cmd = sprintf(
        "timeout 8 ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes root@%s 'echo SSH_KEY_VALID' 2>&1",
        escapeshellarg($key_file),
        escapeshellarg($firewall_ip)
    );

    exec($test_cmd, $output, $return_code);
    $output_str = implode("\n", $output);

    return $return_code === 0 && strpos($output_str, 'SSH_KEY_VALID') !== false;
}

function generate_ssh_keypair($firewall_id) {
    $key_dir = '/etc/opnmgr/keys';
    if (!is_dir($key_dir)) {
        mkdir($key_dir, 0700, true);
    }
    
    $private_key_file = "{$key_dir}/id_firewall_{$firewall_id}";
    $public_key_file = "{$private_key_file}.pub";
    
    // Remove old keys if they exist
    if (file_exists($private_key_file)) unlink($private_key_file);
    if (file_exists($public_key_file)) unlink($public_key_file);
    
    // Generate new key pair
    $cmd = sprintf(
        "ssh-keygen -t ed25519 -f %s -N '' -C 'firewall-%s-auto' 2>&1",
        escapeshellarg($private_key_file),
        $firewall_id
    );
    
    exec($cmd, $output, $return_code);
    
    if ($return_code !== 0 || !file_exists($private_key_file)) {
        return ['success' => false, 'error' => 'Failed to generate SSH key: ' . implode("\n", $output)];
    }
    
    $private_key = file_get_contents($private_key_file);
    $public_key = trim(file_get_contents($public_key_file));
    $private_key_base64 = base64_encode($private_key);
    
    return [
        'success' => true,
        'private_key' => $private_key,
        'private_key_base64' => $private_key_base64,
        'public_key' => $public_key,
        'private_key_file' => $private_key_file
    ];
}

function deploy_ssh_key_to_firewall($firewall_id, $public_key) {
    // Queue command to deploy public key to firewall
    // FIXED: Append to authorized_keys instead of overwriting (preserves existing keys)
    $command = sprintf(
        'mkdir -p /root/.ssh && chmod 700 /root/.ssh && grep -qF "%s" /root/.ssh/authorized_keys 2>/dev/null || echo "%s" >> /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys && echo SSH_KEY_DEPLOYED',
        addslashes($public_key),
        addslashes($public_key)
    );
    
    $cmd_id = queue_command($firewall_id, $command, 'Deploy new SSH public key');
    
    error_log("Queued SSH key deployment command {$cmd_id} for firewall {$firewall_id}");
    
    // Wait for deployment to complete
    $result = wait_for_command($cmd_id);
    
    return $result;
}

function ensure_ssh_key($firewall_id, $force_regenerate = false, $allow_blocking = false) {
    $firewall = get_firewall_by_id($firewall_id);

    if (!$firewall) {
        return ['success' => false, 'error' => 'Firewall not found'];
    }

    $key_file = "/etc/opnmgr/keys/id_firewall_{$firewall_id}";
    $needs_new_key = false;

    // Check if key exists in database
    if (empty($firewall['ssh_private_key'])) {
        error_log("No SSH key in database for firewall {$firewall_id}");
        if (!$allow_blocking) {
            // In non-blocking mode, queue key generation but return existing/temp key
            error_log("Non-blocking mode: queueing key generation for background");
            return ['success' => false, 'error' => 'No SSH key available. Please use "Update/Repair Agent" button to configure SSH key.'];
        }
        $needs_new_key = true;
    } else if ($force_regenerate) {
        error_log("Force regenerating SSH key for firewall {$firewall_id}");
        if (!$allow_blocking) {
            return ['success' => false, 'error' => 'Cannot force regenerate in non-blocking mode'];
        }
        $needs_new_key = true;
    } else {
        // Extract key from database and save to file
        $private_key = base64_decode($firewall['ssh_private_key']);
        file_put_contents($key_file, $private_key);
        chmod($key_file, 0600);

        // Test if key works - use wan_ip as primary, fallback to ip_address, then hostname
        $test_ip = $firewall['wan_ip'] ?: ($firewall['ip_address'] ?: $firewall['hostname']);
        error_log("Testing existing SSH key for firewall {$firewall_id} at {$test_ip}");

        if (!test_ssh_key($test_ip, $key_file)) {
            error_log("Existing SSH key failed for firewall {$firewall_id}");
            if (!$allow_blocking) {
                // In non-blocking mode, return error but keep the key file for retry
                // The key might be deploying via agent, so don't fail hard
                error_log("Non-blocking mode: Using existing key despite test failure (may be deploying)");
                return [
                    'success' => true,
                    'message' => 'Using existing key (authentication pending)',
                    'key_file' => $key_file,
                    'regenerated' => false,
                    'test_failed' => true
                ];
            }
            $needs_new_key = true;
        } else {
            error_log("Existing SSH key valid for firewall {$firewall_id}");
            return [
                'success' => true,
                'message' => 'Existing key is valid',
                'key_file' => $key_file,
                'regenerated' => false
            ];
        }
    }

    if ($needs_new_key) {
        // Generate new key pair
        error_log("Generating new SSH key pair for firewall {$firewall_id}");
        $key_result = generate_ssh_keypair($firewall_id);

        if (!$key_result['success']) {
            return $key_result;
        }

        // Deploy public key to firewall (THIS BLOCKS waiting for agent!)
        error_log("Deploying public key to firewall {$firewall_id} (may block up to 120s)");
        $deploy_result = deploy_ssh_key_to_firewall($firewall_id, $key_result['public_key']);

        if (!$deploy_result['success']) {
            return ['success' => false, 'error' => 'Failed to deploy key to firewall: ' . $deploy_result['result']];
        }

        // Test new key - use wan_ip as primary, fallback to ip_address, then hostname
        $test_ip = $firewall['wan_ip'] ?: ($firewall['ip_address'] ?: $firewall['hostname']);
        error_log("Testing new SSH key for firewall {$firewall_id} at {$test_ip}");
        sleep(2); // Give sshd time to reload
        if (!test_ssh_key($test_ip, $key_result['private_key_file'])) {
            return ['success' => false, 'error' => 'New key deployed but authentication still fails'];
        }

        // Store in database
        error_log("Storing new SSH key in database for firewall {$firewall_id}");
        update_firewall_ssh_key($firewall_id, $key_result['private_key_base64'], $key_result['public_key']);

        return [
            'success' => true,
            'message' => 'New key generated and deployed',
            'key_file' => $key_result['private_key_file'],
            'regenerated' => true
        ];
    }
}

// CLI interface
if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === 'manage_ssh_keys.php') {
    $command = $argv[1] ?? 'help';
    $firewall_id = $argv[2] ?? null;
    
    switch ($command) {
        case 'ensure':
            if (!$firewall_id) {
                echo "Error: Firewall ID required\n";
                exit(1);
            }
            $result = ensure_ssh_key($firewall_id);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit($result['success'] ? 0 : 1);
            
        case 'regenerate':
            if (!$firewall_id) {
                echo "Error: Firewall ID required\n";
                exit(1);
            }
            $result = ensure_ssh_key($firewall_id, true);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit($result['success'] ? 0 : 1);
            
        case 'test':
            if (!$firewall_id) {
                echo "Error: Firewall ID required\n";
                exit(1);
            }
            $firewall = get_firewall_by_id($firewall_id);
            $key_file = "/etc/opnmgr/keys/id_firewall_{$firewall_id}";
            
            if (!file_exists($key_file) && $firewall['ssh_private_key']) {
                file_put_contents($key_file, base64_decode($firewall['ssh_private_key']));
                chmod($key_file, 0600);
            }
            
            $test_ip = $firewall['wan_ip'] ?: ($firewall['ip_address'] ?: $firewall['hostname']);
            $valid = test_ssh_key($test_ip, $key_file);
            echo json_encode(['valid' => $valid, 'tested_ip' => $test_ip]) . "\n";
            exit($valid ? 0 : 1);
            
        case 'help':
        default:
            echo "SSH Key Manager\n";
            echo "Usage: manage_ssh_keys.php <command> <firewall_id>\n\n";
            echo "Commands:\n";
            echo "  ensure <id>     - Ensure valid SSH key exists (generate if needed)\n";
            echo "  regenerate <id> - Force regenerate SSH key\n";
            echo "  test <id>       - Test if current SSH key works\n";
            echo "  help            - Show this help\n";
            exit(0);
    }
}
