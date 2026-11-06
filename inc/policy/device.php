<?php
/**
 * OPNMGR policy templates: the shell that runs on the firewall.
 *
 *   policy_build_apply_script()     verify, save, arm a local recovery timer, install
 *   policy_build_confirm_script()   disarm the timer (sent only after read-back verification)
 *   policy_build_rollback_script()  revert now
 *
 * The recovery timer is enforced on the device, so a change that cuts the
 * manager off is undone without the manager:
 *
 *   1. Refuse unless sha256(current config) equals the plan's base
 *      fingerprint - the configuration the operator approved a diff against.
 *   2. Fetch the artifact with the agent's own credentials plus a single-use
 *      token; refuse unless its sha256 is the approved one and its root
 *      element is this platform's (an OPNsense config is never installed on
 *      pfSense or the reverse).
 *   3. Save the current config under the persistent state directory
 *      (/conf/opnmgr on OPNsense, /cf/conf/opnmgr on pfSense) and write a
 *      state file: id, deadline (epoch), saved path, saved sha256.
 *   4. Install a recovery script and a boot hook, then start the timer
 *      detached (daemon -f, nohup as a fallback) BEFORE installing anything,
 *      so a hang or a crash during installation is still recovered.
 *   5. Install through the platform's locked mechanism (installConfigShell).
 *
 * The timer reinstalls the saved configuration at the deadline unless the
 * state file has been claimed by a confirm. Confirm and revert both claim it
 * with a single mv, so exactly one of them wins a race. After a reboot inside
 * the window the boot hook re-arms the timer, or reverts at once when the
 * deadline has passed. Exit status is not reliably reported by agents, so
 * every outcome is printed as a parseable OPNMGR_POLICY_* marker.
 *
 * Not verified on a live device: this is exercised under /bin/sh with stubbed
 * binaries only (tests/policy_device_test.php).
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../platform/platform.php';
require_once __DIR__ . '/../server_identity.php';

if (!function_exists('policy_device_paths')) {
    /** Platform paths, overridable for tests. */
    function policy_device_paths(OpnmgrPlatformAdapter $adapter, array $overrides = []): array {
        $family = $adapter->family();
        $defaults = match ($family) {
            'opnsense' => ['config' => '/conf/config.xml', 'state_dir' => '/conf/opnmgr',
                           'hook' => '/usr/local/etc/rc.syshook.d/start/60-opnmgr-policy-recover'],
            'pfsense'  => ['config' => '/cf/conf/config.xml', 'state_dir' => '/cf/conf/opnmgr',
                           // pfSense runs /usr/local/etc/rc.d/*.sh with "start" at boot.
                           'hook' => '/usr/local/etc/rc.d/opnmgr_policy_recover.sh'],
            default    => ['config' => '', 'state_dir' => '', 'hook' => ''],
        };
        return array_merge($defaults, [
            'path' => '/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin',
            'hw'   => '/usr/local/etc/opnmanager_hardware_id',
            'key'  => '/usr/local/etc/opnmanager_api_key',
            'url'  => null,
        ], $overrides);
    }
}

