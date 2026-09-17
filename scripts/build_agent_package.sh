#!/bin/sh
# Build the OPNsense agent plugin package from the tracked source tree.
#
# There was no build script: the published tarballs were produced by hand, which
# is how plugin/src came to differ from the package it was supposedly built from
# and how checkin.sh shipped a version label eight releases behind agent.sh.
#
# The version comes from AGENT_VERSION in inc/version.php - the single source
# the rest of the version machinery already uses - so the package name and the
# agent's self-reported version cannot disagree.
#
# Output: downloads/plugins/os-opnmanager-agent-<version>.tar.gz
#
# The archive is built deterministically (sorted entries, fixed mtime, no owner
# names) so rebuilding the same source produces the same bytes, and a diff
# against a published artifact means the source really changed.

set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
SRC="$ROOT/plugin/os-opnmanager-agent/src"
OUT_DIR="$ROOT/downloads/plugins"

if [ ! -d "$SRC" ]; then
    echo "ERROR: agent source tree not found at $SRC" >&2
    exit 1
fi

VERSION=$(php -r '
    require "'"$ROOT"'/inc/version.php";
    echo defined("AGENT_VERSION") ? AGENT_VERSION : "";
' 2>/dev/null)

if [ -z "$VERSION" ]; then
    echo "ERROR: could not read AGENT_VERSION from inc/version.php" >&2
    exit 1
fi

# The agent must report the version it is packaged as.
DECLARED=$(grep '^AGENT_VERSION=' "$SRC/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh" \
           | head -1 | cut -d'"' -f2)
if [ "$DECLARED" != "$VERSION" ]; then
    echo "ERROR: agent.sh declares $DECLARED but inc/version.php says $VERSION" >&2
    echo "       Bump both, or the package will lie about what it contains." >&2
    exit 1
fi

TARBALL="$OUT_DIR/os-opnmanager-agent-${VERSION}.tar.gz"

# Never silently replace a published artifact: an agent that already installed
# this version would keep its old bytes while the manifest advertised new ones.
if [ -f "$TARBALL" ] && [ "${FORCE:-0}" != "1" ]; then
    echo "ERROR: $TARBALL already exists." >&2
    echo "       Bump AGENT_VERSION rather than republishing a version, or set FORCE=1" >&2
    echo "       if you are certain it was never distributed." >&2
    exit 1
fi

mkdir -p "$OUT_DIR"

# Directories are listed too, not just files: the plugin ships an empty
# service/templates tree that every previous package contained, and a files-only
# build would have quietly dropped it. --no-recursion keeps the explicit sorted
# list authoritative rather than letting tar walk the tree in its own order.
#
# __pycache__ is build output from whichever Python happened to run; it is not
# part of the plugin, and its presence is why source and package differed.
( cd "$SRC" && find . -mindepth 1 ! -path '*/__pycache__*' | sed 's|^\./||' | LC_ALL=C sort ) \
    > /tmp/opnmgr-agent-files.$$

tar czf "$TARBALL" \
    -C "$SRC" \
    --owner=0 --group=0 --numeric-owner \
    --mtime='@0' \
    --format=gnu \
    --no-recursion \
    -T /tmp/opnmgr-agent-files.$$

rm -f /tmp/opnmgr-agent-files.$$

echo "Built $TARBALL"
echo "  files:  $(tar tzf "$TARBALL" | grep -vc '/$')"
echo "  size:   $(wc -c < "$TARBALL") bytes"
echo "  sha256: $(sha256sum "$TARBALL" | cut -d' ' -f1)"
echo
echo "Next: php scripts/sign_release.php --publish && php scripts/check_versions.php --fix"
echo
echo "Publishing does not deploy. The new version is held until promoted:"
echo "  php scripts/agent_rollout.php                        # what is held, and who is behind"
echo "  php scripts/agent_rollout.php --pilot <id> --stage pilot --apply"
echo "  php scripts/agent_rollout.php --stage fleet --apply"
