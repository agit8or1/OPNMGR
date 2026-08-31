<?php
/**
 * OPNMGR Configuration Drift
 *
 * Answers "has this firewall's configuration changed since we last agreed it
 * was correct", using the configuration backups the fleet already uploads.
 *
 * Why this does not diff text
 * ---------------------------
 * Two backups of an unchanged firewall routinely differ by hundreds of lines:
 * OPNsense (and whatever serialiser produced the file) vary between
 * `<item />` and `<item/>`, between `<?xml version='1.0'?>` and
 * `<?xml version="1.0"?>`, and in indentation. Comparing text reports drift on
 * a firewall nobody has touched, which trains people to ignore the feature.
 *
 * Instead the XML is parsed into a canonical structure: element order within a
 * parent is sorted, whitespace-only text is dropped, and genuinely volatile
 * values (the <revision> block that OPNsense stamps on every save, rule
 * created/updated timestamps) are removed. The canonical form is hashed for the
 * "changed / unchanged" verdict and compared section by section for the diff.
 *
 * Drift is never acted on automatically. Detecting a change does not restore
 * anything; it raises a finding for a technician.
 *
 * @since 3.13.0
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/backup_storage.php';

if (!defined('DRIFT_VOLATILE_PATHS')) {
    /**
     * Config paths whose values change without anyone changing the firewall.
     *
     * Matched as a path prefix against the canonical tree, e.g. the whole
     * `opnsense/revision` subtree.
     */
    define('DRIFT_VOLATILE_PATHS', [
        'opnsense/revision',            // stamped on every save
        'opnsense/system/firmware/plugins_hash',
        'opnsense/syslog/reverse',
        'opnsense/OPNsense/Netflow/capture/version',
    ]);
}

if (!defined('DRIFT_VOLATILE_LEAVES')) {
    /**
     * Leaf element names dropped wherever they appear.
     *
     * These carry a timestamp or generated id rather than configuration.
     */
    define('DRIFT_VOLATILE_LEAVES', [
        'updated', 'created', 'lastchange', 'last_updated',
    ]);
}

if (!function_exists('drift_xml_to_canonical')) {
    /**
     * Parse an OPNsense config into a canonical, comparable array.
     *
     * @param string $xml Raw configuration XML
     * @return array{ok:bool, error:string, tree:array}
     */
    function drift_xml_to_canonical(string $xml): array {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // LIBXML_NONET: never fetch a DTD or entity over the network.
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            $first = $errors[0]->message ?? 'unparseable XML';
            return ['ok' => false, 'error' => trim($first), 'tree' => []];
        }

        $tree = drift_normalize_node($doc, $doc->getName());
        return ['ok' => true, 'error' => '', 'tree' => [$doc->getName() => $tree]];
    }
}

if (!function_exists('drift_normalize_node')) {
    /**
     * Recursively convert a SimpleXMLElement into a canonical value.
     *
     * Children are grouped by name and sorted, so a reordered but otherwise
     * identical configuration compares equal. Attributes are folded in under an
     * '@' key so they participate in the comparison.
     *
     * @param SimpleXMLElement $node
     * @param string           $path Canonical path used for volatile matching
     * @param int              $depth Recursion guard
     * @return mixed Scalar for a leaf, array for a branch
     */
    function drift_normalize_node(SimpleXMLElement $node, string $path, int $depth = 0) {
        if ($depth > 40) {
            return '[too deep]';
        }

        $children = [];
        foreach ($node->children() as $name => $child) {
            $childPath = $path . '/' . $name;

            if (in_array($name, DRIFT_VOLATILE_LEAVES, true)) {
                continue;
            }
            foreach (DRIFT_VOLATILE_PATHS as $volatile) {
                if ($childPath === $volatile || str_starts_with($childPath, $volatile . '/')) {
                    continue 2;
                }
            }

            $children[$name][] = drift_normalize_node($child, $childPath, $depth + 1);
        }

        $attrs = [];
        foreach ($node->attributes() as $aName => $aVal) {
            $attrs[(string)$aName] = (string)$aVal;
        }

        if (!$children) {
            // Leaf. Trim so indentation and trailing newlines do not register
            // as a change; an empty element and a whitespace-only one are the
            // same thing.
            $value = trim((string)$node);
            if ($attrs) {
                ksort($attrs);
                return ['@' => $attrs, '#' => $value];
            }
            return $value;
        }

        // Sort repeated siblings so that ordering alone is not drift.
        foreach ($children as $name => $list) {
            if (count($list) > 1) {
                usort($list, function ($a, $b) {
                    return strcmp(
                        json_encode($a, JSON_UNESCAPED_SLASHES),
                        json_encode($b, JSON_UNESCAPED_SLASHES)
                    );
                });
                $children[$name] = $list;
            } else {
                $children[$name] = $list[0];
            }
        }
        ksort($children);

        if ($attrs) {
            ksort($attrs);
            $children['@'] = $attrs;
        }

        return $children;
    }
}

