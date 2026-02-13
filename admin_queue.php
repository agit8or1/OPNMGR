<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/timezone_selector.php';
requireLogin();
requireAdmin();
include __DIR__ . '/inc/header.php';
?>

<style>
.card {
    background-color: var(--card) !important;
    border: 1px solid var(--border, rgba(255,255,255,0.08)) !important;
    color: var(--text, #f1f5f9) !important;
}
.card-header {
    background: var(--header, linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)) !important;
    color: white !important;
    border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)) !important;
}
.table {
    color: var(--text, #f1f5f9) !important;
}
.table-striped > tbody > tr:nth-of-type(odd) > td {
    background-color: rgba(255,255,255,0.02) !important;
}
.table thead th {
    background-color: var(--card) !important;
    color: var(--text, #f1f5f9) !important;
    border-color: var(--border, rgba(255,255,255,0.08)) !important;
}
.table td, .table th {
    border-color: var(--border, rgba(255,255,255,0.08)) !important;
}
.table > :not(caption) > * > * {
    background-color: var(--card) !important;
    color: var(--text, #f1f5f9) !important;
}
.nav-tabs .nav-link {
    background-color: var(--card) !important;
    border-color: var(--border, rgba(255,255,255,0.08)) !important;
    color: var(--text, #f1f5f9) !important;
}
.nav-tabs .nav-link.active {
    background-color: var(--accent, #3b82f6) !important;
    border-color: var(--accent, #3b82f6) !important;
    color: white !important;
}
</style>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-list-task text-primary"></i> Queue Management</h2>
                <div>
                    <span id="lastUpdate" class="text-muted me-3"></span>
                    <button class="btn btn-outline-primary btn-sm" onclick="refreshData()">
                        <i class="bi bi-arrow-clockwise" id="refreshIcon"></i> Refresh
                    </button>
                </div>
            </div>
            
            <!-- Timezone Selector -->
            <div class="row mb-3">
                <div class="col-12">
                    <?php renderTimezoneSelector(); ?>
                </div>
            </div>

            <!-- Navigation Tabs -->
            <ul class="nav nav-tabs mb-4" id="queueTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="command-queue-tab" data-bs-toggle="tab" data-bs-target="#command-queue" type="button" role="tab">
                        <i class="bi bi-terminal"></i> Command Queue
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="http-queue-tab" data-bs-toggle="tab" data-bs-target="#http-queue" type="button" role="tab">
                        <i class="bi bi-globe"></i> HTTP Queue
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="queueTabContent">
                <!-- Command Queue Tab -->
                <div class="tab-pane fade show active" id="command-queue" role="tabpanel">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-terminal"></i> Command Queue</h5>
                            <button class="btn btn-sm btn-outline-danger" onclick="clearAllCommands()">
                                <i class="bi bi-trash"></i> Clear All
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="commandQueueContent">
                                <div class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted">Loading command queue...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- HTTP Queue Tab -->
                <div class="tab-pane fade" id="http-queue" role="tabpanel">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-globe"></i> HTTP Request Queue</h5>
                            <button class="btn btn-sm btn-outline-danger" onclick="clearAllRequests()">
                                <i class="bi bi-trash"></i> Clear All
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="httpQueueContent">
                                <div class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted">Loading HTTP queue...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Queue Summary -->
            <div class="row mt-4">
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <h6 class="card-title text-warning mb-1">Pending</h6>
                            <h3 class="mb-0" id="pendingCount">-</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <h6 class="card-title text-primary mb-1">Sent</h6>
                            <h3 class="mb-0" id="sentCount">-</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <h6 class="card-title text-success mb-1">Completed</h6>
                            <h3 class="mb-0" id="completedCount">-</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <h6 class="card-title text-danger mb-1">Failed</h6>
                            <h3 class="mb-0" id="failedCount">-</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <h6 class="card-title text-secondary mb-1">Cancelled</h6>
                            <h3 class="mb-0" id="cancelledCount">-</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <h6 class="card-title text-info mb-1">Total</h6>
                            <h3 class="mb-0" id="totalCount">-</h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Retention / Purge -->
            <div class="row mt-3">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <h6 class="mb-0"><i class="bi bi-clock-history"></i> Data Retention</h6>
                            <div class="d-flex align-items-center gap-2">
                                <span id="purgeableInfo" class="text-muted small"></span>
                                <span id="stuckInfo" class="small"></span>
                                <button class="btn btn-sm btn-outline-warning" onclick="purgeOldRecords()" id="purgeBtn" disabled>
                                    <i class="bi bi-trash3"></i> Purge Old Records
                                </button>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <small class="text-muted">
                                Auto-cleanup runs hourly. Retention: completed 7 days, failed/cancelled 14 days.
                                Stuck commands (pending >1h, sent >30m) are auto-failed. Oldest record: <span id="oldestRecord">-</span>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let refreshInterval;
let isRefreshing = false;

function formatTimestamp(timestamp) {
    if (!timestamp) return 'Never';
    
    // Get the selected timezone from the timezone selector
    const timezoneSelect = document.querySelector('select[name="display_timezone"]');
    const selectedTimezone = timezoneSelect ? timezoneSelect.value : 'America/New_York';
    
    const date = new Date(timestamp);
    
    // Format in the selected timezone with 12-hour time
    const options = {
        timeZone: selectedTimezone,
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    };
    
    return date.toLocaleString('en-US', options);
}

function refreshData() {
    if (isRefreshing) return;

    isRefreshing = true;
    const refreshIcon = document.getElementById('refreshIcon');
    refreshIcon.classList.add('refresh-indicator');

    Promise.all([
        fetch('/api/admin_queue.php?action=get_global_queue_summary'),
        fetch('/api/admin_queue.php?action=get_command_queue'),
        fetch('/api/admin_queue.php?action=get_request_queue')
    ])
    .then(responses => Promise.all(responses.map(r => r.json())))
    .then(([summary, commandQueue, httpQueue]) => {
        updateSummary(summary);
        updateCommandQueue(commandQueue);
        updateHttpQueue(httpQueue);

        document.getElementById('lastUpdate').textContent =
            'Last updated: ' + new Date().toLocaleTimeString();
    })
    .catch(error => {
        console.error('Error refreshing data:', error);
        showError('Failed to refresh data. Please try again.');
    })
    .finally(() => {
        isRefreshing = false;
        refreshIcon.classList.remove('refresh-indicator');
    });
}

function updateSummary(data) {
    if (data.success) {
        document.getElementById('pendingCount').textContent = data.pending || 0;
        document.getElementById('sentCount').textContent = data.sent || 0;
        document.getElementById('completedCount').textContent = data.completed || 0;
        document.getElementById('failedCount').textContent = data.failed || 0;
        document.getElementById('cancelledCount').textContent = data.cancelled || 0;
        document.getElementById('totalCount').textContent = data.total || 0;

        // Update purge info
        const purgeable = parseInt(data.purgeable_total) || 0;
        const stuck = parseInt(data.stuck) || 0;
        const purgeInfo = document.getElementById('purgeableInfo');
        const purgeBtn = document.getElementById('purgeBtn');
        const stuckInfo = document.getElementById('stuckInfo');

        if (purgeable > 0) {
            purgeInfo.textContent = `${purgeable} purgeable records`;
            purgeBtn.disabled = false;
        } else {
            purgeInfo.textContent = 'No old records to purge';
            purgeBtn.disabled = true;
        }

        if (stuck > 0) {
            stuckInfo.innerHTML = `<span class="badge bg-danger">${stuck} stuck</span>`;
        } else {
            stuckInfo.innerHTML = '';
        }

        if (data.oldest_record) {
            document.getElementById('oldestRecord').textContent = formatTimestamp(data.oldest_record);
        }
    }
}

function updateCommandQueue(data) {
    const container = document.getElementById('commandQueueContent');
    
    if (!data.success) {
        container.innerHTML = '<div class="alert alert-danger">Failed to load command queue</div>';
        return;
    }
    
    if (!data.commands || data.commands.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No commands in queue</div>';
        return;
    }
    
    let html = '<div class="table-responsive"><table class="table table-striped">';
    html += '<thead><tr><th>ID</th><th>Command</th><th>Status</th><th>Created</th><th>Updated</th><th>Actions</th></tr></thead>';
    html += '<tbody>';
    
    data.commands.forEach(cmd => {
        const statusClass = cmd.status === 'completed' ? 'success' : 
                           cmd.status === 'failed' ? 'danger' : 
                           cmd.status === 'pending' ? 'warning' : 'info';
        
        html += `<tr>
            <td>${cmd.id}</td>
            <td><code class="small">${escapeHtml(cmd.command)}</code></td>
            <td><span class="badge bg-${statusClass}">${cmd.status}</span></td>
            <td>${formatTimestamp(cmd.created_at)}</td>
            <td>${formatTimestamp(cmd.updated_at)}</td>
            <td>
                ${cmd.status === 'pending' ? 
                    `<button class="btn btn-sm btn-danger" onclick="cancelCommand(${cmd.id})">Cancel</button>` : 
                    (cmd.status === 'completed' || cmd.status === 'failed' || cmd.status === 'sent') ?
                        `<button class="btn btn-sm btn-danger" onclick="deleteCommand(${cmd.id})">Delete</button>` : ''
                }
                ${cmd.output ? `<button class="btn btn-sm btn-info ms-1" onclick="viewOutput(${cmd.id})">View Output</button>` : ''}
            </td>
        </tr>`;
    });
    
    html += '</tbody></table></div>';
    container.innerHTML = html;
}

function updateHttpQueue(data) {
    const container = document.getElementById('httpQueueContent');
    
    if (!data.success) {
        container.innerHTML = '<div class="alert alert-danger">Failed to load HTTP queue</div>';
        return;
    }
    
    if (!data.requests || data.requests.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No HTTP requests in queue</div>';
        return;
    }
    
    let html = '<div class="table-responsive"><table class="table table-striped">';
    html += '<thead><tr><th>ID</th><th>URL</th><th>Method</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>';
    html += '<tbody>';
    
    data.requests.forEach(req => {
        const statusClass = req.status === 'sent' ? 'success' : 
                           req.status === 'failed' ? 'danger' : 
                           req.status === 'pending' ? 'warning' : 'info';
        
        html += `<tr>
            <td>${req.id}</td>
            <td>${escapeHtml(req.url)}</td>
            <td><span class="badge bg-secondary">${req.method}</span></td>
            <td><span class="badge bg-${statusClass}">${req.status}</span></td>
            <td>${formatTimestamp(req.created_at)}</td>
            <td>
                ${req.status === 'pending' ? 
                    `<button class="btn btn-sm btn-danger" onclick="cancelRequest(${req.id})">Cancel</button>` : 
                    (req.status === 'sent' || req.status === 'failed' || req.status === 'completed') ?
                        `<button class="btn btn-sm btn-danger" onclick="deleteRequest(${req.id})">Delete</button>` : ''
                }
                ${req.response ? `<button class="btn btn-sm btn-info ms-1" onclick="viewResponse(${req.id})">View Response</button>` : ''}
            </td>
        </tr>`;
    });
    
    html += '</tbody></table></div>';
    container.innerHTML = html;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showError(message) {
    // You could implement a toast notification here
    alert(message);
}

function cancelCommand(id) {
    if (!confirm('Are you sure you want to cancel this command?')) return;
    
    fetch('/api/admin_queue.php?action=cancel_command', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            refreshData();
        } else {
            showError(data.error || 'Failed to cancel command');
        }
    })
    .catch(error => showError('Network error'));
}

function deleteCommand(id) {
    if (!confirm('Are you sure you want to delete this command from the queue?')) return;
    
    fetch('/api/admin_queue.php?action=delete_command', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            refreshData();
        } else {
            showError(data.error || 'Failed to delete command');
        }
    })
    .catch(error => showError('Network error'));
}

function clearAllCommands() {
    if (!confirm('Are you sure you want to clear ALL commands from the queue? This action cannot be undone.')) return;
    
    fetch('/api/admin_queue.php?action=clear_command_queue', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'}
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            refreshData();
            alert('Command queue cleared successfully');
        } else {
            showError(data.error || 'Failed to clear command queue');
        }
    })
    .catch(error => showError('Network error'));
}

function cancelRequest(id) {
    if (!confirm('Are you sure you want to cancel this request?')) return;
    
    fetch('/api/admin_queue.php?action=cancel_request', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            refreshData();
        } else {
            showError(data.error || 'Failed to cancel request');
        }
    })
    .catch(error => showError('Network error'));
}

