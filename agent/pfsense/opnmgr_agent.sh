#!/bin/sh
#
# OPNManager agent for pfSense (CE and Plus).
#
# A separate agent, not the OPNsense plugin: pfSense has no configd, no MVC
# framework and no Python in base, so none of the plugin's mechanisms apply.
# What is shared is the protocol - the same /agent_checkin.php payload, the
# same api_key adoption and HMAC request signing, the same queued-command
# loop - and the same credential file names, so manager-side commands that
# read /usr/local/etc/opnmanager_* work unchanged on either product.
#
# Supervised by daemon(8) from /usr/local/etc/rc.d/opnmgr_agent.sh.
# Configuration: /usr/local/etc/opnmgr_agent.conf (shell KEY=value), written by
# the installer. config.xml is never edited by this agent.

AGENT_VERSION="1.0.0"
AGENT_DIR="/usr/local/opnmgr"
CONF_FILE="/usr/local/etc/opnmgr_agent.conf"
LOG_FILE="/var/log/opnmgr_agent.log"
HARDWARE_ID_FILE="/usr/local/etc/opnmanager_hardware_id"
API_KEY_FILE="/usr/local/etc/opnmanager_api_key"
API_SECRET_FILE="/usr/local/etc/opnmanager_api_secret"
PHP="/usr/local/bin/php"
MAX_LOG_SIZE=10485760

PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
export PATH

log_message() {
    if [ -f "$LOG_FILE" ] && [ "$(stat -f%z "$LOG_FILE" 2>/dev/null || echo 0)" -gt "$MAX_LOG_SIZE" ]; then
        mv "$LOG_FILE" "$LOG_FILE.old"; : > "$LOG_FILE"; chmod 600 "$LOG_FILE"
    fi
    printf '%s [%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$AGENT_VERSION" \
        "$(printf '%s' "$1" | tr '\n\r\t' '   ')" >> "$LOG_FILE"
}

load_config() {
    SERVER_URL=""; CHECKIN_INTERVAL=120; VERIFY_SSL=1; ENROLL_TOKEN=""
    # shellcheck disable=SC1090
    [ -f "$CONF_FILE" ] && . "$CONF_FILE"
    SERVER_URL="${SERVER_URL%/}"
    case "$CHECKIN_INTERVAL" in ''|*[!0-9]*) CHECKIN_INTERVAL=120 ;; esac
    [ "$CHECKIN_INTERVAL" -lt 60 ] && CHECKIN_INTERVAL=60
    CURL="/usr/local/bin/curl -s"
    [ "$VERIFY_SSL" = "1" ] || CURL="$CURL -k"
}

get_hardware_id() {
    if [ -s "$HARDWARE_ID_FILE" ]; then
        cat "$HARDWARE_ID_FILE"; return
    fi
    seed=$(kenv -q smbios.system.uuid 2>/dev/null)
    case "$seed" in ''|"Not Settable"|"Not Present") seed=$(cat /etc/hostid 2>/dev/null) ;; esac
    [ -z "$seed" ] && seed=$(ifconfig | awk '/ether/ {print $2; exit}')
    [ -z "$seed" ] && seed=$(head -c 16 /dev/urandom | md5)
    id=$(printf '%s' "$seed" | md5 | cut -c1-32)
    ( umask 077; printf '%s\n' "$id" > "$HARDWARE_ID_FILE" )
    log_message "Generated hardware ID $id"
    echo "$id"
}

store_credential() {
    [ -z "$2" ] && return 0
    [ "$(cat "$1" 2>/dev/null)" = "$2" ] && return 0
    ( umask 077; printf '%s' "$2" > "$1" ) && chmod 600 "$1" && log_message "Stored updated credential in $1"
}

# Signature: METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256HEX(BODY), HMAC-SHA256 with
# api_secret - byte-for-byte the canonical string inc/agent_auth.php verifies.
signing_headers() {
    _secret=$(cat "$API_SECRET_FILE" 2>/dev/null)
    [ -z "$_secret" ] && return 0
    _ts=$(date +%s)
    _nonce=$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')
    _bh=$(printf '%s' "$3" | openssl dgst -sha256 -r | cut -d' ' -f1)
    _sig=$(printf '%s\n%s\n%s\n%s\n%s' "$1" "$2" "$_ts" "$_nonce" "$_bh" \
           | openssl dgst -sha256 -hmac "$_secret" -r | cut -d' ' -f1)
    [ -z "$_sig" ] && return 0
    printf -- '-H\nX-OPNMGR-Timestamp: %s\n-H\nX-OPNMGR-Nonce: %s\n-H\nX-OPNMGR-Signature: %s\n' "$_ts" "$_nonce" "$_sig"
}

post_to_manager() {
    _path="$1"; _body="$2"; _timeout="${3:-15}"
    _key=$(cat "$API_KEY_FILE" 2>/dev/null)
    _sig=$(signing_headers POST "$_path" "$_body")
    _ifs="$IFS"; IFS='
'
    set -- $_sig
    IFS="$_ifs"
    $CURL -m "$_timeout" -X POST -H "Content-Type: application/json" \
        ${_key:+-H "X-OPNMGR-API-Key: $_key"} "$@" -d "$_body" "${SERVER_URL}${_path}"
}

