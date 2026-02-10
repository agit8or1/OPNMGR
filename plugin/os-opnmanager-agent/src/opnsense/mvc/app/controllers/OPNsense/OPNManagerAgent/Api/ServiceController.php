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

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * Class ServiceController
 * @package OPNsense\OPNManagerAgent\Api
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = '\OPNsense\OPNManagerAgent\OPNManagerAgent';
    protected static $internalServiceTemplate = 'OPNsense/OPNManagerAgent';
    protected static $internalServiceEnabled = 'general.enabled';
    protected static $internalServiceName = 'opnmanager_agent';

    /**
     * Get service status
     * @return array
     */
    public function statusAction()
    {
        $backend = new Backend();
        $response = $backend->configdRun('opnmanager_agent status');

        $status = 'unknown';
        if (strpos($response, 'is running') !== false) {
            $status = 'running';
        } elseif (strpos($response, 'is not running') !== false) {
            $status = 'stopped';
        }

        return ['status' => $status, 'response' => trim($response)];
    }

    /**
     * Start service
     * @return array
     */
    public function startAction()
    {
        $backend = new Backend();
        $response = $backend->configdRun('opnmanager_agent start');
        return ['response' => trim($response)];
    }

    /**
     * Stop service
     * @return array
     */
    public function stopAction()
    {
        $backend = new Backend();
        $response = $backend->configdRun('opnmanager_agent stop');
        return ['response' => trim($response)];
    }

    /**
     * Restart service
     * @return array
     */
    public function restartAction()
    {
        $backend = new Backend();
        $response = $backend->configdRun('opnmanager_agent restart');
        return ['response' => trim($response)];
    }

    /**
     * Force immediate check-in
     * @return array
     */
    public function checkinAction()
    {
        $backend = new Backend();
        $response = $backend->configdRun('opnmanager_agent checkin');
        return ['response' => trim($response)];
    }

    /**
     * Get or generate hardware ID
     * @return array
     */
    public function gethardwareidAction()
    {
        $hwIdFile = '/usr/local/etc/opnmanager_hardware_id';
        $hardwareId = '';

        // Check if hardware ID file exists
        if (file_exists($hwIdFile)) {
            $hardwareId = trim(file_get_contents($hwIdFile));
        }

        // If not, generate one
        if (empty($hardwareId)) {
            // Try to get from hostid
            if (file_exists('/etc/hostid')) {
                $hwId = trim(file_get_contents('/etc/hostid'));
            }

            // Fallback: smbios UUID
            if (empty($hwId) || $hwId === '00000000') {
                $hwId = trim(shell_exec('kenv -q smbios.system.uuid 2>/dev/null') ?? '');
            }

            // Fallback: MAC address
            if (empty($hwId) || $hwId === 'Not Settable' || $hwId === 'Not Present') {
                $hwId = trim(shell_exec("ifconfig | grep -o 'ether [0-9a-f:]*' | head -1 | awk '{print \$2}' | tr -d ':'") ?? '');
            }

            // Last resort: random
            if (empty($hwId)) {
                $hwId = bin2hex(random_bytes(16));
            }

            // Create MD5 hash
            $hardwareId = substr(md5($hwId), 0, 32);

            // Save to file
            file_put_contents($hwIdFile, $hardwareId);
            chmod($hwIdFile, 0600);
        }

        return ['hardware_id' => $hardwareId];
    }

    /**
     * Get plugin version info
     * @return array
     */
    public function versionAction()
    {
        return [
            'plugin_version' => '1.2.7',
            'plugin_name' => 'OPNManager Agent',
            'release_date' => '2025-12-06'
        ];
    }

    /**
     * Uninstall the plugin
     * @return array
     */
    public function uninstallAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }

        // Stop service first
        $backend = new Backend();
        $backend->configdRun('opnmanager_agent stop');

        // Remove configuration from config.xml using OPNsense API
        $logFile = '/tmp/opnmanager_uninstall_debug.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " Starting uninstall config removal\n", FILE_APPEND);

        try {
            $config = \OPNsense\Core\Config::getInstance();
            $configObj = $config->object();

            file_put_contents($logFile, date('Y-m-d H:i:s') . " Got config object\n", FILE_APPEND);

            // Check for both possible paths
            $removed = false;
            if (isset($configObj->OPNsense->opnmanageragent)) {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " Found opnmanageragent (lowercase)\n", FILE_APPEND);
                unset($configObj->OPNsense->opnmanageragent);
                $removed = true;
            }
            if (isset($configObj->OPNsense->OPNManagerAgent)) {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " Found OPNManagerAgent (camelcase)\n", FILE_APPEND);
                unset($configObj->OPNsense->OPNManagerAgent);
                $removed = true;
            }

            if ($removed) {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " Saving config...\n", FILE_APPEND);
                $config->save();
                file_put_contents($logFile, date('Y-m-d H:i:s') . " Config saved successfully\n", FILE_APPEND);
            } else {
                file_put_contents($logFile, date('Y-m-d H:i:s') . " No config found to remove\n", FILE_APPEND);
            }
        } catch (\Exception $e) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            error_log("Config removal error: " . $e->getMessage());
        }

        // Create uninstall script - runs after response is sent
        $script = <<<'BASH'