if (!function_exists('policy_build_recover_script')) {
    /** The recovery timer. Static: it reads everything from the state file. */
    function policy_build_recover_script(OpnmgrPlatformAdapter $adapter, array $p): string {
        $install = $adapter->installConfigShell('SAVED', 'OPNManager policy recovery');
        return <<<SH
#!/bin/sh
# OPNManager policy recovery timer. Reinstalls the configuration saved before a
# template apply unless that apply was confirmed in time. Does nothing when no
# apply is armed, so it is safe to leave installed.
PATH={$p['path']}
STATE_DIR={$p['state_dir']}
STATE="\$STATE_DIR/policy-armed"
MODE="\${1:-wait}"
val() { grep "^\$1=" "\$2" 2>/dev/null | head -n 1 | cut -d= -f2-; }
[ -f "\$STATE" ] || exit 0
ID=\$(val id "\$STATE")
DEADLINE=\$(val deadline "\$STATE")
SAVED=\$(val saved "\$STATE")
SAVED_SHA=\$(val saved_sha "\$STATE")
NONCE=\$(val nonce "\$STATE")
case "\$MODE" in
    wait)
        while [ "\$(date +%s)" -lt "\$DEADLINE" ]; do
            sleep 2
            [ -f "\$STATE" ] || exit 0
            # A later apply re-arms with a new nonce; this timer is then obsolete.
            [ "\$(val nonce "\$STATE")" = "\$NONCE" ] || exit 0
        done
        ;;
    boot)
        if [ "\$(date +%s)" -lt "\$DEADLINE" ]; then
            daemon -f /bin/sh "\$0" wait 2>/dev/null || { nohup /bin/sh "\$0" wait >/dev/null 2>&1 & }
            exit 0
        fi
        ;;
    now) ;;
    *) exit 2 ;;
esac
# Claim the revert. A confirm claims the same file; whichever mv succeeds wins.
mv "\$STATE" "\$STATE_DIR/policy-\$ID-reverting" 2>/dev/null || exit 0
if [ "\$(sha256 -q "\$SAVED" 2>/dev/null)" != "\$SAVED_SHA" ]; then
    mv "\$STATE_DIR/policy-\$ID-reverting" "\$STATE_DIR/policy-\$ID-revert-failed"
    logger -t opnmgr "policy \$ID: saved configuration failed its checksum; NOT reverted" 2>/dev/null
    exit 3
fi
(
{$install}
)
RC=\$?
if [ \$RC -eq 0 ]; then
    mv "\$STATE_DIR/policy-\$ID-reverting" "\$STATE_DIR/policy-\$ID-reverted"
    logger -t opnmgr "policy \$ID: not confirmed in time; previous configuration reinstalled" 2>/dev/null
    echo "OPNMGR_POLICY_REVERT=ok"
else
    mv "\$STATE_DIR/policy-\$ID-reverting" "\$STATE_DIR/policy-\$ID-revert-failed"
    logger -t opnmgr "policy \$ID: revert failed with code \$RC" 2>/dev/null
    echo "OPNMGR_POLICY_REVERT=failed"
fi
exit \$RC
SH;
    }
}

