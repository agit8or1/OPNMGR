#!/bin/bash
#
# Regenerate database/schema.sql from a live OPNManager database.
#
# The published schema contains table structure for every table plus the small
# amount of static reference data the application needs to boot (the agent
# command allowlist and the feature catalogue). No customer, firewall, user or
# credential data is ever included.
#
# Usage:  scripts/generate_schema.sh [database_name]
#
set -euo pipefail

DB_NAME="${1:-opnsense_fw}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$REPO_ROOT/database/schema.sql"

# Static reference data shipped with the schema.
SEED_TABLES=(approved_commands features)

MYSQLDUMP=(mysqldump)
if [ "$(id -u)" -ne 0 ]; then
    MYSQLDUMP=(sudo mysqldump)
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

"${MYSQLDUMP[@]}" -u root --no-data --skip-comments --skip-add-drop-table \
    --skip-set-charset --single-transaction --routines=false --triggers=false \
    "$DB_NAME" > "$TMP/structure.sql"

"${MYSQLDUMP[@]}" -u root --no-create-info --skip-comments --skip-extended-insert \
    --skip-set-charset --single-transaction --complete-insert \
    "$DB_NAME" "${SEED_TABLES[@]}" > "$TMP/seed.sql"

APP_VERSION="$(cat "$REPO_ROOT/VERSION" 2>/dev/null || echo 'unknown')"

{
    cat <<EOF
-- =============================================================================
-- OPNManager - Database Schema
-- =============================================================================
-- Generated from the reference installation for OPNManager v${APP_VERSION}.
-- Regenerate with: scripts/generate_schema.sh
--
-- This file creates the database, every table, and the static reference data
-- the application needs to start. It contains no user accounts, firewalls,
-- credentials or customer data.
--
-- Import:
--     mysql -u root -p < database/schema.sql
--
-- Then create the application database user (see README.md):
--     CREATE USER 'opnsense_user'@'localhost' IDENTIFIED BY 'your-password';
--     GRANT ALL PRIVILEGES ON opnsense_fw.* TO 'opnsense_user'@'localhost';
--     FLUSH PRIVILEGES;
--
-- Finally create your first admin account:
--     php scripts/create_admin.php
-- =============================================================================

CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE \`${DB_NAME}\`;

-- -----------------------------------------------------------------------------
-- Table structure
-- -----------------------------------------------------------------------------
EOF

    # Strip the MariaDB sandbox marker, the DEFINER clause (which would tie the
    # view to a user that does not exist on a fresh install) and the carried-over
    # AUTO_INCREMENT counters, then make table and view creation idempotent so
    # the file can be re-imported safely.
    sed -e '/enable the sandbox mode/d' \
        -e 's|/\*!50013 DEFINER=[^*]*\*/||' \
        -e 's| AUTO_INCREMENT=[0-9]*||' \
        -e 's|^CREATE TABLE `|CREATE TABLE IF NOT EXISTS `|' \
        -e 's|CREATE VIEW `|CREATE OR REPLACE VIEW `|' \
        "$TMP/structure.sql"

    cat <<'EOF'

-- -----------------------------------------------------------------------------
-- Reference data
-- -----------------------------------------------------------------------------
EOF

    sed -e '/enable the sandbox mode/d' \
        -e 's|^INSERT INTO|INSERT IGNORE INTO|' \
        "$TMP/seed.sql"
} > "$OUT"

echo "Wrote $OUT ($(grep -c '^CREATE TABLE' "$OUT") tables, $(wc -l < "$OUT") lines)"
