<?php
class TwoFactorAuth {
    public static function base32Decode($b32) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $v = strpos($alphabet, $c);
            if ($v === false) continue;
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits,8) as $byte) {
            if (strlen($byte) < 8) continue;
            $bytes .= chr(bindec($byte));
        }
        return $bytes;
    }

    public static function totp($secret, $counter=null, $digits=6, $algo='sha1') {
        return self::totpRaw(self::base32Decode($secret), $counter, $digits, $algo);
    }

    private static function totpRaw($key, $counter=null, $digits=6, $algo='sha1') {
        if ($counter === null) $counter = floor(time()/30);
        $counterBytes = pack('N2', 0, $counter);
        $hash = hash_hmac($algo, $counterBytes, $key, true);
        $offset = ord($hash[strlen($hash)-1]) & 0x0F;
        $binary = (ord($hash[$offset]) & 0x7F) << 24 |
                  (ord($hash[$offset+1]) & 0xFF) << 16 |
                  (ord($hash[$offset+2]) & 0xFF) << 8 |
                  (ord($hash[$offset+3]) & 0xFF);
        $otp = $binary % pow(10, $digits);
        return str_pad($otp, $digits, '0', STR_PAD_LEFT);
    }

    public static function verify($secret, $code, $window=1) {
        $code = preg_replace('/[^0-9]/','',$code);
        if ($code === '') return false;
        $t = floor(time()/30);
        for ($i=-$window;$i<=$window;$i++) {
            if (hash_equals(self::totp($secret, $t+$i), $code)) return true;
        }
        return false;
    }

    /**
     * Verify against users.totp_secret as twofactor_setup.php stores it: hex
     * (bin2hex(random_bytes(16))). verify() base32-decodes its input, which
     * on a hex string drops 0/1/8/9 and derives the wrong key - so every
     * login code was rejected even straight after a successful enrolment.
     * Anything that is not hex is still treated as base32.
     */
    public static function verifyStored($stored, $code, $window=1) {
        $stored = trim((string) $stored);
        $code = preg_replace('/[^0-9]/', '', (string) $code);
        if ($stored === '' || $code === '') return false;
        $key = (ctype_xdigit($stored) && strlen($stored) % 2 === 0)
            ? hex2bin($stored)
            : self::base32Decode($stored);
        $t = floor(time()/30);
        for ($i=-$window;$i<=$window;$i++) {
            if (hash_equals(self::totpRaw($key, $t+$i), $code)) return true;
        }
        return false;
    }
}
