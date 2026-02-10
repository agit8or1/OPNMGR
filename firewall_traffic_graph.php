<?php
/**
 * Firewall Traffic Graph
 * Displays WAN traffic statistics with selectable time ranges
 */

require_once __DIR__ . '/inc/bootstrap.php';

requireLogin();

$firewall_id = (int)($_GET['id'] ?? 0);
$days = (int)($_GET['days'] ?? 7);

// Validate days parameter
if (!in_array($days, [1, 7, 14, 30])) {
    $days = 7;
}

// Get firewall details
$stmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    http_response_code(404);
    die('Firewall not found');
}

// Get traffic data for the selected period
$stmt = db()->prepare('
    SELECT 
        DATE_FORMAT(recorded_at, "%Y-%m-%d %H:00:00") as time_bucket,
        wan_interface,
        SUM(bytes_in) as total_bytes_in,
        SUM(bytes_out) as total_bytes_out,
        SUM(packets_in) as total_packets_in,
        SUM(packets_out) as total_packets_out,
        COUNT(*) as sample_count
    FROM firewall_traffic_stats
    WHERE firewall_id = ?
    AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY time_bucket, wan_interface
    ORDER BY time_bucket ASC
');
$stmt->execute([$firewall_id, $days]);
$traffic_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for Chart.js
$labels = [];
$data_in = [];
$data_out = [];

foreach ($traffic_data as $row) {
    $labels[] = $row['time_bucket'];
    // Convert bytes to MB
    $data_in[] = round($row['total_bytes_in'] / 1024 / 1024, 2);
    $data_out[] = round($row['total_bytes_out'] / 1024 / 1024, 2);
}

$labels_json = json_encode($labels);
$data_in_json = json_encode($data_in);
$data_out_json = json_encode($data_out);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Traffic Graph - <?php echo htmlspecialchars($firewall['hostname']); ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .graph-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .controls {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .time-selector {
            display: flex;
            gap: 10px;
        }
        .time-selector button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            cursor: pointer;
            border-radius: 4px;
        }
        .time-selector button.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        .stat-card {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            text-align: center;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <?php include 'inc/header.php'; ?>
    
    <div class="graph-container">
        <div class="controls">
            <h2>Traffic Statistics - <?php echo htmlspecialchars($firewall['hostname']); ?></h2>
            <div class="time-selector">
                <button class="<?php echo $days == 1 ? 'active' : ''; ?>" onclick="changePeriod(1)">24 Hours</button>
                <button class="<?php echo $days == 7 ? 'active' : ''; ?>" onclick="changePeriod(7)">7 Days</button>
                <button class="<?php echo $days == 14 ? 'active' : ''; ?>" onclick="changePeriod(14)">14 Days</button>
                <button class="<?php echo $days == 30 ? 'active' : ''; ?>" onclick="changePeriod(30)">30 Days</button>
            </div>
        </div>
        
        <canvas id="trafficChart" width="1200" height="400"></canvas>
        
        <div class="stats-summary">
            <?php
            $total_in = array_sum($data_in);
            $total_out = array_sum($data_out);
            $avg_in = count($data_in) > 0 ? $total_in / count($data_in) : 0;
            $avg_out = count($data_out) > 0 ? $total_out / count($data_out) : 0;
            ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($total_in, 0); ?> MB</div>
                <div class="stat-label">Total Inbound</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($total_out, 0); ?> MB</div>
                <div class="stat-label">Total Outbound</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($avg_in, 1); ?> MB/hr</div>
                <div class="stat-label">Avg Inbound</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($avg_out, 1); ?> MB/hr</div>
                <div class="stat-label">Avg Outbound</div>
            </div>
        </div>
        
        <p style="text-align: center; margin-top: 20px;">
            <a href="firewall_details.php?id=<?php echo $firewall_id; ?>" class="button">← Back to Firewall Details</a>
        </p>
    </div>
    
    <script>
        const ctx = document.getElementById('trafficChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo $labels_json; ?>,
                datasets: [
                    {
                        label: 'Inbound (MB)',
                        data: <?php echo $data_in_json; ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Outbound (MB)',
                        data: <?php echo $data_out_json; ?>,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'WAN Traffic (Last <?php echo $days; ?> Day<?php echo $days > 1 ? 's' : ''; ?>)'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' MB';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Traffic (MB)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    }
                }
            }
        });
        
        function changePeriod(days) {
            window.location.href = '?id=<?php echo $firewall_id; ?>&days=' + days;
        }
    </script>
</body>
</html>
