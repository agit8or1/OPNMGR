<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();

include __DIR__ . '/inc/header.php';

// Generate a unique enrollment token
$enrollment_token = bin2hex(random_bytes(32));
// Store token in database
$stmt = db()->prepare('INSERT INTO enrollment_tokens (token, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 24 HOUR))');
$stmt->execute([$enrollment_token]);

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$enrollment_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/enroll_firewall.php?action=download&token=' . $enrollment_token;
$wget_command = 'pkg update && pkg install -y wget && wget -q -O /tmp/opnsense_enroll.sh "' . $enrollment_url . '" && chmod +x /tmp/opnsense_enroll.sh && bash /tmp/opnsense_enroll.sh';

$server_url = opnmgr_server_url();
$agent_install_command = 'fetch -o - ' . $server_url . '/downloads/plugins/install_opnmanager_agent.sh | env OPNMGR_BASE_URL=' . $server_url . ' sh';

// Suggestions for the customer field of the agent enrollment form.
$customer_names = db()->query('SELECT name FROM customers ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

?>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="fas fa-plus-circle me-2"></i>Add New Firewall</h1>
                <a href="/firewalls.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Firewalls
                </a>
            </div>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-key me-2"></i>Enroll with the OPNManager Agent Plugin (Recommended)</h5>
                        </div>
                        <div class="card-body">
                            <p>Create the firewall here, then paste the enrollment key into the agent plugin. Nothing has to be run over SSH.</p>
                            <form id="agentEnrollForm" class="row g-2 mb-3" onsubmit="createAgentEnrollment(event)">
                                <div class="col-md-5">
                                    <label class="form-label" for="enrollHostname">Firewall name</label>
                                    <input type="text" class="form-control" id="enrollHostname" maxlength="255" required placeholder="e.g. branch-office-fw">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="enrollCustomer">Customer (optional)</label>
                                    <input type="text" class="form-control" id="enrollCustomer" maxlength="128" list="enrollCustomerList">
                                    <datalist id="enrollCustomerList">
                                        <?php foreach ($customer_names as $name): ?>
                                        <option value="<?php echo htmlspecialchars($name); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-success w-100" id="agentEnrollBtn">
                                        <i class="fas fa-plus me-1"></i>Create &amp; Get Key
                                    </button>
                                </div>
                            </form>

                            <div id="agentEnrollResult" class="d-none">
                                <label class="form-label" for="agentEnrollKey">Enrollment key for <strong id="agentEnrollName"></strong>:</label>
                                <div class="input-group mb-2">
                                    <input type="text" id="agentEnrollKey" class="form-control font-monospace" readonly>
                                    <button class="btn btn-primary" type="button" onclick="copyAgentEnrollKey(this)">
                                        <i class="fas fa-copy me-1"></i>Copy
                                    </button>
                                </div>
                                <p class="small text-muted mb-2">Expires <span id="agentEnrollExpires"></span>. It can be used once; a new key can be generated from the firewall's details page.</p>
                                <ol class="mb-0">
                                    <li>Install the agent plugin from a shell on the firewall:
                                        <code class="d-block p-2 my-1 border rounded"><?php echo htmlspecialchars($agent_install_command); ?></code>
                                    </li>
                                    <li>On OPNsense, go to <strong>Services &rarr; OPNManager Agent</strong>.</li>
                                    <li>Paste the key into <strong>Quick Enrollment</strong> and click <strong>Enroll</strong>.</li>
                                </ol>
                                <p class="mt-2 mb-0"><a id="agentEnrollLink" href="#">Open the firewall's page</a> &mdash; it shows as pending until the agent enrolls.</p>
                            </div>
                            <div id="agentEnrollError" class="alert alert-danger d-none mt-2 mb-0"></div>
                        </div>
                    </div>

                    <div class="card card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-terminal me-2"></i>Legacy Enrollment Script</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">The older method: run a script on the firewall over SSH. Use the agent plugin above unless you need this.</p>

                            <div class="accordion" id="installationAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#step1" aria-expanded="true">
                                            <strong>Step 1:</strong> ⚡ Ultra-Simple One-Command Enrollment
                                        </button>
                                    </h2>
                                    <div id="step1" class="accordion-collapse collapse show" data-bs-parent="#installationAccordion">
                                        <div class="accordion-body">
                                            <div class="alert alert-success">
                                                <strong>🚀 Simplest Method:</strong> Copy the wget command below and paste it directly into your OPNsense SSH session. That's it!
                                            </div>

                                            <p><strong>Copy this single command</strong> and paste it into your SSH session:</p>
                                            <div class="border p-3 rounded mb-3">
                                                <input type="text" class="form-control" id="wgetCommand" value="<?php echo htmlspecialchars($wget_command); ?>" readonly style="font-family: monospace; font-size: 0.9rem;">
                                            </div>

                                            <div class="d-flex gap-2 mb-3">
                                                <button class="btn btn-success btn-sm" onclick="copyWgetCommand()">
                                                    <i class="fas fa-copy me-1"></i>Copy Wget Command
                                                </button>
                                                <button class="btn btn-primary btn-sm" onclick="selectWgetCommand()">
                                                    <i class="fas fa-mouse-pointer me-1"></i>Select All
                                                </button>
                                            </div>

                                            <div class="alert alert-info">
                                                <strong>What this command does:</strong>
                                                <ul class="mb-0 mt-2">
                                                    <li>Updates package repositories</li>
                                                    <li>Installs wget if needed</li>
                                                    <li>Downloads the enrollment script</li>
                                                    <li>Makes it executable</li>
                                                    <li><strong>Runs it with bash</strong> (fixes shell compatibility)</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step2">
                                            <strong>Step 2:</strong> Manual Method (Alternative)
                                        </button>
                                    </h2>
                                    <div id="step2" class="accordion-collapse collapse" data-bs-parent="#installationAccordion">
                                        <div class="accordion-body">
                                            <p>If the one-command method doesn't work, you can do it manually:</p>
                                            <ol>
                                                <li>Download the script using the button below</li>
                                                <li>Transfer it to your OPNsense firewall (USB drive, SCP, etc.)</li>
                                                <li>SSH into your firewall and run: <code>bash opnsense_enroll.sh</code></li>
                                            </ol>

                                            <div class="d-flex gap-2 mb-3">
                                                <a href="<?php echo htmlspecialchars($enrollment_url); ?>" class="btn btn-primary" target="_blank">
                                                    <i class="fas fa-download me-1"></i>Download Enrollment Script
                                                </a>
                                            </div>

                                            <div class="alert alert-warning">
                                                <strong>Note:</strong> The downloaded script will be valid for 24 hours. Make sure to use it within that timeframe.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Quick Info</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-clock me-2"></i>Process Overview</h6>
                                <ul class="mb-0">
                                    <li>Script runs in ~2 minutes</li>
                                    <li>Firewall appears in your list automatically</li>
                                    <li>No manual configuration needed</li>
                                    <li>Secure token-based authentication</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h6><i class="fas fa-shield-alt me-2"></i>Security Notes</h6>
                                <ul class="mb-0">
                                    <li>Each enrollment token is unique</li>
                                    <li>Tokens expire after 24 hours</li>
                                    <li>All communication is encrypted</li>
                                    <li>No sensitive data is stored</li>
                            <div class="alert alert-danger">
                                <h6><i class="fas fa-trash-alt me-2"></i>Agent Uninstall</h6>
                                <p class="mb-1">To uninstall the agent from a firewall, run these commands on the firewall:</p>
                                <code class="d-block p-2 border rounded mb-2" style="font-family: monospace;">
                                    service opnagent stop<br>
                                    pkg remove opnagent<br>
                                    rm -rf /usr/local/opnsense/scripts/opnagent<br>
                                    rm -f /usr/local/etc/rc.d/opnagent<br>
                                </code>
                                <small class="text-muted">Note: This will completely remove the agent and all associated files.</small>
                            </div>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo csrf_token(); ?>';

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify(Object.assign({csrf: csrfToken}, body))
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) {
        throw new Error(data.message || ('HTTP ' + response.status));
    }
    return data;
}

