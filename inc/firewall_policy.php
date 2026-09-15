<?php
/**
 * Generates the firewall policy scripts that the agent applies.
 *
 * Two features are served from here, and both were previously UI toggles with
 * nothing behind them: Web GUI IP Lockdown and Secure Outbound Lockdown.
 *
 * Mechanism: the agent executes queued commands with `sh -c`, so a policy is a
 * shell script queued through queue_firewall_command(). That works with the
 * deployed agent (1.6.2) and needs no agent release.
 *
 * Every generated script follows the same rules, because these edit a live
 * customer firewall:
 *
 *  - It backs up /conf/config.xml before touching it, to a per-policy path.
 *  - Rules carry a marker in their <descr>, and the script removes every rule
 *    bearing that marker before adding any. That makes it idempotent, makes
 *    disabling exact, and guarantees it never removes a rule a human wrote.
 *  - It validates the resulting XML before installing it, and restores the
 *    backup if the edit produced something unparseable.
 *  - It reloads the filter only after the config validates.
 *
 * What it deliberately does NOT do: touch LAN administration. Web GUI lockdown
 * is applied on WAN interfaces only, and the manager's own address is always
 * permitted. Locking an operator out of their own firewall is a worse outcome
 * than leaving the GUI reachable.
 *
 * @since 3.28.0
 */

if (!defined('OPNMGR_WEBGUI_MARKER')) {
    define('OPNMGR_WEBGUI_MARKER', 'OPNMANAGER-WEBGUI-LOCKDOWN');
}
if (!defined('OPNMGR_OUTBOUND_MARKER')) {
    define('OPNMGR_OUTBOUND_MARKER', 'OPNMANAGER-OUTBOUND-LOCKDOWN');
}

if (!function_exists('opnmgr_manager_address')) {
    /**
     * This manager's address, as a firewall would see it.
     *
     * Used to guarantee the platform is permitted by any access restriction it
     * applies, so an operator cannot cut OPNManager off from the firewall it
     * manages. Resolution order, cheapest and most authoritative first:
     *
     *   1. the configured manager FQDN, resolved
     *   2. SERVER_NAME from the current request, resolved
     *   3. SERVER_ADDR - the address this request arrived on
     *
     * Deliberately no outbound lookup to a third-party "what is my IP" service:
     * this runs while an operator is saving a form, and a slow or unreachable
     * service would hang the save.
     *
     * @return string An IP address, or '' when none could be determined.
     */
    function opnmgr_manager_address(): string
    {
        $candidates = [];

        try {
            $fqdn = db()->query("SELECT `value` FROM settings WHERE `name` = 'manager_fqdn'")
                        ->fetchColumn();
            if (is_string($fqdn) && trim($fqdn) !== '') {
                $candidates[] = trim($fqdn);
            }
        } catch (Throwable $e) {
            // Settings unavailable is not fatal here; fall through.
        }

        if (!empty($_SERVER['SERVER_NAME'])) {
            $candidates[] = (string) $_SERVER['SERVER_NAME'];
        }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
            $resolved = gethostbyname($candidate);
            if ($resolved !== $candidate && filter_var($resolved, FILTER_VALIDATE_IP)) {
                return $resolved;
            }
        }

        $addr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        return filter_var($addr, FILTER_VALIDATE_IP) ? $addr : '';
    }
}

if (!function_exists('policy_parse_ip_list')) {
    /**
     * Parse an operator-supplied address list.
     *
     * Accepts comma or whitespace separated IPv4/IPv6 addresses and CIDRs.
     * Returns ['ips' => [...], 'rejected' => [...]] so the caller can refuse
     * rather than silently dropping something the operator meant to include.
     */
    function policy_parse_ip_list(string $raw): array
    {
        $ips = [];
        $rejected = [];

        foreach (preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $candidate = $token;
            $prefix = null;

            if (str_contains($token, '/')) {
                [$candidate, $prefix] = explode('/', $token, 2);
                if (!ctype_digit($prefix)) {
                    $rejected[] = $token;
                    continue;
                }
                $prefix = (int) $prefix;
            }

            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if ($prefix !== null && ($prefix < 0 || $prefix > 32)) { $rejected[] = $token; continue; }
            } elseif (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                if ($prefix !== null && ($prefix < 0 || $prefix > 128)) { $rejected[] = $token; continue; }
            } else {
                $rejected[] = $token;
                continue;
            }

            $normalised = $prefix === null ? $candidate : "{$candidate}/{$prefix}";
            if (!in_array($normalised, $ips, true)) {
                $ips[] = $normalised;
            }
        }

        return ['ips' => $ips, 'rejected' => $rejected];
    }
}

