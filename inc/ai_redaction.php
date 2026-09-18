<?php
/**
 * OPNMGR AI Redaction
 *
 * Strips credential material from an OPNsense configuration before any of it
 * leaves the server for an external AI provider.
 *
 * Before this existed, api/ai_scan.php sent the entire raw config.xml to
 * whichever provider was configured. An OPNsense configuration contains user
 * password hashes, X.509 private keys, WireGuard private keys, IPsec
 * pre-shared keys, RADIUS and LDAP bind secrets and SNMP communities. Sending
 * that to a third party is a disclosure of customer key material, and no
 * security-analysis benefit justifies it: none of those values help a model
 * reason about whether a rule set is safe.
 *
 * The approach is an explicit deny-list of element names, applied to values
 * only. Structure is preserved so the model still sees that a private key
 * exists and where, just not what it is.
 *
 * Redaction is not optional and cannot be switched off. The switch an
 * administrator gets is whether to use AI at all.
 *
 * @since 3.17.0
 */

if (!defined('AI_REDACT_ELEMENTS')) {
    /**
     * Element names whose text content is credential material.
     *
     * Matched case-insensitively on the local element name, wherever it appears
     * in the tree. Erring towards over-redaction is correct here: a false
     * positive costs the model a little context, a false negative discloses a
     * customer's key.
     */
    define('AI_REDACT_ELEMENTS', [
        // account credentials
        'password', 'passwd', 'pass', 'md5-hash', 'nt-hash', 'sha512-hash',
        'otp_seed', 'totp_secret', 'recovery_codes',
        // Certificates and keys.
        //
        // These name the VALUES, not their containers. Listing 'cert' here as
        // well would match the <cert uuid="..."> wrapper and wipe the whole
        // block including <refid> and <descr>, which are not secret and are
        // exactly the context that makes an expiry finding readable.
        'prv', 'privatekey', 'private_key', 'privkey', 'csr',
        'crt', 'tls', 'ca_prv',
        // VPN
        'preshared_key', 'presharedkey', 'pre-shared-key', 'psk', 'sharedkey',
        'publickey', 'pubkey', 'peer_publickey', 'privkeyfile',
        // service credentials
        'apikey', 'api_key', 'apisecret', 'api_secret', 'secret', 'token',
        'authtoken', 'auth_token', 'bearer',
        'radius_secret', 'radius_secret_enc', 'bindpw', 'ldap_bindpw',
        'community', 'snmp_community', 'rocommunity', 'rwcommunity',
        // misc
        'authorizedkeys', 'ssh_key', 'wg_privkey', 'shared_secret',
    ]);
}

if (!function_exists('ai_redact_config')) {
    /**
     * Redact credential material from a configuration document.
     *
     * @param string $xml Raw OPNsense configuration
     * @return array{ok:bool, error:string, xml:string, redacted:array<string,int>, bytes_removed:int}
     */
    function ai_redact_config(string $xml): array {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new DOMDocument();
        // LIBXML_NONET: never resolve an external entity while parsing a
        // configuration that came from a managed firewall.
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return ['ok' => false, 'error' => 'Configuration is not parseable XML',
                    'xml' => '', 'redacted' => [], 'bytes_removed' => 0];
        }

        $deny = array_map('strtolower', AI_REDACT_ELEMENTS);
        $counts = [];
        $bytesRemoved = 0;

        $walk = function (DOMNode $node) use (&$walk, $deny, &$counts, &$bytesRemoved) {
            if (!$node->hasChildNodes()) {
                return;
            }

            foreach (iterator_to_array($node->childNodes) as $child) {
                if (!($child instanceof DOMElement)) {
                    continue;
                }

                $name = strtolower($child->localName);

                if (in_array($name, $deny, true)) {
                    $value = trim($child->textContent);
                    if ($value !== '') {
                        $bytesRemoved += strlen($value);
                        $counts[$name] = ($counts[$name] ?? 0) + 1;

                        // Replace the content, keep the element. The model can
                        // still see that a key is configured and where.
                        while ($child->firstChild) {
                            $child->removeChild($child->firstChild);
                        }
                        $child->appendChild(new DOMText('[REDACTED]'));
                    }
                    // Do not descend: everything under a redacted element is
                    // part of the same secret.
                    continue;
                }

                $walk($child);
            }
        };

        $walk($doc);

        $out = $doc->saveXML();
        if ($out === false) {
            return ['ok' => false, 'error' => 'Could not serialise the redacted configuration',
                    'xml' => '', 'redacted' => $counts, 'bytes_removed' => $bytesRemoved];
        }

        ksort($counts);

        return ['ok' => true, 'error' => '', 'xml' => $out,
                'redacted' => $counts, 'bytes_removed' => $bytesRemoved];
    }
}

