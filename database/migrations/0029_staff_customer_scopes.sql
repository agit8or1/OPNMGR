-- ---------------------------------------------------------------------------
-- 0029_staff_customer_scopes
-- Staff access scopes (3.79). Customers/sites remain staff-facing groupings;
-- this does not add customer logins. A user with no rows here keeps the
-- existing fleet-wide access, so installing this changes nothing until an
-- administrator restricts someone. Admins are never restricted.
CREATE TABLE IF NOT EXISTS user_customer_scopes (
    user_id     INT NOT NULL,
    customer_id INT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, customer_id),
    KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
