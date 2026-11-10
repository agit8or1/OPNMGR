<?php
/**
 * Customer-side checks for update rings.
 *
 * A firewall that checks in after an update has proved its own WAN works. It
 * has not proved that a workstation behind it can still resolve internal names,
 * reach the file server across the WireGuard tunnel, or get to the internet.
 * Those are what customers notice.
 *
 * A probe is a small program on a customer network: the standalone
 * tools/opnmgr-probe/opnmgr_probe.py, or Rem0te running the same checks. It
 * holds one token, fetches the checks configured for it here, runs them and
 * posts results. It can run exactly three kinds of check and nothing else:
 *
 *   dns               resolve a hostname (optionally via a named internal
 *                     resolver) and optionally require the answer to be in an
 *                     address or CIDR
 *   vpn_service       open a TCP connection to host:port where the host is, or
 *                     resolves only to, a private address - i.e. across the VPN
 *   internet_service  make an HTTPS request, certificate verified, to a host
 *                     that is, or resolves only to, a public address
 *
 * Probes are tenant-scoped: a probe belongs to one customer and covers one of
 * that customer's firewalls (and its CARP partner). A probe token can read only
 * its own checks and write only its own results.
 *
 * Campaigns opt in with update_campaigns.customer_checks (default off). When on:
 *   - before dispatch, each required check must have a fresh result, which is
 *     snapshotted as the target's baseline (a failing baseline is recorded, not
 *     blocking: the update may be the fix)
 *   - after the update, every required check must report healthy, freshly,
 *     before the ring counts as clean or the next ring can start; a probe that
 *     stops reporting holds promotion exactly like a failure
 *   - the second member of a CARP pair is held until the client paths behind
 *     the pair are healthy after the first member's update
 * An operator can still start a ring by hand over a hold by giving a reason,
 * which is audited.
 *
 * @since 3.81.0
 */

require_once __DIR__ . '/audit.php';

if (!defined('CUSTOMER_CHECK_TYPES')) {
    define('CUSTOMER_CHECK_TYPES', [
        'dns'              => 'Internal DNS',
        'vpn_service'      => 'Service across VPN',
        'internet_service' => 'Internet service',
    ]);
}
if (!defined('CUSTOMER_PROBE_MAX_CHECKS')) {
    define('CUSTOMER_PROBE_MAX_CHECKS', 10);
}

if (!function_exists('cc_setting')) {
    function cc_setting(string $name, int $default): int {
        try {
            $s = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $s->execute([$name]);
            $v = $s->fetchColumn();
        } catch (Throwable $e) {
            $v = false;
        }
        return ($v === false || $v === '' || !is_numeric($v)) ? $default : max(1, (int)$v);
    }
}

// ---------------------------------------------------------------------------
// Address classification (mirrored in the probe)
// ---------------------------------------------------------------------------

