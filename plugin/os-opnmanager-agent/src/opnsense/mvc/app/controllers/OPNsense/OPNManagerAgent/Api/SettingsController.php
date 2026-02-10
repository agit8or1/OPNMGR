<?php

/**
 *    Copyright (C) 2024 OPNManager
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\OPNManagerAgent\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

/**
 * Class SettingsController
 * @package OPNsense\OPNManagerAgent\Api
 */
class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'opnmanageragent';
    protected static $internalModelClass = 'OPNsense\OPNManagerAgent\OPNManagerAgent';

    /**
     * Retrieve general settings
     * @return array
     */
    public function getAction()
    {
        // Return nested structure that mapDataToFormUI expects
        // mapDataToFormUI maps form field "general.serverUrl" to data["opnmanageragent"]["general"]["serverUrl"]
        $mdl = $this->getModel();
        $result = ['opnmanageragent' => ['general' => $mdl->general->getNodes()]];
        return $result;
    }

    /**
     * Update general settings
     * @return array
     */
    public function setAction()
    {
        return $this->setBase('general', 'general');
    }

    /**
     * Process enrollment key to auto-configure the agent
     * @return array
     */
    public function enrollAction()
    {
        $result = ['status' => 'error', 'message' => 'Invalid request'];

        if ($this->request->isPost()) {
            $enrollmentKey = $this->request->getPost('enrollment_key', 'string', '');

            if (empty($enrollmentKey)) {
                return ['status' => 'error', 'message' => 'Enrollment key is required'];
            }

            // Decode the enrollment key (base64 encoded JSON)
            $decoded = base64_decode($enrollmentKey, true);
            if ($decoded === false) {
                return ['status' => 'error', 'message' => 'Invalid enrollment key format'];
            }

            $enrollData = json_decode($decoded, true);
            if (!$enrollData || !isset($enrollData['server_url']) || !isset($enrollData['token'])) {
                return ['status' => 'error', 'message' => 'Invalid enrollment key data'];
            }

            $serverUrl = rtrim($enrollData['server_url'], '/');
            $token = $enrollData['token'];
            $checkinInterval = $enrollData['checkin_interval'] ?? 120;
            $sshKeyManagement = $enrollData['ssh_key_management'] ?? true;
            $verifySSL = $enrollData['verify_ssl'] ?? true;

            // Get hardware ID
            $hwIdFile = '/usr/local/etc/opnmanager_hardware_id';
            $hardwareId = '';

            if (file_exists($hwIdFile)) {
                $hardwareId = trim(file_get_contents($hwIdFile));
            }

            if (empty($hardwareId)) {
                // Generate hardware ID
                if (file_exists('/etc/hostid')) {
                    $hwId = trim(file_get_contents('/etc/hostid'));
                }
                if (empty($hwId) || $hwId === '00000000') {
                    $hwId = trim(shell_exec('kenv -q smbios.system.uuid 2>/dev/null') ?? '');
                }
                if (empty($hwId) || $hwId === 'Not Settable' || $hwId === 'Not Present') {
                    $hwId = trim(shell_exec("ifconfig | grep -o 'ether [0-9a-f:]*' | head -1 | awk '{print \$2}' | tr -d ':'") ?? '');
                }
                if (empty($hwId)) {
                    $hwId = bin2hex(random_bytes(16));
                }
                $hardwareId = substr(md5($hwId), 0, 32);
                file_put_contents($hwIdFile, $hardwareId);
                chmod($hwIdFile, 0600);
            }

            // Call server to complete enrollment
            $enrollUrl = $serverUrl . '/agent_enroll.php';
            $postData = json_encode([
                'token' => $token,
                'hardware_id' => $hardwareId,
                'hostname' => gethostname(),
                'agent_version' => '1.1.7'
            ]);

            $ch = curl_init($enrollUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200) {
                return ['status' => 'error', 'message' => 'Server enrollment failed: ' . ($curlError ?: "HTTP $httpCode")];
            }

            $serverResponse = json_decode($response, true);
            if (!$serverResponse || !isset($serverResponse['success']) || !$serverResponse['success']) {
                $msg = $serverResponse['message'] ?? 'Unknown server error';
                return ['status' => 'error', 'message' => 'Enrollment rejected: ' . $msg];
            }

            // Enrollment successful - save ALL settings from enrollment key
            $logFile = '/var/log/opnmanager_enrollment.log';
            file_put_contents($logFile, date('Y-m-d H:i:s') . " Starting enrollment save\n", FILE_APPEND);

            $model = $this->getModel();
            file_put_contents($logFile, date('Y-m-d H:i:s') . " Got model\n", FILE_APPEND);

            // Try direct field setting with setValue
            try {
                $model->general->enabled->setValue('1');
                $model->general->serverUrl->setValue($serverUrl);
                $model->general->checkinInterval->setValue((string)$checkinInterval);
                $model->general->sshKeyManagement->setValue($sshKeyManagement ? '1' : '0');
                $model->general->verifySSL->setValue($verifySSL ? '1' : '0');
                file_put_contents($logFile, date('Y-m-d H:i:s') . " Set values via setValue()\n", FILE_APPEND);
            } catch (\Exception $e) {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " setValue error: " . $e->getMessage() . "\n", FILE_APPEND);
            }

            // Log current values
            file_put_contents($logFile, date('Y-m-d H:i:s') . " Values set - serverUrl: $serverUrl, enabled: 1, interval: $checkinInterval\n", FILE_APPEND);

            // Perform validation
            $validations = $model->performValidation();
            file_put_contents($logFile, date('Y-m-d H:i:s') . " Validation count: " . $validations->count() . "\n", FILE_APPEND);

            if ($validations->count() > 0) {
                $errors = [];
                foreach ($validations as $v) {
                    $errors[] = $v->getMessage();
                    file_put_contents($logFile, date('Y-m-d H:i:s') . " Validation error: " . $v->getMessage() . "\n", FILE_APPEND);
                }
                return ['status' => 'error', 'message' => 'Validation failed: ' . implode(', ', $errors)];
            }

            // Save to config.xml
            file_put_contents($logFile, date('Y-m-d H:i:s') . " Serializing to config\n", FILE_APPEND);
            $model->serializeToConfig();

            file_put_contents($logFile, date('Y-m-d H:i:s') . " Saving config\n", FILE_APPEND);
            \OPNsense\Core\Config::getInstance()->save();

            file_put_contents($logFile, date('Y-m-d H:i:s') . " Config saved!\n", FILE_APPEND);

            // Setup SSH access for OPNManager if SSH key management is enabled
            if ($sshKeyManagement) {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " Setting up SSH access\n", FILE_APPEND);

                // Fetch the OPNManager server's public SSH key (pass hardware_id so server can generate/retrieve the key)
                $sshKeyUrl = $serverUrl . '/api/get_ssh_public_key.php?hardware_id=' . urlencode($hardwareId);
                file_put_contents($logFile, date('Y-m-d H:i:s') . " Fetching SSH key from: $sshKeyUrl\n", FILE_APPEND);
                $ch2 = curl_init($sshKeyUrl);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, $verifySSL);
                $sshKeyResponse = curl_exec($ch2);
                $sshCurlError = curl_error($ch2);
                curl_close($ch2);
                file_put_contents($logFile, date('Y-m-d H:i:s') . " SSH key response: " . substr($sshKeyResponse, 0, 500) . "\n", FILE_APPEND);
                if ($sshCurlError) {
                    file_put_contents($logFile, date('Y-m-d H:i:s') . " SSH key curl error: $sshCurlError\n", FILE_APPEND);
                }

                $sshKeyData = json_decode($sshKeyResponse, true);
                if ($sshKeyData && !empty($sshKeyData['public_key'])) {
                    $publicKey = trim($sshKeyData['public_key']);

                    // Ensure .ssh directory exists
                    $sshDir = '/root/.ssh';
                    if (!is_dir($sshDir)) {
                        mkdir($sshDir, 0700, true);
                    }

                    $authKeysFile = $sshDir . '/authorized_keys';

                    // Check if key already exists
                    $existingKeys = file_exists($authKeysFile) ? file_get_contents($authKeysFile) : '';
                    if (strpos($existingKeys, $publicKey) === false) {
                        // Append the key
                        file_put_contents($authKeysFile, $publicKey . "\n", FILE_APPEND);
                        chmod($authKeysFile, 0600);
                        file_put_contents($logFile, date('Y-m-d H:i:s') . " SSH key deployed successfully\n", FILE_APPEND);
                    } else {
                        file_put_contents($logFile, date('Y-m-d H:i:s') . " SSH key already exists\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents($logFile, date('Y-m-d H:i:s') . " Could not fetch SSH public key from server\n", FILE_APPEND);
                }
            }

            // Start the agent service (with enable in rc.conf)
            $backend = new \OPNsense\Core\Backend();
            file_put_contents($logFile, date('Y-m-d H:i:s') . " Starting agent service\n", FILE_APPEND);
            $result = $backend->configdRun('opnmanager_agent restart');
            file_put_contents($logFile, date('Y-m-d H:i:s') . " Agent restart result: $result\n", FILE_APPEND);

            return [
                'status' => 'success',
                'message' => 'Enrollment successful! The agent is now connected.',
                'hardware_id' => $hardwareId,
                'server_url' => $serverUrl
            ];
        }

        return $result;
    }
}
