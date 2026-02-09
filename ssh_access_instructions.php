<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();
requireAdmin();
include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="fas fa-key me-2"></i>SSH Access Setup</h1>
                <a href="/firewalls.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Firewalls
                </a>
            </div>

            <div class="alert alert-info">
                <h5><i class="fas fa-info-circle me-2"></i>Why SSH Access?</h5>
                <p class="mb-0">SSH access allows OPNManager to automatically repair agents, run remote commands, and manage firewalls without manual intervention.</p>
            </div>

            <div class="card card-dark">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Security Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-success">
                        <strong><i class="fas fa-check me-2"></i>SSH Keys Already Configured</strong>
                        <p class="mb-0 mt-2">During enrollment, firewalls automatically receive the server's SSH public key. No password required!</p>
                    </div>

                    <h6 class="text-white mt-4 mb-3">Server Information:</h6>
                    <div class="bg-dark p-3 rounded border border-secondary">
                        <div class="row">
                            <div class="col-md-4">
                                <strong class="text-white">Server IP:</strong>
                            </div>
                            <div class="col-md-8">
                                <code class="text-info">184.175.206.229</code>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-4">
                                <strong class="text-white">SSH Public Key:</strong>
                            </div>
                            <div class="col-md-8">
                                <code class="text-info" style="word-break: break-all;">ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOSF41zYTRe76rGOj6Q21S2UJPGMaQy2Fx2RfEDYShkU</code>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-dark mt-4">
                <div class="card-header bg-warning">
                    <h5 class="mb-0 text-white"><i class="fas fa-exclamation-triangle me-2"></i>Manual Step Required</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <strong>Firewall Rule Must Be Added Manually</strong>
                        <p class="mb-0 mt-2">For each firewall, you must create a firewall rule to allow SSH from the OPNManager server.</p>
                    </div>

                    <h6 class="text-white mb-3">Step-by-Step Instructions:</h6>

                    <div class="accordion" id="setupAccordion">
                        <div class="accordion-item bg-dark border-secondary">
                            <h2 class="accordion-header">
                                <button class="accordion-button bg-secondary text-white" type="button" data-bs-toggle="collapse" data-bs-target="#step1">
                                    <strong>Step 1:</strong>&nbsp;Access Firewall Rules
                                </button>
                            </h2>
                            <div id="step1" class="accordion-collapse collapse show">
                                <div class="accordion-body bg-dark text-light">
                                    <ol>
                                        <li>Log into your OPNsense firewall web UI</li>
                                        <li>Navigate to: <code class="text-info">Firewall → Rules → WAN</code></li>
                                        <li>Click the <button class="btn btn-sm btn-success"><i class="fas fa-plus"></i></button> button to add a new rule</li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item bg-dark border-secondary mt-2">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed bg-secondary text-white" type="button" data-bs-toggle="collapse" data-bs-target="#step2">
                                    <strong>Step 2:</strong>&nbsp;Configure Rule Settings
                                </button>
                            </h2>
                            <div id="step2" class="accordion-collapse collapse">
                                <div class="accordion-body bg-dark text-light">
                                    <table class="table table-dark table-bordered">
                                        <tbody>
                                            <tr>
                                                <td><strong>Action:</strong></td>
                                                <td><span class="badge bg-success">Pass</span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Interface:</strong></td>
                                                <td><code class="text-info">WAN</code></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Protocol:</strong></td>
                                                <td><code class="text-info">TCP</code></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Source Type:</strong></td>
                                                <td><code class="text-info">Single host or Network</code></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Source:</strong></td>
                                                <td><code class="text-warning">184.175.206.229</code> (OPNManager server)</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Destination:</strong></td>
                                                <td><code class="text-info">WAN address</code> or <code class="text-info">This Firewall</code></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Destination Port:</strong></td>
                                                <td><code class="text-info">22 (SSH)</code></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Description:</strong></td>
                                                <td><code class="text-info">OPNManager Remote Access</code></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item bg-dark border-secondary mt-2">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed bg-secondary text-white" type="button" data-bs-toggle="collapse" data-bs-target="#step3">
                                    <strong>Step 3:</strong>&nbsp;Save and Apply
                                </button>
                            </h2>
                            <div id="step3" class="accordion-collapse collapse">
                                <div class="accordion-body bg-dark text-light">
                                    <ol>
                                        <li>Click <button class="btn btn-sm btn-primary">Save</button> at the bottom of the rule form</li>
                                        <li>Click <button class="btn btn-sm btn-success">Apply Changes</button> at the top of the page</li>
                                        <li>The rule is now active!</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-4">
                        <h6><i class="fas fa-lightbulb me-2"></i>Testing Connection</h6>
                        <p>After adding the rule, test SSH access from this server:</p>
                        <pre class="bg-dark p-3 border border-secondary rounded text-light mb-0"><code>sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_ID root@FIREWALL_IP 'echo "Working"'</code></pre>
                    </div>
                </div>
            </div>

            <div class="card card-dark mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-question-circle me-2"></i>Troubleshooting</h5>
                </div>
                <div class="card-body">
                    <h6 class="text-white">Common Issues:</h6>

                    <div class="mb-3">
                        <strong class="text-warning">Connection Timeout:</strong>
                        <ul class="text-light">
                            <li>Firewall rule not created yet</li>
                            <li>Rule is on wrong interface (should be WAN)</li>
                            <li>Firewall is offline or unreachable</li>
                        </ul>
                    </div>

                    <div class="mb-3">
                        <strong class="text-warning">Connection Refused:</strong>
                        <ul class="text-light">
                            <li>SSH service not enabled on firewall</li>
                            <li>Go to: System → Settings → Administration → Enable Secure Shell</li>
                        </ul>
                    </div>

                    <div class="mb-3">
                        <strong class="text-warning">Permission Denied:</strong>
                        <ul class="text-light">
                            <li>SSH key not in authorized_keys</li>
                            <li>Re-run enrollment script to add key</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
