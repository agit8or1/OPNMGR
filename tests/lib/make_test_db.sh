#!/bin/sh
# Create (or recreate) a disposable test database from the published schema
# plus every migration, so feature tests never touch the live opnsense_fw.
#
#   tests/lib/make_test_db.sh [name]     default: opnsense_fw_test
#
# Then run a test against it:
#   DB_NAME=opnsense_fw_test php tests/<something>_test.php
#
# Needs root on the local MariaDB (sudo mysql) to create the database and to
# grant the application user access to it. The name must start with
# opnsense_fw_test so this script can never be pointed at production.
set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
NAME="${1:-opnsense_fw_test}"

case "$NAME" in
    opnsense_fw_test*) ;;
    *) echo "refusing: test database name must start with opnsense_fw_test" >&2; exit 1 ;;
esac
echo "$NAME" | grep -Eq '^[A-Za-z0-9_]+$' || { echo "refusing: bad name" >&2; exit 1; }

# OPNMGR_TEST_MYSQL names a client command for a server without a root socket,
# e.g. CI's: "mysql -h 127.0.0.1 -u root -pci-root".
MYSQL="${OPNMGR_TEST_MYSQL:-mysql}"
[ -z "${OPNMGR_TEST_MYSQL:-}" ] && [ "$(id -u)" -ne 0 ] && MYSQL="sudo mysql"

APP_USER=$(grep '^DB_USER=' "$ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d '"'"'" || true)
APP_USER="${APP_USER:-opnsense_user}"

$MYSQL -u root -e "DROP DATABASE IF EXISTS \`$NAME\`; CREATE DATABASE \`$NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
$MYSQL -u root -e "GRANT ALL PRIVILEGES ON \`$NAME\`.* TO '$APP_USER'@'localhost';" 2>/dev/null || true

# schema.sql creates and USEs opnsense_fw; retarget it.
sed -e "s/^CREATE DATABASE IF NOT EXISTS \`opnsense_fw\`/CREATE DATABASE IF NOT EXISTS \`$NAME\`/" \
    -e "s/^USE \`opnsense_fw\`;/USE \`$NAME\`;/" \
    "$ROOT/database/schema.sql" | $MYSQL -u root

# Prove the PHP side really targets the test database before migrating it.
ACTUAL=$(DB_NAME="$NAME" php -r 'require "'"$ROOT"'/inc/bootstrap_agent.php"; echo db()->query("SELECT DATABASE()")->fetchColumn();')
if [ "$ACTUAL" != "$NAME" ]; then
    echo "refusing: PHP connected to '$ACTUAL', not '$NAME' - the DB_NAME override is not working" >&2
    exit 1
fi
DB_NAME="$NAME" php "$ROOT/scripts/migrate.php" >/dev/null
echo "test database $NAME ready"
