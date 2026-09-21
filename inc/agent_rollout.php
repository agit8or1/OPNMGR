<?php
/**
 * Staged agent rollout.
 *
 * Publishing an agent version used to be deploying it. Syncing AGENT_VERSION to
 * production made every firewall fetch and install it on its next check-in,
 * within about two minutes, with no step in between. There was no way to build
 * and publish a release without it going to the whole fleet, so "release the
 * agent" and "change every firewall right now" were the same action.
 *
 * The gate here is bound to a specific version, not left as a standing mode.
 * `agent_rollout_stage` applies only to `agent_rollout_version`; when a newer
 * version is published the stored stage no longer matches it and the new version
 * is held. That is the whole point: the safe state is the one you get by
 * forgetting, and promoting a release has to be a deliberate act naming the
 * version it promotes.
 *
 * Stages:
 *   held   nobody is offered it (the default, and what a new publish gets)
 *   pilot  only firewalls with firewalls.agent_rollout_pilot = 1
 *   fleet  everybody
 *
 * Holding suppresses the agent-facing offer only. The manager still knows an
 * update exists and still shows it, because hiding that would trade one silent
 * surprise for another.
 *
 * @since 3.51.0
 */

// db() lives in bootstrap. Without this the settings reads and writes below
// fall into their own catch blocks and fail silently - reads return the default,
// writes do nothing - which looks exactly like a gate that is working.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/agent_version.php';
require_once __DIR__ . '/audit.php';

if (!defined('AGENT_ROLLOUT_STAGES')) {
    define('AGENT_ROLLOUT_STAGES', ['held', 'pilot', 'fleet']);
}

