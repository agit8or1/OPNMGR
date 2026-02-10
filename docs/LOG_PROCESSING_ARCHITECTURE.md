# Log Processing Architecture - Design Decision

## Date: October 23, 2025
## Version: 1.0

## Executive Summary

This document analyzes three approaches for log processing in OPNManager: **Agent-Based (Push)**, **Pull-Based (Central Fetch)**, and **Hybrid**. After comprehensive evaluation, the **HYBRID APPROACH** is recommended as it provides the best balance of real-time monitoring, bandwidth efficiency, and operational flexibility.

---

## Architecture Options

### Option 1: Agent-Based (Push) Architecture

**Description**: Firewall agent compresses and uploads logs periodically to central server.

**How It Works**:
1. Agent monitors log files on firewall
2. Compresses new log entries (gzip)
3. POSTs compressed logs to central API endpoint
4. Central server stores and processes logs

**Advantages**:
- ✅ Real-time or near-real-time log collection
- ✅ Reduces central server SSH connection overhead
- ✅ Firewall controls what data leaves the network
- ✅ Better for high-security environments (outbound only)
- ✅ Scales well (distributed processing)
- ✅ Firewall can pre-filter sensitive data

**Disadvantages**:
- ❌ Requires agent updates for log format changes
- ❌ More complex agent logic (monitoring, compression, uploading)
- ❌ Increased storage on central server
- ❌ Continuous bandwidth usage (every upload)
- ❌ Agent failure = no logs collected
- ❌ Requires log rotation management on firewall

**Bandwidth Impact**:
- Continuous small uploads (every 5-15 minutes)
- Typical: 50-200 KB per upload (compressed)
- Daily: ~5-20 MB per firewall

---

### Option 2: Pull-Based (Central Fetch) Architecture

**Description**: Central server fetches logs on-demand or on schedule via SSH.

**How It Works**:
1. Central server initiates SSH connection
2. Fetches recent log entries (tail -n X)
3. Processes and stores locally
4. Closes connection

**Advantages**:
- ✅ Simpler agent (no log handling required)
- ✅ Central control over what/when to fetch
- ✅ No agent updates needed for log changes
- ✅ On-demand fetching (only when needed)
- ✅ Better for troubleshooting (immediate access)
- ✅ Less firewall disk I/O

**Disadvantages**:
- ❌ Requires SSH access to all firewalls
- ❌ Potential SSH connection bottleneck
- ❌ Higher latency (not real-time)
- ❌ SSH key management complexity
- ❌ Firewall load during fetch (reading large files)
- ❌ Network issues = no data collection

**Bandwidth Impact**:
- Periodic large fetches (every 30-60 minutes)
- Typical: 500 KB - 2 MB per fetch (uncompressed over SSH)
- Daily: ~5-20 MB per firewall (similar to push)

---

### Option 3: Hybrid Architecture (RECOMMENDED)

**Description**: Agent monitors for events and triggers uploads, with scheduled baseline fetches.

**How It Works**:
1. **Normal Operation**: Agent monitors logs passively
2. **Event Triggers**: On critical events (attacks, high blocks), agent immediately uploads recent logs
3. **Scheduled Fetches**: Central server fetches full logs periodically (daily/weekly)
4. **On-Demand**: Manual fetch via UI for troubleshooting

**Advantages**:
- ✅ Real-time alerts for critical events
- ✅ Bandwidth efficient (only uploads when needed)
- ✅ Fallback mechanism (central fetch if agent fails)
- ✅ Best of both worlds (proactive + reactive)
- ✅ Flexible scheduling per firewall
- ✅ Can prioritize high-risk firewalls
- ✅ Automatic failover (if one method fails, use other)

**Disadvantages**:
- ❌ Most complex to implement
- ❌ Requires both agent logic AND central fetching
- ❌ Two code paths to maintain
- ❌ More configuration options

**Bandwidth Impact**:
- Minimal during normal operation (event-driven)
- Spikes during events or scheduled fetches
- Daily average: ~2-10 MB per firewall (most efficient)

---

## Detailed Comparison Matrix