if (!function_exists('cc_ip_is_internal')) {
    /** RFC 1918, CGNAT (100.64/10, common for WireGuard overlays) and IPv6 ULA. */
    function cc_ip_is_internal(string $ip): bool {
        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'] as $cidr) {
            if (cc_ip_in_cidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('cc_ip_is_public')) {
    function cc_ip_is_public(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !cc_ip_is_internal($ip);
    }
}

if (!function_exists('cc_ip_in_cidr')) {
    function cc_ip_in_cidr(string $ip, string $cidr): bool {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipb = @inet_pton($ip);
        $netb = @inet_pton((string)$net);
        if ($ipb === false || $netb === false || strlen($ipb) !== strlen($netb)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipb) * 8 : (int)$bits;
        if ($bits < 0 || $bits > strlen($ipb) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($ipb, 0, $bytes) !== substr($netb, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rem)) & 0xff);
        return (($ipb[$bytes] & $mask) === ($netb[$bytes] & $mask));
    }
}

if (!function_exists('cc_hostname_ok')) {
    function cc_hostname_ok(string $h): bool {
        return strlen($h) <= 253
            && preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?$/D', $h) === 1
            && filter_var($h, FILTER_VALIDATE_IP) === false;
    }
}

// ---------------------------------------------------------------------------
// Check definitions
// ---------------------------------------------------------------------------

if (!function_exists('cc_validate_check')) {
    /**
     * Normalise and validate a check an administrator wants to approve.
     *
     * @return array{ok:bool, error:string, check:array}
     */
    function cc_validate_check(array $in): array {
        $type   = (string)($in['check_type'] ?? '');
        $target = trim((string)($in['target'] ?? ''));
        $label  = trim((string)($in['label'] ?? ''));
        $res    = trim((string)($in['resolver'] ?? ''));
        $expect = trim((string)($in['expect'] ?? ''));
        $fail   = fn(string $e) => ['ok' => false, 'error' => $e, 'check' => []];

        if (!isset(CUSTOMER_CHECK_TYPES[$type])) {
            return $fail('Choose DNS, a service across the VPN, or an internet service');
        }
        if ($label === '') {
            $label = CUSTOMER_CHECK_TYPES[$type] . ': ' . $target;
        }

        switch ($type) {
            case 'dns':
                if (!cc_hostname_ok($target)) {
                    return $fail('DNS checks resolve a hostname, e.g. fileserver.corp.lan');
                }
                if ($res !== '' && !(filter_var($res, FILTER_VALIDATE_IP) && cc_ip_is_internal($res))) {
                    return $fail('The resolver must be an internal (private) address, e.g. 10.0.0.53');
                }
                if ($expect !== '') {
                    [$ip, $bits] = array_pad(explode('/', $expect, 2), 2, null);
                    if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && !ctype_digit($bits))) {
                        return $fail('Expected answer must be an address or CIDR, e.g. 10.20.0.5 or 10.20.0.0/24');
                    }
                }
                break;

            case 'vpn_service':
                if (!preg_match('/^(\[[0-9A-Fa-f:.]+\]|[^:\s\/]+):(\d{1,5})$/D', $target, $m) || (int)$m[2] < 1 || (int)$m[2] > 65535) {
                    return $fail('A VPN service is host:port, e.g. 10.20.0.5:445 or [fd00::5]:443');
                }
                $host = trim($m[1], '[]');
                if (filter_var($host, FILTER_VALIDATE_IP)) {
                    if (!cc_ip_is_internal($host)) {
                        return $fail('A service across the VPN must be on a private address (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7)');
                    }
                } elseif (!cc_hostname_ok($host)) {
                    return $fail('Not a valid host name: ' . $host);
                }
                $res = $expect = '';
                break;

            case 'internet_service':
                $u = parse_url($target);
                if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['pass'])
                    || isset($u['fragment']) || strlen($target) > 255) {
                    return $fail('An internet service is an https:// URL with no credentials, e.g. https://login.microsoftonline.com/');
                }
                $host = trim($u['host'], '[]');
                if (filter_var($host, FILTER_VALIDATE_IP) ? !cc_ip_is_public($host) : !cc_hostname_ok($host)) {
                    return $fail('The internet service must be a public host');
                }
                if ($expect !== '' && !preg_match('/^[1-5]\d\d$/', $expect)) {
                    return $fail('Expected status must be an HTTP status such as 200');
                }
                $res = '';
                break;
        }

        return ['ok' => true, 'error' => '', 'check' => [
            'check_type' => $type, 'label' => substr($label, 0, 100), 'target' => $target,
            'resolver' => $res !== '' ? $res : null, 'expect' => $expect !== '' ? $expect : null,
            'required' => array_key_exists('required', $in) ? (!empty($in['required']) ? 1 : 0) : 1,
        ]];
    }
}

// ---------------------------------------------------------------------------
// Probes
// ---------------------------------------------------------------------------

if (!function_exists('cc_new_token')) {
    function cc_new_token(): string {
        return 'opp_' . bin2hex(random_bytes(24));
    }
}

