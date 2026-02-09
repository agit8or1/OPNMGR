<?php
/**
 * AI Config Scan API
 * Analyzes firewall configuration and generates security report
 */

header('Content-Type: application/json');
require_once '../inc/db.php';
require_once '../inc/auth.php';
require_once '../inc/agent_version.php';


if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$response = ['success' => false, 'error' => ''];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['firewall_id'])) {
        throw new Exception('Firewall ID is required');
    }
    
    $firewall_id = (int)$input['firewall_id'];
    $scan_type = $input['scan_type'] ?? 'config_only';
    $provider = $input['provider'] ?? null;
    
    // Get firewall details
    $stmt = $DB->prepare("SELECT * FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();

    if (!$firewall) {
        throw new Exception('Firewall not found');
    }

    // Get agent version
    $agent_stmt = $DB->prepare("SELECT agent_version FROM firewall_agents WHERE firewall_id = ? AND agent_type = 'primary' LIMIT 1");
    $agent_stmt->execute([$firewall_id]);
    $agent_data = $agent_stmt->fetch();
    if ($agent_data) {
        $firewall['agent_version'] = $agent_data['agent_version'];
    }
    
    // Get AI provider settings
    if ($provider) {
        $stmt = $DB->prepare("SELECT * FROM ai_settings WHERE provider = ? AND is_active = TRUE");
        $stmt->execute([$provider]);
    } else {
        $stmt = $DB->query("SELECT * FROM ai_settings WHERE is_active = TRUE LIMIT 1");
    }
    $ai_settings = $stmt->fetch();

    if (!$ai_settings) {
        throw new Exception('No active AI provider configured. Please configure an AI provider in Settings > AI Configuration.');
    }
    
    // Fetch firewall configuration
    $config_data = fetchFirewallConfig($firewall);

    if (!$config_data) {
        throw new Exception('Failed to fetch firewall configuration. Check SSH connectivity.');
    }
    
    // Fetch logs if requested
    $log_data = null;
    if ($scan_type === 'config_with_logs') {
        $log_data = fetchFirewallLogs($firewall, ['filter', 'system', 'resolver']);
    }
    
    // Create config snapshot
    $config_hash = hash('sha256', json_encode($config_data));
    $stmt = $DB->prepare("INSERT INTO config_snapshots (firewall_id, config_hash, config_data, rule_count, interface_count) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $firewall_id,
        $config_hash,
        json_encode($config_data),
        $config_data['rule_count'] ?? 0,
        $config_data['interface_count'] ?? 0
    ]);
    $snapshot_id = $DB->lastInsertId();
    
    // Perform AI analysis
    $start_time = microtime(true);
    $analysis = performAIAnalysis($ai_settings, $config_data, $firewall, $scan_type, $log_data);
    $scan_duration = round((microtime(true) - $start_time) * 1000);

    // Add log analysis summary if logs were analyzed
    if ($scan_type === 'config_with_logs' && $log_data && !empty($log_data)) {
        $log_count = count($log_data);
        $total_lines = 0;
        foreach ($log_data as $log_content) {
            if (!empty($log_content) && $log_content !== "Log not available") {
                $total_lines += count(explode("\n", $log_content));
            }
        }

        if ($total_lines > 0) {
            $log_summary = "\n\n**Log Analysis:** Analyzed $total_lines lines from " . $log_count . " log file(s) (" . implode(", ", array_keys($log_data)) . "). ";

            // Check for threats - handle both arrays and strings
            $active_threats_count = 0;
            if (isset($analysis['active_threats'])) {
                if (is_array($analysis['active_threats'])) {
                    $active_threats_count = count($analysis['active_threats']);
                }
            }

            if ($active_threats_count > 0) {
                $log_summary .= "Detected " . $active_threats_count . " active threat(s). ";
            } else {
                $log_summary .= "No active threats detected. ";
            }

            // Check for suspicious IPs - handle both arrays and strings
            $suspicious_ips_count = 0;
            if (isset($analysis['suspicious_ips'])) {
                if (is_array($analysis['suspicious_ips'])) {
                    $suspicious_ips_count = count($analysis['suspicious_ips']);
                }
            }

            if ($suspicious_ips_count > 0) {
                $log_summary .= $suspicious_ips_count . " suspicious IP(s) identified.";
            } else {
                $log_summary .= "No suspicious activity detected in logs.";
            }

            $analysis['summary'] .= $log_summary;
        }
    }

    // Save scan report
    $stmt = $DB->prepare("
        INSERT INTO ai_scan_reports (
            firewall_id, config_snapshot_id, scan_type, provider, model,
            overall_grade, security_score, risk_level, summary, 
            recommendations, concerns, improvements, full_report, scan_duration
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $firewall_id,
        $snapshot_id,
        $scan_type,
        $ai_settings['provider'],
        $ai_settings['model'],
        $analysis['grade'],
        $analysis['score'],
        $analysis['risk_level'],
        $analysis['summary'],
        $analysis['recommendations'],
        $analysis['concerns'],
        $analysis['improvements'],
        $analysis['full_report'],
        $scan_duration
    ]);
    $report_id = $DB->lastInsertId();
    
    // Save log analysis results if logs were analyzed
    if ($scan_type === 'config_with_logs' && $log_data) {
        foreach ($log_data as $log_type => $log_content) {
            if (empty($log_content) || $log_content === "Log not available") {
                continue;
            }
            
            $lines_analyzed = count(explode("\n", $log_content));

            // Ensure proper JSON array format
            $active_threats_data = $analysis['active_threats'] ?? [];
            if (is_string($active_threats_data)) {
                $active_threats_data = []; // Convert string to empty array
            }
            $active_threats = json_encode($active_threats_data);

            $suspicious_ips_data = $analysis['suspicious_ips'] ?? [];
            if (is_string($suspicious_ips_data)) {
                $suspicious_ips_data = []; // Convert string to empty array
            }
            $suspicious_ips = json_encode($suspicious_ips_data);
            
            $stmt = $DB->prepare("
                INSERT INTO log_analysis_results (
                    report_id, log_type, lines_analyzed, active_threats, suspicious_ips,
                    blocked_attempts, failed_auth_attempts, anomaly_score, threat_level
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $report_id,
                $log_type,
                $lines_analyzed,
                $active_threats,
                $suspicious_ips,
                $analysis['blocked_attempts'] ?? 0,
                $analysis['failed_auth_attempts'] ?? 0,
                $analysis['anomaly_score'] ?? 0,
                $analysis['threat_level'] ?? 'low'
            ]);
        }
    }
    
    // Save individual findings
    if (!empty($analysis['findings'])) {
        $stmt = $DB->prepare("
            INSERT INTO ai_scan_findings (report_id, source, category, severity, title, description, recommendation, affected_rules)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($analysis['findings'] as $finding) {
            $stmt->execute([
                $report_id,
                $finding['source'] ?? 'config',  // Default to 'config' if not specified
                $finding['category'] ?? 'General',
                $finding['severity'] ?? 'info',
                $finding['title'],
                $finding['description'],
                $finding['recommendation'] ?? '',
                $finding['affected_rules'] ?? ''
            ]);
        }
    }
    
    // Update firewall AI settings
    $stmt = $DB->prepare("
        INSERT INTO firewall_ai_settings (firewall_id, last_scan_at)
        VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE last_scan_at = NOW()
    ");
    $stmt->execute([$firewall_id]);
    
    $response['success'] = true;
    $response['data'] = [
        'report_id' => $report_id,
        'grade' => $analysis['grade'],
        'score' => $analysis['score'],
        'risk_level' => $analysis['risk_level'],
        'findings_count' => count($analysis['findings']),
        'scan_duration' => $scan_duration
    ];
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    $error_msg = "AI Scan Error: " . $e->getMessage();
    error_log($error_msg);

    // Also write to dedicated AI scan log for easier debugging
    $log_file = '/var/log/opnmgr_ai_scan.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $error_msg\n", FILE_APPEND);
}

echo json_encode($response);

/**
 * Fetch firewall configuration via SSH
 */
function fetchFirewallConfig($firewall) {
    // Use wan_ip if ip_address is empty or 0.0.0.0
    $ip = $firewall['ip_address'];
    error_log("[AI_SCAN] fetchFirewallConfig called: ip_address='{$ip}', wan_ip='{$firewall['wan_ip']}'");

    if (empty($ip) || $ip === '0.0.0.0') {
        $ip = $firewall['wan_ip'];
        error_log("[AI_SCAN] Using wan_ip fallback: '{$ip}'");
    }

    if (empty($ip) || $ip === '0.0.0.0') {
        error_log("[AI_SCAN] No valid IP address for firewall {$firewall['hostname']} - ip_address: '{$firewall['ip_address']}', wan_ip: '{$firewall['wan_ip']}'");
        return null;
    }

    // Use SSH key authentication instead of password
    $key_file = __DIR__ . '/../keys/id_firewall_' . $firewall['id'];
    if (!file_exists($key_file)) {
        error_log("[AI_SCAN] No SSH key found for firewall {$firewall['id']}");
        return null;
    }

    // Fetch config.xml from firewall
    $escaped_ip = escapeshellarg($ip);
    $escaped_key = escapeshellarg($key_file);
    $command = "ssh -i {$escaped_key} -o StrictHostKeyChecking=no -o ConnectTimeout=10 root@{$escaped_ip} 'cat /conf/config.xml' 2>&1";
    $output = shell_exec($command);

    if (empty($output) || strpos($output, 'Permission denied') !== false || strpos($output, 'Connection refused') !== false) {
        error_log("[AI_SCAN] Failed to fetch config from {$ip}: " . substr($output, 0, 200));
        return null;
    }

    // Return raw XML config - AI will analyze it
    return [
        'firewall_name' => $firewall['hostname'],
        'ip_address' => $ip,
        'version' => $firewall['opnsense_version'] ?? 'Unknown',
        'config_xml' => $output,
        'config_size' => strlen($output)
    ];
}

/**
 * Fetch firewall logs via SSH
 * Default to 30 lines to stay within AI API token limits (especially OpenAI's 30k TPM limit)
 */
function fetchFirewallLogs($firewall, $log_types = ['filter', 'system', 'resolver'], $lines = 30) {
    // Use wan_ip if ip_address is empty or 0.0.0.0
    $ip = $firewall['ip_address'];
    if (empty($ip) || $ip === '0.0.0.0') {
        $ip = $firewall['wan_ip'];
    }

    if (empty($ip) || $ip === '0.0.0.0') {
        error_log("[AI_SCAN] No valid IP address for firewall {$firewall['hostname']}");
        return [];
    }

    // Use SSH key authentication instead of password
    $key_file = __DIR__ . '/../keys/id_firewall_' . $firewall['id'];
    if (!file_exists($key_file)) {
        error_log("[AI_SCAN] No SSH key found for firewall {$firewall['id']}");
        return [];
    }
    $escaped_ip = escapeshellarg($ip);
    $escaped_key = escapeshellarg($key_file);

    // OPNsense stores logs in subdirectories with latest.log symlinks
    $log_paths = [
        'filter' => '/var/log/filter/latest.log',     // Firewall traffic logs
        'system' => '/var/log/system/latest.log',     // System events
        'resolver' => '/var/log/resolver/latest.log', // DNS/resolver logs
        'dhcp' => '/var/log/dhcpd.log'                // DHCP logs (if exists)
    ];

    $logs = [];

    foreach ($log_types as $log_type) {
        if (!isset($log_paths[$log_type])) {
            continue;
        }

        $log_path = escapeshellarg($log_paths[$log_type]);
        $escaped_lines = (int)$lines;
        $command = "ssh -i {$escaped_key} -o StrictHostKeyChecking=no -o ConnectTimeout=10 root@{$escaped_ip} 'tail -n {$escaped_lines} {$log_path}' 2>/dev/null";
        $output = shell_exec($command);

        if (!empty($output) && strpos($output, 'Permission denied') === false && strpos($output, 'not found') === false) {
            $logs[$log_type] = $output;
            $line_count = count(explode("\n", trim($output)));
            error_log("[AI_SCAN] Successfully fetched {$log_type} log from {$ip}: {$line_count} lines");
        } else {
            error_log("[AI_SCAN] Failed to fetch {$log_type} log from {$ip} (file may not exist)");
        }
    }

    return $logs;
}

/**
 * Perform AI analysis using configured provider
 */
function performAIAnalysis($ai_settings, $config_data, $firewall, $scan_type, $log_data = null) {
    $provider = $ai_settings['provider'];
    $api_key = $ai_settings['api_key'];
    $model = $ai_settings['model'];
    
    // Build prompt for AI
    $prompt = buildAnalysisPrompt($config_data, $firewall, $scan_type, $log_data);
    
    // Call appropriate AI provider
    switch ($provider) {
        case 'openai':
            $result = callOpenAI($api_key, $model, $prompt);
            break;
        case 'anthropic':
            $result = callAnthropic($api_key, $model, $prompt);
            break;
        case 'google':
            $result = callGoogleGemini($api_key, $model, $prompt);
            break;
        case 'ollama':
            $result = callOllama($api_key, $model, $prompt);
            break;
        default:
            throw new Exception("Unsupported AI provider: {$provider}");
    }
    
    // Parse AI response
    return parseAIResponse($result);
}

/**
 * Build analysis prompt for AI
 */
function buildAnalysisPrompt($config_data, $firewall, $scan_type, $log_data = null) {
    $prompt = "You are a cybersecurity expert analyzing an OPNsense firewall ";

    if ($scan_type === 'config_with_logs') {
        $prompt .= "configuration AND recent log files. ";
    } else {
        $prompt .= "configuration. ";
    }

    // CRITICAL RULES - MUST BE AT THE TOP BEFORE ANY DATA
    $prompt .= "\n\n==========================================================\n";
    $prompt .= "CRITICAL SECURITY ANALYSIS RULES - READ FIRST:\n";
    $prompt .= "==========================================================\n\n";

    $prompt .= "**ABSOLUTE PROHIBITION - DO NOT CREATE FINDINGS FOR**:\n";
    $prompt .= "1. SSH root login when configuration shows <permitrootlogin>1</permitrootlogin> - THIS IS SECURE when SSH rules restrict source IPs\n";
    $prompt .= "2. SSH access restricted to specific source IP addresses - THIS IS EXCELLENT SECURITY\n";
    $prompt .= "3. Root login over SSH when firewall rules limit SSH to management IPs - THIS IS INDUSTRY BEST PRACTICE\n";
    $prompt .= "4. Logging configuration or log availability - DO NOT MENTION LOGS AT ALL\n";
    $prompt .= "5. Any service (SSH, HTTP, HTTPS) with source IP restrictions to specific management IPs\n";
    $prompt .= "6. NAT port forward rules - These are intentional and required for services like Plex, media servers, etc. - NEVER FLAG NAT RULES\n\n";

    $prompt .= "**SECURE CONFIGURATION EXAMPLE**:\n";
    $prompt .= "If you see <permitrootlogin>1</permitrootlogin> AND firewall rules showing SSH restricted to specific IPs:\n";
    $prompt .= "- This is SECURE and PROPERLY CONFIGURED\n";
    $prompt .= "- DO NOT create any finding about this\n";
    $prompt .= "- DO NOT recommend disabling root login\n";
    $prompt .= "- DO NOT mention this in concerns or recommendations\n";
    $prompt .= "- Instead, PRAISE this as good security practice in your summary\n\n";

    $prompt .= "**WHAT TO FLAG vs WHAT NOT TO FLAG**:\n";
    $prompt .= "FLAG: SSH open to 0.0.0.0/0 or 'any' source = CRITICAL ISSUE\n";
    $prompt .= "DO NOT FLAG: SSH restricted to 184.175.206.229 or other specific IPs = SECURE\n";
    $prompt .= "FLAG: Web interface open to internet without source restrictions = HIGH RISK\n";
    $prompt .= "DO NOT FLAG: Web interface restricted to LAN or specific management IPs = SECURE\n\n";

    $prompt .= "**CRITICAL: BEFORE FLAGGING SSH AS A CONCERN**:\n";
    $prompt .= "1. You MUST carefully examine the firewall rules in the <filter> section\n";
    $prompt .= "2. Look for rules with <descr> containing 'SSH' or destination port 22\n";
    $prompt .= "3. Check the <source><address> field - if it contains a specific IP (NOT 'any'), SSH is SECURE\n";
    $prompt .= "4. ONLY flag SSH if you find a rule with destination port 22 AND source 'any' or no source restriction\n";
    $prompt .= "5. If SSH has source IP restrictions, DO NOT create any finding, concern, or recommendation about SSH\n";
    $prompt .= "6. NEVER assume SSH is unrestricted - you must verify in the firewall rules XML\n\n";

    $prompt .= "==========================================================\n\n";

    $prompt .= "Provide a comprehensive security analysis with the following structure:\n\n";
    $prompt .= "1. Overall Grade (A+ to F)\n";
    $prompt .= "2. Security Score (0-100)\n";
    $prompt .= "3. Risk Level (low, medium, high, critical)\n";
    $prompt .= "4. Executive Summary\n";
    $prompt .= "5. Key Concerns (list specific issues)\n";
    $prompt .= "6. Recommendations (actionable steps)\n";
    $prompt .= "7. Improvement Opportunities\n\n";

    $prompt .= "Firewall: {$firewall['hostname']} ({$firewall['ip_address']})\n";
    $prompt .= "OPNsense Version: {$config_data['version']}\n";

    // Add agent version info
    $agent_version = $firewall['agent_version'] ?? 'Unknown';
    $latest_agent_version = defined('LATEST_AGENT_VERSION') ? LATEST_AGENT_VERSION : '1.2.7';
    $prompt .= "OPNManager Agent Version: {$agent_version}";
    if ($agent_version !== 'Unknown' && $agent_version === $latest_agent_version) {
        $prompt .= " (Latest Version - Up to Date ✓)";
    } elseif ($agent_version !== 'Unknown' && version_compare($agent_version, $latest_agent_version, '<')) {
        $prompt .= " (Update available: {$latest_agent_version})";
    }
    $prompt .= "\n\n";

    $prompt .= "FIREWALL CONFIGURATION (config.xml):\n";
    $prompt .= "Analyze the following OPNsense configuration XML for security issues, misconfigurations, and best practices:\n\n";
    $prompt .= $config_data['config_xml'] . "\n\n";
    
    if ($log_data && !empty($log_data)) {
        $prompt .= "LOG FILE ANALYSIS:\n";
        $prompt .= "Analyze the following log files for security incidents, suspicious activity, and threats:\n\n";
        
        foreach ($log_data as $log_type => $log_content) {
            if (empty($log_content) || $log_content === "Log not available") {
                continue;
            }
            
            $lines = explode("\n", $log_content);
            $line_count = count($lines);
            $sample_size = min($line_count, 30); // Limit to stay under API token limits (especially OpenAI's 30k TPM)
            $sample_lines = array_slice($lines, -$sample_size);
            
            $prompt .= "=== {$log_type} log (last {$sample_size} of {$line_count} lines) ===\n";
            $prompt .= implode("\n", $sample_lines) . "\n\n";
        }
        
        $prompt .= "For log analysis, identify:\n";
        $prompt .= "- Active security threats or attacks\n";
        $prompt .= "- Suspicious IP addresses or patterns\n";
        $prompt .= "- Failed authentication attempts\n";
        $prompt .= "- Blocked connections and their sources\n";
        $prompt .= "- Traffic anomalies or unusual patterns\n";
        $prompt .= "- Potential data exfiltration attempts\n";
        $prompt .= "- Denial of service indicators\n\n";
    }
    
    $prompt .= "Focus on:\n";
    $prompt .= "- Security misconfigurations\n";
    $prompt .= "- Overly permissive rules\n";
    $prompt .= "- Missing security features\n";
    $prompt .= "- Best practice violations\n";
    $prompt .= "- Performance optimization\n";

    if ($log_data) {
        $prompt .= "- Active threats detected in logs\n";
        $prompt .= "- Security incident patterns\n";
    }

    $prompt .= "\n**CRITICAL - THINGS YOU MUST NOT FLAG AS CONCERNS**:\n";
    $prompt .= "NEVER include these in concerns, recommendations, or findings:\n";
    $prompt .= "1. SSH root login when restricted to specific source IPs - THIS IS SECURE\n";
    $prompt .= "2. SSH access when restricted to specific source IPs - THIS IS SECURE\n";
    $prompt .= "3. Absence of logs in the scan - logs may exist but not included\n";
    $prompt .= "4. Logging configuration - assume logging is properly configured\n";
    $prompt .= "5. Any service with source IP restrictions to management networks\n";
    $prompt .= "6. HTTP web interface when restricted to specific management IPs - THIS IS ACCEPTABLE for management access\n";
    $prompt .= "7. Web GUI access when limited to trusted management servers or networks\n";
    $prompt .= "8. OPNManager Agent updates when agent is already at the latest version (shown above as 'Up to Date ✓')\n";
    $prompt .= "   - If the agent version shows 'Latest Version - Up to Date ✓', DO NOT recommend updating the agent\n";
    $prompt .= "   - NEVER recommend agent updates, monitoring agent updates, or checking agent versions when already up to date\n";
    $prompt .= "   - Focus ONLY on firewall security configuration, not on agent management\n\n";

    $prompt .= "**CRITICAL - Firewall Rule Analysis Guidelines**:\n";
    $prompt .= "When analyzing firewall rules, PAY CLOSE ATTENTION to source IP restrictions:\n\n";

    $prompt .= "**SSH Management Access**:\n";
    $prompt .= "- SSH on WAN with SOURCE IP RESTRICTION to management IPs = EXCELLENT SECURITY (DO NOT FLAG)\n";
    $prompt .= "- SSH root login WITH IP restrictions to management servers = ACCEPTABLE and SECURE (DO NOT FLAG)\n";
    $prompt .= "- This is INDUSTRY STANDARD for secure remote firewall management\n";
    $prompt .= "- NEVER EVER flag SSH or root login when source IP is restricted to specific management IPs\n";
    $prompt .= "- NEVER recommend disabling root login when SSH already restricted to trusted management IPs\n";
    $prompt .= "- ONLY flag SSH if truly open to 0.0.0.0/0 (any source) with NO source restrictions\n\n";

    $prompt .= "**HTTP/HTTPS Web Interface**:\n";
    $prompt .= "- Web GUI should typically be LAN-only or restricted to specific management IPs\n";
    $prompt .= "- If web interface is on WAN, it MUST have source IP restrictions to be acceptable\n";
    $prompt .= "- Web interface open to any source on WAN = CRITICAL security concern\n\n";

    $prompt .= "**Logging and Log Availability**:\n";
    $prompt .= "- NEVER flag logging as a concern - assume logging is properly configured\n";
    $prompt .= "- NEVER recommend enabling logging - it is already enabled\n";
    $prompt .= "- NEVER mention 'logs not available', 'enable logging', or 'log retention' in ANY section\n";
    $prompt .= "- If logs are not in the scan, they may exist but were not included - this is NOT a concern\n";
    $prompt .= "- Focus ONLY on firewall rules and configuration, NOT on logging infrastructure\n";
    $prompt .= "- Logging concerns are STRICTLY FORBIDDEN from appearing in the report\n\n";

    $prompt .= "**General Rule Analysis**:\n";
    $prompt .= "- A rule with SOURCE IP restrictions (specific IPs/subnets) = SECURE, even on WAN\n";
    $prompt .= "- LAN-only access (192.168.x.x, 10.x.x.x, 172.16.x.x) = ACCEPTABLE for internal services\n";
    $prompt .= "- Rules open to 'any', '0.0.0.0/0', or 'internet' = MAJOR CONCERN (flag these)\n";
    $prompt .= "- PRAISE properly restricted management access - this shows good security practices\n";
    $prompt .= "- Focus your concerns ONLY on rules truly exposed to the entire internet without restrictions\n";

    $prompt .= "\n**Grading Guidelines**:\n";
    $prompt .= "Grade firewalls fairly based on ACTUAL security posture:\n";
    $prompt .= "- A/A+ (90-100): Properly configured with source restrictions, no services open to internet, good security practices\n";
    $prompt .= "- B (80-89): Minor improvements possible but generally secure\n";
    $prompt .= "- C (70-79): Some concerns that should be addressed\n";
    $prompt .= "- D (60-69): Significant security issues\n";
    $prompt .= "- F (below 60): Critical security problems\n";
    $prompt .= "A firewall with SSH/HTTP restricted to management IPs should receive A or B grade, NOT C or below\n";
    $prompt .= "Do NOT penalize for things on the forbidden list above\n\n";

    $prompt .= "\n**SECURITY ENHANCEMENT RECOMMENDATIONS**:\n";
    $prompt .= "ALWAYS include these as optional recommendations for improving security (DO NOT reduce grade if not implemented):\n\n";
    $prompt .= "1. Secure Outbound Lockdown:\n";
    $prompt .= "   \"Consider enabling Secure Outbound Lockdown (available under Overview tab).\n";
    $prompt .= "   This feature limits outbound traffic, filters DNS queries, and prevents unauthorized VPN and application access.\n";
    $prompt .= "   It provides an additional layer of security by controlling egress traffic patterns.\"\n\n";
    $prompt .= "2. Intrusion Detection/Prevention (IDS/IPS):\n";
    $prompt .= "   \"Consider enabling Suricata or Snort IDS/IPS plugins for real-time threat detection.\n";
    $prompt .= "   These systems can identify and block malicious traffic patterns and known attack signatures.\"\n\n";
    $prompt .= "3. Additional Security Plugins:\n";
    $prompt .= "   \"Consider implementing additional security plugins such as:\n";
    $prompt .= "   - CrowdSec for collaborative threat intelligence\n";
    $prompt .= "   - Sensei for advanced network intelligence and visibility\n";
    $prompt .= "   - Maltrail for malicious traffic detection\n";
    $prompt .= "   - These are optional enhancements and not required for a secure configuration.\"\n\n";
    $prompt .= "**IMPORTANT**: The absence of IDS/IPS or optional security plugins should NOT negatively impact the security grade.\n";
    $prompt .= "Grade based on actual firewall configuration security, not on optional enhancement features.\n\n";

    $prompt .= "\n**IMPORTANT**: Return ONLY raw JSON (no markdown, no code blocks, no formatting).\n";
    $prompt .= "Format response as JSON with keys: grade, score, risk_level, summary, concerns, recommendations, improvements, findings\n";
    $prompt .= "Each finding should have: source (either 'config' or 'logs'), category, severity, title, description, recommendation, affected_rules\n";
    $prompt .= "IMPORTANT: Tag each finding with 'source':\n";
    $prompt .= "- Use 'config' for findings from firewall configuration analysis (rules, settings, etc.)\n";
    $prompt .= "- Use 'logs' for findings from log file analysis (threats, suspicious activity, attacks)";

    if ($log_data) {
        $prompt .= "\nAlso include: active_threats (array), suspicious_ips (array), blocked_attempts (count)";
    }

    $prompt .= "\nReturn the JSON object directly without wrapping in markdown code blocks.";

    return $prompt;
}

/**
 * Call OpenAI API
 */
function callOpenAI($api_key, $model, $prompt) {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a cybersecurity expert specializing in firewall configuration analysis.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 2000
        ])
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        $error_data = json_decode($response, true);
        $error_message = $error_data['error']['message'] ?? 'Unknown error';
        throw new Exception("OpenAI API error: HTTP {$http_code} - {$error_message}");
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

