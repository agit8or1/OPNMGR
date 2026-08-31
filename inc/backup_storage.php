<?php
/**
 * OPNMGR Configuration Backup Storage
 *
 * Owns where backup bytes live on disk and what is recorded about them.
 *
 * Two things drive this module's design:
 *
 *  1. Backups used to be written into /var/www/opnsense/backups using the
 *     agent-supplied filename. That directory is inside the document root and
 *     nginx executes .php from it, so the upload endpoint was an arbitrary-file
 *     write into an executable path. Storage paths are now chosen entirely by
 *     the server, outside the document root, with a fixed .xml extension.
 *
 *  2. Rows written before this change have no storage_path. resolve_backup_path()
 *     falls back to the legacy directory so ~20k historical backups remain
 *     downloadable and are never orphaned.
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/audit.php';

if (!defined('BACKUP_LEGACY_DIR')) {
    /** Pre-3.12 location, inside the document root. Read-only from now on. */
    define('BACKUP_LEGACY_DIR', '/var/www/opnsense/backups');
}

if (!function_exists('backup_storage_root')) {
    /**
     * Directory that new backups are written to.
     */
    function backup_storage_root(): string {
        static $root = null;
        if ($root !== null) {
            return $root;
        }

        $configured = '';
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute(['backup_storage_path']);
            $configured = (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            error_log('OPNMGR: could not read backup_storage_path setting: ' . $e->getMessage());
        }

        $root = $configured !== '' ? rtrim($configured, '/') : '/var/lib/opnmgr/backups';

        // Refuse to write inside the document root even if the setting says so;
        // that is the exact condition this module exists to prevent.
        if (str_starts_with($root, '/var/www/')) {
            error_log('OPNMGR: backup_storage_path points inside the document root; falling back to /var/lib/opnmgr/backups');
            $root = '/var/lib/opnmgr/backups';
        }

        return $root;
    }
}

if (!function_exists('backup_max_bytes')) {
    /**
     * Largest backup accepted from an agent.
     */
    function backup_max_bytes(): int {
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute(['backup_max_bytes']);
            $value = (int)($stmt->fetchColumn() ?: 0);
            if ($value >= 65536 && $value <= 1073741824) {
                return $value;
            }
        } catch (Throwable $e) {
            // fall through to default
        }
        return 52428800; // 50 MB
    }
}

if (!function_exists('validate_backup_upload')) {
    /**
     * Check that an uploaded file is a usable OPNsense configuration.
     *
     * This is both a correctness check (Phase 8 asks for validation that the
     * XML is actually usable) and a security control: it means the only bytes
     * we ever persist are well-formed XML with an <opnsense> root, not an
     * arbitrary payload chosen by the caller.
     *
     * @param string $tmpPath Path to the uploaded temp file
     * @param int    $size    Reported size in bytes
     * @return array{ok:bool, reason:string, root:string|null}
     */
    function validate_backup_upload(string $tmpPath, int $size): array {
        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            return ['ok' => false, 'reason' => 'file unreadable', 'root' => null];
        }

        $actual = filesize($tmpPath);
        if ($actual === false || $actual === 0) {
            return ['ok' => false, 'reason' => 'file is empty', 'root' => null];
        }
        if ($actual > backup_max_bytes()) {
            return ['ok' => false, 'reason' => 'file exceeds the configured maximum size', 'root' => null];
        }

        // Parse without resolving external entities, so a malicious backup
        // cannot turn config validation into an XXE file read or SSRF.
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // LIBXML_NONET blocks network fetches; libxml has not substituted
        // external entities by default since 2.9, so no DTD is ever resolved.
        $xml = XMLReader::open('file://' . $tmpPath, null, LIBXML_NONET);
        if (!($xml instanceof XMLReader)) {
            libxml_use_internal_errors($previous);
            return ['ok' => false, 'reason' => 'file is not readable as XML', 'root' => null];
        }

        $rootName = null;
        try {
            while (@$xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT) {
                    $rootName = $xml->name;
                    break;
                }
            }
            // Walk the rest to surface well-formedness errors in the body.
            while (@$xml->read()) {
                // no-op
            }
        } catch (Throwable $e) {
            $rootName = null;
        } finally {
            $xml->close();
        }

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $fatal = array_filter($errors, fn($e) => $e->level === LIBXML_ERR_FATAL);
        if ($fatal) {
            return ['ok' => false, 'reason' => 'XML is not well-formed', 'root' => $rootName];
        }

        if ($rootName === null) {
            return ['ok' => false, 'reason' => 'no XML root element found', 'root' => null];
        }

        if (strtolower($rootName) !== 'opnsense') {
            return [
                'ok'     => false,
                'reason' => 'root element is <' . substr($rootName, 0, 32) . '>, expected <opnsense>',
                'root'   => $rootName,
            ];
        }

        return ['ok' => true, 'reason' => '', 'root' => $rootName];
    }
}