if (!function_exists('drift_fingerprint')) {
    /**
     * Stable hash of a configuration's meaningful content.
     *
     * @return array{ok:bool, error:string, hash:string, sections:array<string,string>}
     */
    function drift_fingerprint(string $xml): array {
        $canonical = drift_xml_to_canonical($xml);
        if (!$canonical['ok']) {
            return ['ok' => false, 'error' => $canonical['error'], 'hash' => '', 'sections' => []];
        }

        $root = $canonical['tree'];
        $rootName = array_key_first($root);
        $body = $root[$rootName];

        // Per-section hashes let the UI say WHICH parts changed rather than
        // just that something did.
        $sections = [];
        if (is_array($body)) {
            foreach ($body as $section => $content) {
                if ($section === '@') {
                    continue;
                }
                $sections[$section] = hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES));
            }
        }
        ksort($sections);

        return [
            'ok'       => true,
            'error'    => '',
            'hash'     => hash('sha256', json_encode($root, JSON_UNESCAPED_SLASHES)),
            'sections' => $sections,
        ];
    }
}

if (!function_exists('drift_compare')) {
    /**
     * Compare two configurations.
     *
     * @return array{ok:bool, error:string, changed:bool,
     *               added:string[], removed:string[], modified:string[]}
     */
    function drift_compare(string $baselineXml, string $currentXml): array {
        $a = drift_fingerprint($baselineXml);
        $b = drift_fingerprint($currentXml);

        if (!$a['ok'] || !$b['ok']) {
            return [
                'ok' => false,
                'error' => $a['ok'] ? $b['error'] : $a['error'],
                'changed' => false, 'added' => [], 'removed' => [], 'modified' => [],
            ];
        }

        $added    = array_values(array_diff(array_keys($b['sections']), array_keys($a['sections'])));
        $removed  = array_values(array_diff(array_keys($a['sections']), array_keys($b['sections'])));
        $modified = [];

        foreach ($a['sections'] as $name => $hash) {
            if (isset($b['sections'][$name]) && !hash_equals($hash, $b['sections'][$name])) {
                $modified[] = $name;
            }
        }
        sort($modified);

        return [
            'ok'       => true,
            'error'    => '',
            'changed'  => $a['hash'] !== $b['hash'],
            'added'    => $added,
            'removed'  => $removed,
            'modified' => $modified,
        ];
    }
}

if (!function_exists('drift_section_diff')) {
    /**
     * Human-readable differences within one section.
     *
     * Walks both canonical trees and reports leaf paths that were added,
     * removed or changed. Values are truncated: a configuration section can
     * contain a certificate body, and the point here is orientation, not a full
     * dump.
     *
     * @return array<int, array{path:string, change:string, from:?string, to:?string}>
     */
    function drift_section_diff(string $baselineXml, string $currentXml, string $section, int $limit = 200): array {
        $a = drift_xml_to_canonical($baselineXml);
        $b = drift_xml_to_canonical($currentXml);
        if (!$a['ok'] || !$b['ok']) {
            return [];
        }

        $rootA = $a['tree'][array_key_first($a['tree'])] ?? [];
        $rootB = $b['tree'][array_key_first($b['tree'])] ?? [];

        $subA = is_array($rootA) ? ($rootA[$section] ?? null) : null;
        $subB = is_array($rootB) ? ($rootB[$section] ?? null) : null;

        $out = [];
        drift_walk_diff($subA, $subB, $section, $out, $limit);
        return $out;
    }
}

