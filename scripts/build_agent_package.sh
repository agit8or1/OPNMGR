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
# Output: downloads/plugins/os-opnmanager-agent-<version>.tar.gz, or
# $OUT_DIR/os-opnmanager-agent-<version>.tar.gz when OUT_DIR is set (the release
# workflow and stage_agent_package.php --build-local build outside downloads/).
#
# The archive is built deterministically (sorted entries, fixed mtime, no owner
# names, no group/other write bits) so the same source produces the same bytes on
# any checkout: modes then depend only on the exec bit git records, not on the
# umask of whoever cloned it. Packages built before 3.81.1 carry this host's 775
# modes; stage_agent_package.php compares contents, not bytes, to adopt them.

set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
SRC="$ROOT/plugin/os-opnmanager-agent/src"
OUT_DIR="${OUT_DIR:-$ROOT/downloads/plugins}"

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
LIST=$(mktemp "${TMPDIR:-/tmp}/opnmgr-agent-files.XXXXXX")
trap 'rm -f "$LIST"' EXIT

# Directories are listed too, not just files: the plugin ships an empty
# service/templates tree that every previous package contained, and a files-only
# build would have quietly dropped it. --no-recursion keeps the explicit sorted
# list authoritative rather than letting tar walk the tree in its own order.
#
# __pycache__ is build output from whichever Python happened to run; it is not
# part of the plugin, and its presence is why source and package differed.
# Git does not track empty directories, so a clean clone lacks this tree, which
# every package has shipped (configd looks for templates under it). Create it
# rather than let the package depend on which checkout built it.
mkdir -p "$SRC/opnsense/service/templates/OPNsense/OPNManagerAgent"

( cd "$SRC" && find . -mindepth 1 ! -path '*/__pycache__*' | sed 's|^\./||' | LC_ALL=C sort ) \
    > "$LIST"

# gzip -n: no name or timestamp in the gzip header, so the bytes depend only on
# the source. (tar czf already produced exactly this; the explicit pipe keeps it
# true on a gzip that defaults otherwise.)
tar cf - \
    -C "$SRC" \
    --owner=0 --group=0 --numeric-owner \
    --mode=go-w \
    --mtime='@0' \
    --format=gnu \
    --no-recursion \
    -T "$LIST" | gzip -n > "$TARBALL"

rm -f "$LIST"

# Refuse to emit something the manager would refuse to stage.
if ! php -r '
    require "'"$ROOT"'/inc/agent_package.php";
    $r = agent_package_inspect($argv[1]);
    if (!$r["ok"]) { fwrite(STDERR, "ERROR: built package is not installable: " . $r["error"] . "\n"); exit(1); }
' "$TARBALL"; then
    rm -f "$TARBALL"
    exit 1
fi

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
