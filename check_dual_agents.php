<?php
/**
 * Check Dual-Agent System Status
 * Verifies all firewalls have both primary and update agents running
 */

require_once __DIR__ . '/inc/db.php';

echo "=== Dual-Agent System Status ===\n\n";

// Check all firewalls
$fw_stmt = $DB->query("
    SELECT 
        f.id,
        f.hostname,
        f.status as firewall_status,
        f.last_checkin as firewall_last_checkin
    FROM firewalls f
    ORDER BY f.id
");

$firewalls = $fw_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_firewalls = count($firewalls);
$healthy_firewalls = 0;
$warning_firewalls = 0;
$critical_firewalls = 0;

foreach ($firewalls as $fw) {
    $firewall_id = $fw['id'];
    $hostname = $fw['hostname'];
    
    // Get agent details
    $agent_stmt = $DB->prepare("
        SELECT 
            agent_type,
            agent_version,
            last_checkin,
            TIMESTAMPDIFF(MINUTE, last_checkin, NOW()) as minutes_ago
        FROM firewall_agents
        WHERE firewall_id = ?
        AND last_checkin > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY agent_type
    ");
    $agent_stmt->execute([$firewall_id]);
    $agents = $agent_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $has_primary = false;
    $has_update = false;
    $status = '✅ OK';
    $details = [];
    
    foreach ($agents as $agent) {
        if ($agent['agent_type'] === 'primary') {
            $has_primary = true;
            $details[] = sprintf("Primary: v%s (%d min ago)",
                $agent['agent_version'], $agent['minutes_ago']);
        } elseif ($agent['agent_type'] === 'update') {
            $has_update = true;
            $details[] = sprintf("Update: v%s (%d min ago)",
                $agent['agent_version'], $agent['minutes_ago']);
        }
    }
    
    // Determine status
    if ($has_primary && $has_update) {
        $status = '✅ OK';
        $healthy_firewalls++;
    } elseif ($has_primary || $has_update) {
        $status = '⚠️  WARNING';
        $warning_firewalls++;
        if (!$has_primary) $details[] = "MISSING PRIMARY AGENT!";
        if (!$has_update) $details[] = "Missing update agent";
    } else {
        $status = '❌ CRITICAL';
        $critical_firewalls++;
        $details[] = "NO AGENTS RESPONDING!";
    }
    
    printf("FW #%d (%s): %s\n", $firewall_id, $hostname, $status);
    if (!empty($details)) {
        foreach ($details as $detail) {
            echo "    $detail\n";
        }
    }
    echo "\n";
}

// Summary
echo "=== Summary ===\n";
echo "Total Firewalls: $total_firewalls\n";
echo "Healthy (both agents): $healthy_firewalls ✅\n";
echo "Warning (one agent): $warning_firewalls ⚠️\n";
echo "Critical (no agents): $critical_firewalls ❌\n";
echo "\n";

// Recommendations
if ($warning_firewalls > 0 || $critical_firewalls > 0) {
    echo "=== Action Required ===\n";
    
    // Find firewalls missing agents
    $missing_stmt = $DB->query("
        SELECT 
            f.id,
            f.hostname,
            COUNT(DISTINCT fa.agent_type) as agent_count,
            GROUP_CONCAT(DISTINCT fa.agent_type) as active_agents
        FROM firewalls f
        LEFT JOIN firewall_agents fa ON f.id = fa.firewall_id
            AND fa.last_checkin > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        GROUP BY f.id, f.hostname
        HAVING agent_count < 2
    ");
    
    $missing = $missing_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($missing as $fw) {
        $agents = $fw['active_agents'] ?? 'none';
        echo "\nFirewall #{$fw['id']} ({$fw['hostname']}):\n";
        echo "  Active agents: $agents\n";
        echo "  Deploy with: ./deploy_dual_agent.sh {$fw['id']} root <hostname>\n";
    }
}

echo "\n=== Done ===\n";

// Exit code for scripting
if ($critical_firewalls > 0) {
    exit(2); // Critical
} elseif ($warning_firewalls > 0) {
    exit(1); // Warning
} else {
    exit(0); // OK
}
