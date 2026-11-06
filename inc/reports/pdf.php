<?php
/**
 * PDF rendering through wkhtmltopdf (optional dependency).
 *
 * Report HTML is built only from escaped data, but the renderer is hardened as
 * well so that a mistake elsewhere cannot turn a report into a fetch primitive:
 *   --disable-local-file-access   no file:// reads (default, stated explicitly)
 *   --disable-javascript          nothing in the page can run
 *   --proxy http://127.0.0.1:9    discard port: any stray http(s) URL fails to connect
 *   --no-images                   unless the report carries a data: URI logo
 *   --disable-external-links / --disable-internal-links
 *   --load-error-handling ignore  a blocked resource must not abort the document
 * HTML goes in on stdin and PDF comes out on stdout, so no temp file inside the
 * renderer's reach exists. The environment is stripped of proxy variables so
 * the dead proxy cannot be bypassed.
 *
 * @since 3.79.0
 */

if (!function_exists('report_pdf_binary')) {
    function report_pdf_binary(): ?string {
        foreach (['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf'] as $p) {
            if (is_executable($p)) { return $p; }
        }
        return null;
    }
}
if (!function_exists('report_pdf_available')) {
    function report_pdf_available(): bool { return report_pdf_binary() !== null; }
}

if (!function_exists('report_pdf_args')) {
    function report_pdf_args(string $bin, bool $hasImages): array {
        $a = [$bin, '--quiet', '--disable-local-file-access', '--disable-javascript', '--proxy', 'http://127.0.0.1:9',
              '--disable-external-links', '--disable-internal-links', '--load-error-handling', 'ignore',
              '--load-media-error-handling', 'ignore', '--encoding', 'utf-8', '--page-size', 'A4',
              '--margin-top', '12mm', '--margin-bottom', '14mm', '--margin-left', '10mm', '--margin-right', '10mm',
              '--footer-center', '[page] / [topage]', '--footer-font-size', '8'];
        if (!$hasImages) { $a[] = '--no-images'; }
        return array_merge($a, ['-', '-']);
    }
}

if (!function_exists('report_pdf_render')) {
    /** @return array{ok:bool,pdf:?string,error:?string} */
    function report_pdf_render(string $html, int $timeoutSeconds = 90): array {
        $bin = report_pdf_binary();
        if ($bin === null) { return ['ok' => false, 'pdf' => null, 'error' => 'wkhtmltopdf is not installed']; }
        $cmd = report_pdf_args($bin, str_contains($html, 'src="data:image/'));
        // Minimal environment: no proxy variables to override our dead proxy, no HOME contents.
        $env = ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), 'LANG' => 'C.UTF-8', 'QT_QPA_PLATFORM' => 'offscreen'];
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), $env);
        if (!is_resource($p)) { return ['ok' => false, 'pdf' => null, 'error' => 'could not start wkhtmltopdf']; }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $in = $html; $out = ''; $err = '';
        $deadline = time() + $timeoutSeconds;
        // Write input in chunks while draining output so neither pipe can fill and deadlock.
        stream_set_blocking($pipes[0], false);
        while (true) {
            $r = [$pipes[1], $pipes[2]]; $w = $in !== '' ? [$pipes[0]] : []; $x = null;
            if (@stream_select($r, $w, $x, 1) === false) { break; }
            if ($w && $in !== '') { $n = fwrite($pipes[0], substr($in, 0, 65536)); if ($n > 0) { $in = substr($in, $n); } }
            if ($in === '' && is_resource($pipes[0])) { fclose($pipes[0]); $pipes[0] = null; }
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) { break; }
            if (time() > $deadline) { proc_terminate($p, 9); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
                return ['ok' => false, 'pdf' => null, 'error' => 'PDF rendering timed out']; }
        }
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        if (is_resource($pipes[0] ?? null)) { fclose($pipes[0]); }
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
        if (!str_starts_with($out, '%PDF')) {
            return ['ok' => false, 'pdf' => null, 'error' => 'wkhtmltopdf produced no PDF: ' . mb_substr(trim(preg_replace('/\s+/', ' ', $err)), 0, 160)];
        }
        return ['ok' => true, 'pdf' => $out, 'error' => null];
    }
}
