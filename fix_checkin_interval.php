<?php
// Fix Check-in Interval Issue
// Ensures all firewalls have proper check-in intervals set

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/logging.php';

echo "=== Fixing Agent Check-in Intervals ===\n\n";

// Check current intervals
echo "Current firewall check-in intervals:\n";
$stmt = $DB->query("SELECT id, hostname, checkin_interval, TIMESTAMPDIFF(SECOND, last_checkin, NOW()) as seconds_since_checkin, status FROM firewalls ORDER BY id");
$firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($firewalls as $fw) {
    $interval = $fw['checkin_interval'] ?? 'NULL';
    $since = $fw['seconds_since_checkin'] ?? 'N/A';
    echo sprintf("  FW #%d (%s): interval=%s, last_checkin=%s seconds ago, status=%s\n",
        $fw['id'], $fw['hostname'], $interval, $since, $fw['status']);
}

echo "\n";

// Update all NULL or 0 intervals to sensible defaults
echo "Setting default intervals for firewalls with NULL or 0...\n";
$update_stmt = $DB->prepare("UPDATE firewalls SET checkin_interval = 120 WHERE checkin_interval IS NULL OR checkin_interval = 0 OR checkin_interval < 60");
$result = $update_stmt->execute();
$updated = $update_stmt->rowCount();

if ($updated > 0) {
    echo "  ✓ Updated $updated firewall(s) to 120 second interval\n";
    log_info('system', "Fixed check-in intervals for $updated firewall(s)", ['updated_count' => $updated]);
} else {
    echo "  ✓ All firewalls already have valid intervals\n";
}

echo "\n";

// Show after results
echo "Updated firewall intervals:\n";
$stmt = $DB->query("SELECT id, hostname, checkin_interval FROM firewalls ORDER BY id");
$firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($firewalls as $fw) {
    $interval = $fw['checkin_interval'];
    echo sprintf("  FW #%d (%s): %d seconds (%d minutes)\n",
        $fw['id'], $fw['hostname'], $interval, floor($interval / 60));
}

echo "\n=== Recommendations ===\n";
echo "- Primary agents: 120-180 seconds (2-3 minutes)\n";
echo "- Update agents: 300 seconds (5 minutes)\n";
echo "- High-priority firewalls: 60 seconds (1 minute)\n";
echo "- Low-priority firewalls: 300-600 seconds (5-10 minutes)\n";

echo "\n=== Done ===\n";
?>