if (!function_exists('cc_create_probe')) {
    /**
     * @return array{ok:bool, error:string, id:int, token:string}
     */
    function cc_create_probe(int $customerId, int $firewallId, string $name, string $kind = 'standalone'): array {
        $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => 0, 'token' => ''];
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            return $fail('Give the probe a name (up to 100 characters), e.g. "Front office PC"');
        }
        if (!in_array($kind, ['standalone', 'rem0te'], true)) {
            return $fail('Unknown probe kind');
        }
        $fw = db()->prepare('SELECT id, customer_id FROM firewalls WHERE id = ?');
        $fw->execute([$firewallId]);
        $row = $fw->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $fail('Firewall not found');
        }
        if ((int)$row['customer_id'] !== $customerId) {
            return $fail('That firewall belongs to another customer; a probe can only cover its own customer\'s firewalls');
        }
        $token = cc_new_token();
        db()->prepare('INSERT INTO customer_probes (customer_id, firewall_id, name, kind, token_hash, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$customerId, $firewallId, $name, $kind, hash('sha256', $token), $_SESSION['username'] ?? null]);
        $id = (int)db()->lastInsertId();
        audit_log('customer_probe.create', ['object_type' => 'customer_probe', 'object_id' => (string)$id,
            'firewall_id' => $firewallId, 'message' => sprintf('Customer probe "%s" created', $name)]);
        return ['ok' => true, 'error' => '', 'id' => $id, 'token' => $token];
    }
}

