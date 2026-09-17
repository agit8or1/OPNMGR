<?php

// opnmgr_decrypt() lives here. Without it every stored credential reaches the
// SMTP server as ciphertext.
require_once __DIR__ . '/crypto.php';

if (!function_exists('smtp_safe_error')) {
    /**
     * An SMTP failure an operator can act on, with any credential removed.
     *
     * Keeps the server's response - the code and text are the diagnostic - but
     * strips base64 runs, which is the form a leaked AUTH line would take.
     */
    function smtp_safe_error(string $message): string
    {
        // Long base64 runs: an echoed AUTH argument, never part of a useful
        // human-readable response.
        $message = preg_replace('/\b[A-Za-z0-9+\/]{20,}={0,2}\b/', '[redacted]', $message);

        // Belt and braces: the configured credentials themselves, in case a
        // server quotes them back in the clear.
        foreach (['smtp_username', 'smtp_password'] as $name) {
            try {
                $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
                $stmt->execute([$name]);
                $value = (string) ($stmt->fetchColumn() ?: '');
                // decrypt_setting_value() is not a function in this codebase and
                // never has been, so this guard was always false and the
                // redaction compared the ciphertext against the message.
                if (function_exists('opnmgr_decrypt')) {
                    $value = (string) (opnmgr_decrypt($value) ?? '');
                }
                if ($value !== '' && strlen($value) > 3) {
                    $message = str_ireplace($value, '[redacted]', $message);
                }
            } catch (Throwable $e) {
                // Redaction is best effort on top of the base64 strip above.
            }
        }

        return substr(trim($message), 0, 500);
    }
}
if (!function_exists('smtp_plain_secret')) {
    /**
     * A stored credential in the form the SMTP server expects.
     *
     * Returns '' rather than the ciphertext when decryption fails, because
     * authenticating with an encrypted blob produces a 535 that reads like a
     * wrong password and sends you looking at the wrong thing entirely.
     */
    function smtp_plain_secret(string $stored): string
    {
        if ($stored === '' || !function_exists('opnmgr_decrypt')) {
            return $stored;
        }
        $plain = opnmgr_decrypt($stored);
        if ($plain === null) {
            error_log('OPNMGR: could not decrypt an SMTP credential; refusing to send the stored ciphertext as a password');
            return '';
        }
        return $plain;
    }
}

/**
 * Simple SMTP Mailer
 * Direct SMTP connection without external dependencies
 */