if (!function_exists('agent_rollout_setting')) {
    function agent_rollout_setting(string $name, string $default = ''): string
    {
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute([$name]);
            $value = $stmt->fetchColumn();
            return $value === false ? $default : (string) $value;
        } catch (Throwable $e) {
            error_log('OPNMGR: could not read setting ' . $name . ': ' . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('agent_rollout_state')) {
    /**
     * The effective rollout state for the currently published agent version.
     *
     * @return array{stage:string, stored_stage:string, version:string,
     *               promoted_version:string, superseded:bool}
     */
    function agent_rollout_state(): array
    {
        $published = (string) LATEST_AGENT_VERSION;

        $stored = agent_rollout_setting('agent_rollout_stage', 'held');
        if (!in_array($stored, AGENT_ROLLOUT_STAGES, true)) {
            $stored = 'held';
        }
        $promoted = agent_rollout_setting('agent_rollout_version', '');

        // A stage promoted for an older version says nothing about this one.
        // This is what stops a forgotten 'fleet' from auto-deploying the next
        // release the moment it is published.
        // With auto-promote on, a newly published version is promoted to fleet
        // and every firewall installs it on its next check-in. This is the
        // behaviour the gate was built to prevent, so it is off by default, has
        // to be switched on deliberately, and writes an audit entry each time it
        // fires: the point of the gate was that a deployment should never be a
        // surprise, and an automatic one with no record would be exactly that.
        $decision = agent_rollout_decide(
            $published, $promoted, $stored, agent_rollout_auto_promote_enabled()
        );

        if ($decision['promote']) {
            agent_rollout_setting_put('agent_rollout_version', $published);
            agent_rollout_setting_put('agent_rollout_stage', 'fleet');

            audit_log('agent.rollout.stage', [
                'success'  => true,
                'message'  => "auto-promoted agent {$published} to fleet",
                'metadata' => [
                    'stage'            => 'fleet',
                    'version'          => $published,
                    'previous_version' => $promoted,
                    'auto_promote'     => true,
                ],
            ]);
            $stored = 'fleet';
        }

        return [
            'stage'            => $decision['stage'],
            'stored_stage'     => $stored,
            'version'          => $published,
            'promoted_version' => $decision['promoted_version'],
            'superseded'       => $decision['superseded'],
        ];
    }
}

if (!function_exists('agent_rollout_decide')) {
    /**
     * What stage applies, given the published version and what was promoted.
     *
     * Separated from agent_rollout_state() so the rule can be exercised without
     * touching the live settings. The test that first covered auto-promotion
     * switched the real setting on, and agents check in every two minutes: one
     * did so inside that window and promoted v1.7.0 to the whole fleet for real.
     * Nothing installed it before the revert, but a test must not be capable of
     * deploying software.
     *
     * @return array{stage:string, promoted_version:string, superseded:bool, promote:bool}
     */
    function agent_rollout_decide(string $published, string $promoted, string $stored, bool $auto): array
    {
        if (!in_array($stored, AGENT_ROLLOUT_STAGES, true)) {
            $stored = 'held';
        }

        // A stage promoted for an older version says nothing about this one.
        $superseded = ($promoted !== $published);

        if ($superseded && $published !== '' && $auto) {
            return ['stage' => 'fleet', 'promoted_version' => $published,
                    'superseded' => false, 'promote' => true];
        }

        return [
            'stage'            => $superseded ? 'held' : $stored,
            'promoted_version' => $promoted,
            'superseded'       => $superseded,
            'promote'          => false,
        ];
    }
}

if (!function_exists('agent_rollout_setting_put')) {
    /** Store a rollout setting. */
    function agent_rollout_setting_put(string $name, string $value): void
    {
        try {
            $stmt = db()->prepare(
                'INSERT INTO settings (`name`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
            $stmt->execute([$name, $value]);
        } catch (Throwable $e) {
            error_log('OPNMGR: could not write setting ' . $name . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('agent_rollout_auto_promote_enabled')) {
    /**
     * Should a newly published agent version deploy itself to the whole fleet?
     *
     * Off unless switched on. The staged gate exists because publishing an agent
     * used to mean changing every firewall within two minutes with no step in
     * between; turning this on restores that, deliberately, for an operator who
     * wants their agents to keep themselves current without being asked.
     */
    function agent_rollout_auto_promote_enabled(): bool
    {
        return agent_rollout_setting('agent_rollout_auto_promote', '0') === '1';
    }
}

if (!function_exists('agent_rollout_set_auto_promote')) {
    /** Turn auto-promotion on or off, with a record of who did it. */
    function agent_rollout_set_auto_promote(bool $enabled): void
    {
        $was = agent_rollout_auto_promote_enabled();
        agent_rollout_setting_put('agent_rollout_auto_promote', $enabled ? '1' : '0');

        if ($was !== $enabled) {
            audit_log('agent.rollout.auto_promote', [
                'success'  => true,
                'message'  => $enabled
                    ? 'agent auto-update enabled: new versions deploy to the fleet on publish'
                    : 'agent auto-update disabled: new versions are held until promoted',
                'metadata' => ['enabled' => $enabled],
            ]);
        }
    }
}

if (!function_exists('agent_rollout_allows')) {
    /**
     * May this firewall be offered the published agent version?
     *
     * @param bool $is_pilot firewalls.agent_rollout_pilot for this firewall
     */
    function agent_rollout_allows(bool $is_pilot): bool
    {
        $state = agent_rollout_state();

        switch ($state['stage']) {
            case 'fleet':
                return true;
            case 'pilot':
                return $is_pilot;
            case 'held':
            default:
                return false;
        }
    }
}

if (!function_exists('agent_rollout_promote')) {
    /**
     * Promote the published version to a stage. Always binds the stage to the
     * version being promoted, so it cannot silently carry over to the next one.
     *
     * @return array{ok:bool, message:string}
     */
    function agent_rollout_promote(string $stage): array
    {
        if (!in_array($stage, AGENT_ROLLOUT_STAGES, true)) {
            audit_log('agent.rollout.stage', [
                'success'  => false,
                'message'  => "rejected unknown rollout stage '{$stage}'",
                'metadata' => ['requested_stage' => $stage],
            ]);
            return ['ok' => false, 'message' => "unknown stage '{$stage}'"];
        }

        $published = (string) LATEST_AGENT_VERSION;

        // Capture what it was, so the entry records a transition rather than
        // just a destination. Counted before the write, because promoting is
        // what changes who the update reaches.
        $before = agent_rollout_state();
        $reach  = 0;
        foreach (agent_rollout_targets() as $t) {
            if ($stage === 'fleet' || ($stage === 'pilot' && $t['pilot'])) {
                $reach++;
            }
        }

        try {
            $sql = 'INSERT INTO settings (`name`, `value`) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)';
            db()->prepare($sql)->execute(['agent_rollout_stage', $stage]);
            db()->prepare($sql)->execute(['agent_rollout_version', $published]);
        } catch (Throwable $e) {
            error_log('OPNMGR: could not record agent rollout stage: ' . $e->getMessage());
            audit_log('agent.rollout.stage', [
                'success'  => false,
                'message'  => "failed to promote agent {$published} to '{$stage}'",
                'metadata' => ['stage' => $stage, 'version' => $published, 'error' => $e->getMessage()],
            ]);
            return ['ok' => false, 'message' => 'could not write settings: ' . $e->getMessage()];
        }

        // Promoting is the act that lets a release reach firewalls, and it used
        // to happen implicitly on publish with no record anywhere. The reach
        // count is the part worth reading later: it says how many firewalls this
        // opened the update to, not merely which stage was chosen.
        audit_log('agent.rollout.stage', [
            'object_type' => 'agent_version',
            'object_id'   => $published,
            'message'     => sprintf(
                'agent %s promoted to stage \'%s\' (was \'%s\' for %s); reaches %d firewall(s)',
                $published, $stage, $before['stored_stage'],
                $before['promoted_version'] === '' ? 'no version' : $before['promoted_version'],
                $reach
            ),
            'metadata'    => [
                'version'          => $published,
                'stage'            => $stage,
                'previous_stage'   => $before['stored_stage'],
                'previous_version' => $before['promoted_version'],
                'effective_before' => $before['stage'],
                'reaches'          => $reach,
            ],
        ]);

        return ['ok' => true, 'message' => "agent {$published} is now at stage '{$stage}'"];
    }
}

if (!function_exists('agent_rollout_targets')) {
    /**
     * Firewalls below the published version, and whether each would be offered
     * it right now. Used by the CLI and the settings page so the effect of a
     * promotion is visible before it is made.
     *
     * @return array<int, array{id:int, hostname:string, agent_version:string,
     *                          pilot:bool, offered:bool}>
     */
    function agent_rollout_targets(): array
    {
        $rows = [];
        try {
            $stmt = db()->query(
                'SELECT id, hostname, agent_version, agent_rollout_pilot
                   FROM firewalls ORDER BY id'
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $current = (string) ($row['agent_version'] ?? '');
                if ($current === '' || version_compare(
                        preg_replace('/[^0-9.]/', '', $current),
                        preg_replace('/[^0-9.]/', '', (string) LATEST_AGENT_VERSION),
                        '>='
                    )) {
                    continue; // already current
                }
                $pilot = (bool) ($row['agent_rollout_pilot'] ?? 0);
                $rows[] = [
                    'id'            => (int) $row['id'],
                    'hostname'      => (string) ($row['hostname'] ?? ''),
                    'agent_version' => $current,
                    'pilot'         => $pilot,
                    'offered'       => agent_rollout_allows($pilot),
                ];
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: could not list agent rollout targets: ' . $e->getMessage());
        }
        return $rows;
    }
}
