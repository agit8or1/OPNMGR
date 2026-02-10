<?php
/**
 * Automatic Firewall Onboarding
 * Called by agent_checkin.php when a new firewall checks in
 * Ensures all required packages and configurations are present
 */
require_once __DIR__ . '/../inc/bootstrap.php';

require_once __DIR__ . '/../inc/logging.php';

/**
 * Check if firewall needs onboarding and queue necessary commands
 */
function auto_onboard_firewall($firewall_id) {
    // Check if firewall has been onboarded
    $stmt = db()->prepare('SELECT onboarded, onboard_started_at FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $fw = $stmt->fetch(PDO::FETCH_ASSOC);

    // If already onboarded, skip
    if ($fw && $fw['onboarded'] == 1) {
        return ['status' => 'already_onboarded'];
    }

    // If onboarding in progress (started less than 30 minutes ago), skip
    if ($fw && $fw['onboard_started_at'] &&
        strtotime($fw['onboard_started_at']) > (time() - 1800)) {
        return ['status' => 'onboarding_in_progress'];
    }

    // Start onboarding
    $stmt = db()->prepare('UPDATE firewalls SET onboard_started_at = NOW() WHERE id = ?');
    $stmt->execute([$firewall_id]);

    log_info('onboarding', "Starting automatic onboarding for firewall ID $firewall_id");

    // Queue onboarding commands in sequence
    $commands = [];

    // 1. Check and install iperf3
    $commands[] = [
        'command' => 'which iperf3 >/dev/null 2>&1 && echo IPERF3_INSTALLED || (pkg install -y iperf3 && echo IPERF3_INSTALLED_NOW)',
        'description' => 'Onboarding: Install iperf3',
        'priority' => 1
    ];

    // 2. Check and install curl (if missing)
    $commands[] = [
        'command' => 'which curl >/dev/null 2>&1 && echo CURL_OK || pkg install -y curl',
        'description' => 'Onboarding: Verify curl',
        'priority' => 2
    ];

    // 3. Check and install python3 (if missing)
    $commands[] = [
        'command' => 'which python3 >/dev/null 2>&1 && echo PYTHON3_OK || pkg install -y python3',
        'description' => 'Onboarding: Verify python3',
        'priority' => 3
    ];

    // 4. Add SSH key for management server
    $ssh_key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBiN17ZRfM+3/bylcYO/NHmgTnASMGx5YtCMUS5qSEuL opnmgr-to-firewall';
    $commands[] = [
        'command' => "mkdir -p /root/.ssh && chmod 700 /root/.ssh && grep -q 'opnmgr-to-firewall' /root/.ssh/authorized_keys 2>/dev/null || echo '$ssh_key' >> /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys && echo SSH_KEY_OK",
        'description' => 'Onboarding: Add SSH key',
        'priority' => 4
    ];

    // 5. Add SSH firewall rule
    $commands[] = [
        'command' => get_ssh_rule_command(),
        'description' => 'Onboarding: Enable SSH access',
        'priority' => 5
    ];

    // 6. Mark onboarding complete
    $commands[] = [
        'command' => 'echo ONBOARDING_COMPLETE',
        'description' => 'Onboarding: Finalize',
        'priority' => 6
    ];

    // Queue all commands
    $stmt = db()->prepare('
        INSERT INTO firewall_commands
        (firewall_id, command_type, command, description, status, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ');

    foreach ($commands as $cmd) {
        $stmt->execute([
            $firewall_id,
            'shell',
            $cmd['command'],
            $cmd['description'],
            'pending'
        ]);
    }

    log_info('onboarding', "Queued " . count($commands) . " onboarding commands for firewall ID $firewall_id");

    return [
        'status' => 'onboarding_started',
        'commands_queued' => count($commands)
    ];
}

/**
 * Check onboarding progress and mark as complete when done
 */
function check_onboarding_progress($firewall_id) {
    // Get onboarding commands
    $stmt = db()->prepare('
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
        FROM firewall_commands
        WHERE firewall_id = ?
        AND description LIKE "Onboarding:%"
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ');
    $stmt->execute([$firewall_id]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

    // If all commands completed
    if ($progress['total'] > 0 &&
        $progress['completed'] == $progress['total']) {

        // Mark as onboarded
        $stmt = db()->prepare('UPDATE firewalls SET onboarded = 1, onboarded_at = NOW() WHERE id = ?');
        $stmt->execute([$firewall_id]);

        log_info('onboarding', "Firewall ID $firewall_id onboarding complete!");

        return [
            'status' => 'complete',
            'total' => $progress['total'],
            'completed' => $progress['completed']
        ];
    }

    // If any failed
    if ($progress['failed'] > 0) {
        log_error('onboarding', "Firewall ID $firewall_id onboarding had {$progress['failed']} failures");
    }

    return [
        'status' => 'in_progress',
        'total' => $progress['total'],
        'completed' => $progress['completed'],
        'failed' => $progress['failed']
    ];
}

/**
 * Generate SSH rule command for OPNsense
 */
function get_ssh_rule_command() {
    $mgmt_ip = '184.175.206.229';

    $script = <<<'SCRIPT'
cat > /tmp/add_ssh_rule.php << 'PHPEOF'
<?php
require_once("config.inc");
require_once("filter.inc");

$rule = array(
    'type' => 'pass',
    'interface' => 'wan',
    'ipprotocol' => 'inet',
    'protocol' => 'tcp',
    'source' => array('address' => '184.175.206.229'),
    'destination' => array('address' => '(self)', 'port' => '22'),
    'descr' => 'SSH from OPNManager - AUTO CONFIGURED'
);

// Check if rule exists
$exists = false;
if (isset($config['filter']['rule'])) {
    foreach ($config['filter']['rule'] as $r) {
        if (isset($r['descr']) && strpos($r['descr'], 'SSH from OPNManager') !== false) {
            $exists = true;
            break;
        }
    }
}

if (!$exists) {
    if (!isset($config['filter']['rule'])) {
        $config['filter']['rule'] = array();
    }
    array_unshift($config['filter']['rule'], $rule);
    write_config("Auto-onboarding: SSH access enabled");
    filter_configure();
    echo "SSH_RULE_ADDED";
} else {
    echo "SSH_RULE_EXISTS";
}
PHPEOF

php /tmp/add_ssh_rule.php && rm /tmp/add_ssh_rule.php
SCRIPT;

    return $script;
}

// CLI usage
if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === 'auto_onboard_firewall.php') {
    $firewall_id = $argv[1] ?? null;

    if (!$firewall_id) {
        echo "Usage: php auto_onboard_firewall.php <firewall_id>\n";
        echo "\nOR check progress:\n";
        echo "php auto_onboard_firewall.php <firewall_id> status\n";
        exit(1);
    }

    $action = $argv[2] ?? 'start';

    if ($action === 'status') {
        $result = check_onboarding_progress($firewall_id);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    } else {
        $result = auto_onboard_firewall($firewall_id);
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    }
}
?>