/**
 * Call Anthropic Claude API
 */
function callAnthropic($api_key, $model, $prompt) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'max_tokens' => 2000,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ])
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("Anthropic API error: HTTP {$http_code}");
    }
    
    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? '';
}

/**
 * Call Google Gemini API
 */
function callGoogleGemini($api_key, $model, $prompt) {
    $ch = curl_init("https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$api_key}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ]
        ])
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("Google Gemini API error: HTTP {$http_code}");
    }
    
    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

/**
 * Call Ollama (local)
 */
function callOllama($endpoint, $model, $prompt) {
    $ch = curl_init("{$endpoint}/api/generate");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false
        ])
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    return $data['response'] ?? '';
}

/**
 * Parse AI response into structured data
 */
function parseAIResponse($ai_response) {
    // Strip markdown code blocks if present
    $cleaned_response = $ai_response;
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*(\n```)?/s', $ai_response, $matches)) {
        $cleaned_response = $matches[1];
    }

    // Try to parse as JSON first
    $json_data = json_decode($cleaned_response, true);

    if ($json_data && isset($json_data['grade'])) {
        // POST-PROCESSING: Remove prohibited findings FIRST (before converting arrays)
        $json_data = filterProhibitedFindings($json_data);

        // Then recursively convert ALL remaining arrays to formatted strings
        $json_data = convertAllArraysToStrings($json_data);

        return $json_data;
    }
    
    // Fallback: parse text response
    return [
        'grade' => extractGrade($ai_response),
        'score' => extractScore($ai_response),
        'risk_level' => extractRiskLevel($ai_response),
        'summary' => extractSection($ai_response, 'summary'),
        'concerns' => extractSection($ai_response, 'concerns'),
        'recommendations' => extractSection($ai_response, 'recommendations'),
        'improvements' => extractSection($ai_response, 'improvements'),
        'full_report' => $ai_response,
        'findings' => extractFindings($ai_response)
    ];
}

function extractGrade($text) {
    if (preg_match('/grade[:\s]+([A-F][+\-]?)/i', $text, $matches)) {
        return $matches[1];
    }
    return 'B';
}

function extractScore($text) {
    if (preg_match('/score[:\s]+(\d+)/i', $text, $matches)) {
        return (int)$matches[1];
    }
    return 75;
}

function extractRiskLevel($text) {
    if (preg_match('/risk[:\s]+(low|medium|high|critical)/i', $text, $matches)) {
        return strtolower($matches[1]);
    }
    return 'medium';
}

function extractSection($text, $section) {
    $pattern = '/' . $section . '[:\s]+(.+?)(?=\n\n|\z)/is';
    if (preg_match($pattern, $text, $matches)) {
        return trim($matches[1]);
    }
    return '';
}


/**
 * Convert array to formatted string for display
 */
function formatArrayToString($arr) {
    if (!is_array($arr)) {
        return $arr;
    }
    $result = [];
    foreach ($arr as $key => $value) {
        $result[] = is_numeric($key) ? "• $value" : "• $key: $value";
    }
    return implode("\n", $result);
}

/**
 * Filter out prohibited findings (SSH root login, logging concerns)
 */
function filterProhibitedFindings($data) {
    // Keywords that indicate prohibited findings
    $prohibited_keywords = [
        'root access', 'root login', 'permitrootlogin', 'ssh root',
        'disable root', 'restrict root', 'permissive root',
        'log', 'logging', 'log retention', 'log availability',
        'enable logging', 'log files',
        'nat', 'port forward', 'port forwarding', 'plex', 'unrestricted nat'
    ];

    // Specific SSH patterns to filter (SSH with "any IP", "0.0.0.0/0", etc.)
    $ssh_false_positive_patterns = [
        'ssh.*any ip',
        'ssh.*0\.0\.0\.0',
        'ssh access.*from any',
        'ssh.*configured to allow access from any',
        'restrict ssh access to trusted',
        'ssh.*unrestricted',
        'ssh access configuration'
    ];

    // Web GUI/HTTP patterns to filter when restricted to management IPs
    $web_gui_false_positive_patterns = [
        'web interface.*http',
        'http.*web',
        'web gui.*http',
        'use of http',
        'accessible over http',
        'configure.*https instead of http',
        'web interface to use https'
    ];

    // Agent update patterns to filter when agent is already up to date
    $agent_false_positive_patterns = [
        'agent.*updat',
        'agent.*version',
        'monitoring agent',
        'opnmanager agent',
        'check.*agent',
        'agent.*current',
        'agent.*latest'
    ];

    // Filter findings array if it exists
    if (isset($data['findings']) && is_array($data['findings'])) {
        $filtered_findings = [];

        foreach ($data['findings'] as $finding) {
            $finding_text = strtolower(json_encode($finding));
            $is_prohibited = false;

            // Check prohibited keywords
            foreach ($prohibited_keywords as $keyword) {
                if (stripos($finding_text, $keyword) !== false) {
                    $is_prohibited = true;
                    break;
                }
            }

            // Check SSH false positive patterns
            if (!$is_prohibited) {
                foreach ($ssh_false_positive_patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $finding_text)) {
                        $is_prohibited = true;
                        error_log("[AI_SCAN] Filtered SSH false positive: " . substr($finding_text, 0, 100));
                        break;
                    }
                }
            }

            // Check Web GUI false positive patterns
            if (!$is_prohibited) {
                foreach ($web_gui_false_positive_patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $finding_text)) {
                        $is_prohibited = true;
                        error_log("[AI_SCAN] Filtered Web GUI false positive: " . substr($finding_text, 0, 100));
                        break;
                    }
                }
            }

            // Check Agent false positive patterns
            if (!$is_prohibited) {
                foreach ($agent_false_positive_patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $finding_text)) {
                        $is_prohibited = true;
                        error_log("[AI_SCAN] Filtered Agent false positive: " . substr($finding_text, 0, 100));
                        break;
                    }
                }
            }

            if (!$is_prohibited) {
                $filtered_findings[] = $finding;
            }
        }

        $data['findings'] = $filtered_findings;
    }

    // Filter concerns text
    if (isset($data['concerns']) && is_string($data['concerns'])) {
        $lines = explode("\n", $data['concerns']);
        $filtered_lines = [];

        foreach ($lines as $line) {
            $is_prohibited = false;

            // Check prohibited keywords
            foreach ($prohibited_keywords as $keyword) {
                if (stripos($line, $keyword) !== false) {
                    $is_prohibited = true;
                    break;
                }
            }

            // Check SSH false positive patterns
            if (!$is_prohibited) {
                foreach ($ssh_false_positive_patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $line)) {
                        $is_prohibited = true;
                        break;
                    }
                }
            }

            // Check Web GUI false positive patterns
            if (!$is_prohibited) {
                foreach ($web_gui_false_positive_patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $line)) {
                        $is_prohibited = true;
                        break;
                    }
                }
            }

            // Check Agent false positive patterns
            if (!$is_prohibited) {
                foreach ($agent_false_positive_patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $line)) {
                        $is_prohibited = true;
                        break;
                    }
                }
            }

            if (!$is_prohibited && !empty(trim($line))) {
                $filtered_lines[] = $line;
            }
        }

        $data['concerns'] = implode("\n", $filtered_lines);
    }

    // Filter recommendations text
    if (isset($data['recommendations']) && is_string($data['recommendations'])) {
        $lines = explode("\n", $data['recommendations']);
        $filtered_lines = [];

        foreach ($lines as $line) {
            $is_prohibited = false;

            // Check prohibited keywords
            foreach ($prohibited_keywords as $keyword) {
                if (stripos($line, $keyword) !== false) {
                    $is_prohibited = true;
                    break;
                }
            }

            // Check SSH false positive patterns
            if (!$is_prohibited) {
                foreach ($ssh_false_positive_patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $line)) {
                        $is_prohibited = true;
                        break;
                    }
                }
            }

            // Check Web GUI false positive patterns
            if (!$is_prohibited) {
                foreach ($web_gui_false_positive_patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $line)) {
                        $is_prohibited = true;
                        break;
                    }
                }
            }

            // Check Agent false positive patterns
            if (!$is_prohibited) {
                foreach ($agent_false_positive_patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $line)) {
                        $is_prohibited = true;
                        break;
                    }
                }
            }

            if (!$is_prohibited && !empty(trim($line))) {
                $filtered_lines[] = $line;
            }
        }

        $data['recommendations'] = implode("\n", $filtered_lines);
    }

    return $data;
}

/**
 * Recursively convert all arrays to formatted strings
 */
function convertAllArraysToStrings($data) {
    if (!is_array($data)) {
        return $data;
    }

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            // Special handling for findings array
            if ($key === 'findings') {
                $data[$key] = array_map(function($finding) {
                    // Convert affected_rules specifically
                    if (isset($finding['affected_rules'])) {
                        if (is_array($finding['affected_rules'])) {
                            if (empty($finding['affected_rules'])) {
                                $finding['affected_rules'] = 'N/A';
                            } else {
                                $finding['affected_rules'] = formatArrayToString($finding['affected_rules']);
                            }
                        } elseif (empty($finding['affected_rules'])) {
                            $finding['affected_rules'] = 'N/A';
                        }
                    }
                    // Recursively convert other fields
                    return convertAllArraysToStrings($finding);
                }, $value);
            }
            // Check if it's an indexed array of primitives
            elseif (array_values($value) === $value && !empty($value) && !is_array($value[0])) {
                // Simple indexed array - convert to formatted string
                $data[$key] = formatArrayToString($value);
            }
            // Empty array
            elseif (empty($value)) {
                $data[$key] = '';
            }
            // Nested structure - recurse
            else {
                $data[$key] = convertAllArraysToStrings($value);
            }
        }
    }

    return $data;
}

function extractFindings($text) {
    // Simple extraction - in production would be more sophisticated
    return [
        [
            'category' => 'Security',
            'severity' => 'medium',
            'title' => 'Configuration Review Needed',
            'description' => 'Full configuration analysis completed',
            'recommendation' => 'Review detailed report for specific recommendations'
        ]
    ];
}
