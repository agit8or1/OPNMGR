<?php
/**
 * Agent Scheduler Configuration
 * 
 * This file contains the scheduling logic for agent tasks:
 * 1. PING BEFORE CHECKIN - Agent performs 4 pings before each checkin
 * 2. NIGHTLY SPEEDTEST - Agent runs speedtest at 23:00 daily (unique time per firewall)
 * 
 * Agent Integration:
 * - Agents should read this file periodically to get updated scheduling info
 * - Or use static configuration deployed with agent installation
 */

// Note: db.php must be included by the calling file

// ============================================================================
// SECTION 1: PING CONFIGURATION FOR ALL AGENTS
// ============================================================================
// Every agent checks in with the following ping protocol:
// 
// BEFORE SENDING CHECKIN:
// 1. Execute: ping -c 4 <firewall_gateway>
// 2. Parse results and extract 4 latency measurements
// 3. Calculate: average, min, max latency
// 4. Include ping_results array in checkin payload
// 5. POST to /api/agent_ping_data.php with all 4 measurements
// 6. Then proceed with normal checkin to /agent_checkin.php
//
// Example Agent Code:
// ```bash
// # Ping gateway 4 times
// PING_RESULTS=$(ping -c 4 8.8.8.8 | grep -oP 'time=\K[0-9.]+')
// PING_ARRAY=($(echo "$PING_RESULTS" | tr '\n' ' '))
// 
// # Send ping data to server
// curl -X POST https://manager.local/api/agent_ping_data.php \
//   -H "Content-Type: application/json" \
//   -d '{
//     "firewall_id": '$FIREWALL_ID',
//     "agent_token": "'$AGENT_TOKEN'",
//     "ping_results": [
//       {"latency_ms": '$PING_ARRAY[0]'},
//       {"latency_ms": "'$PING_ARRAY[1]'"},
//       {"latency_ms": "'$PING_ARRAY[2]'"},
//       {"latency_ms": "'$PING_ARRAY[3]'"}
//     ]
//   }'
// ```
// ============================================================================

class AgentScheduler {
    private $DB;
    
    public function __construct($db) {
        $this->DB = $db;
    }
    
    /**
     * Get speedtest configuration for all firewalls
     * Each firewall runs speedtest 2 times daily at 6-hour intervals
     * Times are deterministic per firewall_id (staggered to avoid load spikes)
     *
     * @return array Configuration with firewall_id => scheduled times
     */
    public function getSpeedtestSchedule() {
        try {
            $stmt = $this->DB->prepare('SELECT id FROM firewalls ORDER BY id');
            $stmt->execute();
            $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $schedule = [];

            foreach ($firewalls as $idx => $fw) {
                $firewall_id = $fw['id'];

                // Use firewall_id as seed for consistent random offset (0-59 minutes)
                // This staggers firewalls across each hour to avoid simultaneous tests
                $hash = crc32("speedtest_" . $firewall_id);
                $minute_offset = abs($hash) % 60;

                // Schedule 2 tests per day at 12-hour intervals: 06:XX, 18:XX
                $test_hours = [6, 18];
                $scheduled_times = [];

                foreach ($test_hours as $hour) {
                    $scheduled_times[] = [
                        'hour' => $hour,
                        'minute' => $minute_offset,
                        'time' => str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($minute_offset, 2, '0', STR_PAD_LEFT)
                    ];
                }

                $schedule[$firewall_id] = [
                    'times' => $scheduled_times,
                    'frequency' => '2 times daily',
                    'interval_hours' => 12,
                    'scheduled_times_string' => implode(', ', array_column($scheduled_times, 'time')),
                    'description' => "Firewall {$firewall_id} runs speedtest 2 times daily at " . implode(', ', array_column($scheduled_times, 'time')) . " UTC"
                ];
            }

            return $schedule;
        } catch (Exception $e) {
            error_log("agent_scheduler.php error: " . $e->getMessage());
            return ['error' => 'Internal server error'];
        }
    }
    
    /**
     * Get ping configuration (same for all agents)
     * 
     * @return array Configuration for ping protocol
     */
    public function getPingConfiguration() {
        return [
            'enabled' => true,
            'ping_count' => 4,
            'ping_target' => '8.8.8.8',  // Can be customized per firewall
            'description' => 'Agent performs 4 pings before each checkin',
            'data_endpoint' => '/api/agent_ping_data.php',
            'timing' => 'Before every checkin'
        ];
    }
    
    /**
     * Get speedtest configuration as JSON for agent consumption
     *
     * @param int $firewall_id
     * @return array Configuration for this specific firewall
     */
    public function getSpeedtestConfigForAgent($firewall_id) {
        $schedule = $this->getSpeedtestSchedule();
        $config = $schedule[$firewall_id] ?? null;

        if (!$config) {
            return ['error' => 'Firewall not found'];
        }

        return [
            'enabled' => true,
            'firewall_id' => $firewall_id,
            'scheduled_times' => $config['times'],
            'frequency' => $config['frequency'],
            'interval_hours' => $config['interval_hours'],
            'timezone' => 'UTC',
            'description' => $config['description'],
            'data_endpoint' => '/api/agent_speedtest_result.php',
            'retry_on_failure' => true,
            'max_retries' => 3,
            'timeout_seconds' => 300
        ];
    }
}

// Example usage for API endpoints:
if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $scheduler = new AgentScheduler($GLOBALS['DB'] ?? null);
    
    switch ($_GET['action']) {
        case 'ping_config':
            header('Content-Type: application/json');
            echo json_encode($scheduler->getPingConfiguration());
            break;
            
        case 'speedtest_schedule':
            header('Content-Type: application/json');
            echo json_encode($scheduler->getSpeedtestSchedule());
            break;
            
        case 'speedtest_config':
            $firewall_id = (int)($_GET['firewall_id'] ?? 0);
            if ($firewall_id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing firewall_id']);
            } else {
                header('Content-Type: application/json');
                echo json_encode($scheduler->getSpeedtestConfigForAgent($firewall_id));
            }
            break;
    }
}
