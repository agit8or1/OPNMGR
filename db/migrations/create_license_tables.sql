-- License Management Tables for OPNManager
-- This creates the necessary tables for license tracking and instance management

-- License Tiers (subscription plans)
CREATE TABLE IF NOT EXISTS license_tiers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tier_name VARCHAR(255) NOT NULL UNIQUE,
    max_firewalls INT NOT NULL,
    max_users INT NOT NULL,
    max_api_keys INT NOT NULL,
    price_monthly DECIMAL(10, 2),
    price_annual DECIMAL(10, 2),
    features JSON,
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
);

-- Deployed Instances (customer licenses)
CREATE TABLE IF NOT EXISTS deployed_instances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    instance_name VARCHAR(255) NOT NULL,
    instance_key VARCHAR(255) NOT NULL UNIQUE,
    license_tier VARCHAR(255) NOT NULL,
    max_firewalls INT NOT NULL,
    current_firewalls INT DEFAULT 0,
    status ENUM('trial', 'active', 'suspended', 'expired') DEFAULT 'trial',
    license_expires DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_checkin DATETIME,
    INDEX idx_status (status),
    INDEX idx_expires (license_expires),
    INDEX idx_key (instance_key),
    FOREIGN KEY (license_tier) REFERENCES license_tiers(tier_name)
);

-- License Check-ins (audit trail)
CREATE TABLE IF NOT EXISTS license_checkins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    instance_id INT NOT NULL,
    instance_key VARCHAR(255) NOT NULL,
    firewall_count INT,
    status VARCHAR(100),
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    api_version VARCHAR(50),
    checkin_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instance_id) REFERENCES deployed_instances(id) ON DELETE CASCADE,
    INDEX idx_instance (instance_id),
    INDEX idx_time (checkin_time)
);

-- License Activity Log
CREATE TABLE IF NOT EXISTS license_activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    instance_id INT,
    action VARCHAR(100) NOT NULL,
    action_type ENUM('create', 'extend', 'suspend', 'reactivate', 'delete', 'checkin') DEFAULT 'checkin',
    details TEXT,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instance_id) REFERENCES deployed_instances(id) ON DELETE CASCADE,
    INDEX idx_instance (instance_id),
    INDEX idx_action (action_type),
    INDEX idx_time (created_at)
);

-- API Keys for License Verification
CREATE TABLE IF NOT EXISTS license_api_keys (
    id INT PRIMARY KEY AUTO_INCREMENT,
    instance_id INT NOT NULL,
    api_key VARCHAR(255) NOT NULL UNIQUE,
    api_secret VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    last_used DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    FOREIGN KEY (instance_id) REFERENCES deployed_instances(id) ON DELETE CASCADE,
    INDEX idx_key (api_key),
    INDEX idx_instance (instance_id),
    INDEX idx_active (is_active)
);

-- Insert default license tiers
INSERT INTO license_tiers (tier_name, max_firewalls, max_users, max_api_keys, price_monthly, price_annual, is_active) VALUES
('Trial', 5, 3, 2, 0.00, 0.00, 1),
('Professional', 20, 10, 5, 99.99, 999.90, 1),
('Enterprise', 100, 50, 20, 499.99, 4999.90, 1),
('Ultimate', 500, 200, 100, 1999.99, 19999.90, 1)
ON DUPLICATE KEY UPDATE tier_name = tier_name;