| Criteria | Agent-Based (Push) | Pull-Based (Central) | Hybrid (Recommended) |
|----------|-------------------|---------------------|---------------------|
| **Real-time Capability** | ★★★★★ Excellent | ★★☆☆☆ Poor | ★★★★☆ Very Good |
| **Bandwidth Efficiency** | ★★★☆☆ Moderate | ★★★☆☆ Moderate | ★★★★★ Excellent |
| **Scalability** | ★★★★★ Excellent | ★★★☆☆ Moderate | ★★★★☆ Very Good |
| **Implementation Complexity** | ★★★☆☆ Moderate | ★★★★☆ Simple | ★★☆☆☆ Complex |
| **Maintenance Burden** | ★★☆☆☆ High | ★★★★☆ Low | ★★★☆☆ Moderate |
| **Reliability** | ★★★☆☆ Moderate | ★★★☆☆ Moderate | ★★★★★ Excellent |
| **Security** | ★★★★☆ Very Good | ★★★☆☆ Moderate | ★★★★☆ Very Good |
| **Troubleshooting** | ★★☆☆☆ Difficult | ★★★★★ Excellent | ★★★★☆ Very Good |
| **Agent Simplicity** | ★★☆☆☆ Complex | ★★★★★ Simple | ★★★☆☆ Moderate |
| **Central Control** | ★★☆☆☆ Limited | ★★★★★ Complete | ★★★★☆ Very Good |

---

## Decision: Hybrid Architecture

### Rationale

1. **Real-time + Efficiency**: Critical events trigger immediate uploads while routine data uses scheduled fetches
2. **Reliability**: Dual-path design ensures data collection continues even if one method fails
3. **Bandwidth**: Most efficient - only transmits data when necessary
4. **Flexibility**: Can adjust strategy per firewall based on risk level or bandwidth constraints
5. **Future-proof**: Easy to add new trigger conditions or fetch strategies

### Implementation Strategy

#### Phase 1: Event-Based Agent Enhancement (Week 1-2)
- Add event detection to existing agent
- Implement log compression and upload on trigger
- Create API endpoint for log uploads
- Add trigger configuration per firewall

#### Phase 2: Scheduled Fetch System (Week 3-4)
- Implement cron-based scheduled fetches
- Add job queue for managing fetch requests
- Create background worker for processing
- Implement conflict resolution (avoid simultaneous fetch/upload)

#### Phase 3: Integration & Testing (Week 5-6)
- Integrate event-based and scheduled systems
- Add UI for configuring fetch schedules
- Implement monitoring and alerting
- Performance testing and optimization

---

## Architecture Diagram