if (!function_exists('policy_script_header')) {
    /** Preamble shared by every policy script: backup, helpers, safety. */
    function policy_script_header(string $marker, string $label): string
    {
        $m = escapeshellarg($marker);
        return <<<SH
#!/bin/sh
# {$label}
# Applied by OPNManager. Rules carrying the marker below are managed here and
# may be replaced or removed; anything else in the configuration is left alone.
set -u

# Overridable so the generator can be exercised against a sample configuration
# in tests. Defaults to the real path, which is what the agent runs with.
CONFIG="\${OPNMGR_CONFIG_PATH:-/conf/config.xml}"
MARKER={$m}
STAMP=\$(date +%Y%m%d-%H%M%S)
BACKUP="\${CONFIG}.opnmgr-\${STAMP}"

if [ ! -f "\$CONFIG" ]; then
    echo "FAILED: \$CONFIG not found - is this an OPNsense system?"
    exit 1
fi

cp "\$CONFIG" "\$BACKUP" || { echo "FAILED: could not back up \$CONFIG"; exit 1; }
echo "backup: \$BACKUP"

WORK="\$(mktemp /tmp/opnmgr-policy.XXXXXX)" || { echo "FAILED: mktemp"; exit 1; }

# Drop every rule previously written under this marker. Operating on whole
# <rule> blocks means a partially matching human-written rule is never touched.
awk -v marker="\$MARKER" '
    /<rule>/ { buf = \$0 "\\n"; inrule = 1; next }
    inrule {
        buf = buf \$0 "\\n"
        if (\$0 ~ /<\\/rule>/) {
            if (index(buf, marker) == 0) printf "%s", buf
            inrule = 0
        }
        next
    }
    { print }
' "\$CONFIG" > "\$WORK" || { echo "FAILED: could not filter existing managed rules"; rm -f "\$WORK"; exit 1; }

SH;
    }
}

if (!function_exists('policy_script_footer')) {
    /** Validate, install and reload - or roll back. */
    function policy_script_footer(): string
    {
        return <<<'SH'

# Refuse to install a configuration that will not parse.
if command -v xmllint >/dev/null 2>&1; then
    if ! xmllint --noout "$WORK" 2>/dev/null; then
        echo "FAILED: generated configuration is not well-formed XML; nothing changed"
        rm -f "$WORK"
        exit 1
    fi
else
    php -r 'exit(@simplexml_load_file($argv[1]) === false ? 1 : 0);' "$WORK" 2>/dev/null || {
        echo "FAILED: generated configuration is not well-formed XML; nothing changed"
        rm -f "$WORK"
        exit 1
    }
fi

cp "$WORK" "$CONFIG" || { echo "FAILED: could not install new configuration"; rm -f "$WORK"; exit 1; }
rm -f "$WORK"

# Reload. If the filter refuses to load, put the previous configuration back so
# the firewall is never left running a rule set it could not parse.
if ! ${OPNMGR_RELOAD_CMD:-configctl filter reload} 2>&1 | head -20; then
    echo "filter reload failed - restoring previous configuration"
    cp "$BACKUP" "$CONFIG"
    ${OPNMGR_RELOAD_CMD:-configctl filter reload} 2>&1 | head -5
    echo "FAILED: reload rejected the new rules; previous configuration restored"
    exit 1
fi

echo "OK: policy applied"
SH;
    }
}