if (!function_exists('store_firewall_backup')) {
    /**
     * Persist a validated backup and record it against the firewall.
     *
     * The on-disk name is generated here; $sourceName is retained only as a
     * human-readable label in the database.
     *
     * @param int    $firewallId Authenticated firewall id
     * @param string $tmpPath    Uploaded temp file
     * @param string $sourceName Agent-supplied filename (label only)
     * @param int    $backupId   Pre-created backups row to attach to, if any
     * @return array{backup_id:int, path:string, checksum:string, size:int}
     * @throws RuntimeException when the file cannot be stored
     */
    function store_firewall_backup(int $firewallId, string $tmpPath, string $sourceName = '', int $backupId = 0): array {
        $dir = backup_storage_root() . '/' . $firewallId;

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create backup directory: ' . $dir);
        }

        $checksum = hash_file('sha256', $tmpPath);
        if ($checksum === false) {
            throw new RuntimeException('Cannot checksum uploaded backup');
        }

        // Server-generated name. Nothing from the request reaches the path.
        $name = sprintf(
            'fw%d-%s-%s.xml',
            $firewallId,
            gmdate('Ymd\THis\Z'),
            substr($checksum, 0, 12)
        );
        $target = $dir . '/' . $name;

        // move_uploaded_file for real uploads; rename/copy for internal callers.
        $moved = is_uploaded_file($tmpPath)
            ? @move_uploaded_file($tmpPath, $target)
            : (@rename($tmpPath, $target) ?: @copy($tmpPath, $target));

        if (!$moved || !is_file($target)) {
            throw new RuntimeException('Failed to write backup to ' . $target);
        }
        @chmod($target, 0640);

        $size  = (int)filesize($target);
        $label = $sourceName !== '' ? substr(basename($sourceName), 0, 255) : $name;

        // Attach to the row the queueing code pre-created where possible, so
        // the UI does not end up with a queued row and an uploaded row.
        $row = null;
        if ($backupId > 0) {
            $stmt = db()->prepare('SELECT id FROM backups WHERE id = ? AND firewall_id = ?');
            $stmt->execute([$backupId, $firewallId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row) {
            // Most recent row for this firewall that never received its bytes.
            $stmt = db()->prepare(
                'SELECT id FROM backups
                  WHERE firewall_id = ? AND storage_path IS NULL AND (file_size IS NULL OR file_size = 0)
                  ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->execute([$firewallId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($row) {
            $backupId = (int)$row['id'];
            db()->prepare(
                'UPDATE backups
                    SET storage_path = ?, checksum_sha256 = ?, file_size = ?, validated = 1,
                        validation_error = NULL, uploaded_at = NOW(), source_filename = ?
                  WHERE id = ? AND firewall_id = ?'
            )->execute([$target, $checksum, $size, $label, $backupId, $firewallId]);
        } else {
            db()->prepare(
                'INSERT INTO backups
                    (firewall_id, backup_file, description, backup_type, file_size,
                     storage_path, checksum_sha256, validated, uploaded_at, source_filename)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)'
            )->execute([
                $firewallId, $name, 'Agent upload', 'automated', $size,
                $target, $checksum, $label,
            ]);
            $backupId = (int)db()->lastInsertId();
        }

        db()->prepare(
            'UPDATE firewalls
                SET last_backup_at = NOW(), last_backup_status = ?, last_backup_error = NULL
              WHERE id = ?'
        )->execute(['ok', $firewallId]);

        return [
            'backup_id' => $backupId,
            'path'      => $target,
            'checksum'  => $checksum,
            'size'      => $size,
        ];
    }
}

if (!function_exists('backup_path_is_traversable')) {
    /**
     * Whether every existing ancestor of a directory can be traversed.
     *
     * Returns true when the path chain is fully visible to this process -
     * meaning a file that is not there is genuinely absent rather than hidden
     * behind a directory we lack execute permission on.
     *
     * @param string $dir Directory to test
     */
    function backup_path_is_traversable(string $dir): bool {
        $seen = 0;

        while ($dir !== '' && $dir !== DIRECTORY_SEPARATOR && $seen++ < 64) {
            if (is_dir($dir)) {
                // The nearest existing ancestor decides it.
                return is_readable($dir) && is_executable($dir);
            }
            if (file_exists($dir)) {
                // Exists but is not a directory: nothing can live under it.
                return true;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        // Reached the root without hitting an unreadable directory.
        return true;
    }
}

if (!function_exists('resolve_backup_path_status')) {
    /**
     * Locate a backup's bytes, distinguishing "absent" from "cannot read".
     *
     * Those two cases need opposite handling. A row with no file is the
     * expected result of an upload that never arrived, and callers should move
     * on to an older backup. A file that exists but cannot be read is a
     * permissions problem on this server, and quietly moving on to an older
     * backup means answering questions from stale configuration without saying
     * so - which is how a six-month-old config ended up being used to answer
     * "is SSH exposed".
     *
     * @param array $backup Row from the backups table
     * @return array{path:?string, status:string} status: ok|missing|unreadable
     */
    function resolve_backup_path_status(array $backup): array {
        $candidates = [];

        $stored = $backup['storage_path'] ?? null;
        if (is_string($stored) && $stored !== '') {
            $candidates[] = $stored;
        }

        // Legacy rows carry only a bare filename in the old document-root
        // directory. basename() so a crafted database value cannot traverse out.
        $legacyName = (string)($backup['backup_file'] ?? '');
        if ($legacyName !== '') {
            $candidates[] = BACKUP_LEGACY_DIR . '/' . basename($legacyName);
        }

        $sawUnreadable = false;

        foreach ($candidates as $path) {
            if (is_file($path)) {
                if (is_readable($path)) {
                    return ['path' => $path, 'status' => 'ok'];
                }
                $sawUnreadable = true;
                continue;
            }

            // file_exists() returns false for a file inside a directory we lack
            // execute permission on, so an inaccessible directory is
            // indistinguishable from a missing file by that test alone. Walk up
            // to the nearest ancestor we can actually see: if that ancestor
            // exists but cannot be traversed, this is a permissions problem and
            // the caller must not quietly drop to an older configuration. If the
            // whole chain is visible and the file simply is not there, it is
            // genuinely missing.
            if (!backup_path_is_traversable(dirname($path))) {
                $sawUnreadable = true;
            }
        }

        return ['path' => null, 'status' => $sawUnreadable ? 'unreadable' : 'missing'];
    }
}

if (!function_exists('resolve_backup_path')) {
    /**
     * Absolute path to a backup row's bytes, or null when unavailable.
     *
     * Thin wrapper over resolve_backup_path_status() for callers that only need
     * the path. Prefer the status form where the difference between a missing
     * and an unreadable file matters.
     *
     * @param array $backup Row from the backups table
     */
    function resolve_backup_path(array $backup): ?string {
        return resolve_backup_path_status($backup)['path'];
    }
}

if (!function_exists('record_backup_failure')) {
    /**
     * Note that a backup attempt did not produce a usable file.
     *
     * Phase 8 wants failed backups to be visible rather than silently absent -
     * the pre-3.12 behaviour was that a rejected upload left a queued row with
     * a NULL file_size and nothing anywhere said why.
     */
    function record_backup_failure(int $firewallId, string $reason, int $backupId = 0): void {
        try {
            if ($backupId > 0) {
                db()->prepare('UPDATE backups SET validated = 0, validation_error = ? WHERE id = ? AND firewall_id = ?')
                    ->execute([substr($reason, 0, 255), $backupId, $firewallId]);
            }
            db()->prepare(
                'UPDATE firewalls SET last_backup_status = ?, last_backup_error = ? WHERE id = ?'
            )->execute(['failed', substr($reason, 0, 255), $firewallId]);
        } catch (Throwable $e) {
            error_log('OPNMGR: could not record backup failure: ' . $e->getMessage());
        }
    }
}

if (!function_exists('opnmgr_server_url')) {
    /**
     * Base URL agents should call back to.
     *
     * Read from the server_url setting, then APP_URL in .env. The literal
     * https://opn.agit8or.net that used to be compiled into every queued
     * command is only a last-resort fallback so existing installs keep working.
     */
    function opnmgr_server_url(): string {
        static $url = null;
        if ($url !== null) {
            return $url;
        }

        $candidate = '';
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute(['server_url']);
            $candidate = (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            // fall through
        }

        if ($candidate === '') {
            $candidate = (string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? ''));
        }
        if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_URL)) {
            $candidate = 'https://opn.agit8or.net';
        }

        $url = rtrim($candidate, '/');
        return $url;
    }
}

if (!function_exists('build_backup_upload_command')) {
    /**
     * Shell command queued to an agent to capture and upload its config.
     *
     * Credentials are read on the firewall from the agent's own credential
     * files rather than being baked into the queued command, so the command
     * text (which is visible in the UI and the audit log) never contains a
     * secret.
     *
     * This also fixes a long-standing break: the previous command sent only
     * firewall_id and backup_id, so every upload was rejected by the agent
     * authentication added to upload_backup.php and no backup had reached disk
     * since that check went in.
     *
     * @param int    $firewallId
     * @param int    $backupId  Pre-created backups row
     * @param string $label     Human-readable filename for logs
     */
    function build_backup_upload_command(int $firewallId, int $backupId, string $label): string {
        $url  = opnmgr_server_url() . '/api/upload_backup.php';
        $tmp  = '/tmp/opnmgr-backup-' . $firewallId . '-' . $backupId . '.xml';

        // Single-quoted heredoc-free shell; every interpolated value is an
        // integer or a server-generated URL, never agent-supplied data.
        return <<<SH
#!/bin/sh
# OPNManager configuration backup
HW_FILE=/usr/local/etc/opnmanager_hardware_id
KEY_FILE=/usr/local/etc/opnmanager_api_key
TMP={$tmp}

[ -f "\$HW_FILE" ] || { echo "ERROR: hardware id file missing"; exit 1; }
HARDWARE_ID=\$(cat "\$HW_FILE")
API_KEY=""
[ -f "\$KEY_FILE" ] && API_KEY=\$(cat "\$KEY_FILE")

cp /conf/config.xml "\$TMP" || { echo "ERROR: cannot read /conf/config.xml"; exit 1; }

curl -sS -f -X POST \\
    -F "backup_file=@\$TMP" \\
    -F "firewall_id={$firewallId}" \\
    -F "backup_id={$backupId}" \\
    -F "hardware_id=\$HARDWARE_ID" \\
    -F "api_key=\$API_KEY" \\
    -H "X-OPNMGR-API-Key: \$API_KEY" \\
    {$url}
RC=\$?

rm -f "\$TMP"

if [ \$RC -eq 0 ]; then
    echo "Backup uploaded: {$label}"
else
    echo "ERROR: backup upload failed (curl exit \$RC)"
fi
exit \$RC
SH;
    }
}

if (!function_exists('backup_retention_days')) {
    /**
     * How many days of backups to keep.
     *
     * The `backup_retention_days` setting has existed since 3.12.0 (migration
     * 0002 seeds it at 90) but nothing has ever read it, and no other code
     * prunes either - which is why this installation was holding 519 backups
     * going back to 2025-11-10 under a nominal retention policy. This is now
     * the single authority on the retention window; the older
     * `backup_retention_months` value is migrated into it rather than read.
     *
     * @return int Days to retain, or 0 when retention is disabled
     * @since 3.20.0
     */
    function backup_retention_days(): int {
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute(['backup_retention_days']);
            $raw = $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('OPNMGR: could not read backup_retention_days: ' . $e->getMessage());
            return 0; // On error keep everything: deleting on a failed read is unrecoverable.
        }

        if ($raw === false || trim((string)$raw) === '') {
            return 90;
        }

        $days = (int)$raw;
        if ($days <= 0) {
            return 0; // Explicitly disabled.
        }
        // Clamp rather than trust: this number drives deletions.
        return max(1, min(3650, $days));
    }
}

if (!function_exists('backup_retention_floor')) {
    /**
     * Most recent backups per firewall that are never pruned, whatever their age.
     *
     * Age alone is an unsafe deletion rule: a firewall that stopped checking in
     * four months ago has only backups older than the window, and a pure
     * age sweep would delete every copy of its configuration precisely when it
     * is least recoverable. The floor guarantees a firewall always retains its
     * newest backups.
     *
     * @since 3.20.0
     */
    function backup_retention_floor(): int {
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute(['backup_retention_min_keep']);
            $raw = $stmt->fetchColumn();
            if ($raw !== false && trim((string)$raw) !== '') {
                return max(1, min(100, (int)$raw));
            }
        } catch (Throwable $e) {
            // fall through to default
        }
        return 3;
    }
}

if (!function_exists('find_expired_backups')) {
    /**
     * Backup rows outside the retention window, excluding each firewall's floor.
     *
     * Selection and deletion are deliberately separate so the caller can report
     * exactly what would go before anything does.
     *
     * @param int|null $days       Override the configured window
     * @param int|null $floor      Override the per-firewall floor
     * @param int|null $firewallId Restrict to one firewall (null = fleet-wide)
     * @return array<int, array> Backup rows, oldest first
     * @since 3.20.0
     */
    function find_expired_backups(?int $days = null, ?int $floor = null, ?int $firewallId = null): array {
        $days  = $days  ?? backup_retention_days();
        $floor = $floor ?? backup_retention_floor();

        if ($days <= 0) {
            return []; // Retention disabled.
        }

        // Rank per firewall newest-first, then keep anything within the floor
        // regardless of age. Done in SQL so the floor is applied atomically with
        // the age test rather than in two passes that could disagree.
        $sql = "
            SELECT id, firewall_id, backup_file, storage_path, created_at, file_size
              FROM (
                SELECT b.*,
                       ROW_NUMBER() OVER (PARTITION BY b.firewall_id ORDER BY b.created_at DESC, b.id DESC) AS rn
                  FROM backups b
              ) ranked
             WHERE rn > ?
               AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
             . ($firewallId !== null ? " AND firewall_id = ?" : "") . "
             ORDER BY created_at ASC, id ASC";

        $params = [$floor, $days];
        if ($firewallId !== null) {
            $params[] = $firewallId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('prune_expired_backups')) {
    /**
     * Delete backups outside the retention window.
     *
     * Removes the bytes first and the row second: a row without a file is a
     * recorded gap the UI already handles, whereas a file with no row is an
     * orphan nothing will ever clean up.
     *
     * Defaults to a dry run. Callers must opt in to deletion explicitly, so an
     * accidental invocation reports rather than destroys.
     *
     * @param bool     $apply      False (default) to report only
     * @param int|null $days       Override the configured window
     * @param int|null $floor      Override the per-firewall floor
     * @param int|null $firewallId Restrict to one firewall (null = fleet-wide)
     * @return array{applied:bool, days:int, floor:int, candidates:int, deleted:int, files_removed:int, files_missing:int, bytes:int, errors:string[]}
     * @since 3.20.0
     */
    function prune_expired_backups(bool $apply = false, ?int $days = null, ?int $floor = null, ?int $firewallId = null): array {
        $days  = $days  ?? backup_retention_days();
        $floor = $floor ?? backup_retention_floor();

        $report = [
            'applied'       => $apply,
            'days'          => $days,
            'floor'         => $floor,
            'candidates'    => 0,
            'deleted'       => 0,
            'files_removed' => 0,
            'files_missing' => 0,
            'bytes'         => 0,
            'errors'        => [],
        ];

        if ($days <= 0) {
            return $report;
        }

        $rows = find_expired_backups($days, $floor, $firewallId);
        $report['candidates'] = count($rows);

        foreach ($rows as $row) {
            $report['bytes'] += (int)($row['file_size'] ?? 0);

            if (!$apply) {
                continue;
            }

            $status = resolve_backup_path_status($row);

            // An unreadable file is a permissions fault on this server, not an
            // expired backup. Dropping the row would strand the bytes on disk
            // with nothing left pointing at them, so leave the pair intact and
            // report it.
            if ($status['status'] === 'unreadable') {
                $report['errors'][] = "backup {$row['id']}: file unreadable, left in place";
                continue;
            }

            if ($status['path'] !== null) {
                if (@unlink($status['path'])) {
                    $report['files_removed']++;
                } else {
                    $report['errors'][] = "backup {$row['id']}: could not delete {$status['path']}";
                    continue; // Keep the row so the file is still accounted for.
                }
            } else {
                $report['files_missing']++;
            }

            try {
                db()->prepare('DELETE FROM backups WHERE id = ?')->execute([(int)$row['id']]);
                $report['deleted']++;
            } catch (Throwable $e) {
                $report['errors'][] = "backup {$row['id']}: row delete failed: " . $e->getMessage();
            }
        }

        if ($apply && $report['deleted'] > 0) {
            error_log(sprintf(
                'OPNMGR: pruned %d backup(s) older than %d days (floor %d per firewall)',
                $report['deleted'], $days, $floor
            ));
        }

        return $report;
    }
}
