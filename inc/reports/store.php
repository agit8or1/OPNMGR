<?php
/**
 * Immutable report storage.
 *
 * Generated files are written once (exclusive create), made read-only, and
 * recorded with their SHA-256. Every download re-hashes the file and refuses
 * to serve it on mismatch, so tampering or bit-rot is visible rather than
 * silently delivered to a customer. A report is never updated in place;
 * regenerating produces a new report.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/collect.php';

if (!function_exists('report_storage_root')) {
    function report_storage_root(): string {
        $root = rtrim((string)report_setting('report_storage_path', ''), '/');
        if ($root === '' || str_starts_with($root . '/', '/var/www/')) {
            // Never inside the document root: reports must only leave through an authorising script.
            $root = '/var/lib/opnmgr/reports';
        }
        return $root;
    }
}

if (!function_exists('report_store_file')) {
    /** Write bytes exclusively under the storage root; returns [relative path, sha256]. */
    function report_store_file(string $ext, string $bytes): array {
        $root = report_storage_root();
        $sub = gmdate('Y/m');
        $dir = $root . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Report storage is not writable');
        }
        $rel = $sub . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $fh = @fopen($root . '/' . $rel, 'xb');   // 'x': fail rather than overwrite
        if (!$fh) { throw new RuntimeException('Could not create report file'); }
        fwrite($fh, $bytes);
        fclose($fh);
        @chmod($root . '/' . $rel, 0440);
        return [$rel, hash('sha256', $bytes)];
    }
}

if (!function_exists('report_read_verified')) {
    /**
     * Read a stored report file and verify its hash.
     * @throws RuntimeException with code 404 (missing/unavailable) or 500 (integrity failure)
     */
    function report_read_verified(array $report, string $fmt): string {
        if (!in_array($fmt, ['html', 'csv', 'pdf'], true)) { throw new RuntimeException('Unknown format', 404); }
        $rel = $report[$fmt . '_path'] ?? null; $sha = $report[$fmt . '_sha256'] ?? null;
        if (!$rel || !$sha) { throw new RuntimeException('This format was not produced for the report', 404); }
        $root = realpath(report_storage_root());
        $path = $root ? realpath($root . '/' . $rel) : false;
        if (!$path || !str_starts_with($path, $root . '/')) { throw new RuntimeException('Report file is missing', 404); }
        $bytes = file_get_contents($path);
        if ($bytes === false || !hash_equals((string)$sha, hash('sha256', $bytes))) {
            throw new RuntimeException('Report file failed its integrity check', 500);
        }
        return $bytes;
    }
}

if (!function_exists('report_delete_files')) {
    function report_delete_files(array $report): void {
        $root = realpath(report_storage_root());
        foreach (['html', 'csv', 'pdf'] as $f) {
            $rel = $report[$f . '_path'] ?? null;
            if (!$rel || !$root) { continue; }
            $path = realpath($root . '/' . $rel);
            if ($path && str_starts_with($path, $root . '/')) { @chmod($path, 0640); @unlink($path); }
        }
    }
}
