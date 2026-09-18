#!/bin/sh
# Sync the working copy to the live installation.
#
# --delete is wanted: a file removed here must disappear there, or production
# keeps serving code that no longer exists in the repository.
#
# The exclusions are the things that live in production and must survive it:
#
#   .git    the repository itself
#   keys/   per-firewall SSH private keys, www-data:www-data 0600
#   .env    credentials
#   logs/   runtime output. This one was learned the hard way: without it,
#           every deploy overwrote production's logs with whatever stale copies
#           happened to sit in the working tree.
#
# Run the test suite first; this script does not, because a deploy is sometimes
# a rollback.

set -e

SRC="$(cd "$(dirname "$0")/.." && pwd)/"
DEST="/var/www/opnsense/"

if [ ! -d "$DEST" ]; then
    echo "deploy: $DEST does not exist" >&2
    exit 1
fi

sudo rsync -a --delete \
    --exclude='.git' \
    --exclude='keys/' \
    --exclude='.env' \
    --exclude='logs/' \
    "$SRC" "$DEST"

echo "deployed $(cat "${SRC}VERSION" 2>/dev/null || echo '?') to ${DEST}"

# Anything left differing, excluding the paths above, is drift worth seeing.
if diff -rq --exclude='.git' --exclude='keys' --exclude='.env' --exclude='logs' \
        "$SRC" "$DEST" >/dev/null 2>&1; then
    echo "drift: none"
else
    echo "drift:"
    diff -rq --exclude='.git' --exclude='keys' --exclude='.env' --exclude='logs' \
        "$SRC" "$DEST" 2>/dev/null | head -20
fi
