<?php
/**
 * Minimal test harness for OPNMGR security tests.
 *
 * Deliberately dependency-free: the project has no PHPUnit and adding a
 * composer toolchain is out of scope for a security fix. These tests exercise
 * endpoints by invoking them in a subprocess with a synthetic request
 * environment, which is enough to prove the authentication and authorization
 * behaviour the spec asks about.
 */

define('TEST_ROOT', dirname(__DIR__));

class T {
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var string[] */
    public static array $failures = [];
    public static string $group = '';

    public static function group(string $name): void {
        self::$group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public static function ok(bool $cond, string $what): void {
        if ($cond) {
            self::$pass++;
            echo "  \033[32mPASS\033[0m  {$what}\n";
        } else {
            self::$fail++;
            self::$failures[] = self::$group . ' :: ' . $what;
            echo "  \033[31mFAIL\033[0m  {$what}\n";
        }
    }

    public static function eq($expected, $actual, string $what): void {
        $cond = $expected === $actual;
        self::ok($cond, $what . ($cond ? '' : sprintf(' (expected %s, got %s)',
            var_export($expected, true), var_export($actual, true))));
    }

    public static function summary(): int {
        echo "\n" . str_repeat('-', 60) . "\n";
        printf("%d passed, %d failed\n", self::$pass, self::$fail);
        foreach (self::$failures as $f) {
            echo "  FAILED: {$f}\n";
        }
        return self::$fail === 0 ? 0 : 1;
    }
}

/**
 * Invoke an endpoint in an isolated PHP subprocess with a synthetic request.
 *
 * Runs the script through the CLI SAPI with $_SERVER/$_GET/$_POST/php://input
 * pre-seeded, captures the body, and recovers the HTTP status the script set.
 *
 * @param string $script Path relative to the project root
 * @param array  $opt    method, body (string|array), get, post, headers, files
 * @return array{status:int, body:string, json:array|null}
 */
function call_endpoint(string $script, array $opt = []): array {
    $method  = $opt['method']  ?? 'POST';
    $get     = $opt['get']     ?? [];
    $post    = $opt['post']    ?? [];
    $headers = $opt['headers'] ?? [];
    $files   = $opt['files']   ?? [];

    $body = $opt['body'] ?? '';
    if (is_array($body)) {
        $body = json_encode($body);
    }

    $server = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI'    => '/' . ltrim($script, '/'),
        'REMOTE_ADDR'    => $opt['remote_addr'] ?? '203.0.113.10',
        'HTTP_HOST'      => 'opn.test',
        'SERVER_NAME'    => 'opn.test',
        'HTTPS'          => 'on',
        'SCRIPT_NAME'    => '/' . ltrim($script, '/'),
    ];
    foreach ($headers as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }

    $payload = [
        'script'  => TEST_ROOT . '/' . ltrim($script, '/'),
        'server'  => $server,
        'get'     => $get,
        'post'    => $post,
        'body'    => $body,
        'files'   => $files,
    ];

    $runner = TEST_ROOT . '/tests/runner.php';
    $descr  = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc   = proc_open(
        [PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $runner],
        $descr, $pipes, TEST_ROOT
    );

    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'could not spawn runner', 'json' => null];
    }

    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);

    // The runner prints a status marker line, then the body.
    $status = 200;
    if (preg_match('/^__STATUS__:(\d+)\n/', $out, $m)) {
        $status = (int)$m[1];
        $out    = substr($out, strlen($m[0]));
    }

    if ($err !== '' && getenv('TEST_VERBOSE')) {
        echo "    [stderr] " . trim($err) . "\n";
    }

    return [
        'status' => $status,
        'body'   => $out,
        'json'   => json_decode($out, true),
        'stderr' => $err,
    ];
}
