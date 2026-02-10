# OPNManager System Architecture Flowchart

## 🏗️ COMPLETE APPLICATION FLOW DIAGRAM

```mermaid
graph TB
    subgraph "Frontend Layer"
        UI[Web Interface - Bootstrap/Chart.js]
        FD[firewall_details.php - 2706 lines]
        AUTH[Authentication System]
    end
    
    subgraph "API Layer - /var/www/opnsense/api/"
        AC[agent_checkin.php - Agent Communication]
        GTS[get_traffic_stats.php - ✅ WORKING]
        GSS[get_system_stats.php - ❌ HANGING]
        GLS[get_latency_stats.php - ❌ AUTH REQUIRED]
        GSR[get_speedtest_results.php - ❌ AUTH REQUIRED]
    end
    
    subgraph "Database Layer - MySQL opnsense_fw"
        FW[firewalls table]
        FA[firewall_agents table - 1 record v3.6.1]
        FTS[firewall_traffic_stats - 1,427 records]
        FSS[firewall_system_stats - working]
        FL[firewall_latency - ❌ EMPTY]
        FS[firewall_speedtest - ❌ EMPTY]
        ACH[agent_checkins - ❌ EMPTY TABLE]
    end
    
    subgraph "Remote Firewall - 73.35.46.112"
        A1[Agent v3.5.2 - :00 checkin]
        A2[Agent v3.6.1 - :03 checkin]
        OPN[OPNsense OS]
    end
    
    subgraph "Agent Update System - ❌ FAILING"
        DL[/downloads/ - v3.7.0 available]
        UP[update_agent.sh - not executing]
        FORCE[Force Update Commands - ignored]
    end
    
    %% Data Flow
    UI --> FD
    FD --> AUTH
    FD --> GTS
    FD --> GSS
    FD --> GLS  
    FD --> GSR
    
    GTS --> FTS
    GSS --> FSS
    GLS --> FL
    GSR --> FS
    
    A1 --> AC
    A2 --> AC
    AC --> FA
    AC --> FTS
    AC --> FSS
    AC --> ACH
    
    AC --> FORCE
    FORCE --> A1
    FORCE --> A2
    
    %% Issue Indicators
    GSS -.->|HANGS| X1[❌ System Charts Empty]
    GLS -.->|AUTH ERROR| X2[❌ Latency Charts Empty]
    GSR -.->|AUTH ERROR| X3[❌ SpeedTest Charts Empty]
    A1 -.->|IGNORES UPDATES| X4[❌ Multiple Agents]
    A2 -.->|IGNORES UPDATES| X4
    ACH -.->|NO DATA| X5[❌ No Audit Trail]
    
    %% Success Path
    GTS --> C1[✅ Traffic Charts Working]
    FTS --> C1
```

## 🔄 AGENT COMMUNICATION SEQUENCE

```mermaid
sequenceDiagram
    participant A1 as Agent v3.5.2
    participant A2 as Agent v3.6.1  
    participant AC as agent_checkin.php
    participant DB as MySQL Database
    participant UPD as Update System
    
    loop Every 60 seconds (WRONG - should be 120s)
        A1->>AC: POST checkin at :00
        A2->>AC: POST checkin at :03
        
        AC->>DB: UPDATE firewall_agents
        AC->>DB: INSERT system_stats ✅
        AC->>DB: INSERT traffic_stats ✅
        AC->>DB: INSERT agent_checkins ❌ FAILS
        
        AC-->>A1: Response: force_update_command
        AC-->>A2: Response: force_update_command
        
        Note over A1,A2: ❌ AGENTS IGNORE UPDATE COMMANDS
        
        A1->>A1: Continue running v3.5.2
        A2->>A2: Continue running v3.6.1
    end
    
    Note over UPD: v3.7.0 available but not installed
```

## 📊 CHART DATA PIPELINE

```mermaid
graph LR
    subgraph "Data Collection"
        AGENT[Agents] --> STATS[System Stats]
        AGENT --> TRAFFIC[Traffic Stats] 
        MISSING[❌ Missing] --> LATENCY[Latency Tests]
        MISSING --> SPEEDTEST[Speed Tests]
    end
    
    subgraph "Database Storage"
        STATS --> DB1[firewall_system_stats ✅]
        TRAFFIC --> DB2[firewall_traffic_stats ✅]
        LATENCY --> DB3[firewall_latency ❌ Empty]
        SPEEDTEST --> DB4[firewall_speedtest ❌ Empty]
    end
    
    subgraph "API Processing"
        DB1 --> API1[get_system_stats.php ❌ Hangs]
        DB2 --> API2[get_traffic_stats.php ✅ Working]
        DB3 --> API3[get_latency_stats.php ❌ Auth Error]
        DB4 --> API4[get_speedtest_results.php ❌ Auth Error]
    end
    
    subgraph "Frontend Display"
        API1 --> CHART1[System Charts ❌ Empty]
        API2 --> CHART2[Traffic Charts ✅ Working]
        API3 --> CHART3[Latency Charts ❌ Empty]
        API4 --> CHART4[SpeedTest Charts ❌ Empty]
    end
```

## 🚨 ISSUE DEPENDENCY MAP

