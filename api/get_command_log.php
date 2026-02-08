<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
requireLogin();

$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$timezone = $_GET['timezone'] ?? 'America/New_York'; // Default to EST

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid firewall ID']);
    exit;
}

// Validate timezone
try {
    new DateTimeZone($timezone);
} catch (Exception $e) {
    $timezone = 'America/New_York'; // Fallback to EST if invalid
}

header('Content-Type: application/json');

try {
    $stmt = $DB->prepare('
        SELECT fc.*, f.hostname 
        FROM firewall_commands fc
        LEFT JOIN firewalls f ON fc.firewall_id = f.id
        WHERE fc.firewall_id = ? 
        ORDER BY fc.created_at DESC 
        LIMIT 20
    ');
    $stmt->execute([$firewall_id]);
    $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert timestamps to requested timezone
    foreach ($commands as &$command) {
        if (!empty($command['created_at'])) {
            $dt = new DateTime($command['created_at'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($timezone));
            $command['created_at_formatted'] = $dt->format('M j, Y H:i:s T');
        }
        if (!empty($command['sent_at'])) {
            $dt = new DateTime($command['sent_at'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($timezone));
            $command['sent_at_formatted'] = $dt->format('M j, Y H:i:s T');
        }
        if (!empty($command['completed_at'])) {
            $dt = new DateTime($command['completed_at'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($timezone));
            $command['completed_at_formatted'] = $dt->format('M j, Y H:i:s T');
        }
    }
    
    echo json_encode([
        'success' => true,
        'commands' => $commands,
        'timezone' => $timezone
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}