#!/bin/sh
# OPNManager Agent Uninstall Script
sleep 2

# Stop agent
pkill -f opnmanager_agent 2>/dev/null
service opnmanager_agent stop 2>/dev/null
sysrc -x opnmanager_agent_enable 2>/dev/null

# Remove ALL plugin files
rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/OPNManagerAgent
rm -rf /usr/local/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent
rm -rf /usr/local/opnsense/mvc/app/views/OPNsense/OPNManagerAgent
rm -rf /usr/local/opnsense/scripts/OPNsense/OPNManagerAgent
rm -rf /usr/local/opnsense/service/templates/OPNsense/OPNManagerAgent
rm -f /usr/local/opnsense/service/conf/actions.d/actions_opnmanager_agent.conf
rm -f /usr/local/etc/inc/plugins.inc.d/opnmanageragent.inc
rm -f /usr/local/etc/rc.d/opnmanager_agent
rm -f /var/run/opnmanager_agent.pid
rm -f /var/log/opnmanager_agent.log*
rm -f /usr/local/etc/opnmanager_hardware_id

# Remove SSH key from authorized_keys if it exists (look for opnmanager comment)
if [ -f /root/.ssh/authorized_keys ]; then
    grep -v "opnmanager\|OPNManager" /root/.ssh/authorized_keys > /root/.ssh/authorized_keys.tmp 2>/dev/null
    mv /root/.ssh/authorized_keys.tmp /root/.ssh/authorized_keys 2>/dev/null
    chmod 600 /root/.ssh/authorized_keys 2>/dev/null
fi

# Backup and remove config from config.xml (double-check in case API method failed)
echo "$(date '+%Y-%m-%d %H:%M:%S') Backing up config.xml" >> /tmp/opnmanager_uninstall_debug.log
cp /conf/config.xml /conf/config.xml.pre_opnmanager_uninstall 2>/dev/null

echo "$(date '+%Y-%m-%d %H:%M:%S') Checking for opnmanageragent config in XML" >> /tmp/opnmanager_uninstall_debug.log
if grep -q '<opnmanageragent>' /conf/config.xml; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') Found opnmanageragent config, removing..." >> /tmp/opnmanager_uninstall_debug.log

    # Use xml-starlet if available (best method)
    if command -v xml >/dev/null 2>&1; then
        echo "$(date '+%Y-%m-%d %H:%M:%S') Using xml-starlet" >> /tmp/opnmanager_uninstall_debug.log
        xml ed -d '//OPNsense/opnmanageragent' /conf/config.xml > /conf/config.xml.tmp 2>>/tmp/opnmanager_uninstall_debug.log
        if [ $? -eq 0 ]; then
            mv /conf/config.xml.tmp /conf/config.xml
            echo "$(date '+%Y-%m-%d %H:%M:%S') XML removed with xml-starlet" >> /tmp/opnmanager_uninstall_debug.log
        fi
    else
        # Fallback to sed
        echo "$(date '+%Y-%m-%d %H:%M:%S') Using sed" >> /tmp/opnmanager_uninstall_debug.log
        sed -i.bak '/<opnmanageragent>/,/<\/opnmanageragent>/d' /conf/config.xml 2>>/tmp/opnmanager_uninstall_debug.log
        echo "$(date '+%Y-%m-%d %H:%M:%S') XML removed with sed" >> /tmp/opnmanager_uninstall_debug.log
    fi

    # Verify removal
    if grep -q '<opnmanageragent>' /conf/config.xml; then
        echo "$(date '+%Y-%m-%d %H:%M:%S') WARNING: Config still exists after removal!" >> /tmp/opnmanager_uninstall_debug.log
    else
        echo "$(date '+%Y-%m-%d %H:%M:%S') Config successfully removed" >> /tmp/opnmanager_uninstall_debug.log
    fi
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') No opnmanageragent config found in XML" >> /tmp/opnmanager_uninstall_debug.log
fi

# Clear OPNsense menu cache
echo "$(date '+%Y-%m-%d %H:%M:%S') Clearing menu cache" >> /tmp/opnmanager_uninstall_debug.log
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -rf /var/lib/php/cache/*

# Restart configd to reload actions
echo "$(date '+%Y-%m-%d %H:%M:%S') Restarting configd" >> /tmp/opnmanager_uninstall_debug.log
service configd restart

# DO NOT restart web GUI - user is still logged in!
# Menu will update after user logs out/in

echo "$(date '+%Y-%m-%d %H:%M:%S') Uninstall complete" >> /tmp/opnmanager_uninstall_debug.log
rm -f /tmp/opnmanager_uninstall.sh
exit 0
BASH;

        // Write and execute uninstall script in background
        file_put_contents('/tmp/opnmanager_uninstall.sh', $script);
        chmod('/tmp/opnmanager_uninstall.sh', 0755);
        exec('nohup /tmp/opnmanager_uninstall.sh > /tmp/opnmanager_uninstall.log 2>&1 &');

        return [
            'status' => 'success',
            'message' => 'Uninstall started. Check /tmp/opnmanager_uninstall_debug.log for details. Log out/in to finish.'
        ];
    }
}
