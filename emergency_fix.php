<!DOCTYPE html>
<html>
<head>
    <title>Emergency Agent Fix - Copy and Paste</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #f0f0f0; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: #333; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .script-box { background: #2d2d2d; border: 1px solid #555; padding: 15px; border-radius: 5px; }
        .copy-btn { background: #007acc; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; margin-bottom: 10px; }
        .copy-btn:hover { background: #005a9e; }
        pre { white-space: pre-wrap; word-wrap: break-word; font-size: 12px; line-height: 1.4; }
        .instructions { background: #2a4d2a; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #4caf50; }
        .warning { background: #4d2a2a; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #f44336; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚨 Emergency Agent Fix for OPNsense</h1>
            <p>Use this script to fix the broken command processing in your OPNsense agent</p>
        </div>

        <div class="instructions">
            <h3>� EASY METHOD - One-Line Command:</h3>
            <div style="background: #1a4d1a; padding: 15px; border-radius: 5px; margin: 10px 0;">
                <p><strong>Just copy and paste this single command:</strong></p>
                <button class="copy-btn" onclick="copyWgetCommand()" style="margin-bottom: 10px;">�📋 Copy One-Line Fix Command</button>
                <pre id="wget-command" style="background: #000; padding: 10px; border-radius: 3px; font-size: 14px; color: #0f0;">fetch -o /tmp/fix.sh https://opn.agit8or.net/emergency_agent_installer.sh && chmod +x /tmp/fix.sh && /tmp/fix.sh</pre>
            </div>
            
            <h3>📋 Alternative - Manual Method:</h3>
            <ol>
                <li><strong>Access your OPNsense console:</strong> SSH or console access to the firewall</li>
                <li><strong>Switch to root:</strong> Run <code>sudo su -</code> or <code>su -</code></li>
                <li><strong>Copy the entire script below</strong> (click the copy button)</li>
                <li><strong>Paste it into the OPNsense shell</strong> and press Enter</li>
                <li><strong>Wait for completion</strong> - the script will fix and restart the agent</li>
                <li><strong>The firewall will reboot automatically</strong> within 2 minutes to restart services</li>
            </ol>
        </div>

        <div class="warning">
            <h3>⚠️ Important Notes:</h3>
            <ul>
                <li>This script will <strong>restart the agent</strong> and <strong>trigger a system reboot</strong></li>
                <li>The reboot is necessary to restart the offline updater service</li>
                <li>After reboot, both the agent and updater should be working normally</li>
                <li>Save any unsaved work before running this script</li>
            </ul>
        </div>

        <div class="script-box">
            <button class="copy-btn" onclick="copyScript()">📋 Copy Emergency Fix Script</button>
            <pre id="script-content"><?php
// Read the emergency installer script
$script_content = file_get_contents('/var/www/opnsense/emergency_agent_installer.sh');
if ($script_content === false) {
    echo "Error: Could not read the emergency installer script.";
} else {
    echo htmlspecialchars($script_content);
}
?></pre>
        </div>

        <div style="margin-top: 30px; padding: 15px; background: #333; border-radius: 5px;">
            <h3>What this script does:</h3>
            <ul>
                <li>✅ Backs up the current broken agent</li>
                <li>✅ Creates a new agent with fixed command parsing</li>
                <li>✅ Stops the broken agent and starts the fixed one</li>
                <li>✅ Tests that the new agent is working</li>
                <li>✅ The fixed agent will process the pending reboot command</li>
                <li>✅ System will reboot to restart the updater service</li>
                <li>✅ After reboot, everything should work normally</li>
            </ul>
        </div>

        <div style="margin-top: 20px; padding: 15px; background: #2a4d2a; border-radius: 5px;">
            <h3>🎯 Expected Timeline:</h3>
            <ol>
                <li><strong>0-30 seconds:</strong> Script runs and fixes the agent</li>
                <li><strong>0-2 minutes:</strong> Agent processes reboot command</li>
                <li><strong>2-5 minutes:</strong> System reboots and restarts</li>
                <li><strong>5-6 minutes:</strong> Agent and updater both online</li>
            </ol>
        </div>
    </div>

    <script>
        function copyWgetCommand() {
            const command = "fetch -o /tmp/fix.sh https://opn.agit8or.net/emergency_agent_installer.sh && chmod +x /tmp/fix.sh && /tmp/fix.sh";
            const textArea = document.createElement('textarea');
            textArea.value = command;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = '✅ Copied!';
            button.style.background = '#4caf50';
            
            setTimeout(() => {
                button.textContent = originalText;
                button.style.background = '#007acc';
            }, 2000);
        }

        function copyScript() {
            const scriptContent = document.getElementById('script-content');
            const textArea = document.createElement('textarea');
            textArea.value = scriptContent.textContent;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            
            const button = document.querySelector('.copy-btn');
            const originalText = button.textContent;
            button.textContent = '✅ Copied!';
            button.style.background = '#4caf50';
            
            setTimeout(() => {
                button.textContent = originalText;
                button.style.background = '#007acc';
            }, 2000);
        }
    </script>
</body>
</html>