<?php
/**
 * Reboot-pending state, derived from evidence rather than assumed.
 *
 * The agent has never reported whether a reboot is outstanding - `reboot`
 * appears nowhere in agent.sh - so agent_checkin.php takes its "preserve the
 * existing value" branch on every check-in. That left reboot_required writable
 * only by code that guessed:
 *
 *   - the old update-request path set it to 1 the moment a request was handed
 *     to the agent, before anything had run;
 *   - the update-recovery branches set it to 0 whenever a firewall reappeared
 *     with status 'updating'.
 *
 * Neither consulted the firewall. In practice that left fw.agit8or.net
 * asserting "reboot required" continuously from 2026-03-04 - through many
 * actual reboots - while home.agit8or.net reported no reboot needed
 * immediately after installing a new base and kernel it had not booted into.
 *
 * What we can honestly determine: the agent reports uptime, so we can estimate
 * when the box last booted, and we know when an update last installed
 * successfully. If it booted before that update landed, the new base/kernel is
 * not running yet and a reboot is genuinely outstanding. With no successful
 * update on record there is no evidence of a pending reboot, and we say so
 * rather than inventing one.
 *
 * @since 3.19.2
 */

/**
 * Parse the agent's free-text uptime into seconds.
 *
 * The agent pipes uptime(1) through two sed expressions that strip everything
 * up to "up " and then everything from the first comma, which on FreeBSD yields
 * forms like "187 days", "1 day", "4:12" (h:mm), "42 mins" or "23 secs".
 * Multi-day values are truncated to whole days by uptime(1) itself, so the
 * estimate is only accurate to about a day - fine for asking whether a boot
 * preceded an update, which is the only question asked of it.
 *
 * @param string $uptime Raw uptime string as reported by the agent
 * @return int|null Seconds, or null if unrecognised
 */
function parse_uptime_to_seconds(?string $uptime): ?int {
    $u = strtolower(trim((string)$uptime));
    if ($u === '' || $u === 'unknown') {
        return null;
    }

    if (preg_match('/^(\d+)\s*day/', $u, $m)) {
        return (int)$m[1] * 86400;
    }
    if (preg_match('/^(\d+):(\d{2})$/', $u, $m)) {
        return (int)$m[1] * 3600 + (int)$m[2] * 60;
    }
    if (preg_match('/^(\d+)\s*(hr|hour)/', $u, $m)) {
        return (int)$m[1] * 3600;
    }
    if (preg_match('/^(\d+)\s*min/', $u, $m)) {
        return (int)$m[1] * 60;
    }
    if (preg_match('/^(\d+)\s*sec/', $u, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * Estimate when a firewall last booted.
 *
 * @param array $fw Row with at least uptime and last_checkin
 * @return int|null Unix timestamp, or null if uptime is unusable
 */
function firewall_boot_time(array $fw): ?int {
    $seconds = parse_uptime_to_seconds($fw['uptime'] ?? null);
    if ($seconds === null) {
        return null;
    }
    $checkin = !empty($fw['last_checkin']) ? strtotime($fw['last_checkin']) : null;
    if (!$checkin) {
        return null;
    }
    return $checkin - $seconds;
}

/**
 * Timestamp of the last update this firewall is known to have installed.
 *
 * Only counts commands whose recorded output carries the exit marker showing
 * the updater itself succeeded. The agent reports every command as "completed"
 * regardless of outcome, so command status alone proves nothing.
 *
 * @param int $firewallId
 * @return int|null Unix timestamp of completion, or null if none
 */
function firewall_last_successful_update(int $firewallId): ?int {
    $stmt = db()->prepare(
        "SELECT completed_at, result
           FROM firewall_commands
          WHERE firewall_id = ? AND action = 'install_updates'
            AND status = 'completed' AND completed_at IS NOT NULL
          ORDER BY completed_at DESC LIMIT 20"
    );
    $stmt->execute([$firewallId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (preg_match('/OPNMGR_UPDATE_EXIT=0\b/', (string)$row['result'])) {
            return strtotime($row['completed_at']);
        }
    }
    return null;
}

/**
 * Decide whether a reboot is outstanding.
 *
 * @param array $fw Firewall row (id, uptime, last_checkin)
 * @return array{state:string, reason:string} state is 'pending'|'not_pending'|'unknown'
 */
function firewall_reboot_state(array $fw): array {
    $updatedAt = firewall_last_successful_update((int)$fw['id']);
    if ($updatedAt === null) {
        return [
            'state'  => 'not_pending',
            'reason' => 'no successfully installed update on record',
        ];
    }

    $bootedAt = firewall_boot_time($fw);
    if ($bootedAt === null) {
        return [
            'state'  => 'unknown',
            'reason' => 'uptime not reported in a form we can read',
        ];
    }

    if ($bootedAt < $updatedAt) {
        return [
            'state'  => 'pending',
            'reason' => sprintf(
                'booted %s, before the update installed at %s',
                date('Y-m-d H:i', $bootedAt), date('Y-m-d H:i', $updatedAt)
            ),
        ];
    }

    return [
        'state'  => 'not_pending',
        'reason' => sprintf(
            'booted %s, after the update installed at %s',
            date('Y-m-d H:i', $bootedAt), date('Y-m-d H:i', $updatedAt)
        ),
    ];
}

/**
 * Recompute and persist reboot_required for one firewall.
 *
 * Leaves the stored value untouched when the state cannot be determined, so an
 * unreadable uptime never silently clears a real pending reboot.
 *
 * @param int $firewallId
 * @return array{state:string, reason:string, changed:bool}
 */
function recompute_reboot_required(int $firewallId): array {
    $stmt = db()->prepare('SELECT id, uptime, last_checkin, reboot_required FROM firewalls WHERE id = ?');
    $stmt->execute([$firewallId]);
    $fw = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fw) {
        return ['state' => 'unknown', 'reason' => 'firewall not found', 'changed' => false];
    }

    $verdict = firewall_reboot_state($fw);
    if ($verdict['state'] === 'unknown') {
        return $verdict + ['changed' => false];
    }

    $want = $verdict['state'] === 'pending' ? 1 : 0;
    if ((int)$fw['reboot_required'] === $want) {
        return $verdict + ['changed' => false];
    }

    db()->prepare('UPDATE firewalls SET reboot_required = ? WHERE id = ?')->execute([$want, $firewallId]);
    return $verdict + ['changed' => true];
}
