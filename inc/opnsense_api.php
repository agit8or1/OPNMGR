<?php

class OPNsenseAPI {
    private $host;
    private $api_key;
    private $api_secret;
    private $verify_ssl;
    
    public function __construct($host, $api_key, $api_secret, $verify_ssl = true) {
        $this->host = rtrim($host, '/');
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        $this->verify_ssl = $verify_ssl;
    }
    
    /**
     * Make authenticated API request to OPNsense
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->host . '/api/' . ltrim($endpoint, '/');
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
            CURLOPT_USERPWD => $this->api_key . ':' . $this->api_secret,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL error: $error");
        }
        
        if ($http_code >= 400) {
            throw new Exception("HTTP error: $http_code - Response: $response");
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    /**
     * Check for available firmware updates
     */
    public function checkUpdates() {
        try {
            return $this->makeRequest('/core/firmware/check');
        } catch (Exception $e) {
            error_log("OPNsense API checkUpdates error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get firmware status
     */
    public function getFirmwareStatus() {
        try {
            return $this->makeRequest('/core/firmware/status');
        } catch (Exception $e) {
            error_log("OPNsense API getFirmwareStatus error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update firmware
     */
    public function updateFirmware($type = 'update') {
        try {
            $data = ['type' => $type]; // 'update' or 'upgrade'
            return $this->makeRequest('/core/firmware/update', 'POST', $data);
        } catch (Exception $e) {
            error_log("OPNsense API updateFirmware error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Upgrade firmware to newer version
     */
    public function upgradeFirmware() {
        return $this->updateFirmware('upgrade');
    }
    
    /**
     * Get system information
     */
    public function getSystemInfo() {
        try {
            return $this->makeRequest('/core/firmware/info');
        } catch (Exception $e) {
            error_log("OPNsense API getSystemInfo error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if reboot is required
     */
    public function isRebootRequired() {
        try {
            $status = $this->makeRequest('/core/firmware/reboot');
            return isset($status['reboot_required']) ? $status['reboot_required'] : false;
        } catch (Exception $e) {
            error_log("OPNsense API isRebootRequired error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reboot the system
     */
    public function reboot() {
        try {
            return $this->makeRequest('/core/firmware/reboot', 'POST');
        } catch (Exception $e) {
            error_log("OPNsense API reboot error: " . $e->getMessage());
            return false;
        }
    }
}
?>