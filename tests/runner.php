<?php
/**
 * Subprocess request runner.
 *
 * Reads a JSON job on stdin describing a synthetic HTTP request, seeds the
 * superglobals, includes the target script, and writes back the HTTP status
 * followed by the response body.
 */
$job = json_decode(stream_get_contents(STDIN), true);
if (!$job) {
    fwrite(STDERR, "runner: bad job\n");
    exit(2);
}

$_SERVER = array_merge($_SERVER, $job['server']);
$_GET    = $job['get']  ?? [];
$_POST   = $job['post'] ?? [];
$_FILES  = $job['files'] ?? [];
$_REQUEST = array_merge($_GET, $_POST);

// php://input is not writable from CLI, so provide the raw body through a
// stream wrapper that endpoints reach via file_get_contents('php://input').
if (!empty($job['body'])) {
    $GLOBALS['__TEST_RAW_BODY'] = $job['body'];
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'TestPhpStream');
}

class TestPhpStream {
    private int $pos = 0;
    private string $data = '';
    public $context;

    public function stream_open($path, $mode, $options, &$opened_path) {
        if (str_contains($path, 'input')) {
            $this->data = $GLOBALS['__TEST_RAW_BODY'] ?? '';
            return true;
        }
        // Anything other than php://input (php://stdout etc.) is not needed here.
        $this->data = '';
        return true;
    }
    public function stream_read($count) {
        $chunk = substr($this->data, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }
    public function stream_write($data) { return strlen($data); }
    public function stream_eof() { return $this->pos >= strlen($this->data); }
    public function stream_stat() { return ['size' => strlen($this->data)]; }
    public function stream_tell() { return $this->pos; }
    public function stream_seek($offset, $whence = SEEK_SET) {
        $this->pos = $whence === SEEK_SET ? $offset : $this->pos + $offset;
        return true;
    }
    public function stream_set_option($option, $arg1, $arg2) { return false; }
    public function url_stat($path, $flags) { return ['size' => 0]; }
}

// Endpoints terminate with exit() on the rejection paths, so the status has to
// be emitted from a shutdown handler rather than after the require.
register_shutdown_function(function () {
    $body = '';
    while (ob_get_level() > 0) {
        $body = ob_get_clean() . $body;
    }
    $status = http_response_code();
    if (!is_int($status) || $status === 0) {
        $status = 200;
    }
    echo "__STATUS__:{$status}\n";
    echo $body;
});

ob_start();

try {
    require $job['script'];
} catch (Throwable $e) {
    fwrite(STDERR, 'runner: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
}
