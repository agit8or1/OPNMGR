<?php
// Settings > Scheduled Tasks & Housekeeping
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();

$page_title = "Scheduled Tasks & Housekeeping";
include 'inc/header.php';

// Get tasks from database
function getTasks() {
    try {
        $result = db()->query("SELECT id, task_name as name, schedule, description, enabled FROM scheduled_tasks ORDER BY id");
        return $result->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

$cron_tasks = getTasks();
?>

<style>
body {
    background: #1a1d23;
    color: #e0e0e0;
}
.settings-container {
    max-width: 1000px;
    margin: 30px auto;
    padding: 0 20px;
}
.settings-card {
    background: #2d3139;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    border: 1px solid #3a3f4b;
}
.settings-card h2 {
    color: #4fc3f7;
    border-bottom: 3px solid #3498db;
    padding-bottom: 10px;
    margin-bottom: 20px;
}
.task-item {
    background: #363a45;
    border-left: 4px solid #3498db;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 4px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.task-info h4 {
    color: #4fc3f7;
    margin: 0 0 5px 0;
}
.task-info p {
    color: #95a5a6;
    margin: 0;
    font-size: 14px;
}
.task-schedule {
    color: #81c784;
    font-size: 14px;
    font-weight: 600;
}
.task-toggle {
    display: flex;
    align-items: center;
    gap: 15px;
}
.toggle-switch {
    width: 50px;
    height: 25px;
    background: #555;
    border-radius: 25px;
    cursor: pointer;
    position: relative;
    transition: background 0.3s;
}
.toggle-switch.active {
    background: #27ae60;
}
.toggle-switch::after {
    content: '';
    position: absolute;
    width: 21px;
    height: 21px;
    background: white;
    border-radius: 50%;
    top: 2px;
    left: 2px;
    transition: left 0.3s;
}
.toggle-switch.active::after {
    left: 27px;
}
.status-badge {
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.status-active {
    background: #27ae60;
    color: white;
}
.status-inactive {
    background: #e74c3c;
    color: white;
}
.status-loading {
    background: #3498db;
    color: white;
}
.info-section {
    background: #1a1d23;
    border-left: 4px solid #f39c12;
    padding: 15px;
    margin-top: 20px;
    border-radius: 4px;
}
.info-section h5 {
    color: #f39c12;
    margin-top: 0;
}
.info-section p {
    color: #95a5a6;
    font-size: 14px;
    margin: 10px 0;
}
.error-message {
    background: #e74c3c;
    color: white;
    padding: 12px;
    border-radius: 4px;
    margin-bottom: 15px;
}
.success-message {
    background: #27ae60;
    color: white;
    padding: 12px;
    border-radius: 4px;
    margin-bottom: 15px;
}
</style>

<div class="settings-container">
    <div class="settings-card">
        <h2><i class="fa fa-clock me-2"></i> Scheduled Tasks & Housekeeping</h2>
        <p>Manage automatic maintenance and monitoring tasks. All tasks run in the background on the server.</p>
        
        <div id="message-container"></div>
        
        <div class="task-items-list">
            <?php if (empty($cron_tasks)): ?>
                <p style="color: #95a5a6;">Loading tasks...</p>
            <?php else: ?>
                <?php foreach ($cron_tasks as $task): ?>
                    <div class="task-item">
                        <div class="task-info">
                            <h4><?= htmlspecialchars($task['name']) ?></h4>
                            <p><?= htmlspecialchars($task['description']) ?></p>
                            <p class="task-schedule">
                                <i class="fa fa-clock-o me-1"></i> 
                                <?= htmlspecialchars($task['schedule']) ?>
                            </p>
                        </div>
                        <div class="task-toggle">
                            <span class="status-badge <?= $task['enabled'] ? 'status-active' : 'status-inactive' ?>">
                                <?= $task['enabled'] ? 'ACTIVE' : 'INACTIVE' ?>
                            </span>
                            <div class="toggle-switch <?= $task['enabled'] ? 'active' : '' ?>" 
                                 onclick="toggleTask(this, <?= (int)$task['id'] ?>, '<?= htmlspecialchars($task['name']) ?>')"
                                 data-task-id="<?= (int)$task['id'] ?>">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="info-section">
            <h5><i class="fa fa-info-circle me-2"></i> Important Notes</h5>
            <p><strong>Backups:</strong> Firewall configs are backed up daily at 2:00 AM. Essential for disaster recovery.</p>
            <p><strong>Health Checks:</strong> Firewall connectivity monitored in real-time. Alerts if firewall goes offline.</p>
            <p><strong>Tunnel Cleanup:</strong> Expired SSH tunnels removed every 5 minutes to free ports and system resources.</p>
            <p><strong>Report Housekeeping:</strong> AI scan reports older than 30 days automatically deleted to save storage.</p>
            <p><strong>Warning:</strong> Disabling tasks may impact system reliability and security monitoring.</p>
        </div>
    </div>
</div>

<script>
async function toggleTask(element, taskId, taskName) {
    const isCurrentlyActive = element.classList.contains('active');
    const newState = isCurrentlyActive ? 0 : 1;
    const statusBadge = element.previousElementSibling;
    
    // Show loading state
    const originalBadgeText = statusBadge.textContent;
    statusBadge.textContent = 'UPDATING...';
    statusBadge.classList.add('status-loading');
    
    try {
        const response = await fetch('/api/manage_tasks.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                task_id: taskId,
                enabled: newState
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update UI
            element.classList.toggle('active');
            if (newState === 1) {
                element.classList.add('active');
                statusBadge.textContent = 'ACTIVE';
                statusBadge.classList.remove('status-inactive', 'status-loading');
                statusBadge.classList.add('status-active');
            } else {
                element.classList.remove('active');
                statusBadge.textContent = 'INACTIVE';
                statusBadge.classList.remove('status-active', 'status-loading');
                statusBadge.classList.add('status-inactive');
            }
            
            showMessage(`✓ ${taskName} ${newState ? 'enabled' : 'disabled'} successfully`, 'success');
        } else {
            statusBadge.textContent = originalBadgeText;
            statusBadge.classList.remove('status-loading');
            statusBadge.classList.add(isCurrentlyActive ? 'status-active' : 'status-inactive');
            showMessage(`Error: ${data.message || 'Failed to update task'}`, 'error');
        }
    } catch (error) {
        statusBadge.textContent = originalBadgeText;
        statusBadge.classList.remove('status-loading');
        statusBadge.classList.add(isCurrentlyActive ? 'status-active' : 'status-inactive');
        showMessage(`Error: ${error.message}`, 'error');
    }
}

function showMessage(message, type) {
    const container = document.getElementById('message-container');
    const className = type === 'success' ? 'success-message' : 'error-message';
    const messageDiv = document.createElement('div');
    messageDiv.className = className;
    messageDiv.textContent = message;
    container.innerHTML = '';
    container.appendChild(messageDiv);
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        messageDiv.style.opacity = '0';
        messageDiv.style.transition = 'opacity 0.3s';
        setTimeout(() => {
            messageDiv.remove();
        }, 300);
    }, 5000);
}
</script>

<?php include 'inc/footer.php'; ?>
