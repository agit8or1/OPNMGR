<?php
/**
 * Two-factor enrolment: secret encoding, and no third-party QR service.
 *
 * Both halves of this were broken at once, and neither showed up as an error:
 *
 *  - The otpauth:// URI carried the raw hex secret. Hex contains 0, 1, 8 and 9,
 *    none of which exist in the Base32 alphabet, and a-f decode to different
 *    values - so an authenticator derived a different key from the one the
 *    server verifies with, and the six digits never matched. Two-factor could
 *    not be enabled at all.
 *  - The QR was fetched from api.qrserver.com with the URI in the query string,
 *    which handed the shared secret to a third party and to every proxy and log
 *    in between.
 *
 * Run with: php tests/twofactor_test.php
 *
 * @since 3.26.0
 */

require_once __DIR__ . '/bootstrap.php';

$root = rtrim(TEST_ROOT, '/');
$page = $root . '/twofactor_setup.php';
$src  = (string) file_get_contents($page);

// Load the helpers in the same compile-time context the page gives them, so
// the BaconQrCode `use` aliases resolve exactly as they do in production.
$uses = [];
preg_match_all('/^use BaconQrCode\\\\[^\n]+;$/m', $src, $uses);
$fns = [];
foreach (['base32Encode', 'totpSecretForApp', 'generateQRCodeUrl', 'render2FAQrSvg', 'verify2FACode'] as $fn) {
    if (preg_match('/\nfunction ' . $fn . '\(.*?\n}\n/s', $src, $m)) {
        $fns[] = $m[0];
    }
}
$harness = tempnam(sys_get_temp_dir(), 'twofa') . '.php';
file_put_contents($harness, "<?php\nrequire '{$root}/vendor/autoload.php';\n"
    . implode("\n", $uses[0]) . "\n" . implode("\n", $fns));
require $harness;
@unlink($harness);

// ---------------------------------------------------------------------------
T::group('Base32 encoding');

// RFC 4648 test vectors, so this is checked against the standard rather than
// against itself.
T::eq('MY',         base32Encode('f'),      'RFC 4648: "f"');
T::eq('MZXQ',       base32Encode('fo'),     'RFC 4648: "fo"');
T::eq('MZXW6',      base32Encode('foo'),    'RFC 4648: "foo"');
T::eq('MZXW6YQ',    base32Encode('foob'),   'RFC 4648: "fooba"[0..3]');
T::eq('MZXW6YTB',   base32Encode('fooba'),  'RFC 4648: "fooba"');
T::eq('MZXW6YTBOI', base32Encode('foobar'), 'RFC 4648: "foobar"');

// ---------------------------------------------------------------------------
T::group('The advertised secret is the key the server verifies with');

$secret = bin2hex(random_bytes(16));
$uri    = generateQRCodeUrl($secret, 'tester');
parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);

T::ok(isset($q['secret']) && $q['secret'] !== '', 'the URI carries a secret');
T::eq(0, preg_match('/[^A-Z2-7]/', $q['secret']), 'the secret is valid Base32 (no 0, 1, 8, 9 or lowercase)');

/** Decode Base32 the way an authenticator app does. */
$b32dec = static function (string $s): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
        $i = strpos($alpha, $c);
        if ($i === false) { return ''; }
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) === 8) { $out .= chr(bindec($b)); }
    }
    return $out;
};

T::eq(bin2hex(hex2bin($secret)), bin2hex($b32dec($q['secret'])),
      'the app and the server derive the same HMAC key');

// A full TOTP round-trip: generate the code the way a compliant app does, and
// require the server to accept it.
$counter = pack('N*', 0) . pack('N*', intdiv(time(), 30));
$hash    = hash_hmac('sha1', $counter, $b32dec($q['secret']), true);
$off     = ord($hash[19]) & 0x0F;
$code    = str_pad((string) ((unpack('N', substr($hash, $off, 4))[1] & 0x7FFFFFFF) % 1000000), 6, '0', STR_PAD_LEFT);

T::ok(verify2FACode($secret, $code), 'a code from a compliant authenticator is accepted');
T::ok(!verify2FACode($secret, $code === '999999' ? '111111' : '999999'), 'an unrelated code is rejected');

// ---------------------------------------------------------------------------
T::group('The QR is rendered locally');

$html = render2FAQrSvg($uri);
T::ok(str_contains($html, '<svg'), 'an inline SVG is produced');
// xmlns="http://www.w3.org/2000/svg" is a namespace identifier, not a resource
// the browser fetches, so look for things that actually load something.
T::eq(0, preg_match('/<image\b|(?:src|href|xlink:href)\s*=\s*["\']https?:/i', $html),
      'the rendered QR loads nothing over the network');
T::ok(!str_contains($html, 'qrserver'), 'the rendered QR does not touch a QR service');

foreach ([$page, $root . '/inc/header.php'] as $f) {
    $body = (string) preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], '', (string) file_get_contents($f));
    T::ok(!str_contains($body, 'qrserver.com'),
          basename($f) . ' does not reference a third-party QR service');
}

exit(T::summary());