if (!function_exists('ai_redact_text')) {
    /**
     * Redact credential-looking material from free text such as log excerpts.
     *
     * Logs are not structured, so this is pattern-based and necessarily
     * coarser than the XML path.
     *
     * @return array{text:string, redactions:int}
     */
    function ai_redact_text(string $text): array {
        $patterns = [
            // key blocks
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            // key=value secrets
            '/\b(pass(?:word|wd)?|secret|token|api[_-]?key|bearer|psk|community)\b\s*[:=]\s*\S+/i',
            // Authorization headers: consume the rest of the line, not just the
            // scheme word - "Authorization: Bearer <token>" left the token
            // behind when this stopped at \S+.
            '/\bAuthorization:.*/i',
            // JWTs, which are shorter than the generic base64 rule below.
            '/\beyJ[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?/',
            // long base64-ish blobs, which are almost always key material in logs
            '/\b[A-Za-z0-9+\/]{60,}={0,2}\b/',
        ];

        $count = 0;
        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($m) use (&$count) {
                $count++;
                return '[REDACTED]';
            }, $text) ?? $text;
        }

        return ['text' => $text, 'redactions' => $count];
    }
}

if (!function_exists('ai_enabled')) {
    /**
     * Whether AI features may be used at all.
     *
     * A self-hosted administrator can switch AI off entirely; nothing in the
     * product depends on it. Configuration search, security checks, health,
     * updates, drift detection, alerting and backups all work with AI disabled.
     */
    function ai_enabled(): bool {
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute(['ai_enabled']);
            $v = $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
        // Absent means "not configured", which is off. AI is opt-in.
        return $v !== false && (string)$v === '1';
    }
}

if (!function_exists('ai_disclosure')) {
    /**
     * What is transmitted to an external provider, for the administrator to read
     * before enabling AI.
     *
     * @return array{sent:string[], never_sent:string[], provider:?string, model:?string}
     */
    function ai_disclosure(): array {
        $provider = null;
        $model    = null;
        try {
            $row = db()->query(
                'SELECT provider, model FROM ai_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $provider = $row['provider'];
                $model    = $row['model'];
            }
        } catch (Throwable $e) { /* not configured */ }

        return [
            'provider' => $provider,
            'model'    => $model,
            'sent' => [
                'Firewall rule set: interfaces, sources, destinations, ports, actions and descriptions',
                'Interface and network configuration, including IP addresses and subnets',
                'Service configuration: which services are enabled and how they are set up',
                'NAT rules and port forwards',
                'VPN configuration structure, without any key material',
                'The firewall hostname and OPNsense version',
                'For log analysis: recent log excerpts, with credential-looking values removed',
            ],
            'never_sent' => [
                'User passwords and password hashes',
                'X.509 private keys and certificate bodies',
                'WireGuard private and public keys',
                'IPsec pre-shared keys',
                'RADIUS and LDAP bind secrets',
                'SNMP community strings',
                'API keys, tokens and shared secrets',
                'MFA seeds and recovery codes',
                'Authorised SSH keys',
            ],
        ];
    }
}