if (!function_exists('drift_walk_diff')) {
    /**
     * Recursive helper for drift_section_diff().
     *
     * @param mixed  $a
     * @param mixed  $b
     * @param string $path
     * @param array  $out   Accumulator, by reference
     * @param int    $limit Stop once this many differences are collected
     */
    function drift_walk_diff($a, $b, string $path, array &$out, int $limit): void {
        if (count($out) >= $limit) {
            return;
        }

        $scalarA = !is_array($a);
        $scalarB = !is_array($b);

        if ($scalarA && $scalarB) {
            if ((string)$a !== (string)$b) {
                $out[] = [
                    'path'   => $path,
                    'change' => 'modified',
                    'from'   => drift_truncate((string)$a),
                    'to'     => drift_truncate((string)$b),
                ];
            }
            return;
        }

        if ($a === null && $b !== null) {
            $out[] = ['path' => $path, 'change' => 'added', 'from' => null, 'to' => drift_summarize($b)];
            return;
        }
        if ($a !== null && $b === null) {
            $out[] = ['path' => $path, 'change' => 'removed', 'from' => drift_summarize($a), 'to' => null];
            return;
        }
        if ($scalarA !== $scalarB) {
            $out[] = [
                'path'   => $path,
                'change' => 'modified',
                'from'   => drift_summarize($a),
                'to'     => drift_summarize($b),
            ];
            return;
        }

        // Repeated siblings (firewall rules, interfaces, users) are canonically
        // sorted lists. Comparing them by index makes a single inserted rule
        // look like every later rule was edited, because everything shifts by
        // one. Compare them as sets instead and report entries that appeared or
        // disappeared.
        // A single occurrence of a repeatable element is canonicalised as a bare
        // map, several as a list. Going from one firewall rule to two therefore
        // compares a map against a list, which fell through to a field-by-field
        // walk and reported one "modified" line per field of the first rule.
        // Promote the singleton so the set comparison below sees both sides in
        // the same shape.
        if (drift_is_list($a) !== drift_is_list($b)) {
            if (drift_is_list($a) && is_array($b)) {
                $b = [$b];
            } elseif (drift_is_list($b) && is_array($a)) {
                $a = [$a];
            }
        }

        if (drift_is_list($a) && drift_is_list($b)) {
            $hashA = [];
            foreach ($a as $item) {
                $hashA[hash('sha256', json_encode($item, JSON_UNESCAPED_SLASHES))] = $item;
            }
            $hashB = [];
            foreach ($b as $item) {
                $hashB[hash('sha256', json_encode($item, JSON_UNESCAPED_SLASHES))] = $item;
            }

            foreach ($hashB as $h => $item) {
                if (!isset($hashA[$h])) {
                    if (count($out) >= $limit) return;
                    $out[] = ['path' => $path, 'change' => 'added',
                              'from' => null, 'to' => drift_describe_entry($item)];
                }
            }
            foreach ($hashA as $h => $item) {
                if (!isset($hashB[$h])) {
                    if (count($out) >= $limit) return;
                    $out[] = ['path' => $path, 'change' => 'removed',
                              'from' => drift_describe_entry($item), 'to' => null];
                }
            }
            return;
        }

        $keys = array_unique(array_merge(array_keys((array)$a), array_keys((array)$b)));
        sort($keys);
        foreach ($keys as $key) {
            drift_walk_diff(
                $a[$key] ?? null,
                $b[$key] ?? null,
                $path . '/' . $key,
                $out,
                $limit
            );
        }
    }
}

if (!function_exists('drift_truncate')) {
    /** Shorten a value for display. */
    function drift_truncate(string $value, int $max = 120): string {
        $value = preg_replace('/\s+/', ' ', trim($value));
        return strlen($value) > $max ? substr($value, 0, $max) . '…' : $value;
    }
}

if (!function_exists('drift_summarize')) {
    /** One-line description of a subtree or scalar. */
    function drift_summarize($value): string {
        if (!is_array($value)) {
            return drift_truncate((string)$value);
        }
        $count = count($value);
        return sprintf('[%d %s]', $count, $count === 1 ? 'entry' : 'entries');
    }
}

if (!function_exists('drift_is_list')) {
    /**
     * Whether a canonical value is a list of repeated siblings.
     *
     * drift_normalize_node() only produces a numerically-indexed array when an
     * element appeared more than once under the same parent.
     */
    function drift_is_list($value): bool {
        return is_array($value) && $value !== [] && array_keys($value) === range(0, count($value) - 1);
    }
}