function deleteRequest(id) {
    if (!confirm('Are you sure you want to delete this request from the queue?')) return;
    
    fetch('/api/admin_queue.php?action=delete_request', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            refreshData();
        } else {
            showError(data.error || 'Failed to delete request');
        }
    })
    .catch(error => showError('Network error'));
}

function clearAllRequests() {
    if (!confirm('Are you sure you want to clear ALL requests from the queue? This action cannot be undone.')) return;
    
    fetch('/api/admin_queue.php?action=clear_request_queue', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'}
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            refreshData();
            alert('Request queue cleared successfully');
        } else {
            showError(data.error || 'Failed to clear request queue');
        }
    })
    .catch(error => showError('Network error'));
}

function purgeOldRecords() {
    if (!confirm('Purge old command records?\n\nThis will delete:\n- Completed commands older than 7 days\n- Failed/cancelled commands older than 14 days\n\nThis cannot be undone.')) return;

    fetch('/api/admin_queue.php?action=purge_old_commands', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({retention_days: 7})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const p = data.purged;
            alert(`Purged ${data.total} records:\n- ${p.completed} completed\n- ${p.failed} failed\n- ${p.cancelled} cancelled`);
            refreshData();
        } else {
            showError(data.error || 'Failed to purge records');
        }
    })
    .catch(error => showError('Network error'));
}

function viewOutput(id) {
    fetch(`/api/admin_queue.php?action=get_command_output&id=${id}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show output in a modal or new window
            const output = data.output || 'No output available';
            alert('Command Output:\n\n' + output);
        } else {
            showError(data.error || 'Failed to get output');
        }
    })
    .catch(error => showError('Network error'));
}

function viewResponse(id) {
    fetch(`/api/admin_queue.php?action=get_request_response&id=${id}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show response in a modal or new window
            const response = data.response || 'No response available';
            alert('HTTP Response:\n\n' + response);
        } else {
            showError(data.error || 'Failed to get response');
        }
    })
    .catch(error => showError('Network error'));
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    refreshData();
    
    // Auto-refresh every 5 seconds
    refreshInterval = setInterval(refreshData, 5000);
    
    // Add timezone change listener
    const timezoneSelect = document.querySelector('select[name="display_timezone"]');
    if (timezoneSelect) {
        timezoneSelect.addEventListener('change', function() {
            // Refresh data to update all timestamps with new timezone
            refreshData();
        });
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
});
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>