# JSON helpers run in pfSense's PHP: there is no jq or Python in base.
json_get() {
    printf '%s' "$1" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1];
        $v=$d; foreach(explode(".",$k) as $p){ $v=is_array($v)&&array_key_exists($p,$v)?$v[$p]:null; }
        if(is_bool($v)) echo $v?"true":"false"; elseif(is_scalar($v)) echo $v;' "$2" 2>/dev/null
}

enroll_if_needed() {
    [ -s "$API_KEY_FILE" ] && return 0
    [ -z "$ENROLL_TOKEN" ] && return 0
    body=$(printf '{"token":"%s","hardware_id":"%s","hostname":"%s","agent_version":"%s","agent_flavor":"pfsense-agent"}' \
        "$ENROLL_TOKEN" "$HARDWARE_ID" "$(hostname)" "$AGENT_VERSION")
    resp=$($CURL -m 20 -X POST -H "Content-Type: application/json" -d "$body" "${SERVER_URL}/agent_enroll.php")
    if [ "$(json_get "$resp" success)" = "true" ]; then
        log_message "Enrolled with manager (firewall $(json_get "$resp" firewall_id))"
        # The token is single use; forget it so it is not replayed.
        sed -i '' '/^ENROLL_TOKEN=/d' "$CONF_FILE"
        ENROLL_TOKEN=""
    else
        log_message "Enrollment failed: $(json_get "$resp" message)"
    fi
}

run_queued_commands() {
    printf '%s' "$1" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true);
        foreach(($d["queued_commands"] ?? []) as $c){ echo (int)$c["id"], "|", base64_encode((string)$c["command"]), "\n"; }' 2>/dev/null \
    | while IFS='|' read -r cmd_id cmd_b64; do
        [ -z "$cmd_id" ] && continue
        cmd=$(printf '%s' "$cmd_b64" | openssl base64 -d -A 2>/dev/null)
        [ -z "$cmd" ] && continue
        log_message "Executing command $cmd_id ($(printf '%s' "$cmd" | wc -c | tr -d ' ') bytes)"
        out="/tmp/opnmgr_cmd_${cmd_id}.out"
        status="completed"
        # timeout(1) bounds the run; output is capped before it is reported.
        timeout 300 sh -c "$cmd" > "$out" 2>&1 || [ $? -ne 124 ] || status="failed"
        result=$(head -c 200000 "$out" | openssl base64 -A)
        rm -f "$out"
        post_to_manager "/agent_checkin.php" \
            "{\"hardware_id\":\"$HARDWARE_ID\",\"command_id\":$cmd_id,\"status\":\"$status\",\"result\":\"$result\"}" \
            > /dev/null 2>&1
        log_message "Command $cmd_id $status"
    done
}

main() {
    load_config
    HARDWARE_ID=$(get_hardware_id)
    while [ -z "$SERVER_URL" ]; do
        log_message "SERVER_URL not configured in $CONF_FILE - waiting"
        sleep 60; load_config
    done
    log_message "Starting OPNManager pfSense agent v$AGENT_VERSION (server $SERVER_URL)"
    errors=0

    while true; do
        load_config
        enroll_if_needed

        collected=$("$PHP" -f "$AGENT_DIR/opnmgr_collect.php" 2>/dev/null)
        case "$collected" in \{*\}) ;; *) collected='{}' ;; esac
        payload=$(printf '%s' "$collected" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true) ?: [];
            $d["hardware_id"]=$argv[1]; $d["agent_version"]=$argv[2]; $d["agent_type"]="primary";
            echo json_encode($d, JSON_UNESCAPED_SLASHES);' "$HARDWARE_ID" "$AGENT_VERSION")

        resp=$(post_to_manager "/agent_checkin.php" "$payload" 30)
        if [ "$(json_get "$resp" success)" = "true" ]; then
            errors=0
            # Adopt before use: credentials in this response sign the next one.
            if printf '%s' "$resp" | grep -q '"agent_credentials"'; then
                store_credential "$API_KEY_FILE" "$(json_get "$resp" agent_credentials.api_key)"
                store_credential "$API_SECRET_FILE" "$(json_get "$resp" agent_credentials.api_secret)"
            fi
            upd=$(json_get "$resp" update_command)
            if [ "$(json_get "$resp" agent_update_available)" = "true" ] && [ -n "$upd" ]; then
                log_message "Agent update to $(json_get "$resp" latest_version) offered; running installer"
                nohup sh -c "$upd" >/dev/null 2>&1 &
            fi
            run_queued_commands "$resp"
            ivl=$(json_get "$resp" checkin_interval)
            case "$ivl" in ''|*[!0-9]*) ;; *) [ "$ivl" -ge 60 ] && CHECKIN_INTERVAL=$ivl ;; esac
        else
            errors=$((errors + 1)); [ $errors -gt 10 ] && errors=10
            log_message "Check-in failed: $(printf '%s' "$resp" | head -c 200)"
        fi

        sleep $((CHECKIN_INTERVAL + errors * 30))
    done
}

main "$@"