```
┌──────────────────────────────────────────────────────────────┐
│                     Central Server                            │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐  │
│  │          Log Collection API                            │  │
│  │  • /api/log_upload.php (Agent Push)                   │  │
│  │  • /api/log_fetch.php (Scheduled Pull)                │  │
│  │  • /api/log_manual_fetch.php (On-Demand)              │  │
│  └────────────────────────────────────────────────────────┘  │
│                          ↓                                    │
│  ┌────────────────────────────────────────────────────────┐  │
│  │          Log Processing Engine                         │  │
│  │  • Decompression                                       │  │
│  │  • Parsing & Normalization                            │  │
│  │  • GeoIP Enrichment                                   │  │
│  │  • Categorization                                      │  │
│  │  • Threat Detection                                    │  │
│  └────────────────────────────────────────────────────────┘  │
│                          ↓                                    │
│  ┌────────────────────────────────────────────────────────┐  │
│  │          Storage & Analysis                            │  │
│  │  • log_entries table (processed logs)                 │  │
│  │  • log_analysis_results (AI analysis)                 │  │
│  │  • detected_threats (security events)                 │  │
│  │  • ip_reputation (threat intelligence)                │  │
│  └────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
                    ↑                        ↑
              Event Trigger            Scheduled Fetch
                    │                        │
┌───────────────────┴────────────────────────┴───────────────┐
│                  Firewall Agent                             │
│                                                            │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Event Monitor (Passive)                            │  │
│  │  • Watch for critical events                        │  │
│  │  • Threshold breaches                               │  │
│  │  • Attack signatures                                │  │
│  └──────────────────────────────────────────────────────┘  │
│                          ↓                                  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Trigger Logic                                       │  │
│  │  • Failed auth > 10/min → UPLOAD                    │  │
│  │  • Blocked attempts > 100/min → UPLOAD              │  │
│  │  │  • IDS alert → UPLOAD                              │  │
│  │  • Otherwise → Wait for scheduled fetch             │  │
│  └──────────────────────────────────────────────────────┘  │
│                          ↓                                  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Log Compression & Upload                            │  │
│  │  • gzip recent logs (last 1000 lines)               │  │
│  │  • POST to /api/log_upload.php                      │  │
│  │  • Include metadata (timestamp, log type)           │  │
│  └──────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

---

## Event Triggers Configuration

### Critical Events (Immediate Upload)
1. **Failed Authentication**
   - Threshold: > 10 attempts per minute
   - Action: Upload auth.log (last 1000 lines)
   
2. **High Block Rate**
   - Threshold: > 100 blocks per minute
   - Action: Upload filter.log (last 2000 lines)
   
3. **IDS/IPS Alerts**
   - Threshold: Any severity HIGH or CRITICAL
   - Action: Upload suricata.log (last 500 lines)
   
4. **Service Failures**
   - Threshold: Any critical service restart
   - Action: Upload system.log (last 500 lines)

### Warning Events (Batched Upload Every 15 Minutes)
1. **Moderate Block Rate** (50-100/min)
2. **VPN Connection Issues** (> 5 failures/hour)
3. **DHCP Exhaustion** (> 90% pool utilization)

### Scheduled Fetches (No Agent Upload)
1. **Daily Full Fetch**: All logs, last 24 hours
2. **Weekly Archive**: Compressed full logs
3. **On-Demand**: Manual fetch via UI

---

## GeoIP Integration

### Implementation
1. **Database**: MaxMind GeoLite2 City
2. **Update Frequency**: Weekly via cron
3. **Integration Point**: Log processing pipeline

### Enrichment Data
- Country code
- Country name
- City
- Latitude/Longitude
- ISP (if available)
- ASN (Autonomous System Number)

### Use Cases
- Geographic threat visualization
- Country-based blocking recommendations
- Traffic pattern analysis
- Suspicious location detection

---

## Traffic Categorization

### Categories
1. **Social Media**: Facebook, Twitter, Instagram, TikTok
2. **Streaming**: Netflix, YouTube, Spotify, Twitch
3. **Productivity**: Office 365, Google Workspace, Slack
4. **Shopping**: Amazon, eBay, retail sites
5. **News**: News websites and aggregators
6. **Gaming**: Gaming platforms and services
7. **Adult**: Adult content sites
8. **Malware**: Known malicious domains
9. **P2P**: Torrent and file sharing
10. **Gambling**: Online gambling sites

### Implementation
- **URL Database**: Maintain domain categorization database
- **DNS Analysis**: Categorize based on DNS queries
- **Pattern Matching**: Regex patterns for dynamic categorization
- **AI Classification**: Use AI to categorize unknown domains

### Productivity Scoring
- **High Productivity**: Work-related tools, documentation = +10 points
- **Neutral**: News, educational content = 0 points
- **Low Productivity**: Social media, streaming = -5 points
- **Unproductive**: Gaming, adult, excessive streaming = -10 points

**Daily Score**: Sum of all traffic scores / total traffic time * 100

---

## Database Schema

### New Tables

```sql
-- Main log entries table
CREATE TABLE log_entries (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    firewall_id INT NOT NULL,
    log_type ENUM('filter', 'dhcp', 'system', 'auth', 'vpn', 'squid', 'suricata') NOT NULL,
    timestamp TIMESTAMP NOT NULL,
    source_ip VARCHAR(45),
    destination_ip VARCHAR(45),
    port INT,
    protocol VARCHAR(20),
    action VARCHAR(50),
    message TEXT,
    raw_log TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE CASCADE,
    INDEX idx_firewall_type_time (firewall_id, log_type, timestamp),
    INDEX idx_source_ip (source_ip),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB;

-- GeoIP enriched data
CREATE TABLE log_geoip (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    log_entry_id BIGINT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    country_code CHAR(2),
    country_name VARCHAR(100),
    city VARCHAR(100),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    isp VARCHAR(200),
    asn INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (log_entry_id) REFERENCES log_entries(id) ON DELETE CASCADE,
    INDEX idx_country (country_code),
    INDEX idx_ip (ip_address)
) ENGINE=InnoDB;

-- Traffic categorization
CREATE TABLE traffic_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL UNIQUE,
    category VARCHAR(50) NOT NULL,
    subcategory VARCHAR(50),
    productivity_score INT DEFAULT 0,
    is_malicious BOOLEAN DEFAULT FALSE,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_domain (domain)
) ENGINE=InnoDB;

