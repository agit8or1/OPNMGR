<?php
/**
 * Can the fleet actually satisfy the configured agent signing policy?
 *
 * The server side of request signing is complete: HMAC-SHA256 verification, a
 * freshness window, nonce replay rejection, a per-firewall ratchet, and three
 * fleet-wide policy modes. The check-in response even hands the agent its
 * signing secret and the canonical string to sign, with the note "Sign requests
 * once supported."
 *
 * It never was. No agent release has contained a single line of signing code -
 * no HMAC, no X-OPNMGR-Signature header, and the agent does not even store the
 * api_secret the server sends it. agent_signing_supported is 0 for every
 * firewall because no agent has ever produced a signature.
 *
 * That makes `agent_auth_mode = require_signed` a trap. It reads as the
 * hardened option and would refuse every check-in in the fleet, and the setting
 * has no UI, so it can only be set by someone who went looking for it in the
 * database - exactly the person most likely to choose it.
 *
 * The policy is deliberately NOT overridden here. Silently downgrading a
 * security setting is the failure this codebase is full of. Instead the
 * condition is made loud, in the interface, where it can be acted on - the
 * fleet going quiet is survivable and reversible; a security control that
 * pretends to be on is not.
 *
 * @since 3.43.0
 */

if (!function_exists('agent_signing_status')) {
    /**
     * @return array{mode:string, supported:int, total:int, satisfiable:bool, implemented:bool}
     */
    function agent_signing_status(): array
    {
        $mode = 'compatibility';
        $supported = 0;
        $total = 0;

        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute(['agent_auth_mode']);
            $stored = (string) ($stmt->fetchColumn() ?: '');
            if (in_array($stored, ['compatibility', 'prefer_signed', 'require_signed'], true)) {
                $mode = $stored;
            }

            $row = db()->query(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN agent_signing_supported = 1 THEN 1 ELSE 0 END) AS signing
                   FROM firewalls'
            )->fetch(PDO::FETCH_ASSOC);

            $total     = (int) ($row['total'] ?? 0);
            $supported = (int) ($row['signing'] ?? 0);
        } catch (Throwable $e) {
            error_log('OPNMGR: could not read agent signing status: ' . $e->getMessage());
        }

        // require_signed refuses every agent that cannot sign. Any other mode
        // tolerates them, so it is satisfiable whatever the fleet supports.
        $satisfiable = $mode !== 'require_signed' || ($total > 0 && $supported === $total);

        return [
            'mode'        => $mode,
            'supported'   => $supported,
            'total'       => $total,
            'satisfiable' => $satisfiable,
            'implemented' => $supported > 0,
        ];
    }
}

if (!function_exists('agent_signing_banner')) {
    /** Warning for the interface, or '' when the configured policy is workable. */
    function agent_signing_banner(): string
    {
        try {
            $s = agent_signing_status();
        } catch (Throwable $e) {
            return '';
        }

        if ($s['satisfiable']) {
            return '';
        }

        $cannot = $s['total'] - $s['supported'];

        return '<div class="alert alert-danger mb-3" role="alert">'
             . '<i class="fas fa-key me-2"></i>'
             . '<strong>Agent signing is required but not supported.</strong> '
             . '<code>agent_auth_mode</code> is set to <code>require_signed</code>, and '
             . (int) $cannot . ' of ' . (int) $s['total'] . ' firewalls run an agent that '
             . 'cannot produce a signature. Those agents are being refused at check-in and '
             . 'the fleet will go quiet.'
             . '<div class="small mt-2">Request signing arrived in agent 1.6.4; any agent '
             . 'older than that cannot produce a signature, and an agent that has never '
             . 'signed has not yet proven it can. Upgrade the affected firewalls, or set '
             . '<code>agent_auth_mode</code> back to <code>compatibility</code> in the '
             . '<code>settings</code> table to restore check-ins.</div>'
             . '</div>';
    }
}