if (!function_exists('smtp_verify_credentials')) {
    /**
     * Connect, negotiate TLS and authenticate. Send nothing.
     *
     * Exists because the configuration page's "test" opened a socket and closed
     * it, which passes with no credentials at all - so it could never detect a
     * rejected password, the one failure it was being used to rule out.
     *
     * @return true|string true on success, otherwise a safe error message
     */
    function smtp_verify_credentials(array $smtp_settings)
    {
        $host       = (string) ($smtp_settings['smtp_host'] ?? '');
        $port       = (int) ($smtp_settings['smtp_port'] ?? 0);
        $encryption = (string) ($smtp_settings['smtp_encryption'] ?? 'tls');
        $username   = smtp_plain_secret((string) ($smtp_settings['smtp_username'] ?? ''));
        $password   = smtp_plain_secret((string) ($smtp_settings['smtp_password'] ?? ''));

        if ($host === '' || $port === 0) {
            return 'SMTP host and port are required.';
        }
        if ($username === '' || $password === '') {
            return 'SMTP username and password are required. '
                 . 'If a password is stored, it could not be decrypted.';
        }

        $errno = 0;
        $errstr = '';
        $target = ($encryption === 'ssl') ? "ssl://{$host}" : $host;
        $socket = @fsockopen($target, $port, $errno, $errstr, 10);
        if (!$socket) {
            return smtp_safe_error("Connection failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, 10);

        $read = static function () use ($socket): string {
            $out = '';
            while (($line = fgets($socket, 515)) !== false) {
                $out .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $out;
        };
        $say = static function (string $cmd) use ($socket): void {
            fputs($socket, $cmd . "\r\n");
        };

        try {
            if (strpos($read(), '220') !== 0) {
                return 'Server did not greet with 220.';
            }

            $say('EHLO ' . gethostname());
            $read();

            if ($encryption === 'tls') {
                $say('STARTTLS');
                if (strpos($read(), '220') !== 0) {
                    return 'STARTTLS refused by the server.';
                }
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return 'TLS negotiation failed.';
                }
                $say('EHLO ' . gethostname());
                $read();
            }

            $say('AUTH LOGIN');
            if (strpos($read(), '334') !== 0) {
                return 'Server did not offer AUTH LOGIN.';
            }
            $say(base64_encode($username));
            if (strpos($read(), '334') !== 0) {
                return 'Server rejected the username.';
            }
            $say(base64_encode($password));
            $final = $read();
            if (strpos($final, '235') !== 0) {
                // The message that matters: this is what 8,497 alerts hit.
                return smtp_safe_error('Authentication failed: ' . trim($final));
            }

            return true;
        } catch (Throwable $e) {
            return smtp_safe_error($e->getMessage());
        } finally {
            @fputs($socket, "QUIT\r\n");
            @fclose($socket);
        }
    }
}

function send_smtp_email($smtp_settings, $to, $subject, $message, $from_address, $from_name = '') {
    $host = $smtp_settings['smtp_host'];
    $port = (int)$smtp_settings['smtp_port'];

    // Callers read these straight out of `settings`, where secrets are stored
    // as `enc:v1:...`. Nothing decrypted them, so the ciphertext was handed to
    // AUTH and every server answered 535. Decrypting here rather than in each
    // caller means there is one place that can get it wrong, and it is this one.
    //
    // opnmgr_decrypt() returns plaintext unchanged, so a value stored before
    // encryption existed still works.
    $username = smtp_plain_secret($smtp_settings['smtp_username'] ?? '');
    $password = smtp_plain_secret($smtp_settings['smtp_password'] ?? '');
    $encryption = $smtp_settings['smtp_encryption'] ?? 'tls';
    
    $from = $from_name ? "$from_name <$from_address>" : $from_address;
    
    // Connect to SMTP server
    $socket = null;
    $errno = 0;
    $errstr = '';
    
    try {
        // Use SSL/TLS wrapper if needed
        if ($encryption === 'ssl') {
            $socket = @fsockopen("ssl://$host", $port, $errno, $errstr, 30);
        } else {
            $socket = @fsockopen($host, $port, $errno, $errstr, 30);
        }
        
        if (!$socket) {
            throw new Exception("Failed to connect to SMTP server: $errstr ($errno)");
        }
        
        // Set timeout
        stream_set_timeout($socket, 30);
        
        // Read greeting
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '220') {
            throw new Exception("SMTP Error: $response");
        }
        
        // Send EHLO
        fputs($socket, "EHLO " . gethostname() . "\r\n");
        
        // Read all EHLO responses (may be multiline)
        do {
            $response = fgets($socket, 515);
            $continue = (isset($response[3]) && $response[3] == '-');
        } while ($continue);
        
        // STARTTLS if needed
        if ($encryption === 'tls') {
            fputs($socket, "STARTTLS\r\n");
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) != '220') {
                throw new Exception("STARTTLS failed: $response");
            }
            
            // Enable crypto
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("Failed to enable TLS");
            }
            
            // Send EHLO again after STARTTLS
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            
            // Read all EHLO responses again
            do {
                $response = fgets($socket, 515);
                $continue = (isset($response[3]) && $response[3] == '-');
            } while ($continue);
        }
        
        // Authenticate
        fputs($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '334') {
            throw new Exception("AUTH failed: $response");
        }
        
        fputs($socket, base64_encode($username) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '334') {
            throw new Exception("Username rejected: $response");
        }
        
        fputs($socket, base64_encode($password) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '235') {
            throw new Exception("Authentication failed: $response");
        }
        
        // Send MAIL FROM
        fputs($socket, "MAIL FROM: <$from_address>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '250') {
            throw new Exception("MAIL FROM failed: $response");
        }
        
        // Send RCPT TO
        fputs($socket, "RCPT TO: <$to>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '250') {
            throw new Exception("RCPT TO failed: $response");
        }
        
        // Send DATA
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '354') {
            throw new Exception("DATA failed: $response");
        }
        
        // Build email headers and body
        $email_data = "From: $from\r\n";
        $email_data .= "To: $to\r\n";
        $email_data .= "Subject: $subject\r\n";
        $email_data .= "MIME-Version: 1.0\r\n";
        $email_data .= "Content-Type: text/html; charset=UTF-8\r\n";
        $email_data .= "Content-Transfer-Encoding: 8bit\r\n";
        $email_data .= "X-Mailer: OpnMgr/1.0\r\n";
        $email_data .= "\r\n";
        $email_data .= $message;
        $email_data .= "\r\n.\r\n";
        
        fputs($socket, $email_data);
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '250') {
            throw new Exception("Message not accepted: $response");
        }
        
        // Send QUIT
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        return ['success' => true, 'message' => 'Email sent successfully'];
        
    } catch (Exception $e) {
        if ($socket) {
            @fclose($socket);
        }
        error_log("smtp_mailer.php error: " . $e->getMessage());

        // The caller is an administrator diagnosing their own mail server, and
        // "Internal server error" told them nothing: this installation failed
        // 911 deliveries on a rejected credential while the only description of
        // why sat in a log file. The messages thrown above are SMTP protocol
        // responses - "535-5.7.8 Username and Password not accepted" - which is
        // exactly what is needed to fix it.
        //
        // Redacted first. AUTH lines carry base64 of the username and password,
        // and a server that echoes the offending line back would otherwise put
        // a credential into alert_history and onto the screen.
        return ['success' => false, 'error' => smtp_safe_error($e->getMessage())];
    }
}