-- Daily productivity reports
CREATE TABLE productivity_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firewall_id INT NOT NULL,
    report_date DATE NOT NULL,
    total_traffic_mb DECIMAL(10, 2),
    productive_traffic_mb DECIMAL(10, 2),
    unproductive_traffic_mb DECIMAL(10, 2),
    productivity_score INT,
    top_categories JSON,
    top_users JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE CASCADE,
    UNIQUE KEY unique_firewall_date (firewall_id, report_date),
    INDEX idx_date (report_date)
) ENGINE=InnoDB;
```

---

## API Endpoints

### 1. Log Upload API (Agent Push)
**Endpoint**: `/api/log_upload.php`

**Request**:
```json
{
  "firewall_id": 123,
  "instance_key": "abc-123-def",
  "log_type": "filter",
  "trigger": "high_block_rate",
  "timestamp": "2025-10-23T14:30:00Z",
  "logs_compressed": "base64_encoded_gzip_data"
}
```

**Response**:
```json
{
  "success": true,
  "message": "Logs uploaded successfully",
  "lines_processed": 1524,
  "threats_detected": 3,
  "upload_id": 789
}
```

### 2. Scheduled Fetch API (Central Pull)
**Endpoint**: `/api/log_fetch.php`

**Request** (Internal - called by cron):
```php
fetchLogs($firewall_id, $log_types, $hours_back);
```

**Process**:
1. SSH to firewall
2. Fetch logs: `tail -n 5000 /var/log/filter.log`
3. Process and store
4. Return summary

### 3. Manual Fetch API (On-Demand)
**Endpoint**: `/api/log_manual_fetch.php`

**Request**:
```json
{
  "firewall_id": 123,
  "log_types": ["filter", "auth"],
  "lines": 1000
}
```

---

## Configuration Interface

### Firewall Log Settings Page
**File**: `/var/www/opnsense/firewall_log_settings.php`

**Features**:
- Enable/disable event-based uploads
- Configure trigger thresholds
- Set scheduled fetch frequency
- Choose log types to collect
- Bandwidth limits per day/week
- Storage retention policies

---

## Performance Considerations

### Storage
- **Estimate**: 100 MB per firewall per day (uncompressed)
- **Compressed**: ~20 MB per firewall per day
- **Retention**: 30 days online, 90 days compressed archive
- **Database**: 50 firewalls * 20 MB * 30 days = 30 GB

### Processing
- **Real-time**: Process event-triggered uploads immediately
- **Batch**: Process scheduled fetches in background queue
- **Parallel**: Use job workers for concurrent processing

### Bandwidth
- **Normal**: 5-10 MB per firewall per day
- **High Activity**: 20-50 MB per firewall per day
- **Burst**: Event uploads add 1-5 MB per event

---

## Security Considerations

1. **Encryption**: All uploads over HTTPS with certificate pinning
2. **Authentication**: Instance key validation for uploads
3. **Authorization**: SSH key authentication for fetches
4. **Data Privacy**: Option to redact sensitive IPs/domains
5. **Audit Trail**: All log access logged with user/timestamp

---

## Monitoring & Alerting

### Metrics to Track
- Upload success/failure rate
- Fetch success/failure rate
- Processing time per log batch
- Storage utilization
- Bandwidth usage per firewall
- Event trigger frequency

### Alerts
- Agent not uploading (> 24 hours)
- Fetch failures (> 3 consecutive)
- Storage approaching limit (> 80%)
- High threat detection rate
- Bandwidth limit exceeded

---

## Implementation Timeline

### Week 1-2: Event-Based Agent
- Day 1-3: Event monitoring logic
- Day 4-7: Compression and upload
- Day 8-10: API endpoint
- Day 11-14: Testing

### Week 3-4: Scheduled Fetch
- Day 15-17: SSH fetch implementation
- Day 18-21: Job queue system
- Day 22-24: Background workers
- Day 25-28: Integration testing

### Week 5-6: UI & Integration
- Day 29-32: Configuration interface
- Day 33-35: GeoIP integration
- Day 36-38: Categorization system
- Day 39-42: Final testing & deployment

---

## Conclusion

The **Hybrid Architecture** provides the optimal solution for log processing in OPNManager:
- Real-time alerting for critical events
- Efficient bandwidth usage
- Reliable data collection with failover
- Flexible configuration per firewall
- Scalable to hundreds of firewalls

**Recommendation**: Proceed with Hybrid implementation starting with Phase 1 (Event-Based Agent Enhancement).