async function createAgentEnrollment(event) {
    event.preventDefault();
    const btn = document.getElementById('agentEnrollBtn');
    const errorBox = document.getElementById('agentEnrollError');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating...';
    errorBox.classList.add('d-none');

    try {
        const created = await postJson('api/create_firewall_for_enrollment.php', {
            hostname: document.getElementById('enrollHostname').value.trim(),
            customer_group: document.getElementById('enrollCustomer').value.trim()
        });
        const key = await postJson('api/enrollment_key.php', {firewall_id: created.firewall_id});

        document.getElementById('agentEnrollName').textContent = created.hostname;
        document.getElementById('agentEnrollKey').value = key.enrollment_key;
        document.getElementById('agentEnrollExpires').textContent = new Date(key.expires_at.replace(' ', 'T')).toLocaleString();
        document.getElementById('agentEnrollLink').href = '/firewall_details.php?id=' + encodeURIComponent(created.firewall_id);
        document.getElementById('agentEnrollResult').classList.remove('d-none');
    } catch (e) {
        errorBox.textContent = 'Could not create the enrollment: ' + e.message;
        errorBox.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

function copyAgentEnrollKey(btn) {
    const input = document.getElementById('agentEnrollKey');
    input.select();
    navigator.clipboard.writeText(input.value).catch(() => document.execCommand('copy'));
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
    setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
}

function copyWgetCommand() {
    const input = document.getElementById('wgetCommand');
    input.select();
    input.setSelectionRange(0, 99999);
    document.execCommand('copy');

    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
    btn.classList.remove('btn-success');
    btn.classList.add('btn-success');

    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-success');
    }, 2000);
}

function selectWgetCommand() {
    const input = document.getElementById('wgetCommand');
    input.select();
    input.setSelectionRange(0, 99999);
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