if (!function_exists('ai_prepare_config')) {
    /**
     * Redact a configuration and record what was removed.
     *
     * The single entry point every AI code path must use. Returning an error
     * rather than the original document on failure means a parse failure can
     * never fall back to sending raw key material.
     *
     * @return array{ok:bool, error:string, xml:string, summary:string, redacted:array}
     */
    /**
     * A normalised view of the firewall's rules, whichever section holds them.
     *
     * OPNsense keeps rules in two places. The legacy `<filter>` section is the
     * one whose name says "filter", and on a current installation it is often
     * `<filter/>` - self-closing and empty. The rules that are actually compiled
     * into pf live in `<OPNsense><Firewall><Filter><rules>`, several hundred
     * lines further down under a heading that does not announce itself.
     *
     * A reader that stops at `<filter/>` concludes the firewall has no rules at
     * all. That happened here: one installation has 61 MVC rules including
     *
     *     interface=wan  source=any  destination=(self)  port=443  action=pass
     *
     * which is the WAN-facing web GUI, and an analysis based on the empty legacy
     * section could neither find it nor rule it out.
     *
     * The digest is built from the REDACTED document, never the raw one, so it
     * cannot become a route around ai_redact_config().
     */
    function ai_rule_digest(string $redactedXml): string {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($redactedXml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            return '';
        }

        $rows = [];
        $selfRules = 0;

        $val = static function ($node, string $path): string {
            $v = $node->{$path} ?? null;
            return $v === null ? '' : trim((string) $v);
        };

        // Legacy <filter><rule>
        if (isset($doc->filter->rule)) {
            foreach ($doc->filter->rule as $r) {
                $src = isset($r->source->any) ? 'any'
                     : ($val($r->source, 'network') ?: $val($r->source, 'address') ?: 'any');
                $dst = isset($r->destination->any) ? 'any'
                     : ($val($r->destination, 'network') ?: $val($r->destination, 'address') ?: 'any');
                $rows[] = [
                    'origin' => 'legacy',
                    'iface'  => $val($r, 'interface'),
                    'action' => $val($r, 'type') ?: 'pass',
                    'proto'  => $val($r, 'protocol') ?: 'any',
                    'src'    => $src,
                    'dst'    => $dst,
                    'port'   => $val($r->destination ?? new SimpleXMLElement('<x/>'), 'port'),
                    'on'     => isset($r->disabled) && trim((string) $r->disabled) === '1' ? 'no' : 'yes',
                    'descr'  => $val($r, 'descr'),
                ];
            }
        }

        // Current <OPNsense><Firewall><Filter><rules><rule>
        if (isset($doc->OPNsense->Firewall->Filter->rules->rule)) {
            foreach ($doc->OPNsense->Firewall->Filter->rules->rule as $r) {
                $enabled = $val($r, 'enabled');
                $rows[] = [
                    'origin' => 'mvc',
                    'iface'  => $val($r, 'interface'),
                    'action' => $val($r, 'action') ?: 'pass',
                    'proto'  => $val($r, 'protocol') ?: 'any',
                    'src'    => $val($r, 'source_net') ?: 'any',
                    'dst'    => $val($r, 'destination_net') ?: 'any',
                    'port'   => $val($r, 'destination_port'),
                    'on'     => $enabled === '0' ? 'no' : 'yes',
                    'descr'  => $val($r, 'description'),
                ];
            }
        }

        if (!$rows) {
            return "FIREWALL RULES: none found in either <filter> or "
                 . "<OPNsense><Firewall><Filter><rules>.\n\n";
        }

        $out  = "FIREWALL RULES (normalised from both rule sections)\n";
        $out .= "Rules live in two places in an OPNsense configuration: the legacy\n";
        $out .= "<filter> section, which is frequently empty, and the current\n";
        $out .= "<OPNsense><Firewall><Filter><rules> section. Both are listed here.\n";
        $out .= "Treat THIS table as the authoritative rule set. An empty <filter/>\n";
        $out .= "element in the XML below does NOT mean the firewall has no rules.\n\n";
        $out .= "A destination of (self) means the firewall itself - its own\n";
        $out .= "management interfaces, not a host behind it.\n\n";
        $out .= sprintf("%-7s %-9s %-6s %-6s %-22s %-22s %-10s %-4s %s\n",
                        'SOURCE', 'INTERFACE', 'ACTION', 'PROTO', 'FROM', 'TO', 'PORT', 'ON', 'DESCRIPTION');

        foreach ($rows as $row) {
            if (stripos($row['dst'], 'self') !== false && $row['on'] === 'yes'
                && strcasecmp($row['action'], 'pass') === 0) {
                $selfRules++;
            }
            $out .= sprintf("%-7s %-9s %-6s %-6s %-22s %-22s %-10s %-4s %s\n",
                $row['origin'], substr($row['iface'], 0, 9), substr($row['action'], 0, 6),
                substr($row['proto'], 0, 6), substr($row['src'], 0, 22), substr($row['dst'], 0, 22),
                substr($row['port'], 0, 10), $row['on'], substr($row['descr'], 0, 46));
        }

        $out .= sprintf("\n%d rule(s) total; %d enabled pass rule(s) target the firewall itself.\n\n",
                        count($rows), $selfRules);
        return $out;
    }

    function ai_prepare_config(string $xml, ?int $firewallId = null): array {
        $result = ai_redact_config($xml);

        if (!$result['ok']) {
            // Deliberately no fallback to the raw document.
            error_log('OPNMGR: refusing to send an unparseable configuration to an AI provider');
            return ['ok' => false, 'error' => $result['error'], 'xml' => '',
                    'summary' => '', 'redacted' => []];
        }

        $total = array_sum($result['redacted']);
        $summary = $total === 0
            ? 'No credential material was found in this configuration.'
            : sprintf('%d value(s) redacted across %d element type(s): %s',
                      $total, count($result['redacted']),
                      implode(', ', array_keys($result['redacted'])));

        if (function_exists('audit_log')) {
            audit_log('ai.config.redacted', [
                'actor_type'  => $firewallId !== null ? 'system' : 'user',
                'object_type' => 'firewall',
                'object_id'   => $firewallId !== null ? (string)$firewallId : null,
                'firewall_id' => $firewallId,
                'message'     => 'Configuration redacted before external AI analysis',
                'metadata'    => ['redacted_counts' => $result['redacted'],
                                  'bytes_removed' => $result['bytes_removed']],
            ]);
        }

        return ['ok' => true, 'error' => '', 'xml' => $result['xml'],
                'summary' => $summary, 'redacted' => $result['redacted']];
    }
}

