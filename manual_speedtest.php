<?php
/**
 * Manual Speedtest Trigger - FOOLPROOF METHOD
 * Directly runs speedtest by executing iperf3 on this server against known firewall IPs
 */

require_once __DIR__ . '/inc/bootstrap.php';

requireLogin();

$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$action = $_GET['action'] ?? 'show';

if ($action === 'run' && $firewall_id > 0) {
    // Get firewall info
    $stmt = db()->prepare("SELECT id, hostname, lan_ip, wan_ip FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$firewall) {
        die("Firewall not found");
    }

    // Try to run iperf3 test FROM the management server TO the firewall
    // This tests download speed (firewall receiving from server)
    $target_ip = $firewall['lan_ip'] ?: $firewall['wan_ip'];

    echo "<html><head><title>Manual Speedtest - " . htmlspecialchars($firewall['hostname']) . "</title></head><body>";
    echo "<h2>Running speedtest to " . htmlspecialchars($firewall['hostname']) . " (" . htmlspecialchars($target_ip) . ")</h2>";
    echo "<pre>";

    // Check if iperf3 server is running on firewall
    echo "Testing if iperf3 server is running on firewall...\n";
    $test_cmd = "timeout 5 nc -z " . escapeshellarg($target_ip) . " 5201 2>&1";
    exec($test_cmd, $test_output, $test_result);

    if ($test_result === 0) {
        echo "iperf3 server is running on firewall!\n\n";
        echo "Running iperf3 test...\n";

        // Run iperf3 test
        $iperf_cmd = "iperf3 -c " . escapeshellarg($target_ip) . " -t 10 -J 2>&1";
        exec($iperf_cmd, $iperf_output, $iperf_result);
        $iperf_json = implode("\n", $iperf_output);

        if ($iperf_result === 0 && !empty($iperf_json)) {
            $data = json_decode($iperf_json, true);
            if ($data && isset($data['end'])) {
                $download_bps = $data['end']['sum_received']['bits_per_second'] ?? 0;
                $upload_bps = $data['end']['sum_sent']['bits_per_second'] ?? 0;
                $download_mbps = round($download_bps / 1000000, 2);
                $upload_mbps = round($upload_bps / 1000000, 2);

                echo "\n=== SPEEDTEST RESULTS ===\n";
                echo "Download: {$download_mbps} Mbps\n";
                echo "Upload: {$upload_mbps} Mbps\n";
                echo "=========================\n\n";

                // Save to database
                $stmt = db()->prepare("
                    INSERT INTO bandwidth_tests (firewall_id, test_type, test_status, download_speed, upload_speed, latency, test_server, tested_at)
                    VALUES (?, 'manual', 'completed', ?, ?, 0, 'Manual-LAN', NOW())
                ");
                $stmt->execute([$firewall_id, $download_mbps, $upload_mbps]);
                echo "Results saved to database!\n";
            } else {
                echo "Failed to parse iperf3 results\n";
                echo $iperf_json;
            }
        } else {
            echo "iperf3 test failed\n";
            echo $iperf_json;
        }
    } else {
        echo "iperf3 server is NOT running on firewall.\n";
        echo "You need to start iperf3 server on the firewall first:\n";
        echo "  SSH to firewall and run: iperf3 -s -D\n\n";
        echo "Or queue a command to start it:\n";
        $start_cmd = "pkg install -y iperf3 2>/dev/null; iperf3 -s -D";
        echo "  " . $start_cmd . "\n";
    }

    echo "</pre>";
    echo "<p><a href='?'>Back to firewall list</a></p>";
    echo "</body></html>";
    exit;
}

// Show firewall list
$stmt = db()->query("SELECT id, hostname, lan_ip, wan_ip, last_checkin FROM firewalls ORDER BY hostname");
$firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/inc/header.php';
?>

<div class="card card-dark">
    <div class="card-body">
        <h3>Manual Speedtest Tool (Foolproof Method)</h3>
        <p>This tool runs speedtests directly from the management server to each firewall.</p>
        <p><strong>Note:</strong> Firewalls must have iperf3 server running on port 5201.</p>

        <table class="table table-dark table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Hostname</th>
                    <th>LAN IP</th>
                    <th>WAN IP</th>
                    <th>Last Checkin</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($firewalls as $fw): ?>
                <tr>
                    <td><?php echo $fw['id']; ?></td>
                    <td><?php echo htmlspecialchars($fw['hostname']); ?></td>
                    <td><?php echo htmlspecialchars($fw['lan_ip']); ?></td>
                    <td><?php echo htmlspecialchars($fw['wan_ip']); ?></td>
                    <td><?php echo htmlspecialchars($fw['last_checkin']); ?></td>
                    <td>
                        <a href="?action=run&firewall_id=<?php echo $fw['id']; ?>" class="btn btn-primary btn-sm">
                            Run Speedtest
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