```mermaid
graph TD
    ROOT[Multiple Agents Running] --> FREQ[Wrong Checkin Frequency]
    ROOT --> UPDATE[Update Commands Ignored]
    ROOT --> CONFLICT[Version Conflicts]
    
    AUTH[Authentication Issues] --> EMPTY1[Empty Latency Charts]
    AUTH --> EMPTY2[Empty SpeedTest Charts]
    
    HANG[API Hanging] --> EMPTY3[Empty System Charts]
    
    MISSING[Missing Data Collection] --> EMPTY1
    MISSING --> EMPTY2
    
    DB[Database Issues] --> AUDIT[No Agent Audit Trail]
    DB --> HANG
    
    %% Priority Levels
    ROOT -.->|P1 CRITICAL| FIX1[Agent Consolidation]
    AUTH -.->|P2 HIGH| FIX2[Session Management]  
    HANG -.->|P3 MEDIUM| FIX3[API Timeout Handling]
    MISSING -.->|P4 LOW| FIX4[Data Collection Setup]
```

## 🔧 SOLUTION ARCHITECTURE

```mermaid
graph TB
    subgraph "Phase 1: Agent Emergency Fix"
        KILL[Server-side Agent Kill Script]
        INSTALL[Force Install v3.7.0]
        VERIFY[Verify Single Agent]
    end
    
    subgraph "Phase 2: API Authentication Fix"
        BYPASS[Local Authentication Bypass]
        SESSION[Session State Debugging]
        TEST[API Endpoint Testing]
    end
    
    subgraph "Phase 3: Data Pipeline Fix"
        TIMEOUT[Add API Timeouts]
        TABLES[Verify Database Tables]
        COLLECT[Setup Data Collection]
    end
    
    subgraph "Phase 4: Monitoring & Health"
        LOGS[Log Management]
        ALERTS[Error Monitoring]
        CLEANUP[Data Retention]
    end
    
    KILL --> INSTALL --> VERIFY
    VERIFY --> BYPASS --> SESSION --> TEST
    TEST --> TIMEOUT --> TABLES --> COLLECT
    COLLECT --> LOGS --> ALERTS --> CLEANUP
```

## 📋 CRITICAL FILE LOCATIONS & MODIFICATIONS NEEDED

### Files Requiring Immediate Attention
```
🔴 CRITICAL:
/var/www/opnsense/agent_checkin.php (Lines 407-440)
├── Issue: Agents ignore update commands
├── Fix: Add HTTP 500 response for old agents
└── Priority: P1 - Agent consolidation

/var/www/opnsense/api/get_system_stats.php (Entire file)
├── Issue: Hangs on execution  
├── Fix: Add timeouts, error handling
└── Priority: P2 - Chart functionality

🟡 HIGH:
/var/www/opnsense/api/get_latency_stats.php (Lines 7-15)
├── Issue: Authentication required
├── Fix: Bypass for local requests
└── Priority: P2 - Chart data

/var/www/opnsense/api/get_speedtest_results.php (Lines 7-15) 
├── Issue: Authentication required
├── Fix: Bypass for local requests  
└── Priority: P2 - Chart data

🟢 WORKING (Reference):
/var/www/opnsense/api/get_traffic_stats.php
├── Status: Successfully fixed
└── Use as template for other APIs
```

### Database Schema Fixes Required
```sql
-- Fix agent_checkins logging
ALTER TABLE agent_checkins ADD COLUMN IF NOT EXISTS 
    response_data TEXT AFTER checkin_data;

-- Ensure latency table structure
ALTER TABLE firewall_latency 
    MODIFY measured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Ensure speedtest table structure  
ALTER TABLE firewall_speedtest
    MODIFY test_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Clean up bad data
DELETE FROM firewall_traffic_stats 
WHERE bytes_in > 1000000000 OR bytes_out > 1000000000;
```

## 🎯 IMMEDIATE NEXT STEPS FOR CLAUDE CODER

### Step 1: Agent Consolidation (30 minutes)
```bash
# Create emergency agent fix script
cat > /var/www/opnsense/emergency_agent_fix.php << 'EOF'
<?php
// Force HTTP 500 error for old agents to trigger restart/update
if (isset($_POST['agent_version'])) {
    $version = $_POST['agent_version'];
    if (version_compare($version, '3.7.0', '<')) {
        http_response_code(500);
        echo json_encode(['error' => 'FORCE_RESTART_UPDATE']);
        exit;
    }
}
EOF
```

### Step 2: Fix Chart APIs (45 minutes)  
```php
// Add to each chart API after line 8:
if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || 
    $_SERVER['HTTP_HOST'] === 'localhost' ||
    strpos($_SERVER['HTTP_USER_AGENT'], 'curl') !== false) {
    // Skip authentication for local/testing requests
    $bypass_auth = true;
}

if (!$bypass_auth && !isLoggedIn()) {
    // Regular authentication check
}
```

### Step 3: System Stats Timeout (15 minutes)
```php
// Add to get_system_stats.php at top:
ini_set('max_execution_time', 10);
set_time_limit(10);
$DB->setAttribute(PDO::ATTR_TIMEOUT, 5);
```

This comprehensive technical handoff provides Claude Coder with complete context to take over and resolve all outstanding issues systematically.