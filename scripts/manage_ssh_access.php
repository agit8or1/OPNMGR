<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Temporary SSH Access Manager
 * Creates time-limited SSH firewall rules that auto-expire
 */

require_once(__DIR__ . '/../inc/bootstrap_agent.php');
require_once(__DIR__ . '/../inc/logging.php');

// Configuration
define('SSH_ACCESS_DURATION_MINUTES', 30); // How long the rule stays active
define('SSH_IDLE_TIMEOUT_MINUTES', 10);    // Close rule after this many minutes of inactivity
define('TUNNEL_PORT_MIN', 8100);           // Min port for tunnels
define('TUNNEL_PORT_MAX', 8200);           // Max port for tunnels

if (!function_exists('get_firewall_by_id')) {
    function get_firewall_by_id($firewall_id) {
                $stmt = db()->prepare("SELECT * FROM firewalls WHERE id = ?");
        $stmt->execute([$firewall_id]);
        return $stmt->fetch();
    }
}

function get_manager_public_ip() {
    // Try multiple services in case one is down
    $services = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com'
    ];
    
    foreach ($services as $service) {
        $ip = @file_get_contents($service);
        if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
            return trim($ip);
        }
    }
    
    return null;
}

/**
 * PIDs of the ssh processes forwarding a given tunnel port.
 *
 * Matched on the -L argument rather than a pidfile, because the tunnel is
 * started with -f and nothing records which process ended up owning the port.
 */
function tunnel_ssh_pids(int $port): array
{
    // Deliberately not pgrep -f: its pattern appears in the command line of the
    // shell running it, so `pgrep -f -- "-L 127.0.0.1:8199:"` reports a match
    // for a port with no tunnel at all. Reconciliation kills what this returns,
    // so it matches on the executable being ssh and on this process's own pid
    // being excluded, rather than on a string that can match the search itself.
    $lines = [];
    exec('ps -eo pid=,comm=,args= 2>/dev/null', $lines);

    $needle = "-L 127.0.0.1:{$port}:";
    $self   = getmypid();
    $pids   = [];

    foreach ($lines as $line) {
        if (!preg_match('/^\s*(\d+)\s+(\S+)\s+(.*)$/', $line, $m)) {
            continue;
        }
        [$_, $pid, $comm, $args] = $m;
        $pid = (int) $pid;

        if ($pid === $self || $comm !== 'ssh') {
            continue;
        }
        if (str_contains($args, $needle)) {
            $pids[] = $pid;
        }
    }

    return $pids;
}

/**
 * Make the session table and the machine agree about which ports are in use.
 *
 * They drifted in both directions and nothing ever reconciled them:
 *
 *   - A session past expires_at stayed 'active' forever. The status enum has
 *     an 'expired' value and no code ever set it, so an abandoned session
 *     reserved its port permanently.
 *   - A session whose ssh process had died stayed 'active', reserving a port
 *     that nothing was listening on.
 *   - An ssh process outliving its session held the port while the table
 *     called it free - which is the case that produced "Port pair 8100/8101
 *     shows as free in DB but one is in use on system".
 *
 * The allocator detected that last one and skipped to the next pair, which is
 * correct but only treats the symptom: the range leaks a pair at a time until
 * it is exhausted.
 *
 * @return array{expired:int, orphaned_sessions:int, orphaned_processes:int}
 */