if (!function_exists('drift_describe_entry')) {
    /**
     * Identify one entry of a repeated list for a human.
     *
     * Prefers the fields OPNsense uses to label things, so a changed rule reads
     * as its description rather than as an opaque hash.
     */
    function drift_describe_entry($item): string {
        if (!is_array($item)) {
            return drift_truncate((string)$item);
        }

        foreach (['descr', 'description', 'name', 'hostname', 'if', 'interface'] as $label) {
            if (isset($item[$label]) && !is_array($item[$label]) && trim((string)$item[$label]) !== '') {
                $text = drift_truncate((string)$item[$label], 60);

                // Add a little context where it is cheap and useful.
                $extra = [];
                foreach (['interface', 'protocol', 'type'] as $ctx) {
                    if ($ctx !== $label && isset($item[$ctx]) && !is_array($item[$ctx]) && (string)$item[$ctx] !== '') {
                        $extra[] = $ctx . '=' . drift_truncate((string)$item[$ctx], 24);
                    }
                }
                return $extra ? $text . ' (' . implode(', ', $extra) . ')' : $text;
            }
        }

        return drift_summarize($item);
    }
}

// ---------------------------------------------------------------------------
// Persistence: baselines and drift state
// ---------------------------------------------------------------------------

if (!function_exists('drift_load_backup_xml')) {
    /**
     * Read a backup's configuration XML from disk.
     *
     * @return array{ok:bool, error:string, xml:string, row:array}
     */
    function drift_load_backup_xml(int $backupId): array {
        $stmt = db()->prepare('SELECT * FROM backups WHERE id = ?');
        $stmt->execute([$backupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['ok' => false, 'error' => 'Backup not found', 'xml' => '', 'row' => []];
        }

        $path = resolve_backup_path($row);
        if ($path === null) {
            return ['ok' => false, 'error' => 'Backup file is missing on disk', 'xml' => '', 'row' => $row];
        }

        $xml = @file_get_contents($path);
        if ($xml === false || $xml === '') {
            return ['ok' => false, 'error' => 'Backup file could not be read', 'xml' => '', 'row' => $row];
        }

        return ['ok' => true, 'error' => '', 'xml' => $xml, 'row' => $row];
    }
}

if (!function_exists('drift_latest_backup')) {
    /**
     * Newest backup for a firewall that actually has bytes on disk.
     *
     * A backups row is created when the capture is queued, and only gets a file
     * when the agent uploads one. Installations that went through the period
     * when uploads were failing have long runs of rows with nothing behind
     * them, so this walks back in pages until it finds a readable file rather
     * than giving up after a fixed window and reporting "no configuration".
     *
     * @param int $maxScan Safety bound on how far back to look
     */
    function drift_latest_backup(int $firewallId, int $maxScan = 500, ?array &$diag = null): ?array {
        $pageSize = 50;
        $diag = [
            'skipped_missing'    => 0,
            'skipped_unreadable' => 0,
            'newest_unreadable'  => null,
        ];

        for ($offset = 0; $offset < $maxScan; $offset += $pageSize) {
            $stmt = db()->prepare(
                "SELECT * FROM backups
                  WHERE firewall_id = ?
                  ORDER BY COALESCE(uploaded_at, created_at) DESC, id DESC
                  LIMIT {$pageSize} OFFSET {$offset}"
            );
            $stmt->execute([$firewallId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $resolved = resolve_backup_path_status($row);

                if ($resolved['status'] === 'ok') {
                    return $row;
                }

                if ($resolved['status'] === 'unreadable') {
                    // Present but not readable by this process. Skipping to an
                    // older backup here would silently answer from stale
                    // configuration, so this is recorded and shouted about
                    // rather than absorbed.
                    $diag['skipped_unreadable']++;
                    if ($diag['newest_unreadable'] === null) {
                        $diag['newest_unreadable'] = $row['uploaded_at'] ?: $row['created_at'];
                        error_log(sprintf(
                            'OPNMGR: backup %d for firewall %d exists but is not readable by %s - '
                            . 'falling back to an older configuration. Check permissions on %s',
                            (int)$row['id'],
                            $firewallId,
                            function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                                ? (posix_getpwuid(posix_geteuid())['name'] ?? 'this user')
                                : 'this user',
                            $row['storage_path'] ?: BACKUP_LEGACY_DIR
                        ));
                    }
                } else {
                    $diag['skipped_missing']++;
                }
            }
        }

        return null;
    }
}

if (!function_exists('drift_get_baseline')) {
    /**
     * Current baseline for a firewall, or null when none is set.
     */
    function drift_get_baseline(int $firewallId): ?array {
        $stmt = db()->prepare(
            'SELECT * FROM config_baselines
              WHERE firewall_id = ? AND is_current = 1
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$firewallId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('drift_set_baseline')) {
    /**
     * Declare a backup to be the approved configuration for a firewall.
     *
     * @return array{ok:bool, error:string, baseline_id:int}
     */
    function drift_set_baseline(int $firewallId, int $backupId, string $notes = ''): array {
        $loaded = drift_load_backup_xml($backupId);
        if (!$loaded['ok']) {
            return ['ok' => false, 'error' => $loaded['error'], 'baseline_id' => 0];
        }
        if ((int)$loaded['row']['firewall_id'] !== $firewallId) {
            return ['ok' => false, 'error' => 'That backup belongs to a different firewall', 'baseline_id' => 0];
        }

        $fp = drift_fingerprint($loaded['xml']);
        if (!$fp['ok']) {
            return ['ok' => false, 'error' => 'Backup is not parseable: ' . $fp['error'], 'baseline_id' => 0];
        }

        try {
            db()->prepare('UPDATE config_baselines SET is_current = 0 WHERE firewall_id = ?')
                ->execute([$firewallId]);

            db()->prepare(
                'INSERT INTO config_baselines
                    (firewall_id, backup_id, config_hash, section_hashes,
                     set_by_user_id, set_by_username, notes, is_current)
                 VALUES (?,?,?,?,?,?,?,1)'
            )->execute([
                $firewallId, $backupId, $fp['hash'],
                json_encode($fp['sections']),
                $_SESSION['user_id'] ?? null,
                $_SESSION['username'] ?? null,
                substr($notes, 0, 255) ?: null,
            ]);
            $baselineId = (int)db()->lastInsertId();
        } catch (Throwable $e) {
            error_log('OPNMGR: drift_set_baseline failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the baseline', 'baseline_id' => 0];
        }

        audit_log('drift.baseline.set', [
            'object_type' => 'firewall',
            'object_id'   => (string)$firewallId,
            'firewall_id' => $firewallId,
            'message'     => 'Configuration baseline set from backup #' . $backupId,
            'metadata'    => ['backup_id' => $backupId, 'baseline_id' => $baselineId],
        ]);

        // Re-evaluate immediately so the UI is consistent straight away.
        drift_evaluate($firewallId);

        return ['ok' => true, 'error' => '', 'baseline_id' => $baselineId];
    }
}

if (!function_exists('drift_evaluate')) {
    /**
     * Compare a firewall's newest backup against its baseline and store the
     * result.
     *
     * Deliberately does not restore, alert-on-its-own or change anything on the
     * firewall. It records a state.
     *
     * @return array{status:string, changed:bool, sections:array, error:string}
     */
    function drift_evaluate(int $firewallId): array {
        $baseline = drift_get_baseline($firewallId);

        if ($baseline === null) {
            drift_store_state($firewallId, 0, null, null, 'unknown', [], 'No baseline has been set');
            return ['status' => 'unknown', 'changed' => false, 'sections' => [],
                    'error' => 'No baseline has been set'];
        }

        $latest = drift_latest_backup($firewallId);
        if ($latest === null) {
            drift_store_state($firewallId, (int)$baseline['id'], null, null, 'unknown', [],
                              'No usable configuration backup');
            return ['status' => 'unknown', 'changed' => false, 'sections' => [],
                    'error' => 'No usable configuration backup'];
        }

        $currentXml = drift_load_backup_xml((int)$latest['id']);
        if (!$currentXml['ok']) {
            drift_store_state($firewallId, (int)$baseline['id'], (int)$latest['id'], null, 'error', [],
                              $currentXml['error']);
            return ['status' => 'error', 'changed' => false, 'sections' => [], 'error' => $currentXml['error']];
        }

        $fp = drift_fingerprint($currentXml['xml']);
        if (!$fp['ok']) {
            drift_store_state($firewallId, (int)$baseline['id'], (int)$latest['id'], null, 'error', [],
                              'Current configuration is not parseable: ' . $fp['error']);
            return ['status' => 'error', 'changed' => false, 'sections' => [], 'error' => $fp['error']];
        }

        // Compare section hashes rather than re-parsing the baseline file, so a
        // baseline whose backup file is later pruned still works.
        $baseSections = json_decode((string)$baseline['section_hashes'], true) ?: [];
        $added    = array_values(array_diff(array_keys($fp['sections']), array_keys($baseSections)));
        $removed  = array_values(array_diff(array_keys($baseSections), array_keys($fp['sections'])));
        $modified = [];
        foreach ($baseSections as $name => $hash) {
            if (isset($fp['sections'][$name]) && !hash_equals((string)$hash, $fp['sections'][$name])) {
                $modified[] = $name;
            }
        }
        sort($modified);

        $sections = ['added' => $added, 'removed' => $removed, 'modified' => $modified];
        $changed  = !hash_equals((string)$baseline['config_hash'], $fp['hash']);
        $status   = $changed ? 'drifted' : 'match';

        drift_store_state($firewallId, (int)$baseline['id'], (int)$latest['id'], $fp['hash'],
                          $status, $sections, null);

        return ['status' => $status, 'changed' => $changed, 'sections' => $sections, 'error' => ''];
    }
}

if (!function_exists('drift_store_state')) {
    /**
     * Upsert the drift row for a firewall.
     *
     * first_detected_at is only set when the firewall transitions INTO a
     * drifted state, so the UI can say how long a change has been outstanding
     * rather than resetting the clock on every check. Acknowledgement is
     * cleared when the set of changed sections itself changes, because that is
     * a new change, not the one somebody already signed off.
     */
    function drift_store_state(
        int $firewallId, int $baselineId, ?int $backupId, ?string $hash,
        string $status, array $sections, ?string $detail
    ): void {
        try {
            $existing = db()->prepare('SELECT * FROM config_drift WHERE firewall_id = ?');
            $existing->execute([$firewallId]);
            $prev = $existing->fetch(PDO::FETCH_ASSOC);

            $sectionsJson = json_encode($sections);
            $changeCount  = count($sections['added'] ?? []) + count($sections['removed'] ?? [])
                          + count($sections['modified'] ?? []);

            $firstDetected = $prev['first_detected_at'] ?? null;
            $ackAt         = $prev['acknowledged_at'] ?? null;
            $ackBy         = $prev['acknowledged_by'] ?? null;
            $ackNote       = $prev['acknowledged_note'] ?? null;

            if ($status === 'drifted') {
                if (($prev['status'] ?? '') !== 'drifted') {
                    $firstDetected = date('Y-m-d H:i:s');
                    $ackAt = $ackBy = $ackNote = null;
                } elseif (($prev['sections_changed'] ?? '') !== $sectionsJson) {
                    // Different change than the one acknowledged.
                    $ackAt = $ackBy = $ackNote = null;
                }
            } else {
                $firstDetected = null;
                $ackAt = $ackBy = $ackNote = null;
            }

            db()->prepare(
                'INSERT INTO config_drift
                    (firewall_id, baseline_id, current_backup_id, current_hash, status,
                     sections_changed, change_count, first_detected_at, last_checked_at,
                     acknowledged_at, acknowledged_by, acknowledged_note, detail)
                 VALUES (?,?,?,?,?,?,?,?,NOW(),?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    baseline_id = VALUES(baseline_id),
                    current_backup_id = VALUES(current_backup_id),
                    current_hash = VALUES(current_hash),
                    status = VALUES(status),
                    sections_changed = VALUES(sections_changed),
                    change_count = VALUES(change_count),
                    first_detected_at = VALUES(first_detected_at),
                    last_checked_at = NOW(),
                    acknowledged_at = VALUES(acknowledged_at),
                    acknowledged_by = VALUES(acknowledged_by),
                    acknowledged_note = VALUES(acknowledged_note),
                    detail = VALUES(detail)'
            )->execute([
                $firewallId, $baselineId, $backupId, $hash, $status,
                $sectionsJson, $changeCount, $firstDetected,
                $ackAt, $ackBy, $ackNote, $detail,
            ]);
        } catch (Throwable $e) {
            error_log('OPNMGR: drift_store_state failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('drift_acknowledge')) {
    /**
     * Record that a technician has seen and accepted a drift finding.
     *
     * Acknowledging does not change the baseline: the configuration is still
     * different from what was approved, it is just no longer flagged as
     * unreviewed.
     */
    function drift_acknowledge(int $firewallId, string $note = ''): array {
        $stmt = db()->prepare(
            'UPDATE config_drift
                SET acknowledged_at = NOW(), acknowledged_by = ?, acknowledged_note = ?
              WHERE firewall_id = ? AND status = "drifted"'
        );
        $stmt->execute([
            $_SESSION['username'] ?? 'system',
            substr($note, 0, 255) ?: null,
            $firewallId,
        ]);

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'That firewall is not currently drifted'];
        }

        audit_log('drift.acknowledge', [
            'object_type' => 'firewall',
            'object_id'   => (string)$firewallId,
            'firewall_id' => $firewallId,
            'message'     => 'Configuration drift acknowledged',
            'metadata'    => ['note' => substr($note, 0, 255)],
        ]);

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('drift_fleet_status')) {
    /**
     * Drift state for the whole fleet, for the overview table.
     */
    function drift_fleet_status(): array {
        try {
            return db()->query("
                SELECT f.id, f.hostname, f.status AS firewall_status,
                       c.name AS customer_name, s.name AS site_name,
                       d.status, d.change_count, d.sections_changed,
                       d.first_detected_at, d.last_checked_at,
                       d.acknowledged_at, d.acknowledged_by, d.detail,
                       b.set_at AS baseline_set_at, b.set_by_username AS baseline_set_by,
                       b.backup_id AS baseline_backup_id,
                       (SELECT MAX(COALESCE(bk.uploaded_at, bk.created_at))
                          FROM backups bk WHERE bk.firewall_id = f.id) AS latest_backup_at
                  FROM firewalls f
                  LEFT JOIN customers c ON c.id = f.customer_id
                  LEFT JOIN sites s ON s.id = f.site_id
                  LEFT JOIN config_drift d ON d.firewall_id = f.id
                  LEFT JOIN config_baselines b ON b.firewall_id = f.id AND b.is_current = 1
                 ORDER BY
                    FIELD(IFNULL(d.status,'unknown'),'drifted','error','unknown','match'),
                    f.hostname
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: drift_fleet_status failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('drift_config_freshness')) {
    /**
     * How current the configuration a caller is about to reason from actually is.
     *
     * Answers to security questions ("is SSH exposed?") are only as good as the
     * configuration behind them, so anything presenting such an answer should
     * show this alongside it.
     *
     * @return array{ok:bool, used_at:?string, newest_at:?string, stale:bool,
     *               unreadable:int, message:string}
     */
    function drift_config_freshness(int $firewallId): array {
        $out = ['ok' => false, 'used_at' => null, 'newest_at' => null,
                'stale' => false, 'unreadable' => 0, 'message' => ''];

        $diag = null;
        $used = drift_latest_backup($firewallId, 500, $diag);

        try {
            $stmt = db()->prepare(
                'SELECT MAX(COALESCE(uploaded_at, created_at))
                   FROM backups
                  WHERE firewall_id = ? AND (storage_path IS NOT NULL OR backup_file IS NOT NULL)'
            );
            $stmt->execute([$firewallId]);
            $out['newest_at'] = $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) {
            // non-fatal
        }

        $out['unreadable'] = (int)($diag['skipped_unreadable'] ?? 0);

        if ($used === null) {
            $out['message'] = $out['unreadable'] > 0
                ? 'No readable configuration backup. Files exist but cannot be read - check permissions.'
                : 'No configuration backup has been received for this firewall yet.';
            return $out;
        }

        $out['ok']      = true;
        $out['used_at'] = $used['uploaded_at'] ?: $used['created_at'];

        if ($out['unreadable'] > 0) {
            $out['stale']   = true;
            $out['message'] = sprintf(
                'Answering from a configuration dated %s. %d newer backup(s) exist but are not readable '
                . 'by this process - check permissions on the backup directory.',
                $out['used_at'], $out['unreadable']
            );
            return $out;
        }

        // Flag a config that is old even though nothing was skipped: the agent
        // may simply have stopped uploading.
        if ($out['used_at'] !== null && (time() - strtotime($out['used_at'])) > 7 * 86400) {
            $out['stale']   = true;
            $out['message'] = sprintf(
                'The newest configuration backup is from %s, more than a week old.',
                $out['used_at']
            );
            return $out;
        }

        $out['message'] = 'Configuration from ' . $out['used_at'];
        return $out;
    }
}