if (!function_exists('policy_webgui_lockdown_script')) {
    /**
     * Restrict the OPNsense web GUI on WAN to an explicit address list.
     *
     * @param string[] $ips       Operator-supplied addresses/CIDRs.
     * @param string   $managerIp This manager, always permitted.
     * @param int      $guiPort   The GUI port to restrict.
     * @param bool     $enable    False removes the policy and restores open access.
     */
    function policy_webgui_lockdown_script(array $ips, string $managerIp, int $guiPort, bool $enable): string
    {
        $marker = OPNMGR_WEBGUI_MARKER;
        $sh = policy_script_header($marker, 'Web GUI access restriction');

        if (!$enable) {
            $sh .= "echo \"disabling: removing all rules marked {$marker}\"\n";
            return $sh . policy_script_footer();
        }

        // The manager is always permitted, and is listed first, so an operator
        // cannot lock the platform out of the firewall it manages. If its
        // address could not be determined, do not guess - an allow-list missing
        // the manager is exactly the lockout this is meant to prevent.
        if (!filter_var($managerIp, FILTER_VALIDATE_IP)) {
            throw new RuntimeException(
                'Refusing to build a Web GUI restriction without this manager\'s address'
            );
        }
        $allow = array_values(array_unique(array_merge([$managerIp], $ips)));

        $rules = '';
        foreach ($allow as $ip) {
            $isCidr = str_contains($ip, '/');
            $addr   = $isCidr
                ? '<network>' . htmlspecialchars($ip, ENT_XML1) . '</network>'
                : '<address>' . htmlspecialchars($ip, ENT_XML1) . '</address>';
            $rules .= <<<XML
    <rule>
      <type>pass</type>
      <interface>wan</interface>
      <ipprotocol>inet</ipprotocol>
      <protocol>tcp</protocol>
      <source>
        {$addr}
      </source>
      <destination>
        <network>(self)</network>
        <port>{$guiPort}</port>
      </destination>
      <descr>{$marker} permit {$ip}</descr>
      <statetype>keep state</statetype>
    </rule>

XML;
        }

        // The deny follows the permits, so ordering decides the outcome.
        $rules .= <<<XML
    <rule>
      <type>block</type>
      <interface>wan</interface>
      <ipprotocol>inet</ipprotocol>
      <protocol>tcp</protocol>
      <source>
        <any/>
      </source>
      <destination>
        <network>(self)</network>
        <port>{$guiPort}</port>
      </destination>
      <descr>{$marker} deny everything else</descr>
      <log>1</log>
      <statetype>keep state</statetype>
    </rule>

XML;

        $sh .= "RULES=" . escapeshellarg($rules) . "\n\n";
        $sh .= <<<'SH'
awk -v rules="$RULES" '
    /<\/filter>/ && !done { printf "%s", rules; done = 1 }
    { print }
' "$WORK" > "$WORK.new" && mv "$WORK.new" "$WORK" || {
    echo "FAILED: could not insert rules"; rm -f "$WORK" "$WORK.new"; exit 1
}

SH;
        return $sh . policy_script_footer();
    }
}

if (!function_exists('policy_outbound_lockdown_script')) {
    /**
     * Restrict LAN outbound traffic to web and DNS.
     *
     * This is the policy the UI has always described: permit DNS to the
     * firewall, permit HTTP and HTTPS out, block and log the rest.
     *
     * @param bool $enable False removes the policy and restores the previous
     *                     (unrestricted) behaviour.
     */
    function policy_outbound_lockdown_script(bool $enable): string
    {
        $marker = OPNMGR_OUTBOUND_MARKER;
        $sh = policy_script_header($marker, 'Secure outbound lockdown');

        if (!$enable) {
            $sh .= "echo \"disabling: removing all rules marked {$marker}\"\n";
            return $sh . policy_script_footer();
        }

        $rules = '';

        // DNS to the firewall itself, so name resolution keeps working through
        // the resolver rather than to arbitrary upstreams.
        foreach (['tcp', 'udp'] as $proto) {
            $rules .= <<<XML
    <rule>
      <type>pass</type>
      <interface>lan</interface>
      <ipprotocol>inet</ipprotocol>
      <protocol>{$proto}</protocol>
      <source>
        <network>lan</network>
      </source>
      <destination>
        <network>(self)</network>
        <port>53</port>
      </destination>
      <descr>{$marker} DNS to firewall ({$proto})</descr>
      <statetype>keep state</statetype>
    </rule>

XML;
        }

        foreach ([80 => 'HTTP', 443 => 'HTTPS'] as $port => $name) {
            $rules .= <<<XML
    <rule>
      <type>pass</type>
      <interface>lan</interface>
      <ipprotocol>inet</ipprotocol>
      <protocol>tcp</protocol>
      <source>
        <network>lan</network>
      </source>
      <destination>
        <any/>
        <port>{$port}</port>
      </destination>
      <descr>{$marker} permit {$name}</descr>
      <statetype>keep state</statetype>
    </rule>

XML;
        }

        $rules .= <<<XML
    <rule>
      <type>block</type>
      <interface>lan</interface>
      <ipprotocol>inet</ipprotocol>
      <source>
        <network>lan</network>
      </source>
      <destination>
        <any/>
      </destination>
      <descr>{$marker} block and log everything else</descr>
      <log>1</log>
      <statetype>keep state</statetype>
    </rule>

XML;

        $sh .= "RULES=" . escapeshellarg($rules) . "\n\n";
        $sh .= <<<'SH'
awk -v rules="$RULES" '
    /<\/filter>/ && !done { printf "%s", rules; done = 1 }
    { print }
' "$WORK" > "$WORK.new" && mv "$WORK.new" "$WORK" || {
    echo "FAILED: could not insert rules"; rm -f "$WORK" "$WORK.new"; exit 1
}

SH;
        return $sh . policy_script_footer();
    }
}