function reconcile_tunnel_sessions(): array
{
    $expired = 0;
    $orphanedSessions = 0;
    $orphanedProcesses = 0;

    try {
        $active = db()->query(
            "SELECT id, tunnel_port, (expires_at < NOW()) AS is_expired
               FROM ssh_access_sessions WHERE status = 'active'"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('OPNMGR: could not read tunnel sessions to reconcile: ' . $e->getMessage());
        return ['expired' => 0, 'orphaned_sessions' => 0, 'orphaned_processes' => 0];
    }

    $liveSessionPorts = [];

    foreach ($active as $row) {
        $id   = (int) $row['id'];
        $port = (int) $row['tunnel_port'];
        $pids = tunnel_ssh_pids($port);

        if ((int) $row['is_expired'] === 1) {
            // Past its time: close it and take the tunnel down with it.
            foreach ($pids as $pid) {
                // 15, not SIGTERM: the constant comes from pcntl, which is not
                // loaded under PHP-FPM. This runs from both the CLI evaluator and
                // a web request that starts a tunnel, and referencing the
                // constant made the web path a fatal.
                // manage_ssh_tunnel.php already did it this way.
                posix_kill($pid, 15);
            }
            $stmt = db()->prepare(
                "UPDATE ssh_access_sessions
                    SET status = 'expired', closed_reason = 'expired'
                  WHERE id = ? AND status = 'active'"
            );
            $stmt->execute([$id]);
            $expired++;
            continue;
        }

        if (!$pids) {
            // Still within its window, but the tunnel is gone. Holding the port
            // helps nobody; the session cannot be revived by keeping the row.
            $stmt = db()->prepare(
                "UPDATE ssh_access_sessions
                    SET status = 'closed', closed_reason = 'tunnel process gone'
                  WHERE id = ? AND status = 'active'"
            );
            $stmt->execute([$id]);
            $orphanedSessions++;
            continue;
        }

        $liveSessionPorts[$port] = true;
    }

    // Processes outliving their session. Bounded to the tunnel range, and only
    // where no active session claims the port, so nothing else is touched.
    for ($port = TUNNEL_PORT_MIN; $port <= TUNNEL_PORT_MAX; $port++) {
        if (isset($liveSessionPorts[$port])) {
            continue;
        }
        foreach (tunnel_ssh_pids($port) as $pid) {
            posix_kill($pid, 15); // SIGTERM; the constant is pcntl-only
            $orphanedProcesses++;
        }
    }

    if ($expired || $orphanedSessions || $orphanedProcesses) {
        error_log(sprintf(
            'OPNMGR tunnel reconcile: %d expired, %d sessions without a tunnel, %d tunnels without a session',
            $expired, $orphanedSessions, $orphanedProcesses
        ));
    }

    return [
        'expired'            => $expired,
        'orphaned_sessions'  => $orphanedSessions,
        'orphaned_processes' => $orphanedProcesses,
    ];
}

function find_available_tunnel_port() {

    // Reconcile first, or the range leaks a pair at a time: every stale row and
    // every orphaned process permanently removes a port pair from circulation.
    reconcile_tunnel_sessions();

    // Get ports already in use (both tunnel_port AND tunnel_port-1 for nginx)
    $stmt = db()->query("SELECT tunnel_port FROM ssh_access_sessions WHERE status = 'active'");
    $used_ports = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $all_used = [];
    foreach ($used_ports as $p) {
        $all_used[] = (int)$p;
        $all_used[] = (int)$p - 1; // nginx HTTPS proxy port
    }

    // Each session uses a port pair: odd for SSH tunnel, even (port-1) for nginx HTTPS proxy
    // Step by 2 starting from first odd port to avoid overlapping pairs
    $start = TUNNEL_PORT_MIN % 2 === 0 ? TUNNEL_PORT_MIN + 1 : TUNNEL_PORT_MIN;
    for ($port = $start; $port <= TUNNEL_PORT_MAX; $port += 2) {
        $nginx_port = $port - 1;
        if (!in_array($port, $all_used) && !in_array($nginx_port, $all_used)) {
            // Double-check both ports with system
            $check_ssh = shell_exec('ss -tln | grep ' . escapeshellarg(":{$port} ") . ' 2>/dev/null');
            $check_nginx = shell_exec('ss -tln | grep ' . escapeshellarg(":{$nginx_port} ") . ' 2>/dev/null');
            if (empty($check_ssh) && empty($check_nginx)) {
                return $port;
            } else {
                error_log("Port pair {$nginx_port}/{$port} shows as free in DB but one is in use on system");
            }
        }
    }

    return null;
}

if (!function_exists('queue_firewall_command')) {
function queue_firewall_command($firewall_id, $command, $description) {
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
        
        if ($result && in_array($result['status'], ['completed', 'failed'])) {
            return ['success' => $result['result'] === 'success', 'result' => $result['result']];
        }
        
        sleep(5);
    }
    
    return ['success' => false, 'result' => 'timeout'];
}
}