if (!function_exists('cc_get_probe')) {
    function cc_get_probe(int $probeId): ?array {
        $s = db()->prepare('SELECT * FROM customer_probes WHERE id = ?');
        $s->execute([$probeId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('cc_rotate_token')) {
    function cc_rotate_token(int $probeId): array {
        $token = cc_new_token();
        $s = db()->prepare('UPDATE customer_probes SET token_hash = ? WHERE id = ?');
        $s->execute([hash('sha256', $token), $probeId]);
        if ($s->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Probe not found', 'token' => ''];
        }
        audit_log('customer_probe.rotate', ['object_type' => 'customer_probe', 'object_id' => (string)$probeId,
            'message' => 'Customer probe token replaced; the old token stopped working']);
        return ['ok' => true, 'error' => '', 'token' => $token];
    }
}

if (!function_exists('cc_set_probe_enabled')) {
    function cc_set_probe_enabled(int $probeId, bool $enabled): void {
        db()->prepare('UPDATE customer_probes SET enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $probeId]);
        audit_log('customer_probe.' . ($enabled ? 'enable' : 'disable'), ['object_type' => 'customer_probe',
            'object_id' => (string)$probeId, 'message' => 'Customer probe ' . ($enabled ? 'enabled' : 'disabled')]);
    }
}

if (!function_exists('cc_delete_probe')) {
    function cc_delete_probe(int $probeId): void {
        foreach (['customer_probe_results', 'customer_probe_checks'] as $t) {
            db()->prepare("DELETE FROM {$t} WHERE probe_id = ?")->execute([$probeId]);
        }
        db()->prepare('DELETE FROM customer_probes WHERE id = ?')->execute([$probeId]);
        audit_log('customer_probe.delete', ['object_type' => 'customer_probe', 'object_id' => (string)$probeId,
            'message' => 'Customer probe deleted with its checks and results']);
    }
}

if (!function_exists('cc_add_check')) {
    function cc_add_check(int $probeId, array $in): array {
        $v = cc_validate_check($in);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => $v['error'], 'id' => 0];
        }
        $n = db()->prepare('SELECT COUNT(*) FROM customer_probe_checks WHERE probe_id = ?');
        $n->execute([$probeId]);
        if ((int)$n->fetchColumn() >= CUSTOMER_PROBE_MAX_CHECKS) {
            return ['ok' => false, 'error' => 'A probe runs at most ' . CUSTOMER_PROBE_MAX_CHECKS . ' checks', 'id' => 0];
        }
        if (!cc_get_probe($probeId)) {
            return ['ok' => false, 'error' => 'Probe not found', 'id' => 0];
        }
        $c = $v['check'];
        db()->prepare('INSERT INTO customer_probe_checks (probe_id, check_type, label, target, resolver, expect, required) VALUES (?,?,?,?,?,?,?)')
            ->execute([$probeId, $c['check_type'], $c['label'], $c['target'], $c['resolver'], $c['expect'], $c['required']]);
        $id = (int)db()->lastInsertId();
        audit_log('customer_probe.check.add', ['object_type' => 'customer_probe', 'object_id' => (string)$probeId,
            'message' => sprintf('Approved %s check "%s" (%s)', $c['check_type'], $c['label'], $c['target'])]);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }
}

if (!function_exists('cc_delete_check')) {
    function cc_delete_check(int $probeId, int $checkId): void {
        db()->prepare('DELETE FROM customer_probe_results WHERE check_id = ? AND probe_id = ?')->execute([$checkId, $probeId]);
        db()->prepare('DELETE FROM customer_probe_checks WHERE id = ? AND probe_id = ?')->execute([$checkId, $probeId]);
        audit_log('customer_probe.check.delete', ['object_type' => 'customer_probe', 'object_id' => (string)$probeId,
            'message' => 'Customer check #' . $checkId . ' removed']);
    }
}

if (!function_exists('cc_set_check_required')) {
    function cc_set_check_required(int $probeId, int $checkId, bool $required): void {
        db()->prepare('UPDATE customer_probe_checks SET required = ? WHERE id = ? AND probe_id = ?')
            ->execute([$required ? 1 : 0, $checkId, $probeId]);
    }
}

// ---------------------------------------------------------------------------
// The probe's side
// ---------------------------------------------------------------------------

if (!function_exists('cc_probe_by_token')) {
    function cc_probe_by_token(string $token): ?array {
        if (!preg_match('/^opp_[0-9a-f]{48}$/D', $token)) {
            return null;
        }
        $s = db()->prepare('SELECT * FROM customer_probes WHERE token_hash = ? AND enabled = 1');
        $s->execute([hash('sha256', $token)]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('cc_probe_config')) {
    /** What a probe is told to run. Only enabled checks, only these fields. */
    function cc_probe_config(array $probe): array {
        $s = db()->prepare('SELECT id, check_type, target, resolver, expect FROM customer_probe_checks
                             WHERE probe_id = ? AND enabled = 1 ORDER BY id LIMIT ' . CUSTOMER_PROBE_MAX_CHECKS);
        $s->execute([(int)$probe['id']]);
        $checks = array_map(fn($c) => [
            'id' => (int)$c['id'], 'type' => $c['check_type'], 'target' => $c['target'],
            'resolver' => $c['resolver'], 'expect' => $c['expect'], 'timeout_seconds' => 5,
        ], $s->fetchAll(PDO::FETCH_ASSOC));
        return ['probe_id' => (int)$probe['id'], 'interval_seconds' => max(30, (int)$probe['interval_seconds']), 'checks' => $checks];
    }
}

if (!function_exists('cc_record_results')) {
    /**
     * Store a probe's results. Results for checks that are not this probe's
     * are dropped, not stored.
     *
     * @return array{accepted:int, rejected:int}
     */
    function cc_record_results(array $probe, array $results, ?string $version = null, ?string $ip = null): array {
        $own = db()->prepare('SELECT id FROM customer_probe_checks WHERE probe_id = ? AND enabled = 1');
        $own->execute([(int)$probe['id']]);
        $mine = array_flip(array_map('intval', $own->fetchAll(PDO::FETCH_COLUMN)));

        $ins = db()->prepare('INSERT INTO customer_probe_results (probe_id, check_id, ok, latency_ms, detail) VALUES (?,?,?,?,?)');
        $accepted = $rejected = 0;
        foreach (array_slice($results, 0, CUSTOMER_PROBE_MAX_CHECKS * 2) as $r) {
            $cid = (int)($r['check_id'] ?? 0);
            if (!is_array($r) || !isset($mine[$cid]) || !array_key_exists('ok', $r)) {
                $rejected++;
                continue;
            }
            $lat = isset($r['latency_ms']) && is_numeric($r['latency_ms']) ? max(0, min(600000, (int)$r['latency_ms'])) : null;
            $detail = preg_replace('/[^\x20-\x7E]/', '', substr((string)($r['detail'] ?? ''), 0, 255));
            $ins->execute([(int)$probe['id'], $cid, $r['ok'] === true ? 1 : 0, $lat, $detail !== '' ? $detail : null]);
            $accepted++;
        }
        db()->prepare('UPDATE customer_probes SET last_seen_at = NOW(), last_seen_ip = ?, probe_version = ? WHERE id = ?')
            ->execute([$ip !== null ? substr($ip, 0, 45) : null,
                       $version !== null ? substr(preg_replace('/[^A-Za-z0-9._+-]/', '', $version), 0, 32) : null,
                       (int)$probe['id']]);
        return ['accepted' => $accepted, 'rejected' => $rejected];
    }
}

// ---------------------------------------------------------------------------
// Evaluation
// ---------------------------------------------------------------------------

if (!function_exists('cc_checks_for_firewall')) {
    /**
     * Enabled checks on enabled probes that cover a firewall: probes behind it
     * or behind its CARP partner, of the same customer as the firewall.
     */
    function cc_checks_for_firewall(int $firewallId): array {
        $s = db()->prepare(
            'SELECT c.*, p.name AS probe_name, p.firewall_id AS probe_firewall_id, p.last_seen_at,
                    TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS probe_silent
               FROM firewalls f
               JOIN customer_probes p ON p.enabled = 1 AND p.customer_id = f.customer_id
                                     AND (p.firewall_id = f.id OR (f.ha_peer_firewall_id IS NOT NULL AND p.firewall_id = f.ha_peer_firewall_id))
               JOIN customer_probe_checks c ON c.probe_id = p.id AND c.enabled = 1
              WHERE f.id = ?
              ORDER BY p.name, c.id'
        );
        $s->execute([$firewallId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('cc_latest_result')) {
    /** Newest result for a check, optionally only at or after a time. */
    function cc_latest_result(int $checkId, ?string $since = null, ?string $before = null): ?array {
        $sql = 'SELECT *, TIMESTAMPDIFF(SECOND, observed_at, NOW()) AS age_seconds FROM customer_probe_results WHERE check_id = ?';
        $p = [$checkId];
        if ($since !== null) { $sql .= ' AND observed_at >= ?'; $p[] = $since; }
        if ($before !== null) { $sql .= ' AND observed_at < ?'; $p[] = $before; }
        $s = db()->prepare($sql . ' ORDER BY observed_at DESC, id DESC LIMIT 1');
        $s->execute($p);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('cc_path_label')) {
    function cc_path_label(array $check): string {
        return sprintf('%s → %s: %s', $check['probe_name'], CUSTOMER_CHECK_TYPES[$check['check_type']] ?? $check['check_type'], $check['label']);
    }
}

if (!function_exists('cc_check_now')) {
    /**
     * State of one check right now: pass, fail, or stale (no result within the
     * stale window - the probe is offline or cannot reach the manager).
     */
    function cc_check_now(array $check, int $staleSeconds): array {
        $latest = cc_latest_result((int)$check['id']);
        if (!$latest || (int)$latest['age_seconds'] > $staleSeconds) {
            return ['state' => 'stale', 'result' => $latest,
                    'detail' => $latest ? sprintf('no result for %d min (probe offline?)', intdiv((int)$latest['age_seconds'], 60))
                                        : 'the probe has never reported this check'];
        }
        return ['state' => (int)$latest['ok'] === 1 ? 'pass' : 'fail', 'result' => $latest,
                'detail' => (string)($latest['detail'] ?? '')];
    }
}

if (!function_exists('cc_baseline_snapshot')) {
    /**
     * Before dispatch: every required check must have a fresh result, which
     * becomes the target's baseline. A failing baseline does not hold - it is
     * recorded so a failure afterwards can be told apart from a regression.
     *
     * @return array{ok:bool, reason:string, snapshot:array, checks:int}
     */
    function cc_baseline_snapshot(int $firewallId): array {
        $stale = cc_setting('customer_check_stale_minutes', 10) * 60;
        $checks = cc_checks_for_firewall($firewallId);
        $snap = [];
        $holds = [];
        foreach ($checks as $c) {
            $now = cc_check_now($c, $stale);
            $snap[] = ['check_id' => (int)$c['id'], 'probe' => $c['probe_name'], 'type' => $c['check_type'],
                       'label' => $c['label'], 'required' => (int)$c['required'], 'state' => $now['state'],
                       'detail' => $now['detail'], 'at' => $now['result']['observed_at'] ?? null];
            if ((int)$c['required'] === 1 && $now['state'] === 'stale') {
                $holds[] = cc_path_label($c) . ' (' . $now['detail'] . ')';
            }
        }
        if ($holds) {
            return ['ok' => false, 'snapshot' => $snap, 'checks' => count($checks),
                    'reason' => 'No fresh pre-update customer check: ' . implode('; ', $holds)];
        }
        return ['ok' => true, 'reason' => '', 'snapshot' => $snap, 'checks' => count($checks)];
    }
}

if (!function_exists('cc_target_evaluation')) {
    /**
     * After the update: the state of each check behind a completed target.
     *
     * pass     every required check reported healthy since the update, and is fresh
     * waiting  updated, settle time not yet over or first result not in yet
     * fail     a required check's latest result is a failure
     * stale    a required check has not reported within the stale window
     * none     no required checks cover this firewall (nothing to gate on)
     *
     * @param array $target update_campaign_targets row
     */
    function cc_target_evaluation(array $target): array {
        $fid    = (int)$target['firewall_id'];
        $stale  = cc_setting('customer_check_stale_minutes', 10) * 60;
        $settle = cc_setting('customer_check_settle_minutes', 3) * 60;
        $checks = cc_checks_for_firewall($fid);

        $baseline = [];
        foreach ((array)json_decode((string)($target['customer_baseline'] ?? ''), true) as $b) {
            if (isset($b['check_id'])) { $baseline[(int)$b['check_id']] = $b; }
        }

        $completed = $target['completed_at'] ?? null;
        $since = $completed ? date('Y-m-d H:i:s', strtotime((string)$completed) + $settle) : null;
        $out = [];
        $rank = ['none' => 0, 'pass' => 1, 'waiting' => 2, 'stale' => 3, 'fail' => 4];
        $worst = 'none';
        $reasons = [];

        foreach ($checks as $c) {
            $req = (int)$c['required'] === 1;
            $before = $baseline[(int)$c['id']]['state'] ?? null;
            if ($since === null) {
                $state = 'waiting';
                $detail = 'update not finished';
            } else {
                $post = cc_latest_result((int)$c['id'], $since);
                $now  = cc_check_now($c, $stale);
                if ($post === null && $now['state'] === 'stale') {
                    // Silent since before the update finished: offline, not "waiting".
                    $state = 'stale';
                    $detail = $now['detail'];
                } elseif ($post === null) {
                    $state = (time() - strtotime($since) > $stale) ? 'stale' : 'waiting';
                    $detail = $state === 'stale'
                        ? 'no result since the update (probe offline?)'
                        : (time() < strtotime($since) ? 'settling after the update' : 'waiting for the first post-update result');
                } else {
                    $state = $now['state'];
                    $detail = $now['detail'];
                }
            }
            if ($state === 'fail' && $before === 'fail') {
                $detail .= ($detail !== '' ? '; ' : '') . 'was already failing before the update';
            } elseif ($state === 'fail' && $before === 'pass') {
                $detail .= ($detail !== '' ? '; ' : '') . 'passed before the update';
            }
            $out[] = ['check_id' => (int)$c['id'], 'probe' => $c['probe_name'], 'type' => $c['check_type'],
                      'path' => CUSTOMER_CHECK_TYPES[$c['check_type']], 'label' => $c['label'], 'target' => $c['target'],
                      'required' => $req, 'state' => $state, 'before' => $before, 'detail' => $detail];
            if ($req) {
                if ($rank[$state] > $rank[$worst]) {
                    $worst = $state;
                }
                if ($state !== 'pass') {
                    $reasons[] = cc_path_label($c) . ' ' . $state . ($detail !== '' ? ' (' . $detail . ')' : '');
                }
            }
        }
        return ['state' => $worst, 'checks' => $out, 'reasons' => $reasons];
    }
}

if (!function_exists('cc_ring_gate')) {
    /**
     * Whether a ring's customer checks allow promotion past it.
     *
     * Only targets that completed their update are evaluated: a ring still in
     * progress is already reported as such by campaign_ring_is_clean().
     *
     * @return array{ok:bool, state:string, reasons:string[], targets:array}
     */
    function cc_ring_gate(int $campaignId, string $ring): array {
        $s = db()->prepare(
            'SELECT t.*, f.hostname FROM update_campaign_targets t JOIN firewalls f ON f.id = t.firewall_id
              WHERE t.campaign_id = ? AND t.ring = ? AND t.status = "succeeded" ORDER BY f.hostname'
        );
        $s->execute([$campaignId, $ring]);
        $rank = ['none' => 0, 'pass' => 1, 'waiting' => 2, 'stale' => 3, 'fail' => 4];
        $state = 'none';
        $reasons = [];
        $targets = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $e = cc_target_evaluation($t);
            $targets[(int)$t['firewall_id']] = $e + ['hostname' => $t['hostname']];
            if ($rank[$e['state']] > $rank[$state]) {
                $state = $e['state'];
            }
            foreach ($e['reasons'] as $r) {
                $reasons[] = $t['hostname'] . ': ' . $r;
            }
        }
        return ['ok' => in_array($state, ['none', 'pass'], true), 'state' => $state, 'reasons' => $reasons, 'targets' => $targets];
    }
}

if (!function_exists('cc_probe_overview')) {
    /** Probes visible to the current user, with each check's current state. */
    function cc_probe_overview(?array $customerIds): array {
        $where = '';
        $params = [];
        if ($customerIds !== null) {
            if (!$customerIds) {
                return [];
            }
            $where = 'WHERE p.customer_id IN (' . implode(',', array_fill(0, count($customerIds), '?')) . ')';
            $params = $customerIds;
        }
        $s = db()->prepare("SELECT p.*, c.name AS customer_name, f.hostname, f.ha_peer_firewall_id, peer.hostname AS peer_hostname,
                                   TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS silent_seconds
                              FROM customer_probes p
                              JOIN customers c ON c.id = p.customer_id
                              LEFT JOIN firewalls f ON f.id = p.firewall_id
                              LEFT JOIN firewalls peer ON peer.id = f.ha_peer_firewall_id
                              {$where} ORDER BY c.name, p.name");
        $s->execute($params);
        $stale = cc_setting('customer_check_stale_minutes', 10) * 60;
        $probes = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($probes as &$p) {
            $cs = db()->prepare('SELECT * FROM customer_probe_checks WHERE probe_id = ? ORDER BY id');
            $cs->execute([(int)$p['id']]);
            $p['checks'] = [];
            foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $c['probe_name'] = $p['name'];
                $c['now'] = cc_check_now($c, $stale);
                $p['checks'][] = $c;
            }
        }
        unset($p);
        return $probes;
    }
}
