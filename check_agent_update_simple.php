<?php
// Simple function to check for pending agent updates
function checkPendingAgentUpdate($firewall_id) {
    $stmt = db()->prepare('
        SELECT id, to_version, update_script
        FROM agent_updates
        WHERE firewall_id = ?
        AND status = "pending"
        ORDER BY created_at DESC
        LIMIT 1
    ');
    $stmt->execute([$firewall_id]);
    $update = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($update) {
        // Mark as downloading
        db()->prepare('UPDATE agent_updates SET status = ?, started_at = NOW() WHERE id = ?')
           ->execute(['downloading', $update['id']]);
           
        return [
            'update_available' => true,
            'latest_version' => $update['to_version'],
            'update_id' => $update['id'],
            'update_script' => $update['update_script']
        ];
    }
    
    return ['update_available' => false];
}
