<?php
/**
 * VPN topology: operator decisions and per-user preferences.
 *
 * Pairings are the correction mechanism for automatic matching. They are
 * stored against endpoint keys (firewall + protocol + the peer's public key,
 * IKE id or instance id), so they survive config re-parses and renames.
 * Layout preferences are per user and kept on the server so they follow the
 * person across browsers.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/util.php';

const VPN_ENDPOINT_KEY_RE = '/^fw(\d+)\|(wireguard|ipsec|openvpn)\|[^\x00-\x1f]{1,150}$/';
const VPN_CLASSES = ['site_to_site', 'remote_access', 'external', 'unknown'];

if (!function_exists('vpn_endpoint_key_parts')) {
    function vpn_endpoint_key_parts(string $key): ?array {
        if (!preg_match(VPN_ENDPOINT_KEY_RE, $key, $m)) { return null; }
        return ['firewall_id' => (int)$m[1], 'protocol' => $m[2]];
    }
}

if (!function_exists('vpn_pairing_apply')) {
    /**
     * Pair, un-pair or classify. A pairing makes both endpoints exclusive to
     * each other; an un-pair forbids that one match without forbidding others.
     *
     * @param string $action pair | unpair | classify | clear_pair | clear_classify
     * @param array  $in     a, b?, classification?, note?
     * @param array|null $known endpoint keys currently in the topology (to reject typos), or null to skip
     * @return array{ok:bool, error:string}
     */
    function vpn_pairing_apply(string $action, array $in, string $user, ?array $known = null): array {
        $a = (string)($in['a'] ?? ''); $b = (string)($in['b'] ?? '');
        $note = isset($in['note']) ? mb_substr(trim((string)$in['note']), 0, 255) : null;
        $pa = vpn_endpoint_key_parts($a);
        if (!$pa) { return ['ok' => false, 'error' => 'Invalid endpoint reference.']; }
        if ($known !== null && !isset($known[$a])) { return ['ok' => false, 'error' => 'That endpoint is not in the current topology.']; }

        if ($action === 'classify' || $action === 'clear_classify') {
            if ($action === 'clear_classify') {
                db()->prepare("DELETE FROM vpn_peer_pairings WHERE kind = 'classify' AND endpoint_a = ?")->execute([$a]);
            } else {
                $c = (string)($in['classification'] ?? '');
                if (!in_array($c, VPN_CLASSES, true)) { return ['ok' => false, 'error' => 'Classification must be one of ' . implode(', ', VPN_CLASSES) . '.']; }
                db()->prepare("DELETE FROM vpn_peer_pairings WHERE kind = 'classify' AND endpoint_a = ?")->execute([$a]);
                db()->prepare("INSERT INTO vpn_peer_pairings (kind, endpoint_a, endpoint_b, classification, note, created_by) VALUES ('classify',?,'',?,?,?)")
                    ->execute([$a, $c, $note, $user]);
            }
            audit_log('vpn.pairing.' . $action, ['success' => true, 'firewall_id' => $pa['firewall_id'], 'message' => "VPN endpoint classification: $action", 'metadata' => ['a' => $a, 'classification' => $in['classification'] ?? null]]);
            return ['ok' => true, 'error' => ''];
        }

        $pb = vpn_endpoint_key_parts($b);
        if (!$pb) { return ['ok' => false, 'error' => 'Invalid second endpoint reference.']; }
        if ($known !== null && !isset($known[$b])) { return ['ok' => false, 'error' => 'The second endpoint is not in the current topology.']; }
        if ($pa['firewall_id'] === $pb['firewall_id']) { return ['ok' => false, 'error' => 'Both ends of a tunnel cannot be on the same firewall.']; }
        if ($pa['protocol'] !== $pb['protocol']) { return ['ok' => false, 'error' => 'Endpoints of different protocols cannot be paired.']; }
        [$x, $y] = strcmp($a, $b) <= 0 ? [$a, $b] : [$b, $a];

        switch ($action) {
            case 'pair':
                // Exclusive: drop any other manual pairing that uses either end, and any veto on this pair.
                db()->prepare("DELETE FROM vpn_peer_pairings WHERE kind = 'pair' AND (endpoint_a IN (?,?) OR endpoint_b IN (?,?))")->execute([$x, $y, $x, $y]);
                db()->prepare("DELETE FROM vpn_peer_pairings WHERE kind = 'unpair' AND endpoint_a = ? AND endpoint_b = ?")->execute([$x, $y]);
                db()->prepare("INSERT INTO vpn_peer_pairings (kind, endpoint_a, endpoint_b, note, created_by) VALUES ('pair',?,?,?,?)")->execute([$x, $y, $note, $user]);
                break;
            case 'unpair':
                db()->prepare("DELETE FROM vpn_peer_pairings WHERE kind = 'pair' AND endpoint_a = ? AND endpoint_b = ?")->execute([$x, $y]);
                db()->prepare("INSERT INTO vpn_peer_pairings (kind, endpoint_a, endpoint_b, note, created_by) VALUES ('unpair',?,?,?,?)
                               ON DUPLICATE KEY UPDATE note = VALUES(note), created_by = VALUES(created_by)")->execute([$x, $y, $note, $user]);
                break;
            case 'clear_pair':
                db()->prepare("DELETE FROM vpn_peer_pairings WHERE kind IN ('pair','unpair') AND endpoint_a = ? AND endpoint_b = ?")->execute([$x, $y]);
                break;
            default:
                return ['ok' => false, 'error' => 'Unknown action.'];
        }
        audit_log('vpn.pairing.' . $action, ['success' => true, 'firewall_id' => $pa['firewall_id'],
            'message' => "VPN endpoint pairing: $action", 'metadata' => ['a' => $x, 'b' => $y, 'note' => $note]]);
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('vpn_prefs_sanitize')) {
    /** Accept only the shape the page writes; bound everything. */
    function vpn_prefs_sanitize($p): array {
        $p = is_array($p) ? $p : [];
        $out = ['view' => in_array($p['view'] ?? '', ['map', 'table'], true) ? $p['view'] : 'map', 'positions' => [], 'filters' => []];
        foreach (array_slice((array)($p['positions'] ?? []), 0, 500, true) as $id => $xy) {
            if (is_string($id) && preg_match('/^[A-Za-z0-9:_.|\-]{1,64}$/', $id) && is_array($xy)
                && isset($xy['x'], $xy['y']) && is_numeric($xy['x']) && is_numeric($xy['y'])) {
                $out['positions'][$id] = ['x' => max(-5000, min(5000, round((float)$xy['x'], 1))), 'y' => max(-5000, min(5000, round((float)$xy['y'], 1)))];
            }
        }
        foreach (['customer', 'site', 'platform', 'protocol', 'state', 'relationship', 'kind', 'q'] as $k) {
            if (isset($p['filters'][$k]) && is_scalar($p['filters'][$k])) {
                $out['filters'][$k] = mb_substr(preg_replace('/[\x00-\x1f]/u', '', (string)$p['filters'][$k]), 0, 80);
            }
        }
        return $out;
    }
}

if (!function_exists('vpn_prefs_get')) {
    function vpn_prefs_get(int $userId, string $view = 'topology'): array {
        $s = db()->prepare('SELECT prefs FROM vpn_layout_prefs WHERE user_id = ? AND view_key = ?');
        $s->execute([$userId, $view]);
        $raw = $s->fetchColumn();
        return vpn_prefs_sanitize($raw ? json_decode((string)$raw, true) : []);
    }
}

if (!function_exists('vpn_prefs_save')) {
    function vpn_prefs_save(int $userId, array $prefs, string $view = 'topology'): array {
        $clean = vpn_prefs_sanitize($prefs);
        $json = json_encode($clean);
        if (strlen($json) > 60000) { return ['ok' => false, 'error' => 'Preferences too large.']; }
        db()->prepare('INSERT INTO vpn_layout_prefs (user_id, view_key, prefs) VALUES (?,?,?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)')
            ->execute([$userId, $view, $json]);
        return ['ok' => true, 'error' => '', 'prefs' => $clean];
    }
}