if (!function_exists('policy_build_apply_script')) {
    /**
     * @param array $o target_id, firewall_id, token, base_sha, artifact_sha, window
     */
    function policy_build_apply_script(OpnmgrPlatformAdapter $adapter, array $o, array $overrides = []): string {
        $root = $adapter->configRoot();
        $install = $adapter->installConfigShell('NEW', 'OPNManager policy target #' . (int)$o['target_id']);
        if ($root === null || $install === null) {
            return "echo 'OPNMGR_POLICY_APPLY=unsupported'\nexit 5\n";
        }
        $p = policy_device_paths($adapter, $overrides);
        $url = $p['url'] ?? (opnmgr_server_url() . '/api/policy_artifact.php');
        $recover = policy_build_recover_script($adapter, $p);
        $hookDir = dirname($p['hook']);
        $id = (int)$o['target_id'];
        $fw = (int)$o['firewall_id'];
        $window = max(60, (int)$o['window']);
        foreach (['token', 'base_sha', 'artifact_sha'] as $k) {
            if (!preg_match('/^[0-9a-f]{64}$/', (string)$o[$k])) {
                return "echo 'OPNMGR_POLICY_APPLY=error_bad_parameters'\nexit 5\n";
            }
        }
        if (isset($o['window_override'])) {   // tests only: sub-minute windows
            $window = (int)$o['window_override'];
        }

        return <<<SH
#!/bin/sh
# OPNManager policy apply, deployment target {$id}
PATH={$p['path']}
umask 077
CFG={$p['config']}
STATE_DIR={$p['state_dir']}
ID={$id}
BASE_SHA={$o['base_sha']}
ART_SHA={$o['artifact_sha']}
WINDOW={$window}
ROOT={$root}
NEW="\$STATE_DIR/policy-\$ID-new.xml"
SAVED="\$STATE_DIR/policy-\$ID-pre.xml"
RECOVER="\$STATE_DIR/policy-recover.sh"
HOOK={$p['hook']}
say() { echo "OPNMGR_POLICY_APPLY=\$1"; }
sha() { sha256 -q "\$1" 2>/dev/null; }

mkdir -p "\$STATE_DIR" && chmod 700 "\$STATE_DIR" || { say error_state_dir; exit 20; }
if [ -f "\$STATE_DIR/policy-armed" ]; then
    echo "another policy apply is awaiting confirmation"
    say refused_busy; exit 11
fi

# 1. The configuration must still be the one the plan was approved against.
CUR=\$(sha "\$CFG")
if [ -z "\$CUR" ] || [ "\$CUR" != "\$BASE_SHA" ]; then
    echo "current configuration sha256 \$CUR does not match the plan base \$BASE_SHA"
    say refused_fingerprint; exit 10
fi

HW=\$(cat {$p['hw']} 2>/dev/null) || HW=""
KEY=\$(cat {$p['key']} 2>/dev/null) || KEY=""
if [ -z "\$HW" ] || [ -z "\$KEY" ]; then
    say error_credentials; exit 1
fi

# The artifact is a full configuration (password hashes, keys); it never
# outlives this script except as the installed configuration.
trap 'rm -f "\$NEW"' EXIT HUP INT TERM

# 2. Fetch and check the artifact.
curl -sS -f -o "\$NEW" \\
  --data-urlencode "firewall_id={$fw}" \\
  --data-urlencode "hardware_id=\$HW" \\
  --data-urlencode "api_key=\$KEY" \\
  --data-urlencode "target_id={$id}" \\
  --data-urlencode "token={$o['token']}" \\
  {$url} || { say fetch_failed; exit 12; }
if [ "\$(sha "\$NEW")" != "\$ART_SHA" ]; then
    say refused_artifact; exit 13
fi
FIRST=\$(head -c 4096 "\$NEW" | tr '\\n\\r\\t' '   ' | sed -e 's/<?[^>]*?>//g' -e 's/<!--[^>]*-->//g' \\
        | grep -o '<[A-Za-z_][A-Za-z0-9_.-]*' | head -n 1 | cut -c2-)
if [ "\$FIRST" != "\$ROOT" ]; then
    echo "artifact root <\$FIRST> is not <\$ROOT>; never installed across products"
    say refused_root; exit 14
fi

# 3. Save the current configuration.
cp -p "\$CFG" "\$SAVED" || { say error_save; exit 21; }
if [ "\$(sha "\$SAVED")" != "\$BASE_SHA" ]; then
    rm -f "\$SAVED"; say error_save; exit 21
fi

# 4. Recovery script, boot hook, state file, timer - all before installing.
cat > "\$RECOVER" <<'OPNMGR_RECOVER_EOF'
{$recover}
OPNMGR_RECOVER_EOF
chmod 700 "\$RECOVER"
mkdir -p {$hookDir}
cat > "\$HOOK" <<OPNMGR_HOOK_EOF
#!/bin/sh
# OPNManager: re-arm or complete a pending policy recovery after a reboot.
case "\\\$1" in stop) exit 0 ;; esac
[ -f "\$RECOVER" ] && /bin/sh "\$RECOVER" boot
exit 0
OPNMGR_HOOK_EOF
chmod 755 "\$HOOK"
DEADLINE=\$(( \$(date +%s) + WINDOW ))
printf 'id=%s\\nnonce=%s\\ndeadline=%s\\nsaved=%s\\nsaved_sha=%s\\n' "\$ID" "\$\$-\$DEADLINE" "\$DEADLINE" "\$SAVED" "\$BASE_SHA" > "\$STATE_DIR/policy-armed.tmp" \\
    && mv "\$STATE_DIR/policy-armed.tmp" "\$STATE_DIR/policy-armed" || { say error_state; exit 22; }
daemon -f /bin/sh "\$RECOVER" wait 2>/dev/null || { nohup /bin/sh "\$RECOVER" wait >/dev/null 2>&1 & }

# 5. Install through the platform's own locked mechanism.
(
{$install}
)
RC=\$?
if [ \$RC -ne 0 ]; then
    echo "install failed with code \$RC; reverting now"
    /bin/sh "\$RECOVER" now
    say install_failed; exit 15
fi
say "ok deadline=\$DEADLINE"
exit 0
SH;
    }
}

