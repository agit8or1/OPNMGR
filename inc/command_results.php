<?php
/**
 * OPNMGR Command Result Handling
 *
 * Shared validation for agents reporting back the outcome of a queued command.
 * Both agent_checkin.php and api/command_result.php funnel through here so the
 * ownership and state checks cannot drift apart between the two entry points.
 *
 * Guarantees enforced here:
 *   - the command exists
 *   - the command belongs to the authenticated firewall (never firewall A
 *     writing a result for firewall B)
 *   - the reported status is one the schema allows
 *   - a command already in a terminal state is not silently overwritten
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/audit.php';

if (!defined('COMMAND_TERMINAL_STATES')) {
    /** States after which a further result report is rejected. */
    define('COMMAND_TERMINAL_STATES', ['completed', 'failed', 'cancelled']);
}

if (!function_exists('normalize_command_status')) {
    /**
     * Map what an agent reports onto the firewall_commands.status enum.
     *
     * Agents in the field send a mixture of vocabularies ('success', 'ok',
     * 'error', 'partial', plus the enum values themselves), so this must stay
     * permissive on input while only ever emitting a valid enum value.
     *
     * @return string|null Null when the status is not recognised
     */
    function normalize_command_status(string $status): ?string {
        $status = strtolower(trim($status));

        $map = [
            'success'   => 'completed',
            'ok'        => 'completed',
            'done'      => 'completed',
            'completed' => 'completed',
            'partial'   => 'completed',
            'error'     => 'failed',
            'failure'   => 'failed',
            'failed'    => 'failed',
            'timeout'   => 'failed',
            'cancelled' => 'cancelled',
            'canceled'  => 'cancelled',
        ];

        return $map[$status] ?? null;
    }
}

if (!function_exists('record_agent_command_result')) {
    /**
     * Validate and store a command result reported by an authenticated agent.
     *
     * The caller MUST have already authenticated the firewall via
     * authenticateAgentRequest(); this function trusts $firewallId and uses it
     * as the authorization boundary.
     *
     * @param int    $firewallId Authenticated firewall id
     * @param int    $commandId  Command being reported on
     * @param string $status     Agent-reported status
     * @param string $result     Result payload (base64 when $opts['result_is_base64'])
     * @param array  $opts       result_is_base64 => bool,
     *                           error_output     => string (base64, appended as stderr)
     * @return array{ok:bool, status:int, message:string, result:string,
     *                normalized_status:string, command:array|null}
     */
    function record_agent_command_result(
        int $firewallId,
        int $commandId,
        string $status,
        string $result,
        array $opts = []
    ): array {
        $fail = static function (int $httpStatus, string $message) {
            return [
                'ok'                => false,
                'status'            => $httpStatus,
                'message'           => $message,
                'result'            => '',
                'normalized_status' => '',
                'command'           => null,
            ];
        };

        if ($commandId <= 0) {
            return $fail(400, 'Missing command_id');
        }

        $normalized = normalize_command_status($status);
        if ($normalized === null) {
            audit_log_agent('command.result.rejected', $firewallId, [
                'success'   => false,
                'object_id' => (string)$commandId,
                'message'   => 'Unrecognised command status reported',
                'metadata'  => ['reported_status' => substr($status, 0, 64)],
            ]);
            return $fail(400, 'Invalid status');
        }

        // --- ownership -------------------------------------------------------
        $stmt = db()->prepare(
            'SELECT id, firewall_id, command, command_type, description, status
               FROM firewall_commands
              WHERE id = ?'
        );
        $stmt->execute([$commandId]);
        $command = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$command || (int)$command['firewall_id'] !== $firewallId) {
            // Same response whether the command is missing or belongs to another
            // firewall, so this cannot be used to enumerate the command table.
            error_log(sprintf(
                'OPNMGR command result REJECTED: command %d not owned by firewall %d',
                $commandId,
                $firewallId
            ));
            audit_log_agent('command.result.rejected', $firewallId, [
                'success'   => false,
                'object_id' => (string)$commandId,
                'message'   => 'Command does not belong to the authenticated firewall',
            ]);
            return $fail(403, 'Command not found for this firewall');
        }

        // --- terminal state --------------------------------------------------
        if (in_array($command['status'], COMMAND_TERMINAL_STATES, true)) {
            audit_log_agent('command.result.duplicate', $firewallId, [
                'success'   => false,
                'object_id' => (string)$commandId,
                'message'   => 'Result reported for a command already in a terminal state',
                'metadata'  => ['existing_status' => $command['status'], 'reported_status' => $normalized],
            ]);
            return $fail(409, 'Command already finalised');
        }

        // --- decode payload --------------------------------------------------
        $decoded = $result;
        if (!empty($opts['result_is_base64']) && $result !== '') {
            // strict base64: a payload that is not valid base64 is kept verbatim
            // rather than silently mangled, since older agents sent plain text.
            $maybe = base64_decode($result, true);
            $decoded = ($maybe === false) ? $result : $maybe;
        }

        if (!empty($opts['error_output'])) {
            $stderr = base64_decode($opts['error_output'], true);
            if ($stderr === false) {
                $stderr = $opts['error_output'];
            }
            if ($stderr !== '') {
                $decoded .= "\n\n=== STDERR ===\n" . $stderr;
            }
        }

        // Bound what an agent can write into the database in one report.
        if (strlen($decoded) > 262144) {
            $decoded = substr($decoded, 0, 262144) . "\n\n[output truncated by OPNMGR at 256 KB]";
        }

        // --- store -----------------------------------------------------------
        // firewall_id in the WHERE clause as a second, independent guard.
        $update = db()->prepare(
            'UPDATE firewall_commands
                SET status = ?, result = ?, completed_at = NOW()
              WHERE id = ? AND firewall_id = ? AND status NOT IN (?, ?, ?)'
        );
        $update->execute(array_merge(
            [$normalized, $decoded, $commandId, $firewallId],
            COMMAND_TERMINAL_STATES
        ));

        if ($update->rowCount() === 0) {
            // Lost a race with a concurrent report.
            return $fail(409, 'Command already finalised');
        }

        audit_log_agent('command.result', $firewallId, [
            'success'   => $normalized === 'completed',
            'object_type' => 'command',
            'object_id' => (string)$commandId,
            'message'   => sprintf('Command %d reported %s', $commandId, $normalized),
            'metadata'  => [
                'command_type' => $command['command_type'] ?? null,
                'description'  => $command['description'] ?? null,
                'output_bytes' => strlen($decoded),
            ],
        ]);

        return [
            'ok'                => true,
            'status'            => 200,
            'message'           => 'Command result recorded',
            'result'            => $decoded,
            'normalized_status' => $normalized,
            'command'           => $command,
        ];
    }
}
