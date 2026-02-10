<?php
require_once __DIR__ . '/inc/bootstrap_agent.php';

header('Content-Type: application/json');

try {
    // Clean up expired tokens automatically
    $cleanup = db()->prepare("DELETE FROM enrollment_tokens WHERE expires_at < NOW()");
    $cleanup->execute();
    $cleaned = $cleanup->rowCount();
    
    // Get request parameters
    $days = intval($_POST['days'] ?? $_GET['days'] ?? 1);
    $days = max(1, min(30, $days)); // Limit between 1-30 days
    
    // Generate a secure token
    $token = bin2hex(random_bytes(32)); // 64-character hex token
    
    // Insert new token
    $stmt = db()->prepare("INSERT INTO enrollment_tokens (token, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL ? DAY))");
    $stmt->execute([$token, $days]);
    
    // Get token stats
    $stats = db()->query("SELECT 
        COUNT(*) as total_tokens,
        SUM(CASE WHEN used = 1 THEN 1 ELSE 0 END) as used_tokens,
        SUM(CASE WHEN used = 0 AND expires_at > NOW() THEN 1 ELSE 0 END) as active_tokens
        FROM enrollment_tokens")->fetch(PDO::FETCH_ASSOC);
    
    $response = [
        'success' => true,
        'token' => $token,
        'expires_in_days' => $days,
        'expires_at' => date('Y-m-d H:i:s', strtotime("+{$days} days")),
        'cleaned_expired' => $cleaned,
        'stats' => $stats,
        'enrollment_url' => "https://opn.agit8or.net/enroll_firewall.php?action=download&token={$token}",
        'command' => "wget -q -O /tmp/opnsense_enroll.sh \"https://opn.agit8or.net/enroll_firewall.php?action=download&token={$token}\" && chmod +x /tmp/opnsense_enroll.sh && bash /tmp/opnsense_enroll.sh"
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("generate_enrollment_token.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>