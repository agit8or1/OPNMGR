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
