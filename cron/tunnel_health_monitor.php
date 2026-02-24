#!/usr/bin/env php
<?php
/**
 * SSH Tunnel Health Monitor & Auto-Healer
 *
 * Runs every 2 minutes via cron. For each active SSH tunnel session:
 *   1. Checks if the SSH tunnel process is alive
 *   2. If dead: verifies SSH key, re-establishes tunnel, fixes nginx config
 *   3. If alive: quick connectivity test, updates last_activity
 *   4. Cleans up truly expired sessions
 *
 * Usage (cron): every 2 minutes
 *   php /var/www/opnsense/cron/tunnel_health_monitor.php >> /var/log/tunnel_health.log 2>&1
 *
 * @since 3.9.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/logging.php';

// Prevent overlapping runs
$lock_file = '/tmp/tunnel_health_monitor.lock';
$lock_fp = fopen($lock_file, 'w');
if (!flock($lock_fp, LOCK_EX | LOCK_NB)) {
    exit(0); // Another instance is running
}

$pdo = db();
$now = date('Y-m-d H:i:s');
$healed = 0;
$alive = 0;
$cleaned = 0;

echo "[{$now}] Tunnel health monitor starting\n";

// ─── Phase 1: Check active, non-expired sessions ───────────────────────────

$stmt = $pdo->prepare("
    SELECT s.*, f.wan_ip, f.ip_address, f.hostname, f.web_port, f.ssh_private_key, f.ssh_public_key
    FROM ssh_access_sessions s
    JOIN firewalls f ON s.firewall_id = f.id
    WHERE s.status = 'active'
      AND s.expires_at > NOW()
    ORDER BY s.id
");
$stmt->execute();
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($sessions as $session) {
    $sid = $session['id'];
    $port = $session['tunnel_port'];
    $fw_id = $session['firewall_id'];
    $fw_ip = $session['wan_ip'] ?: ($session['ip_address'] ?: $session['hostname']);
    $fw_web_port = !empty($session['web_port']) ? (int)$session['web_port'] : 443;
    $protocol = ($fw_web_port == 443) ? 'https' : 'http';

    // Check if SSH tunnel process is running on this port
    $tunnel_alive = false;
    $check = trim(shell_exec("ss -tln4 2>/dev/null | grep '127.0.0.1:{$port} '") ?? '');
    if (!empty($check)) {
        $tunnel_alive = true;
    }

    if ($tunnel_alive) {
        // ── Tunnel process exists — quick connectivity test ──
        $test_cmd = sprintf(
            "timeout 3 curl -sk -o /dev/null -w '%%{http_code}' %s://127.0.0.1:%d/ 2>/dev/null",
            $protocol, $port
        );
        $http_code = trim(shell_exec($test_cmd) ?? '');

        if ($http_code && $http_code !== '000') {
            // Responding — update last_activity
            $pdo->prepare("UPDATE ssh_access_sessions SET last_activity = NOW() WHERE id = ?")->execute([$sid]);
            $alive++;
        } else {
            // Port is listening but not responding (e.g. tunnel half-dead)
            echo "[{$now}] Session #{$sid} (fw#{$fw_id} port {$port}): port listening but not responding (HTTP {$http_code}), killing and re-establishing\n";
            // Kill the broken tunnel
            shell_exec("ps aux | grep 'ssh.*-L.*{$port}:' | grep -v grep | awk '{print \$2}' | xargs -r kill -9 2>/dev/null");
            sleep(1);
            $tunnel_alive = false; // Fall through to re-establish
        }
    }

    if (!$tunnel_alive) {
        // ── Tunnel is dead — attempt auto-heal ──
        echo "[{$now}] Session #{$sid} (fw#{$fw_id} port {$port}): tunnel DEAD, attempting auto-heal\n";

        // Step 1: Ensure SSH key on disk
        $key_file = "/etc/opnmgr/keys/id_firewall_{$fw_id}";
        if (empty($session['ssh_private_key'])) {
            echo "[{$now}]   SKIP: No SSH key in DB for firewall #{$fw_id}\n";
            log_warning('TUNNEL', "Auto-heal skipped for session #{$sid}: no SSH key in DB", null, $fw_id);
            continue;
        }

        $private_key = base64_decode($session['ssh_private_key']);
        if (!is_dir(dirname($key_file))) {
            mkdir(dirname($key_file), 0700, true);
        }
        file_put_contents($key_file, $private_key);
        chmod($key_file, 0600);

        // Step 2: Test SSH key
        $test_cmd = sprintf(
            "timeout 8 ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=5 -o BatchMode=yes root@%s 'echo SSH_KEY_VALID' 2>&1",
            escapeshellarg($key_file),
            escapeshellarg($fw_ip)
        );
        $test_output = trim(shell_exec($test_cmd) ?? '');

        if (strpos($test_output, 'SSH_KEY_VALID') === false) {
            // Key rejected — queue re-deployment via agent and skip this cycle
            echo "[{$now}]   SSH key rejected for fw#{$fw_id} ({$fw_ip}), queuing key re-deploy\n";

            // Queue the public key deployment command
            if (!empty($session['ssh_public_key'])) {
                $pub_key = $session['ssh_public_key'];
                $deploy_cmd = sprintf(
                    'mkdir -p /root/.ssh && chmod 700 /root/.ssh && grep -qF "%s" /root/.ssh/authorized_keys 2>/dev/null || echo "%s" >> /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys && echo SSH_KEY_DEPLOYED',
                    addslashes($pub_key),
                    addslashes($pub_key)
                );
                $pdo->prepare("INSERT INTO firewall_commands (firewall_id, command, description, status) VALUES (?, ?, ?, 'pending')")
                    ->execute([$fw_id, $deploy_cmd, 'Auto-heal: re-deploy SSH public key']);
                echo "[{$now}]   Queued key re-deploy command for fw#{$fw_id}\n";
            }

            log_warning('TUNNEL', "Auto-heal: SSH key rejected for session #{$sid}, queued re-deploy", null, $fw_id);
            continue;
        }

        echo "[{$now}]   SSH key valid for fw#{$fw_id}\n";

        // Step 3: Re-establish the SSH tunnel
        $ssh_cmd = sprintf(
            "ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=5 -o ServerAliveInterval=60 -o ServerAliveCountMax=2 -L 127.0.0.1:%d:localhost:%d -N -f root@%s 2>&1",
            escapeshellarg($key_file),
            $port,
            $fw_web_port,
            escapeshellarg($fw_ip)
        );
        $ssh_output = trim(shell_exec($ssh_cmd) ?? '');
        $ssh_result = 0;
        exec($ssh_cmd, $exec_output, $ssh_result);

        // Verify tunnel came up
        sleep(1);
        $verify = trim(shell_exec("ss -tln4 2>/dev/null | grep '127.0.0.1:{$port} '") ?? '');
        if (empty($verify)) {
            echo "[{$now}]   FAILED to re-establish tunnel on port {$port}\n";
            log_error('TUNNEL', "Auto-heal FAILED for session #{$sid}: tunnel did not come up (ssh output: {$ssh_output})", null, $fw_id);
            continue;
        }

        echo "[{$now}]   Tunnel re-established on port {$port}\n";

        // Step 4: Quick curl test
        $curl_test = sprintf(
            "timeout 3 curl -sk -o /dev/null -w '%%{http_code}' %s://127.0.0.1:%d/ 2>/dev/null",
            $protocol, $port
        );
        $http_code = trim(shell_exec($curl_test) ?? '');
        echo "[{$now}]   Connectivity test: HTTP {$http_code}\n";

        // Step 5: Check nginx proxy config exists and has correct protocol
        $https_port = $port - 1;
        $nginx_config = "/etc/nginx/sites-available/tunnel-session-{$sid}";
        if (file_exists($nginx_config)) {
            $nginx_content = file_get_contents($nginx_config);
            $expected_proxy = "proxy_pass {$protocol}://127.0.0.1:{$port}";
            if (strpos($nginx_content, $expected_proxy) === false) {
                echo "[{$now}]   Nginx config has wrong protocol, regenerating\n";
                // Regenerate nginx config with correct protocol
                exec("sudo /usr/bin/php " . dirname(__DIR__) . "/scripts/manage_nginx_tunnel_proxy.php remove {$sid} 2>&1");
                exec("sudo /usr/bin/php " . dirname(__DIR__) . "/scripts/manage_nginx_tunnel_proxy.php create {$sid} 2>&1", $nginx_out, $nginx_rc);
                if ($nginx_rc !== 0) {
                    echo "[{$now}]   WARNING: Failed to regenerate nginx config\n";
                }
            }
        } else {
            // Nginx config missing entirely — recreate it
            echo "[{$now}]   Nginx config missing, creating\n";
            exec("sudo /usr/bin/php " . dirname(__DIR__) . "/scripts/manage_nginx_tunnel_proxy.php create {$sid} 2>&1", $nginx_out, $nginx_rc);
            if ($nginx_rc !== 0) {
                echo "[{$now}]   WARNING: Failed to create nginx config\n";
            }
        }

        // Step 6: Update session
        $pdo->prepare("UPDATE ssh_access_sessions SET last_activity = NOW() WHERE id = ?")->execute([$sid]);
        $healed++;

        log_info('TUNNEL', "Auto-healed tunnel session #{$sid} (fw#{$fw_id}, port {$port})", null, $fw_id);
    }
}

// ─── Phase 2: Clean up expired sessions ────────────────────────────────────

$stmt = $pdo->query("
    SELECT s.*, f.hostname
    FROM ssh_access_sessions s
    JOIN firewalls f ON s.firewall_id = f.id
    WHERE s.status = 'active'
      AND s.expires_at <= NOW()
    ORDER BY s.id
");
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($expired as $session) {
    $sid = $session['id'];
    $port = $session['tunnel_port'];
    $fw_id = $session['firewall_id'];

    echo "[{$now}] Session #{$sid} (fw#{$fw_id} port {$port}): EXPIRED, cleaning up\n";

    // Kill SSH tunnel process
    shell_exec("ps aux | grep 'ssh.*-L.*{$port}:' | grep -v grep | awk '{print \$2}' | xargs -r kill -9 2>/dev/null");

    // Remove nginx config
    exec("sudo /usr/bin/php " . dirname(__DIR__) . "/scripts/manage_nginx_tunnel_proxy.php remove {$sid} 2>&1");

    // Update session status
    $pdo->prepare("UPDATE ssh_access_sessions SET status = 'closed', closed_reason = 'Expired (auto-heal monitor)' WHERE id = ?")
        ->execute([$sid]);

    $cleaned++;
    log_info('TUNNEL', "Expired session #{$sid} cleaned up by health monitor", null, $fw_id);
}

// ─── Summary ───────────────────────────────────────────────────────────────

$total = count($sessions);
echo "[{$now}] Done: {$total} active sessions checked, {$alive} alive, {$healed} healed, {$cleaned} expired cleaned\n";

// Release lock
flock($lock_fp, LOCK_UN);
fclose($lock_fp);
