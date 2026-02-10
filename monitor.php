<?php
require_once __DIR__ . '/inc/bootstrap_agent.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>OPNsense Health Monitor</title>
    <meta http-equiv="refresh" content="30">
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .firewall { background: #2a2a2a; margin: 10px 0; padding: 15px; border-radius: 5px; border-left: 4px solid #4CAF50; }
        .firewall.offline { border-left-color: #f44336; }
        .firewall.warning { border-left-color: #ff9800; }
        .status { font-weight: bold; }
        .online { color: #4CAF50; }
        .offline { color: #f44336; }
        .warning { color: #ff9800; }
        .header { text-align: center; margin-bottom: 30px; }
        .info { margin: 5px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔥 OPNsense Health Monitor</h1>
            <p>Auto-refreshes every 30 seconds</p>
            <p>Current time: <?php echo date('Y-m-d H:i:s'); ?> UTC</p>
        </div>

        <?php
        try {
            $stmt = db()->prepare('SELECT id, hostname, wan_ip, lan_ip, agent_version, version, last_checkin, status FROM firewalls ORDER BY hostname');
            $stmt->execute();
            $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($firewalls)) {
                echo '<div class="firewall"><p>No firewalls found in the system.</p></div>';
            } else {
                foreach ($firewalls as $fw) {
                    $lastCheckin = $fw['last_checkin'];
                    $minutesAgo = $lastCheckin ? round((time() - strtotime($lastCheckin)) / 60) : 999;
                    
                    $statusClass = 'offline';
                    $statusText = '🔴 Offline';
                    
                    if ($minutesAgo <= 5) {
                        $statusClass = 'online';
                        $statusText = '🟢 Online';
                    } elseif ($minutesAgo <= 15) {
                        $statusClass = 'warning';
                        $statusText = '🟡 Warning';
                    }
                    
                    $timeAgo = $minutesAgo < 60 ? "{$minutesAgo} min ago" : round($minutesAgo/60) . " hr ago";
                    if ($minutesAgo < 1) $timeAgo = "Just now";
                    if ($minutesAgo > 1440) $timeAgo = round($minutesAgo/1440) . " days ago";
                    
                    echo "<div class='firewall {$statusClass}'>";
                    echo "<h3>" . htmlspecialchars($fw['hostname'] ?: 'Unknown') . "</h3>";
                    echo "<div class='info'>Status: <span class='status {$statusClass}'>{$statusText}</span></div>";
                    echo "<div class='info'>Agent: " . htmlspecialchars($fw['agent_version'] ?: 'Unknown') . "</div>";
                    echo "<div class='info'>Last Checkin: {$timeAgo}</div>";
                    echo "<div class='info'>WAN IP: " . htmlspecialchars($fw['wan_ip'] ?: 'Unknown') . "</div>";
                    echo "<div class='info'>LAN IP: " . htmlspecialchars($fw['lan_ip'] ?: 'Unknown') . "</div>";
                    echo "<div class='info'>OPNsense: " . htmlspecialchars($fw['version'] ?: 'Unknown') . "</div>";
                    echo "</div>";
                }
                
                // Summary
                $total = count($firewalls);
                $online = 0;
                $offline = 0;
                foreach ($firewalls as $fw) {
                    $minutesAgo = $fw['last_checkin'] ? round((time() - strtotime($fw['last_checkin'])) / 60) : 999;
                    if ($minutesAgo <= 5) $online++;
                    elseif ($minutesAgo > 15) $offline++;
                }
                
                echo "<div class='firewall'>";
                echo "<h3>📊 System Summary</h3>";
                echo "<div class='info'>Total Firewalls: {$total}</div>";
                echo "<div class='info'>Online: <span class='online'>{$online}</span></div>";
                echo "<div class='info'>Offline: <span class='offline'>{$offline}</span></div>";
                echo "</div>";
            }
        } catch (Exception $e) {
            error_log("monitor.php error: " . $e->getMessage());
            echo "<div class='firewall'><p>An internal error occurred.</p></div>";
        }
        ?>
        
        <div class="firewall">
            <h3>🔗 Quick Links</h3>
            <div class="info"><a href="/system_status.php" style="color: #4CAF50;">JSON API Status</a></div>
            <div class="info"><a href="/" style="color: #4CAF50;">Main Dashboard</a></div>
        </div>
    </div>
</body>
</html>