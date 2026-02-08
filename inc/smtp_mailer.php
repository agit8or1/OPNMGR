<?php
/**
 * Simple SMTP Mailer
 * Direct SMTP connection without external dependencies
 */

function send_smtp_email($smtp_settings, $to, $subject, $message, $from_address, $from_name = '') {
    $host = $smtp_settings['smtp_host'];
    $port = (int)$smtp_settings['smtp_port'];
    $username = $smtp_settings['smtp_username'];
    $password = $smtp_settings['smtp_password'];
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
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
