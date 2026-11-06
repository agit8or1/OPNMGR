#!/bin/sh
# Build the pfSense agent package from agent/pfsense/.
#
# Output: downloads/pfsense/opnmgr-agent-pfsense-<version>.tar.gz (+ .sha256)
#         downloads/pfsense/install_opnmgr_agent_pfsense.sh
#
# Deterministic like build_agent_package.sh: sorted entries, fixed mtime,
# numeric owner, so the same source always produces the same bytes. The
# version is PFSENSE_AGENT_VERSION in inc/version.php and must match both the
# agent and the installer, or the build refuses.

set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
SRC="$ROOT/agent/pfsense"
OUT="$ROOT/downloads/pfsense"

VERSION=$(php -r 'require "'"$ROOT"'/inc/version.php"; echo PFSENSE_AGENT_VERSION;')
AGENT_DECLARED=$(grep '^AGENT_VERSION=' "$SRC/opnmgr_agent.sh" | cut -d'"' -f2)
INSTALLER_DECLARED=$(grep '^AGENT_PKG_VERSION=' "$SRC/install_opnmgr_agent_pfsense.sh" | cut -d'"' -f2)

for v in "$AGENT_DECLARED" "$INSTALLER_DECLARED"; do
    if [ "$v" != "$VERSION" ]; then
        echo "ERROR: version mismatch: inc/version.php=$VERSION agent=$AGENT_DECLARED installer=$INSTALLER_DECLARED" >&2
        exit 1
    fi
done

sh -n "$SRC/opnmgr_agent.sh"
sh -n "$SRC/opnmgr_agent.rc"
sh -n "$SRC/install_opnmgr_agent_pfsense.sh"
php -l "$SRC/opnmgr_collect.php" >/dev/null

TARBALL="$OUT/opnmgr-agent-pfsense-${VERSION}.tar.gz"
if [ -f "$TARBALL" ] && [ "${FORCE:-0}" != "1" ]; then
    echo "ERROR: $TARBALL exists; bump PFSENSE_AGENT_VERSION (or FORCE=1 if never distributed)" >&2
    exit 1
fi

mkdir -p "$OUT"
tar czf "$TARBALL" -C "$SRC" --owner=0 --group=0 --numeric-owner --mtime='@0' --format=gnu \
    opnmgr_agent.rc opnmgr_agent.sh opnmgr_collect.php
( cd "$OUT" && sha256sum "$(basename "$TARBALL")" > "$(basename "$TARBALL").sha256" )
install -m 0644 "$SRC/install_opnmgr_agent_pfsense.sh" "$OUT/install_opnmgr_agent_pfsense.sh"

echo "Built $TARBALL"
cat "$TARBALL.sha256"