if (!function_exists('policy_build_confirm_script')) {
    function policy_build_confirm_script(OpnmgrPlatformAdapter $adapter, int $targetId, array $overrides = []): string {
        $p = policy_device_paths($adapter, $overrides);
        return <<<SH
#!/bin/sh
# OPNManager policy confirm, target {$targetId}: the manager verified read-back.
PATH={$p['path']}
STATE_DIR={$p['state_dir']}
STATE="\$STATE_DIR/policy-armed"
ID={$targetId}
val() { grep "^\$1=" "\$2" 2>/dev/null | head -n 1 | cut -d= -f2-; }
if [ -f "\$STATE" ] && [ "\$(val id "\$STATE")" = "\$ID" ] && mv "\$STATE" "\$STATE_DIR/policy-\$ID-confirmed" 2>/dev/null; then
    rm -f "\$STATE_DIR/policy-\$ID-pre.xml"
    echo "OPNMGR_POLICY_CONFIRM=ok"
elif [ -f "\$STATE_DIR/policy-\$ID-reverted" ] || [ -f "\$STATE_DIR/policy-\$ID-reverting" ]; then
    echo "OPNMGR_POLICY_CONFIRM=already_reverted"
else
    echo "OPNMGR_POLICY_CONFIRM=not_armed"
fi
exit 0
SH;
    }
}

if (!function_exists('policy_build_rollback_script')) {
    function policy_build_rollback_script(OpnmgrPlatformAdapter $adapter, int $targetId, array $overrides = []): string {
        $p = policy_device_paths($adapter, $overrides);
        return <<<SH
#!/bin/sh
# OPNManager policy rollback, target {$targetId}.
PATH={$p['path']}
STATE_DIR={$p['state_dir']}
STATE="\$STATE_DIR/policy-armed"
ID={$targetId}
val() { grep "^\$1=" "\$2" 2>/dev/null | head -n 1 | cut -d= -f2-; }
if [ -f "\$STATE" ] && [ "\$(val id "\$STATE")" = "\$ID" ]; then
    if /bin/sh "\$STATE_DIR/policy-recover.sh" now >/dev/null 2>&1 && [ -f "\$STATE_DIR/policy-\$ID-reverted" ]; then
        echo "OPNMGR_POLICY_ROLLBACK=ok"
    else
        echo "OPNMGR_POLICY_ROLLBACK=failed"
    fi
elif [ -f "\$STATE_DIR/policy-\$ID-reverted" ]; then
    echo "OPNMGR_POLICY_ROLLBACK=already_reverted"
else
    echo "OPNMGR_POLICY_ROLLBACK=not_armed"
fi
exit 0
SH;
    }
}

if (!function_exists('policy_parse_marker_line')) {
    /** Value of an OPNMGR_POLICY_<NAME>= marker in command output, or null. */
    function policy_parse_marker_line(?string $output, string $name): ?string {
        if ($output !== null && preg_match('/OPNMGR_POLICY_' . $name . '=([a-z_]+)/', $output, $m)) {
            return $m[1];
        }
        return null;
    }
}
