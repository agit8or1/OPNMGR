<?php
/**
 * OPNsense Manager - Development Todo List
 * Track features, bugs, and improvements
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

// Check authentication
if (!check_authentication()) {
    header('HTTP/1.1 401 Unauthorized');
    echo "Authentication required";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Development Todo List - OPNsense Manager</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0d1117; color: #c9d1d9; }
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #30363d; padding-bottom: 20px; }
        h1 { color: #58a6ff; font-size: 28px; }
        .stats { display: flex; gap: 20px; }
        .stat { background: #161b22; padding: 10px 15px; border-radius: 6px; text-align: center; }
        .stat-num { font-size: 20px; font-weight: bold; color: #58a6ff; }
        .stat-label { font-size: 12px; color: #8b949e; }
        
        .filters { display: flex; gap: 10px; margin-bottom: 20px; }
        .filter-btn { padding: 8px 12px; border: 1px solid #30363d; background: #0d1117; color: #c9d1d9; border-radius: 6px; cursor: pointer; }
        .filter-btn.active { background: #58a6ff; color: #0d1117; border-color: #58a6ff; }
        
        .todo-list { display: flex; flex-direction: column; gap: 15px; }
        .todo-item { background: #161b22; border: 1px solid #30363d; border-radius: 6px; padding: 15px; display: flex; gap: 15px; }
        .todo-item.completed { opacity: 0.6; }
        .todo-item.high-priority { border-left: 4px solid #ff7b72; }
        .todo-item.medium-priority { border-left: 4px solid #d29922; }
        .todo-item.low-priority { border-left: 4px solid #58a6ff; }
        
        .checkbox { width: 20px; height: 20px; cursor: pointer; flex-shrink: 0; }
        .checkbox:checked { accent-color: #58a6ff; }
        
        .todo-content { flex: 1; }
        .todo-title { font-weight: bold; font-size: 14px; color: #c9d1d9; margin-bottom: 5px; }
        .todo-desc { font-size: 12px; color: #8b949e; line-height: 1.5; }
        
        .todo-meta { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; }
        .badge-priority { background: #161b22; border: 1px solid #30363d; }
        .badge-status { background: #161b22; border: 1px solid #30363d; }
        .badge-priority.high { background: #ff7b72; color: #0d1117; border: none; }
        .badge-status.completed { background: #3fb950; color: #0d1117; border: none; }
        .badge-status.in-progress { background: #58a6ff; color: #0d1117; border: none; }
        
        .new-todo { background: #161b22; border: 1px solid #30363d; border-radius: 6px; padding: 15px; margin-top: 20px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; color: #8b949e; margin-bottom: 5px; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 8px; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; color: #c9d1d9; font-family: monospace; }
        .form-group textarea { resize: vertical; min-height: 60px; }
        .btn { padding: 8px 12px; border: 1px solid #30363d; background: #238636; color: #fff; border-radius: 6px; cursor: pointer; }
        .btn:hover { background: #2ea043; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>📋 Development Todo List</h1>
                <p style="color: #8b949e; margin-top: 5px;">Track features, bugs, and improvements</p>
            </div>
            <div class="stats">
                <div class="stat">
                    <div class="stat-num" id="total-count">0</div>
                    <div class="stat-label">Total</div>
                </div>
                <div class="stat">
                    <div class="stat-num" id="completed-count">0</div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat">
                    <div class="stat-num" id="in-progress-count">0</div>
                    <div class="stat-label">In Progress</div>
                </div>
            </div>
        </div>

        <div class="filters">
            <button class="filter-btn active" onclick="filterTodos('all')">All</button>
            <button class="filter-btn" onclick="filterTodos('not-started')">Not Started</button>
            <button class="filter-btn" onclick="filterTodos('in-progress')">In Progress</button>
            <button class="filter-btn" onclick="filterTodos('completed')">Completed</button>
        </div>

        <div class="todo-list" id="todo-list">
            <!-- Todos will be loaded here -->
        </div>

        <div class="new-todo">
            <h3 style="margin-bottom: 15px; color: #58a6ff;">➕ Add New Todo</h3>
            <form onsubmit="addTodo(event)">
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" id="title" placeholder="Feature name or bug description" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="description" placeholder="Detailed notes..."></textarea>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Priority</label>
                        <select id="priority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select id="status">
                            <option value="not-started" selected>Not Started</option>
                            <option value="in-progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn">Add Todo</button>
            </form>
        </div>
    </div>

    <script>
        const todos = [
            {
                id: 1,
                title: "Fix System Stats Not Storing",
                description: "Agent v3.5.2 was sending system stats but handler had wrong column names. Database uses cpu_load_1min/memory_percent/disk_percent but code used cpu_usage/memory_usage/disk_usage. FIXED - system stats now storing successfully every 2 minutes.",
                priority: "high",
                status: "completed"
            },
            {
                id: 2,
                title: "Enable Web-Based Task Management",
                description: "Created scheduled_tasks table in database with default 5 tasks (Nightly Backups, Firewall Health Check, SSH Tunnel Cleanup, Proxy Session Cleanup, AI Report Housekeeping). Created /api/manage_tasks.php endpoint to list and enable/disable tasks. Tasks can now be toggled via web API.",
                priority: "high",
                status: "completed"
            },
            {
                id: 3,
                title: "Fix Traffic Graph Spike (12,000 Mbps)",
                description: "Fallback code was summing cumulative bytes instead of calculating rates. Removed broken SUM() query. Now always uses LAG() window function to calculate delta: (bytes_delta / time_delta) * 8 / 1000000 = Mbps. Graph now shows realistic 3-6 Mbps values.",
                priority: "high",
                status: "completed"
            },
            {
                id: 4,
                title: "Create Development Documentation",
                description: "Document all critical variables, database schema, known bugs, and data pipelines at /development/documentation.php. Include testing checklist and prevention strategies.",
                priority: "high",
                status: "completed"
            },
            {
                id: 5,
                title: "Add SSH Key Management UI",
                description: "Add SSH key status display and regeneration button to firewall details page. Show key fingerprint, age, last regenerated date. Implement backend API to trigger key pair regeneration.",
                priority: "medium",
                status: "not-started"
            },
            {
                id: 6,
                title: "Implement Latency Monitoring",
                description: "Modify agent to run ping tests and collect latency metrics. Create firewall_latency_metrics table. Add latency graph to firewall details with alert thresholds (warning >100ms, critical >200ms).",
                priority: "medium",
                status: "not-started"
            },
            {
                id: 7,
                title: "Add Data Retention Policies",
                description: "Implement automated cleanup of old statistics (keep 90 days, archive or delete). Add settings for retention period. Reduce database size and improve query performance.",
                priority: "low",
                status: "not-started"
            },
            {
                id: 8,
                title: "Improve UI/Network Tools Tab",
                description: "Fix spacing issues where content appears too far down page. Add scheduled tasks tab for cron management. Improve security report formatting.",
                priority: "low",
                status: "not-started"
            }
        ];

        let currentFilter = 'all';

        function loadTodos() {
            renderTodos();
            updateStats();
        }

        function renderTodos() {
            const list = document.getElementById('todo-list');
            list.innerHTML = '';

            const filtered = todos.filter(t => currentFilter === 'all' || t.status === currentFilter);

            filtered.forEach(todo => {
                const item = document.createElement('div');
                item.className = `todo-item ${todo.status}-priority ${todo.status}`;
                
                item.innerHTML = `
                    <input type="checkbox" class="checkbox" ${todo.status === 'completed' ? 'checked' : ''} onchange="toggleTodo(${todo.id})">
                    <div class="todo-content">
                        <div class="todo-title">${escapeHtml(todo.title)}</div>
                        <div class="todo-desc">${escapeHtml(todo.description)}</div>
                        <div class="todo-meta">
                            <span class="badge badge-priority badge-${todo.priority}">${todo.priority}</span>
                            <span class="badge badge-status badge-${todo.status}">${todo.status.replace('-', ' ')}</span>
                        </div>
                    </div>
                `;
                list.appendChild(item);
            });
        }

        function filterTodos(filter) {
            currentFilter = filter;
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            renderTodos();
        }

        function toggleTodo(id) {
            const todo = todos.find(t => t.id === id);
            if (todo) {
                todo.status = todo.status === 'completed' ? 'not-started' : 'completed';
                renderTodos();
                updateStats();
            }
        }

        function updateStats() {
            document.getElementById('total-count').textContent = todos.length;
            document.getElementById('completed-count').textContent = todos.filter(t => t.status === 'completed').length;
            document.getElementById('in-progress-count').textContent = todos.filter(t => t.status === 'in-progress').length;
        }

        function addTodo(e) {
            e.preventDefault();
            const title = document.getElementById('title').value;
            const description = document.getElementById('description').value;
            const priority = document.getElementById('priority').value;
            const status = document.getElementById('status').value;

            const newTodo = {
                id: Math.max(...todos.map(t => t.id)) + 1,
                title, description, priority, status
            };

            todos.push(newTodo);
            document.getElementById('title').value = '';
            document.getElementById('description').value = '';
            document.getElementById('priority').value = 'medium';
            document.getElementById('status').value = 'not-started';
            
            renderTodos();
            updateStats();
            alert('Todo added!');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Load on page load
        document.addEventListener('DOMContentLoaded', loadTodos);
    </script>
</body>
</html>