function create_temporary_ssh_rule($firewall_id, $source_ip, $duration_minutes = SSH_ACCESS_DURATION_MINUTES) {
    $firewall = get_firewall_by_id($firewall_id);
    if (!$firewall) {
        return ['success' => false, 'error' => 'Firewall not found'];
    }
    
    // Generate unique rule label
    $rule_label = sprintf("ssh_temp_%s_%s", $firewall_id, substr(md5(time() . $source_ip), 0, 8));
    
    // Create rule XML
    $rule_xml = sprintf(
        '    <rule>
      <type>pass</type>
      <interface>wan</interface>
      <ipprotocol>inet</ipprotocol>
      <protocol>tcp</protocol>
      <source>
        <address>%s</address>
      </source>
      <destination>
        <network>(self)</network>
        <port>22</port>
      </destination>
      <descr>Temporary SSH access - Expires in %d min - %s</descr>
      <statetype>keep state</statetype>
    </rule>',
        $source_ip,
        $duration_minutes,
        $rule_label
    );
    
    // Command to add rule
    $command = sprintf(
        'cat > /tmp/ssh_temp_rule.xml << \'RULEEOF\'
%s
RULEEOF
cp /conf/config.xml /conf/config.xml.backup_ssh_temp
FILTER_LINE=$(grep -n "</filter>" /conf/config.xml | head -1 | cut -d: -f1)
head -n $((FILTER_LINE - 1)) /conf/config.xml > /tmp/config.xml.new
cat /tmp/ssh_temp_rule.xml >> /tmp/config.xml.new
tail -n +${FILTER_LINE} /conf/config.xml >> /tmp/config.xml.new
mv /tmp/config.xml.new /conf/config.xml
/usr/local/etc/rc.filter_configure
echo RULE_ADDED_%s',
        $rule_xml,
        $rule_label
    );
    
    $cmd_id = queue_firewall_command($firewall_id, $command, "Add temporary SSH rule: {$rule_label}");
    error_log("Queued temporary SSH rule creation command {$cmd_id} for firewall {$firewall_id}");
    
    // DON'T wait for command - let it execute in background
    // The rule will be active within 5-10 seconds, and the session is already created in the DB
    // $result = wait_for_command($cmd_id);
    
    // if (!$result['success']) {
    //     return ['success' => false, 'error' => 'Failed to create firewall rule: ' . $result['result']];
    // }
    
    return ['success' => true, 'rule_label' => $rule_label];
}

function remove_ssh_rule($firewall_id, $rule_label) {
    // Command to remove rule by description
    $command = sprintf(
        'cp /conf/config.xml /conf/config.xml.backup_remove_temp
sed -i.bak "/Temporary SSH access.*%s/,/<\\/rule>/d" /conf/config.xml
/usr/local/etc/rc.filter_configure
echo RULE_REMOVED_%s',
        preg_quote($rule_label, '/'),
        $rule_label
    );
    
    $cmd_id = queue_firewall_command($firewall_id, $command, "Remove temporary SSH rule: {$rule_label}");
    error_log("Queued temporary SSH rule removal command {$cmd_id} for firewall {$firewall_id}");
    
    return wait_for_command($cmd_id);
}

