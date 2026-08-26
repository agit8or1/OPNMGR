-- ---------------------------------------------------------------------------
-- 0008_customers_and_sites
--
-- Customer and site model (P1 #9).
--
-- A customer is an MSP customer organisation: a container used to group managed
-- firewalls. Customers do not log in and have no accounts. Sites sit optionally
-- beneath a customer:  Customer -> Site -> Firewall(s).
--
-- Before this migration a firewall was linked to a customer by TWO independent
-- free-text columns, firewalls.customer_name and firewalls.customer_group, with
-- no foreign key. customers.php counted firewalls by matching customer_name,
-- while firewall_edit.php wrote customer_group, so the Customers page reported
-- zero firewalls for customers that had them.
--
-- Both legacy columns are kept and backfilled so the ~19 files that read them
-- keep working; new code uses customer_id / site_id.
-- ---------------------------------------------------------------------------

-- --- customers -------------------------------------------------------------
ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS code VARCHAR(32) NULL
        COMMENT 'Short customer code used in search and reports',
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS timezone VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS tags VARCHAR(255) NULL
        COMMENT 'Comma-separated labels',
    ADD COLUMN IF NOT EXISTS maintenance_window_start TIME NULL,
    ADD COLUMN IF NOT EXISTS maintenance_window_end TIME NULL,
    ADD COLUMN IF NOT EXISTS maintenance_window_days VARCHAR(32) NULL
        COMMENT 'Comma-separated day numbers, 0=Sunday';

ALTER TABLE customers
    ADD INDEX IF NOT EXISTS idx_is_active (is_active),
    ADD INDEX IF NOT EXISTS idx_code (code);

-- --- sites -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sites (
    id           INT(11) NOT NULL AUTO_INCREMENT,
    customer_id  INT(11) NOT NULL,
    name         VARCHAR(255) NOT NULL,
    code         VARCHAR(32) NULL,
    timezone     VARCHAR(64) NULL,
    address      TEXT NULL,
    notes        TEXT NULL,
    maintenance_window_start TIME NULL,
    maintenance_window_end   TIME NULL,
    maintenance_window_days  VARCHAR(32) NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_customer_site (customer_id, name),
    KEY idx_customer (customer_id),
    CONSTRAINT fk_sites_customer FOREIGN KEY (customer_id)
        REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Organisational grouping beneath a customer. Sites do not log in.';

-- --- firewalls -------------------------------------------------------------
ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS customer_id INT(11) NULL,
    ADD COLUMN IF NOT EXISTS site_id INT(11) NULL;

ALTER TABLE firewalls
    ADD INDEX IF NOT EXISTS idx_customer_id (customer_id),
    ADD INDEX IF NOT EXISTS idx_site_id (site_id);

-- --- backfill ---------------------------------------------------------------
-- Create a customer row for any group name that is in use but has no customer.
INSERT INTO customers (name, notes)
SELECT DISTINCT TRIM(f.customer_group), 'Created automatically from firewalls.customer_group during the 3.12.0 upgrade'
  FROM firewalls f
 WHERE TRIM(IFNULL(f.customer_group, '')) <> ''
   AND NOT EXISTS (
       SELECT 1 FROM customers c WHERE LOWER(c.name) = LOWER(TRIM(f.customer_group))
   );

INSERT INTO customers (name, notes)
SELECT DISTINCT TRIM(f.customer_name), 'Created automatically from firewalls.customer_name during the 3.12.0 upgrade'
  FROM firewalls f
 WHERE TRIM(IFNULL(f.customer_name, '')) <> ''
   AND NOT EXISTS (
       SELECT 1 FROM customers c WHERE LOWER(c.name) = LOWER(TRIM(f.customer_name))
   );

-- Link firewalls: customer_name wins where set, otherwise customer_group.
UPDATE firewalls f
  JOIN customers c ON LOWER(c.name) = LOWER(TRIM(f.customer_name))
   SET f.customer_id = c.id
 WHERE f.customer_id IS NULL
   AND TRIM(IFNULL(f.customer_name, '')) <> '';

UPDATE firewalls f
  JOIN customers c ON LOWER(c.name) = LOWER(TRIM(f.customer_group))
   SET f.customer_id = c.id
 WHERE f.customer_id IS NULL
   AND TRIM(IFNULL(f.customer_group, '')) <> '';

-- Keep the legacy display column consistent with the link we just made, so the
-- pages still reading customer_name show the right thing.
UPDATE firewalls f
  JOIN customers c ON c.id = f.customer_id
   SET f.customer_name = c.name
 WHERE TRIM(IFNULL(f.customer_name, '')) = '';

-- Derive a short code for customers that do not have one.
UPDATE customers
   SET code = UPPER(LEFT(REGEXP_REPLACE(name, '[^A-Za-z0-9]', ''), 8))
 WHERE code IS NULL OR code = '';

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('default_timezone', 'America/New_York');
