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

/**
 * Register OPNManager Agent service
 * @return array
 */
function opnmanageragent_services()
{
    global $config;

    $services = array();

    if (
        isset($config['OPNsense']['opnmanageragent']['general']['enabled']) &&
        $config['OPNsense']['opnmanageragent']['general']['enabled'] == '1'
    ) {
        $services[] = array(
            'description' => gettext('OPNManager Agent'),
            'configd' => array(
                'restart' => array('opnmanager_agent restart'),
                'start' => array('opnmanager_agent start'),
                'stop' => array('opnmanager_agent stop'),
            ),
            'name' => 'opnmanager_agent',
            'pidfile' => '/var/run/opnmanager_agent.pid'
        );
    }

    return $services;
}

/**
 * Register configure hook
 * @return array
 */
function opnmanageragent_configure()
{
    return array(
        'bootup' => array('opnmanageragent_configure_do')
    );
}

/**
 * Configure and start service on boot
 * @param bool $verbose
 * @return void
 */
function opnmanageragent_configure_do($verbose = false)
{
    global $config;

    if (
        isset($config['OPNsense']['opnmanageragent']['general']['enabled']) &&
        $config['OPNsense']['opnmanageragent']['general']['enabled'] == '1'
    ) {
        if ($verbose) {
            echo 'Starting OPNManager Agent...';
        }
        mwexec('/usr/local/etc/rc.d/opnmanager_agent start');
        if ($verbose) {
            echo "done.\n";
        }
    }
}