function request_ssh_access($firewall_id, $duration_minutes = SSH_ACCESS_DURATION_MINUTES) {
        
    // Get manager's public IP
    $source_ip = get_manager_public_ip();
    if (!$source_ip) {
        return ['success' => false, 'error' => 'Could not determine manager public IP'];
    }
    
    // Check if there's already an active session
    $stmt = db()->prepare("
        SELECT * FROM ssh_access_sessions 
        WHERE firewall_id = ? 
        AND source_ip = ? 
        AND status = 'active' 
        AND expires_at > NOW()
    ");
    $stmt->execute([$firewall_id, $source_ip]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Extend existing session
        $new_expires = date('Y-m-d H:i:s', strtotime("+{$duration_minutes} minutes"));
        $stmt = db()->prepare("UPDATE ssh_access_sessions SET expires_at = ?, last_activity = NOW() WHERE id = ?");
        $stmt->execute([$new_expires, $existing['id']]);
        
        return [
            'success' => true,
            'message' => 'Extended existing SSH access',
            'session_id' => $existing['id'],
            'tunnel_port' => $existing['tunnel_port'],
            'expires_at' => $new_expires,
            'rule_label' => null // No longer using temporary rules
        ];
    }
    
    // Use firewall's existing tunnel port (from permanent agent tunnel)
    $stmt = db()->prepare("SELECT tunnel_port FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $fw_data = $stmt->fetch();
    $tunnel_port = $fw_data['tunnel_port'] ?? null;

    if (!$tunnel_port) {
        // Fallback: find available port if firewall doesn't have one assigned
        $tunnel_port = find_available_tunnel_port();
        if (!$tunnel_port) {
            return ['success' => false, 'error' => 'No tunnel port available for firewall'];
        }
    }
    
    // NO LONGER CREATE TEMPORARY RULES - Using permanent rule now
    // The permanent rule "Allow SSH from OPNManager - PERMANENT" is always active
    
    // Create session record
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration_minutes} minutes"));
    // Record the operator who opened this session and mint a bearer token, so
    // tunnel_proxy.php can authorise access instead of trusting the row id.
    $access_token = bin2hex(random_bytes(24));

    $stmt = db()->prepare("
        INSERT INTO ssh_access_sessions 
        (firewall_id, source_ip, tunnel_port, rule_label, expires_at, status,
         created_by_user_id, created_by_username, access_token) 
        VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?)
    ");
    $stmt->execute([
        $firewall_id, $source_ip, $tunnel_port, null, $expires_at,
        $_SESSION['user_id']  ?? null,
        $_SESSION['username'] ?? null,
        $access_token,
    ]);
    $session_id = db()->lastInsertId();
    
    // Log the tunnel creation
    log_info("TUNNEL", "Tunnel session #{$session_id} created for firewall #{$firewall_id} on port {$tunnel_port} from {$source_ip} (using permanent SSH rule)", null, $firewall_id);
    
    
    // Create nginx HTTPS proxy config for this session (with sudo)
    exec("sudo /usr/bin/php " . __DIR__ . "/manage_nginx_tunnel_proxy.php create {$session_id} 2>&1", $nginx_output, $nginx_result);
    if ($nginx_result !== 0) {
        error_log("WARNING: Failed to create nginx proxy for session {$session_id}: " . implode("\n", $nginx_output));
    }
    // Update firewall tunnel port
    $stmt = db()->prepare("UPDATE firewalls SET ssh_tunnel_port = ? WHERE id = ?");
    $stmt->execute([$tunnel_port, $firewall_id]);
    
    return [
        'success' => true,
        'session_id' => $session_id,
        'tunnel_port' => $tunnel_port,
        'source_ip' => $source_ip,
        'expires_at' => $expires_at,
        'rule_label' => null, // No temporary rule needed
        'duration_minutes' => $duration_minutes
    ];
}

function close_ssh_access($session_id) {
        
    $stmt = db()->prepare("SELECT * FROM ssh_access_sessions WHERE id = ?");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch();
    
    if (!$session) {
        return ['success' => false, 'error' => 'Session not found'];
    }
    
    // Kill the SSH tunnel process (use kill -9 for force kill)
    $port = $session['tunnel_port'];
    $kill_cmd = "ps aux | grep 'ssh.*-L {$port}:' | grep -v grep | awk '{print \$2}' | xargs -r sudo kill -9 2>/dev/null";
    exec($kill_cmd, $output, $return_code);
    // Return code 123 means xargs found no processes (already dead), which is fine
    $tunnel_killed = ($return_code === 0 || $return_code === 123);
    
    // Remove firewall rule
    $result = remove_ssh_rule($session['firewall_id'], $session['rule_label']);
    
    // Update session status
    $stmt = db()->prepare("UPDATE ssh_access_sessions SET status = 'closed', closed_reason = 'Manual close' WHERE id = ?");
    $stmt->execute([$session_id]);
    
    // Remove nginx HTTPS proxy config (with sudo)
    exec("sudo /usr/bin/php " . __DIR__ . "/manage_nginx_tunnel_proxy.php remove {$session_id} 2>&1");

    
    // Log the closure
    log_info("TUNNEL", "Tunnel session #{$session_id} closed (firewall #{$session['firewall_id']}, port {$port}, tunnel_killed={$tunnel_killed})", null, $session['firewall_id']);
    
    return ['success' => true, 'message' => 'SSH access closed', 'tunnel_killed' => $tunnel_killed];
}

function cleanup_expired_sessions() {
        
    // Find expired sessions
    $stmt = db()->query("
        SELECT * FROM ssh_access_sessions 
        WHERE status = 'active' 
        AND (
            expires_at < NOW() 
            OR TIMESTAMPDIFF(MINUTE, last_activity, NOW()) > " . SSH_IDLE_TIMEOUT_MINUTES . "
        )
    ");
    $expired = $stmt->fetchAll();
    
    $cleaned = 0;
    $tunnels_killed = 0;
    
    foreach ($expired as $session) {
        error_log("Cleaning up expired SSH session {$session['id']} for firewall {$session['firewall_id']} on port {$session['tunnel_port']}");
        
        // Kill the SSH tunnel process (use kill -9 for force kill)
        $port = $session['tunnel_port'];
        $kill_cmd = "ps aux | grep 'ssh.*-L {$port}:' | grep -v grep | awk '{print \$2}' | xargs -r sudo kill -9 2>/dev/null";
        exec($kill_cmd, $output, $return_code);
        // Return code 123 means xargs found no processes (already dead), which is fine
        if ($return_code === 0 || $return_code === 123) {
            $tunnels_killed++;
            error_log("Killed SSH tunnel on port {$port}");
        }
        
        // NO LONGER REMOVE FIREWALL RULES - Using permanent rule now
        // The permanent "Allow SSH from OPNManager - PERMANENT" rule stays active

        // Remove nginx proxy config for this session
        exec("sudo /usr/bin/php " . __DIR__ . "/manage_nginx_tunnel_proxy.php remove {$session['id']} 2>&1");

        // Update status
        $reason = (strtotime($session['expires_at']) < time()) ? 'Expired' : 'Idle timeout';
        $stmt = db()->prepare("UPDATE ssh_access_sessions SET status = 'closed', closed_reason = ? WHERE id = ?");
        $stmt->execute([$reason, $session['id']]);

        $cleaned++;
    }
    
    // Also clean up orphaned tunnels (tunnels running for closed sessions)
    $stmt = db()->query("
        SELECT DISTINCT tunnel_port FROM ssh_access_sessions 
        WHERE status = 'closed' 
        AND tunnel_port IS NOT NULL
        AND tunnel_port BETWEEN " . TUNNEL_PORT_MIN . " AND " . TUNNEL_PORT_MAX . "
    ");
    $closed_ports = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $orphaned_killed = 0;
    foreach ($closed_ports as $port) {
        $kill_cmd = "ps aux | grep 'ssh.*-L {$port}:' | grep -v grep | awk '{print \$2}' | xargs -r sudo kill -9 2>/dev/null";
        exec($kill_cmd, $output, $return_code);
        if ($return_code === 0) {
            $orphaned_killed++;
            error_log("Killed orphaned SSH tunnel on port {$port}");
        }
    }
    
    return [
        'success' => true, 
        'cleaned' => $cleaned, 
        'tunnels_killed' => $tunnels_killed,
        'orphaned_killed' => $orphaned_killed
    ];
}

// CLI interface
if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === 'manage_ssh_access.php') {
    $command = $argv[1] ?? 'help';
    $firewall_id = $argv[2] ?? null;
    
    switch ($command) {
        case 'request':
            if (!$firewall_id) {
                echo "Error: Firewall ID required\n";
                exit(1);
            }
            $duration = $argv[3] ?? SSH_ACCESS_DURATION_MINUTES;
            $result = request_ssh_access($firewall_id, $duration);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit($result['success'] ? 0 : 1);
            
        case 'close':
            $session_id = $firewall_id; // In this case, second arg is session ID
            if (!$session_id) {
                echo "Error: Session ID required\n";
                exit(1);
            }
            $result = close_ssh_access($session_id);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit($result['success'] ? 0 : 1);
            
        case 'cleanup':
            // Only the cleanup subcommand is a scheduled run; the others are
            // driven by an operator opening a tunnel.
            require_once __DIR__ . '/../inc/cron_runs.php';
            cron_run_begin('ssh_access_cleanup');
            $result = cleanup_expired_sessions();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit(0);
            
        case 'help':
        default:
            echo "Temporary SSH Access Manager\n";
            echo "Usage: manage_ssh_access.php <command> [args]\n\n";
            echo "Commands:\n";
            echo "  request <firewall_id> [duration]  - Request temporary SSH access\n";
            echo "  close <session_id>                - Close SSH access session\n";
            echo "  cleanup                           - Clean up expired sessions\n";
            echo "  help                              - Show this help\n";
            exit(0);
    }
}
