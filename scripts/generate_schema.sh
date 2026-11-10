#!/bin/bash
#
# Regenerate database/schema.sql from a live OPNManager database.
#
# The published schema contains table structure for every table plus the small
# amount of static reference data the application needs to boot (the agent
# command allowlist and the feature catalogue). No customer, firewall, user or
# credential data is ever included.
#
# Since 3.80.3 it also carries the rows the migrations seed and records every
# migration as applied (see scripts/schema_baseline.php), so the first
# migrate.php after a clean install is a no-op.
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

MYSQL=(mysql)
if [ "$(id -u)" -ne 0 ]; then
    MYSQL=(sudo mysql)
fi

# Scratch database: the source structure and reference data with every
# migration applied on top. Everything published is dumped from here, so
# schema.sql is exactly "a database that has run every migration on disk" and
# can record them all as applied. Only structure and the reference tables are
# copied from the source; its settings hold credentials and are never read.
SCRATCH=opnsense_fw_test_schemagen
APP_USER=$(grep '^DB_USER=' "$REPO_ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d "\"'" || true)
APP_USER="${APP_USER:-opnsense_user}"
trap 'rm -rf "$TMP"; "${MYSQL[@]}" -u root -e "DROP DATABASE IF EXISTS \`$SCRATCH\`" 2>/dev/null || true' EXIT

"${MYSQLDUMP[@]}" -u root --no-data --skip-comments --skip-add-drop-table \
    --skip-set-charset --single-transaction --routines=false --triggers=false \
    "$DB_NAME" > "$TMP/source_structure.sql"
"${MYSQLDUMP[@]}" -u root --no-create-info --skip-comments --skip-extended-insert \
    --skip-set-charset --single-transaction --complete-insert \
    "$DB_NAME" "${SEED_TABLES[@]}" > "$TMP/source_seed.sql"

"${MYSQL[@]}" -u root -e "DROP DATABASE IF EXISTS \`$SCRATCH\`; CREATE DATABASE \`$SCRATCH\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
"${MYSQL[@]}" -u root -e "GRANT ALL PRIVILEGES ON \`$SCRATCH\`.* TO '$APP_USER'@'localhost';" 2>/dev/null || true
# The source's own schema_migrations rows are structure-only here (no data is
# copied), so migrate.php applies, and records, every file on disk.
sed -e '/enable the sandbox mode/d' -e 's|/\*!50013 DEFINER=[^*]*\*/||' "$TMP/source_structure.sql" "$TMP/source_seed.sql" \
    | "${MYSQL[@]}" -u root "$SCRATCH"

ACTUAL=$(DB_NAME="$SCRATCH" php -r 'require "'"$REPO_ROOT"'/inc/bootstrap_agent.php"; echo db()->query("SELECT DATABASE()")->fetchColumn();')
if [ "$ACTUAL" != "$SCRATCH" ]; then
    echo "refusing: PHP connected to '$ACTUAL', not '$SCRATCH'" >&2
    exit 1
fi
DB_NAME="$SCRATCH" php "$REPO_ROOT/scripts/migrate.php" > /dev/null

"${MYSQLDUMP[@]}" -u root --no-data --skip-comments --skip-add-drop-table \
    --skip-set-charset --single-transaction --routines=false --triggers=false \
    "$SCRATCH" > "$TMP/structure.sql"

"${MYSQLDUMP[@]}" -u root --no-create-info --skip-comments --skip-extended-insert \
    --skip-set-charset --single-transaction --complete-insert \
    "$SCRATCH" "${SEED_TABLES[@]}" > "$TMP/seed.sql"

DB_NAME="$SCRATCH" php "$REPO_ROOT/scripts/schema_baseline.php" "${SEED_TABLES[@]}" > "$TMP/baseline.sql"

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

    cat "$TMP/baseline.sql"
} > "$OUT"

echo "Wrote $OUT ($(grep -c '^CREATE TABLE' "$OUT") tables, $(wc -l < "$OUT") lines)"
