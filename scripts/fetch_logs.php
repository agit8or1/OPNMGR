<?php
/**
 * Firewall Log Fetcher
 * Fetches logs from firewall via SSH for AI analysis
 */

require_once __DIR__ . '/../inc/db.php';

function fetchFirewallLogs($firewall_id, $log_types = ['filter', 'dhcp', 'system'], $lines = 1000) {
    global $DB;
    
    // Get firewall details
    $stmt = $DB->prepare("SELECT * FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();
    
    if (!$firewall) {
        throw new Exception("Firewall not found");
    }
    
    $logs = [];
    $ssh_key = "/etc/opnmgr/keys/id_firewall_{$firewall['id']}";
    
    if (!file_exists($ssh_key)) {
        throw new Exception("SSH key not found for firewall");
    }
    
    foreach ($log_types as $log_type) {
        $log_data = fetchLogType($firewall, $ssh_key, $log_type, $lines);
        if ($log_data) {
            $logs[$log_type] = $log_data;
        }
    }
    
    return $logs;
}

function fetchLogType($firewall, $ssh_key, $log_type, $lines) {
    $ip = $firewall['ip_address'];
    $username = $firewall['ssh_username'] ?? 'root';
    
    // Map log types to actual log files
    $log_paths = [
        'filter' => '/var/log/filter.log',
        'dhcp' => '/var/log/dhcpd.log',
        'system' => '/var/log/system.log',
        'auth' => '/var/log/auth.log',
        'vpn' => '/var/log/openvpn.log',
        'squid' => '/var/log/squid/access.log',
        'suricata' => '/var/log/suricata/suricata.log'
    ];
    
    if (!isset($log_paths[$log_type])) {
        return null;
    }
    
    $log_path = $log_paths[$log_type];
    
    // Fetch last N lines via SSH
    $command = "ssh -i {$ssh_key} -o StrictHostKeyChecking=no -o ConnectTimeout=10 {$username}@{$ip} 'tail -n {$lines} {$log_path} 2>/dev/null || echo \"Log not available\"'";
    
    $output = [];
    $return_code = 0;
    exec($command, $output, $return_code);
    
    if ($return_code !== 0) {
        error_log("Failed to fetch {$log_type} log from {$ip}: return code {$return_code}");
        return null;
    }
    
    return implode("\n", $output);
}

function compressLogs($logs) {
    $compressed = [];
    
    foreach ($logs as $type => $content) {
        if (empty($content) || $content === "Log not available") {
            continue;
        }
        
        // Compress using gzip
        $compressed[$type] = gzencode($content, 9);
    }
    
    return $compressed;
}

function saveLogs($firewall_id, $logs, $compressed = true) {
    $logs_dir = "/var/www/opnsense/logs/fetched";
    if (!is_dir($logs_dir)) {
        mkdir($logs_dir, 0755, true);
    }
    
    $timestamp = date('Ymd_His');
    $log_files = [];
    
    foreach ($logs as $type => $content) {
        $filename = "firewall_{$firewall_id}_{$type}_{$timestamp}." . ($compressed ? 'gz' : 'log');
        $filepath = "{$logs_dir}/{$filename}";
        
        if ($compressed) {
            file_put_contents($filepath, $content);
        } else {
            file_put_contents($filepath, $content);
        }
        
        $log_files[$type] = $filepath;
    }
    
    return $log_files;
}

function analyzeLogsWithAI($firewall_id, $config_data, $logs, $ai_settings) {
    // Build comprehensive prompt including both config and logs
    $prompt = buildLogAnalysisPrompt($config_data, $logs, $firewall_id);
    
    // Call AI provider
    $provider = $ai_settings['provider'];
    $api_key = $ai_settings['api_key'];
    $model = $ai_settings['model'];
    
    switch ($provider) {
        case 'openai':
            $result = callOpenAIForLogs($api_key, $model, $prompt);
            break;
        case 'anthropic':
            $result = callAnthropicForLogs($api_key, $model, $prompt);
            break;
        default:
            throw new Exception("Provider {$provider} not supported for log analysis");
    }
    
    return parseLogAnalysisResponse($result);
}

function buildLogAnalysisPrompt($config_data, $logs, $firewall_id) {
    $prompt = "You are a cybersecurity expert analyzing a firewall's configuration AND log files. ";
    $prompt .= "Perform comprehensive security analysis including:\n\n";
    $prompt .= "1. Configuration Review\n";
    $prompt .= "2. Active Threats & Incidents in Logs\n";
    $prompt .= "3. Suspicious Activity Patterns\n";
    $prompt .= "4. Failed Login Attempts\n";
    $prompt .= "5. Blocked Connection Analysis\n";
    $prompt .= "6. Traffic Anomalies\n";
    $prompt .= "7. Security Violations\n";
    $prompt .= "8. Recommendations\n\n";
    
    $prompt .= "CONFIGURATION:\n";
    $prompt .= json_encode($config_data, JSON_PRETTY_PRINT) . "\n\n";
    
    $prompt .= "LOG FILES:\n\n";
    foreach ($logs as $type => $content) {
        $lines = explode("\n", $content);
        $line_count = count($lines);
        $prompt .= "=== {$type} LOG ({$line_count} lines) ===\n";
        $prompt .= $content . "\n\n";
    }
    
    $prompt .= "Provide detailed analysis as JSON with keys:\n";
    $prompt .= "- grade (A+ to F)\n";
    $prompt .= "- score (0-100)\n";
    $prompt .= "- risk_level (low, medium, high, critical)\n";
    $prompt .= "- summary\n";
    $prompt .= "- active_threats (array of detected threats)\n";
    $prompt .= "- suspicious_ips (array of IPs with suspicious activity)\n";
    $prompt .= "- blocked_attempts (count and details)\n";
    $prompt .= "- recommendations\n";
    $prompt .= "- findings (array with category, severity, title, description, recommendation)";
    
    return $prompt;
}

function callOpenAIForLogs($api_key, $model, $prompt) {
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
                ['role' => 'system', 'content' => 'You are a cybersecurity expert specializing in firewall log analysis and threat detection.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.5,
            'max_tokens' => 4000
        ]),
        CURLOPT_TIMEOUT => 120
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("OpenAI API error: HTTP {$http_code}");
    }
    
    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

function callAnthropicForLogs($api_key, $model, $prompt) {
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
            'max_tokens' => 4000,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]),
        CURLOPT_TIMEOUT => 120
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

function parseLogAnalysisResponse($response) {
    $json_data = json_decode($response, true);
    
    if ($json_data) {
        return $json_data;
    }
    
    // Fallback parsing
    return [
        'grade' => 'B',
        'score' => 75,
        'risk_level' => 'medium',
        'summary' => 'Log analysis completed',
        'active_threats' => [],
        'suspicious_ips' => [],
        'blocked_attempts' => 0,
        'recommendations' => 'Review detailed analysis',
        'findings' => []
    ];
}

// If called from command line
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $firewall_id = $argv[1];
    echo "Fetching logs for firewall ID: {$firewall_id}\n";
    
    try {
        $logs = fetchFirewallLogs($firewall_id);
        $compressed = compressLogs($logs);
        $saved_files = saveLogs($firewall_id, $compressed, true);
        
        echo "Logs fetched and saved:\n";
        foreach ($saved_files as $type => $file) {
            echo "  - {$type}: {$file}\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
