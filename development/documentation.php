<?php
/**
 * OPNsense Manager - Variable Documentation & Architecture Guide
 * This page documents all key variables and data structures to prevent bugs
 * 
 * Last Updated: 2025-10-27
 * Current Version: v2.1.0
 */
?>

<!DOCTYPE html>
<html>
<head>
    <title>Development Documentation - OPNsense Manager</title>
    <style>
        body { font-family: monospace; background: #0d1117; color: #c9d1d9; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #58a6ff; border-bottom: 2px solid #30363d; padding-bottom: 10px; }
        h2 { color: #79c0ff; margin-top: 30px; }
        h3 { color: #a0d8ff; }
        .section { background: #161b22; border: 1px solid #30363d; border-radius: 6px; padding: 15px; margin: 15px 0; }
        .var-block { background: #0d1117; border-left: 3px solid #58a6ff; padding: 10px; margin: 10px 0; }
        .important { color: #ff7b72; font-weight: bold; }
        .table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .table th, .table td { border: 1px solid #30363d; padding: 8px; text-align: left; }
        .table th { background: #161b22; }
        code { background: #0d1117; padding: 2px 5px; border-radius: 3px; }
        pre { background: #161b22; padding: 10px; border-radius: 6px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 OPNsense Manager - Development Documentation</h1>

        <div class="section">
            <h2>📌 Quick Reference: Critical Variables</h2>
            <p>These variables have caused bugs before. Document any changes!</p>
            
            <table class="table">
                <tr>
                    <th>Variable Name</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>Critical Notes</th>
                </tr>
                <tr>
                    <td>$firewall_id</td>
                    <td>int</td>
                    <td>Database (firewalls.id)</td>
                    <td>Primary key for all queries. Always validate > 0</td>
                </tr>
                <tr>
                    <td>$firewall['id']</td>
                    <td>int</td>
                    <td>Database result</td>
                    <td>Same as firewall_id, used in firewall_details.php</td>
                </tr>
                <tr>
                    <td>bytes_in / bytes_out</td>
                    <td>bigint</td>
                    <td>firewall_traffic_stats table</td>
                    <td><span class="important">CUMULATIVE COUNTER</span> - must calculate delta for rates!</td>
                </tr>
                <tr>
                    <td>mbps_in / mbps_out</td>
                    <td>float</td>
                    <td>Calculated field</td>
                    <td>Formula: (bytes_delta / time_delta_seconds) * 8 / 1000000</td>
                </tr>
                <tr>
                    <td>cpu_load_1min / cpu_load_5min / cpu_load_15min</td>
                    <td>float</td>
                    <td>firewall_system_stats table</td>
                    <td>Load average from FreeBSD. Different from CPU_usage %!</td>
                </tr>
                <tr>
                    <td>memory_percent / disk_percent</td>
                    <td>float</td>
                    <td>firewall_system_stats table</td>
                    <td>Percentages (0-100). Range validation required!</td>
                </tr>
                <tr>
                    <td>memory_total_mb / memory_used_mb</td>
                    <td>int</td>
                    <td>firewall_system_stats table</td>
                    <td>Megabytes. Used for detailed reports.</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>🐛 Known Bug History</h2>
            
            <h3>Bug #1: Traffic Graph Shows 12,000 Mbps (Spike Anomaly)</h3>
            <div class="var-block">
                <strong>Root Cause:</strong> Fallback code was summing cumulative bytes for entire day instead of calculating rate<br>
                <strong>Wrong Formula:</strong> <code>SUM(bytes_in) * 8 / 1000000</code><br>
                <strong>Correct Formula:</strong> <code>(bytes_in - prev_bytes_in) * 8 / TIMESTAMPDIFF(SECOND, prev_time, recorded_at) / 1000000</code><br>
                <strong>Fix Applied:</strong> Removed broken fallback code. Always use LAG() window function to calculate deltas.<br>
                <strong>Prevention:</strong> NEVER sum bytes directly. ALWAYS calculate delta between consecutive samples.
            </div>

            <h3>Bug #2: System Stats Not Storing (Empty CPU/Memory/Disk Graphs)</h3>
            <div class="var-block">
                <strong>Root Cause:</strong> INSERT statement used wrong column names<br>
                <strong>Agent Sends:</strong> <code>cpu_load_1min, memory_percent, disk_percent</code><br>
                <strong>Code Expected:</strong> <code>cpu_usage, memory_usage, disk_usage</code> (WRONG!)<br>
                <strong>Database Schema:</strong> <code>cpu_load_1min, memory_percent, disk_percent</code><br>
                <strong>Fix Applied:</strong> Updated agent_checkin.php handler to use correct column names<br>
                <strong>Prevention:</strong> ALWAYS run <code>DESCRIBE table_name</code> before writing INSERT statements!
            </div>

            <h3>Bug #3: Tunnel Proxy URL Construction ("Port rejected")</h3>
            <div class="var-block">
                <strong>Root Cause:</strong> URL slash missing between port and path<br>
                <strong>Generated:</strong> <code>http://127.0.0.1:8106ui/themes/theme.css</code> (INVALID)<br>
                <strong>Fixed:</strong> <code>http://127.0.0.1:8106/ui/themes/theme.css</code> (VALID)<br>
                <strong>Fix Location:</strong> tunnel_proxy.php line 117<br>
                <strong>Prevention:</strong> Always test URL construction with actual port numbers
            </div>

            <h3>Bug #4: Cookie Deletion Every Request (Login Loop)</h3>
            <div class="var-block">
                <strong>Root Cause:</strong> Aggressive cookie deletion for first 10 seconds of ANY session<br>
                <strong>Broken Code:</strong> <code>if ($session_age < 10) { setcookie(..., time()-1) }</code><br>
                <strong>Fixed Code:</strong> Only delete on <code>$_GET['fresh']=1</code> parameter<br>
                <strong>Fix Location:</strong> tunnel_proxy.php line 147<br>
                <strong>Prevention:</strong> Never delete cookies based on age alone. Use explicit flags.
            </div>
        </div>

        <div class="section">
            <h2>📊 Database Schema - Critical Tables</h2>

            <h3>firewall_traffic_stats</h3>
            <pre>id, firewall_id, wan_interface, bytes_in (BIGINT), bytes_out (BIGINT), 
packets_in, packets_out, recorded_at (DATETIME)</pre>
            <p><span class="important">IMPORTANT:</span> bytes_in/out are CUMULATIVE since firewall boot. Always calculate delta!</p>

            <h3>firewall_system_stats</h3>
            <pre>id, firewall_id, cpu_load_1min (FLOAT), cpu_load_5min, cpu_load_15min,
memory_total_mb (INT), memory_used_mb (INT), memory_percent (FLOAT),
disk_total_gb (FLOAT), disk_used_gb (FLOAT), disk_percent (FLOAT), recorded_at</pre>
            <p><span class="important">IMPORTANT:</span> cpu_load_* is NOT percentage! It's load average. memory/disk_percent is 0-100.</p>

            <h3>ssh_access_sessions</h3>
            <pre>id, firewall_id, user_id, session_id, tunnel_port (8100-8199),
local_tunnel_pid, status, created_at, last_activity, expires_at, idle_timeout</pre>
            <p>Timeout: 30 min max OR 15 min idle. Auto-cleaned every 5 minutes.</p>

            <h3>scheduled_tasks</h3>
            <pre>id, task_name, description, schedule, status, enabled (TINYINT), 
last_run (DATETIME), next_run (DATETIME), created_at, updated_at</pre>
            <p>Used to track cron task status. Enable/disable via web API: /api/manage_tasks.php</p>
        </div>

        <div class="section">
            <h2>🔄 Data Flow - Key Pipelines</h2>

            <h3>Traffic Statistics Pipeline</h3>
            <pre>
Firewall Agent (v3.5.2+)
  ↓ Sends cumulative bytes every 120 seconds
Agent_checkin.php (manager)
  ↓ Extracts from $input['traffic_stats']
firewall_traffic_stats table
  ↓ Raw cumulative bytes stored
Get_traffic_stats.php (API)
  ↓ Calculates delta: LAG(bytes) OVER ORDER BY recorded_at
  ↓ Formula: (bytes_delta / time_delta) * 8 / 1000000 = Mbps
  ↓ Groups by hour, AVG(Mbps)
firewall_details.php (frontend)
  ↓ Fetches via Chart.js + fetch()
Browser
  ↓ Displays line chart with Mbps on Y-axis
            </pre>

            <h3>System Statistics Pipeline</h3>
            <pre>
Firewall Agent
  ↓ Collects: cpu_load_1min, memory_percent, disk_percent
Agent_checkin.php
  ↓ FROM $input['system_stats']
firewall_system_stats table
Get_system_stats.php (API)
  ↓ Hourly averages
firewall_details.php
  ↓ 4 separate charts (CPU, Memory, Disk, blank 4th)
Browser
  ↓ Real-time display
            </pre>
        </div>

        <div class="section">
            <h2>⚡ Critical API Endpoints</h2>

            <table class="table">
                <tr>
                    <th>Endpoint</th>
                    <th>Method</th>
                    <th>Purpose</th>
                    <th>Returns</th>
                </tr>
                <tr>
                    <td>/agent_checkin.php</td>
                    <td>POST (JSON)</td>
                    <td>Agent heartbeat + data push</td>
                    <td>{success, checkin_interval, server_time}</td>
                </tr>
                <tr>
                    <td>/api/get_traffic_stats.php?firewall_id=X&days=Y</td>
                    <td>GET</td>
                    <td>Fetch traffic data for graph</td>
                    <td>{success, labels[], inbound[], outbound[], unit}</td>
                </tr>
                <tr>
                    <td>/api/get_system_stats.php?firewall_id=X&days=Y&metric=cpu</td>
                    <td>GET</td>
                    <td>Fetch CPU/Memory/Disk stats</td>
                    <td>{success, labels[], load_1min[] or usage[]}</td>
                </tr>
                <tr>
                    <td>/api/manage_tasks.php</td>
                    <td>GET/POST</td>
                    <td>List and control scheduled tasks</td>
                    <td>{success, tasks[]} or {success, message}</td>
                </tr>
                <tr>
                    <td>/agent_checkin.php?action=check_update</td>
                    <td>GET</td>
                    <td>Agent version/update check</td>
                    <td>{update_available, new_version, download_url}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>📋 Testing Checklist</h2>
            <pre>
[ ] Before editing agent_checkin.php:
    [ ] Verify database schema: DESCRIBE firewall_traffic_stats
    [ ] Verify database schema: DESCRIBE firewall_system_stats
    [ ] Check agent payload format with: grep -A 5 '"traffic_stats"' agent_script.sh
    
[ ] Before editing get_traffic_stats.php:
    [ ] Verify bytes are CUMULATIVE: SELECT * FROM firewall_traffic_stats LIMIT 1
    [ ] Test calculation manually: (1000000 - 999000) * 8 / 120 / 1000000 = 0.067 Mbps
    [ ] Never use SUM() on bytes_in/bytes_out! Always calculate delta!
    
[ ] After any API changes:
    [ ] Clear PHP OPcache: sudo systemctl restart php8.3-fpm
    [ ] Test with curl (bypass auth if needed)
    [ ] Check browser console for fetch errors
    [ ] Verify chart Y-axis scale is reasonable (3-10 Mbps, NOT 12,000!)
    
[ ] After agent_checkin.php changes:
    [ ] Verify syntax: php -l agent_checkin.php
    [ ] Wait 2 minutes for agent checkin
    [ ] Query database: SELECT COUNT(*), MAX(recorded_at) FROM firewall_traffic_stats
    [ ] Verify new records created with correct data
            </pre>
        </div>

        <div class="section">
            <h2>🎯 Future Improvements</h2>
            <ul>
                <li>Add SSH key management (status, regeneration) to firewall details</li>
                <li>Add latency monitoring with ping-based metrics</li>
                <li>Implement alert system for traffic thresholds</li>
                <li>Add user-configurable task scheduling</li>
                <li>Implement audit logging for all API changes</li>
                <li>Add data retention policies (clean old stats after 90 days)</li>
            </ul>
        </div>

        <div class="section" style="text-align: center; margin-top: 40px;">
            <p style="color: #888;">Last Updated: 2025-10-27 19:15 UTC | OPNsense Manager v2.1.0</p>
        </div>
    </div>
</body>
</html>
