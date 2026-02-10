{#
 # Copyright (c) 2024 OPNManager
 # All rights reserved.
 #}

<script>
    $(document).ready(function() {
        loadSettings();

        function loadSettings() {
            console.log('loadSettings called');
            var data_get_map = {'frm_GeneralSettings':"/api/opnmanageragent/settings/get"};
            mapDataToFormUI(data_get_map).done(function(data){
                console.log('mapDataToFormUI returned:', JSON.stringify(data));
                formatTokenizersUI();
                $('.selectpicker').selectpicker('refresh');

                // Update connection status - API returns {opnmanageragent: {general: {...}}}
                updateConnectionStatus(data);

                // Set hardware ID (readonly)
                ajaxCall(url="/api/opnmanageragent/service/gethardwareid", sendData={}, callback=function(hwdata,status) {
                    console.log('Hardware ID response:', hwdata);
                    if (hwdata && hwdata.hardware_id) {
                        var hwInput = $('input[id="general\\.hardwareId"]');
                        hwInput.val(hwdata.hardware_id);
                        hwInput.prop('readonly', true);
                        hwInput.css('background-color', '#f5f5f5');
                    }
                });
            });
        }

        function updateConnectionStatus(data) {
            console.log('updateConnectionStatus called with:', JSON.stringify(data));

            // Check if service is actually running
            ajaxCall(url="/api/opnmanageragent/service/status", sendData={}, callback=function(statusData,status) {
                console.log('Service status:', statusData);

                if (statusData && statusData.status == 'running') {
                    // Service is running - show as connected
                    $("#connection_status").html('<i class="fa fa-circle text-success"></i> Connected');
                } else {
                    // Service not running - check config
                    var config = null;
                    if (data && data.frm_GeneralSettings && data.frm_GeneralSettings.opnmanageragent) {
                        config = data.frm_GeneralSettings.opnmanageragent.general || data.frm_GeneralSettings.opnmanageragent;
                    } else if (data && data.opnmanageragent) {
                        config = data.opnmanageragent.general || data.opnmanageragent;
                    }

                    var serverUrl = '';
                    if (config) {
                        serverUrl = config.serverUrl || config.server_url || config.ServerUrl || config['general.serverUrl'] || '';
                    }

                    var hasServer = serverUrl && serverUrl.trim() !== '';
                    if (hasServer) {
                        $("#connection_status").html('<i class="fa fa-circle text-warning"></i> Configured (Stopped)');
                    } else {
                        $("#connection_status").html('<i class="fa fa-circle text-danger"></i> Not Configured');
                    }
                }
            });
        }

        ajaxCall(url="/api/opnmanageragent/service/version", sendData={}, callback=function(data,status) {
            if (data && data.plugin_version) {
                $("#plugin_version").text(data.plugin_version);
            }
        });

        updateServiceStatus();

        $("#saveAct").click(function(){
            $("#saveAct_progress").addClass("fa fa-spinner fa-pulse");

            // First get current settings, then update only enabled field
            ajaxCall(url="/api/opnmanageragent/settings/get", sendData={}, callback=function(getdata,status) {
                console.log('Current settings for save:', JSON.stringify(getdata));

                // Extract current config
                var currentConfig = {};
                if (getdata && getdata.opnmanageragent && getdata.opnmanageragent.general) {
                    currentConfig = getdata.opnmanageragent.general;
                }

                // Update enabled field with checkbox value
                currentConfig.enabled = $("#enabledCheckbox").is(':checked') ? '1' : '0';

                console.log('Saving config:', JSON.stringify(currentConfig));

                // Save with all fields
                ajaxCall(url="/api/opnmanageragent/settings/set", sendData={'general': currentConfig}, callback=function(data,status) {
                    console.log('Save response:', JSON.stringify(data));
                    if (data && data.result === 'saved') {
                        ajaxCall(url="/api/opnmanageragent/service/reconfigure", sendData={}, callback=function(data,status) {
                            console.log('Reconfigure response:', JSON.stringify(data));
                            updateServiceStatus();
                            displayCurrentConfig();
                            $("#saveAct_progress").removeClass("fa fa-spinner fa-pulse");
                        });
                    } else {
                        console.error('Save failed:', data);
                        alert('Save failed: ' + (data.message || 'Unknown error'));
                        $("#saveAct_progress").removeClass("fa fa-spinner fa-pulse");
                    }
                });
            });
        });

        $("#startService").click(function(){
            $("#startService_progress").addClass("fa fa-spinner fa-pulse");
            ajaxCall(url="/api/opnmanageragent/service/start", sendData={}, callback=function(data,status) {
                updateServiceStatus();
                $("#startService_progress").removeClass("fa fa-spinner fa-pulse");
            });
        });

        $("#stopService").click(function(){
            $("#stopService_progress").addClass("fa fa-spinner fa-pulse");
            ajaxCall(url="/api/opnmanageragent/service/stop", sendData={}, callback=function(data,status) {
                updateServiceStatus();
                $("#stopService_progress").removeClass("fa fa-spinner fa-pulse");
            });
        });

        $("#restartService").click(function(){
            $("#restartService_progress").addClass("fa fa-spinner fa-pulse");
            ajaxCall(url="/api/opnmanageragent/service/restart", sendData={}, callback=function(data,status) {
                updateServiceStatus();
                $("#restartService_progress").removeClass("fa fa-spinner fa-pulse");
            });
        });

        function updateServiceStatus() {
            ajaxCall(url="/api/opnmanageragent/service/status", sendData={}, callback=function(data,status) {
                var statusClass = 'label-danger';
                var statusText = 'Stopped';
                if (data.status == 'running') {
                    statusClass = 'label-success';
                    statusText = 'Running';
                }
                $("#service_status").html('<span class="label ' + statusClass + '">' + statusText + '</span>');
            });
        }

        setInterval(updateServiceStatus, 30000);

        // Decode and display enrollment key configuration
        $("#enrollmentKey").on('input paste', function() {
            setTimeout(function() {
                var enrollmentKey = $("#enrollmentKey").val().trim();
                if (!enrollmentKey) {
                    $("#enrollKeyConfig").hide();
                    return;
                }

                try {
                    // Decode base64
                    var decoded = atob(enrollmentKey);
                    var config = JSON.parse(decoded);

                    // Display configuration
                    if (config.server_url && config.token) {
                        var html = '<div class="alert alert-info" style="margin-top:10px; padding:10px"><strong><i class="fa fa-info-circle"></i> Enrollment Configuration:</strong><br>';
                        html += '<small>';
                        html += '<strong>Server URL:</strong> ' + config.server_url + '<br>';
                        html += '<strong>Token:</strong> ' + config.token.substring(0, 16) + '...<br>';
                        html += '<strong>Check-in Interval:</strong> ' + (config.checkin_interval || '120') + ' seconds<br>';
                        html += '<strong>SSH Key Management:</strong> ' + (config.ssh_key_management ? 'Enabled' : 'Disabled') + '<br>';
                        html += '<strong>Verify SSL:</strong> ' + (config.verify_ssl ? 'Yes' : 'No');
                        html += '</small></div>';
                        $("#enrollKeyConfig").html(html).show();
                    } else {
                        $("#enrollKeyConfig").html('<div class="alert alert-warning" style="margin-top:10px; padding:10px"><small>Invalid enrollment key format</small></div>').show();
                    }
                } catch(e) {
                    $("#enrollKeyConfig").html('<div class="alert alert-warning" style="margin-top:10px; padding:10px"><small>Invalid enrollment key format</small></div>').show();
                }
            }, 100);
        });

        // Display current configuration
        function displayCurrentConfig() {
            ajaxCall(url="/api/opnmanageragent/settings/get", sendData={}, callback=function(data,status) {
                console.log('Settings API response:', JSON.stringify(data));

                var config = null;

                // Try multiple paths to find the config
                if (data && data.opnmanageragent && data.opnmanageragent.general) {
                    config = data.opnmanageragent.general;
                } else if (data && data.frm_GeneralSettings && data.frm_GeneralSettings.opnmanageragent) {
                    config = data.frm_GeneralSettings.opnmanageragent.general;
                } else if (data && data.general) {
                    config = data.general;
                }

                console.log('Config extracted:', config);

                if (config) {
                    // Agent Enabled
                    var enabled = config.enabled === '1' || config.enabled === 1 || config.enabled === true;
                    $("#enabledCheckbox").prop('checked', enabled);

                    // Server URL - check multiple possible field names
                    var serverUrl = config.serverUrl || config.server_url || config.ServerUrl || '';
                    if (serverUrl && serverUrl.trim() !== '') {
                        $("#displayServerUrl").text(serverUrl);
                    } else {
                        $("#displayServerUrl").html('<span class="text-muted">Not configured</span>');
                    }

                    // Check-in Interval - check multiple possible field names
                    var interval = config.checkinInterval || config.checkin_interval || config.CheckinInterval;
                    if (interval && interval !== '') {
                        $("#displayCheckinInterval").text(interval + ' seconds');
                    } else {
                        $("#displayCheckinInterval").text('120 seconds (default)');
                    }

                    // SSH Key Management - check multiple possible field names
                    var sshKeyMgmt = config.sshKeyManagement || config.ssh_key_management || config.SshKeyManagement;
                    if (sshKeyMgmt === '1' || sshKeyMgmt === 1 || sshKeyMgmt === true) {
                        $("#displaySshKeyManagement").html('<i class="fa fa-check text-success"></i> Enabled');
                    } else if (sshKeyMgmt === '0' || sshKeyMgmt === 0 || sshKeyMgmt === false) {
                        $("#displaySshKeyManagement").html('<i class="fa fa-times text-muted"></i> Disabled');
                    } else {
                        $("#displaySshKeyManagement").html('<span class="text-muted">Not configured</span>');
                    }

                    // Verify SSL - check multiple possible field names
                    var verifySSL = config.verifySSL || config.verify_ssl || config.VerifySSL;
                    if (verifySSL === '1' || verifySSL === 1 || verifySSL === true) {
                        $("#displayVerifySSL").html('<i class="fa fa-check text-success"></i> Yes');
                    } else if (verifySSL === '0' || verifySSL === 0 || verifySSL === false) {
                        $("#displayVerifySSL").html('<i class="fa fa-times text-muted"></i> No');
                    } else {
                        $("#displayVerifySSL").html('<span class="text-muted">Not configured</span>');
                    }

                    console.log('Display updated with values:', {
                        enabled: enabled,
                        serverUrl: serverUrl,
                        interval: interval,
                        sshKeyMgmt: sshKeyMgmt,
                        verifySSL: verifySSL
                    });
                } else {
                    console.warn('No config found in response');
                }

                // Get and display hardware ID
                ajaxCall(url="/api/opnmanageragent/service/gethardwareid", sendData={}, callback=function(hwdata,status) {
                    console.log('Hardware ID response:', hwdata);
                    if (hwdata && hwdata.hardware_id) {
                        $("#displayHardwareId").text(hwdata.hardware_id);
                    }
                });
            });
        }

        displayCurrentConfig();

        $("#enrollBtn").click(function(){
            var enrollmentKey = $("#enrollmentKey").val().trim();
            if (!enrollmentKey) {
                $("#enrollResult").html('<span class="label label-warning">Please enter an enrollment key</span>');
                return;
            }
            $("#enrollBtn_progress").addClass("fa fa-spinner fa-pulse");
            $("#enrollResult").html('<span class="label label-info">Enrolling...</span>');

            ajaxCall(url="/api/opnmanageragent/settings/enroll", sendData={enrollment_key: enrollmentKey}, callback=function(data,status) {
                $("#enrollBtn_progress").removeClass("fa fa-spinner fa-pulse");
                if (data && data.status == 'success') {
                    $("#enrollResult").html('<span class="label label-success">' + data.message + '</span>');
                    $("#enrollmentKey").val('');
                    $("#enrollKeyConfig").hide();
                    // Reload all settings from the server after successful enrollment
                    loadSettings();
                    displayCurrentConfig();
                    updateServiceStatus();
                } else {
                    $("#enrollResult").html('<span class="label label-danger">' + (data.message || 'Error') + '</span>');
                }
            });
        });

        $("#copyHwId").click(function(){
            var hwId = $("#displayHardwareId").text();
            if (hwId && hwId !== 'Loading...') {
                navigator.clipboard.writeText(hwId).then(function() {
                    $("#copyHwId").html('<i class="fa fa-check"></i> Copied!');
                    setTimeout(function() { $("#copyHwId").html('<i class="fa fa-copy"></i> Copy'); }, 1500);
                });
            }
        });

        $("#uninstallBtn").click(function(){
            if (!confirm('Remove OPNManager Agent? This cannot be undone.')) return;
            $("#uninstallBtn").prop('disabled', true).html('<i class="fa fa-spinner fa-pulse"></i>');
            ajaxCall(url="/api/opnmanageragent/service/uninstall", sendData={}, callback=function(data,status) {
                if (data && data.status == 'success') {
                    alert(data.message);
                    setTimeout(function() { window.location.href = '/'; }, 5000);
                } else {
                    alert('Error: ' + (data.message || 'Failed'));
                    $("#uninstallBtn").prop('disabled', false).html('<i class="fa fa-trash"></i> Uninstall');
                }
            });
        });
    });
</script>

<div class="content-box">
    <div class="content-box-main">
        <div class="row">
            <div class="col-sm-6">
                <h2 style="margin-top:0"><i class="fa fa-cloud"></i> OPNManager Agent <small class="label label-info">v<span id="plugin_version">...</span></small></h2>
            </div>
            <div class="col-sm-6 text-right">
                <span id="connection_status" style="margin-right:15px"><i class="fa fa-circle text-muted"></i> Loading...</span>
                <span id="service_status"><span class="label label-default">...</span></span>
                <div class="btn-group btn-group-xs" style="margin-left:10px">
                    <button class="btn btn-success" id="startService" title="Start"><i class="fa fa-play"></i><i id="startService_progress"></i></button>
                    <button class="btn btn-warning" id="stopService" title="Stop"><i class="fa fa-stop"></i><i id="stopService_progress"></i></button>
                    <button class="btn btn-primary" id="restartService" title="Restart"><i class="fa fa-refresh"></i><i id="restartService_progress"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="content-box" style="margin-top:10px">
    <div class="content-box-main">
        <div class="row">
            <div class="col-md-6">
                <h4><i class="fa fa-key"></i> Quick Enrollment</h4>
                <div class="input-group">
                    <input type="text" class="form-control input-sm" id="enrollmentKey" placeholder="Paste enrollment key..." style="font-family:monospace">
                    <span class="input-group-btn">
                        <button class="btn btn-success btn-sm" id="enrollBtn"><i class="fa fa-check"></i> Enroll <i id="enrollBtn_progress"></i></button>
                    </span>
                </div>
                <div id="enrollKeyConfig" style="display:none"></div>
                <div id="enrollResult" style="margin-top:5px"></div>
            </div>
            <div class="col-md-6">
                <h4><i class="fa fa-info-circle"></i> Quick Start</h4>
                <small>
                    1. Get enrollment key from OPNManager<br>
                    2. Paste above and click Enroll<br>
                    <em>Or manually configure settings below</em>
                </small>
            </div>
        </div>
    </div>
</div>

<div class="content-box" style="margin-top:10px">
    <div class="content-box-main">
        <h4><i class="fa fa-cog"></i> Configuration <small class="text-muted">(Configured via Enrollment Key)</small></h4>

        <table class="table table-striped table-condensed">
            <tbody>
                <tr>
                    <td style="width:30%"><strong>Agent Enabled</strong></td>
                    <td>
                        <input type="checkbox" id="enabledCheckbox"> <small class="text-muted">Toggle agent on/off</small>
                    </td>
                </tr>
                <tr>
                    <td><strong>Server URL</strong></td>
                    <td><span id="displayServerUrl" style="font-family:monospace">Not configured</span></td>
                </tr>
                <tr>
                    <td><strong>Hardware ID</strong></td>
                    <td>
                        <span id="displayHardwareId" style="font-family:monospace">Loading...</span>
                        <button class="btn btn-default btn-xs" id="copyHwId" style="margin-left:10px"><i class="fa fa-copy"></i> Copy</button>
                    </td>
                </tr>
                <tr>
                    <td><strong>Check-in Interval</strong></td>
                    <td><span id="displayCheckinInterval">120 seconds</span></td>
                </tr>
                <tr>
                    <td><strong>SSH Key Management</strong></td>
                    <td><span id="displaySshKeyManagement"><i class="fa fa-times text-danger"></i> Disabled</span></td>
                </tr>
                <tr>
                    <td><strong>Verify SSL Certificate</strong></td>
                    <td><span id="displayVerifySSL"><i class="fa fa-times text-danger"></i> No</span></td>
                </tr>
            </tbody>
        </table>

        <hr style="margin:10px 0"/>
        <button class="btn btn-primary btn-sm" id="saveAct"><i class="fa fa-save"></i> Save Enable/Disable <i id="saveAct_progress"></i></button>
        <button class="btn btn-danger btn-xs pull-right" id="uninstallBtn"><i class="fa fa-trash"></i> Uninstall</button>
        <div style="margin-top:15px">
            <small class="text-muted"><i class="fa fa-info-circle"></i> Configuration settings are managed via enrollment key and cannot be manually edited.</small>
        </div>
    </div>
</div>
