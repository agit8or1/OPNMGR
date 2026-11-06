#!/bin/sh
#
# OPNManager agent installer for pfSense CE and pfSense Plus.
#
#   fetch -o - https://manager.example/downloads/pfsense/install_opnmgr_agent_pfsense.sh \
#     | env OPNMGR_BASE_URL=https://manager.example OPNMGR_ENROLL_KEY=<key from Add Firewall> sh
#
# OPNMGR_ENROLL_KEY is the same enrollment key the OPNsense plugin takes
# (base64 JSON with server_url and a one-time token). OPNMGR_ENROLL_TOKEN (the
# bare token) is accepted too.
#
# What it does, and does not do:
#   - installs /usr/local/opnmgr/{opnmgr_agent.sh,opnmgr_collect.php}
#   - installs /usr/local/etc/rc.d/opnmgr_agent.sh (started at boot, supervised)
#   - writes /usr/local/etc/opnmgr_agent.conf
#   - does NOT edit config.xml, install packages, or touch firewall rules
#   - refuses to run on OPNsense, where the os-opnmanager-agent plugin is the
#     agent; installing both would give one firewall two identities
#
# Re-running is an upgrade: identity (/usr/local/etc/opnmanager_hardware_id)
# and credentials are kept.

set -eu

AGENT_PKG_VERSION="1.0.0"
BASE="${OPNMGR_BASE_URL:-${1:-}}"
BASE="${BASE%/}"
TOKEN="${OPNMGR_ENROLL_TOKEN:-}"
VERIFY_SSL="${OPNMGR_VERIFY_SSL:-1}"

die() { echo "ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "must be run as root"
[ -n "$BASE" ] || die "OPNMGR_BASE_URL is not set"
case "$BASE" in https://*|http://*) ;; *) die "OPNMGR_BASE_URL must start with https://" ;; esac

# Product check from pfSense's own files, never from FreeBSD alone.
if [ -x /usr/local/sbin/opnsense-version ]; then
    die "this is OPNsense - install the os-opnmanager-agent plugin instead"
fi
grep -q "'xml_rootobj' => 'pfsense'" /etc/inc/globals.inc 2>/dev/null \
    || die "this does not look like pfSense (/etc/inc/globals.inc)"
[ -x /usr/local/bin/php ] || die "/usr/local/bin/php not found"

if [ -n "${OPNMGR_ENROLL_KEY:-}" ]; then
    # Decoded with pfSense's PHP; the key is data, never evaluated as shell.
    TOKEN=$(printf '%s' "$OPNMGR_ENROLL_KEY" | /usr/local/bin/php -r '$d=json_decode(base64_decode(trim(stream_get_contents(STDIN)),true),true);
        $t=(string)($d["token"]??""); echo preg_match("/^[a-f0-9]{64}$/",$t)?$t:"";')
    [ -n "$TOKEN" ] || die "OPNMGR_ENROLL_KEY is not a valid enrollment key"
fi
case "$TOKEN" in ''|*[!a-f0-9]*) [ -z "$TOKEN" ] || die "enrollment token must be hex" ;; esac
command -v openssl >/dev/null 2>&1 || die "openssl not found"

EDITION="CE"
[ -f /etc/inc/globals.plus.inc ] && EDITION="Plus"
echo "pfSense ${EDITION} $(cat /etc/version 2>/dev/null) detected"

FETCH="fetch -q"
[ "$VERIFY_SSL" = "1" ] || FETCH="fetch -q --no-verify-peer"
TARBALL_URL="${BASE}/downloads/pfsense/opnmgr-agent-pfsense-${AGENT_PKG_VERSION}.tar.gz"
SUM_URL="${TARBALL_URL}.sha256"

WORK=$(mktemp -d /tmp/opnmgr-install.XXXXXX)
trap 'rm -rf "$WORK"' EXIT HUP INT TERM

echo "Downloading agent ${AGENT_PKG_VERSION}..."
$FETCH -o "$WORK/agent.tgz" "$TARBALL_URL" || die "download failed: $TARBALL_URL"
$FETCH -o "$WORK/agent.sha256" "$SUM_URL" || die "checksum download failed"
EXPECTED=$(awk '{print $1}' "$WORK/agent.sha256")
ACTUAL=$(sha256 -q "$WORK/agent.tgz")
[ "$EXPECTED" = "$ACTUAL" ] || die "checksum mismatch (expected $EXPECTED, got $ACTUAL)"

tar -xzf "$WORK/agent.tgz" -C "$WORK"

install -d -m 0755 /usr/local/opnmgr
install -m 0755 "$WORK/opnmgr_agent.sh"   /usr/local/opnmgr/opnmgr_agent.sh
install -m 0644 "$WORK/opnmgr_collect.php" /usr/local/opnmgr/opnmgr_collect.php
install -m 0755 "$WORK/opnmgr_agent.rc"   /usr/local/etc/rc.d/opnmgr_agent.sh

CONF=/usr/local/etc/opnmgr_agent.conf
if [ ! -f "$CONF" ]; then
    ( umask 077; : > "$CONF" )
fi
# Rewrite only our keys; keep anything else an operator added.
TMPCONF="$WORK/conf"
grep -v -E '^(SERVER_URL|VERIFY_SSL|ENROLL_TOKEN)=' "$CONF" > "$TMPCONF" || true
{
    echo "SERVER_URL=\"$BASE\""
    echo "VERIFY_SSL=$VERIFY_SSL"
    [ -n "$TOKEN" ] && echo "ENROLL_TOKEN=\"$TOKEN\""
} >> "$TMPCONF"
install -m 0600 "$TMPCONF" "$CONF"

echo "Starting agent..."
/usr/local/etc/rc.d/opnmgr_agent.sh restart
sleep 2
/usr/local/etc/rc.d/opnmgr_agent.sh status || die "agent did not start; see /var/log/opnmgr_agent.log"
echo "Installed. Log: /var/log/opnmgr_agent.log"