/**
 * Render a finding's cited rules.
 *
 * The model returned accurate citations in an unreadable shape: pasted XML
 * fragments, and lines repeating the column headings of the rule table it was
 * given. The prompt now asks for
 *
 *     wan | pass TCP any -> (self):443 | HTTPS Allow
 *
 * but reports already stored hold the old shape, so this normalises both. XML
 * fragments are reduced to their meaningful attributes rather than dropped -
 * a citation that cannot be parsed is still evidence, and hiding it would make
 * an old report look like it cited nothing.
 */
function ai_format_affected_rules(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        $line = ltrim($line, "•-* \t");
        $line = trim($line, " \"'");
        if ($line === '' || strcasecmp($line, 'N/A') === 0) { continue; }

        // An XML fragment: keep the fields that identify the rule.
        if (str_starts_with($line, '<')) {
            $fields = [];
            if (preg_match_all('/<([a-z_]+)>([^<]{1,60})<\/\1>/i', $line, $m, PREG_SET_ORDER)) {
                $keep = ['interface', 'source_net', 'destination_net', 'destination_port',
                         'description', 'descr', 'protocol', 'action', 'target', 'port',
                         'network', 'protocol', 'enabled'];
                foreach ($m as $hit) {
                    if (in_array(strtolower($hit[1]), $keep, true)) {
                        $fields[] = $hit[1] . '=' . $hit[2];
                    }
                }
            }
            $out[] = ['text' => $fields ? implode('  ', $fields) : $line, 'parsed' => (bool) $fields];
            continue;
        }

        // A headerless dump of the rule table's columns.
        if (stripos($line, 'SOURCE') === 0 && stripos($line, 'INTERFACE') !== false) {
            $line = preg_replace('/\b(SOURCE|INTERFACE|ACTION|PROTO|FROM|TO|PORT|ON|DESCRIPTION)\b\s*/', '', $line);
            $line = trim(preg_replace('/\s{2,}/', ' ', $line));
        }

        $out[] = ['text' => $line, 'parsed' => true];
    }
    return $out;
}
