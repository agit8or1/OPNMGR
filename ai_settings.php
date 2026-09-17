<?php
// Settings > AI Configuration
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/secrets.php';
require_once __DIR__ . '/inc/ai_redaction.php';

// Authorise before any output. inc/header.php was included first, so the page
// shell was already on the wire by the time a redirect could be sent.
require_permission('ai.manage');

$page_title = "AI Configuration";

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = 'CSRF validation failed. Please try again.';
        $message_type = 'danger';
    } elseif (isset($_POST['save_ai_provider'])) {
        $provider = $_POST['provider'];
        $api_key = $_POST['api_key'];
        $model = $_POST['model'];
        
        // Check if provider already exists
        $stmt = db()->prepare("SELECT id FROM ai_settings WHERE provider = ?");
        $stmt->execute([$provider]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing
            $stmt = db()->prepare("UPDATE ai_settings SET api_key = ?, model = ?, is_active = TRUE, updated_at = NOW() WHERE provider = ?");
            $stmt->execute([opnmgr_encrypt($api_key), $model, $provider]);
            $message = "AI provider updated successfully!";
        } else {
            // is_active defaults to 1 in the schema and this insert did not
            // override it, so adding a second provider quietly made it active
            // alongside the first. The scan then took `WHERE is_active = TRUE
            // LIMIT 1` with no ordering, and which LLM actually ran was whatever
            // the database happened to return first.
            //
            // The first provider configured becomes the active one because
            // otherwise nothing would be; any later one is added inactive and
            // has to be chosen deliberately.
            $existing = (int) db()->query('SELECT COUNT(*) FROM ai_settings')->fetchColumn();
            $stmt = db()->prepare(
                'INSERT INTO ai_settings (provider, api_key, model, is_active) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$provider, opnmgr_encrypt($api_key), $model, $existing === 0 ? 1 : 0]);
            $message = $existing === 0
                ? 'AI provider added and selected for analysis.'
                : 'AI provider added. Select it above to use it for analysis.';
        }
        $message_type = 'success';
    }
    
    if (isset($_POST['toggle_ai'])) {
        // Enabling AI means customer configuration leaves the building, so it
        // is an explicit acknowledgement rather than a bare toggle.
        $enable = !empty($_POST['ai_enabled']) ? '1' : '0';
        if ($enable === '1' && empty($_POST['ack_disclosure'])) {
            $message = 'Confirm you have read what is transmitted before enabling AI.';
            $message_type = 'warning';
        } else {
            db()->prepare('INSERT INTO settings (`name`,`value`) VALUES ("ai_enabled", ?)
                           ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$enable]);
            audit_log('ai.toggle', [
                'message'  => $enable === '1' ? 'AI analysis enabled' : 'AI analysis disabled',
                'metadata' => ['enabled' => $enable],
            ]);
            $message = $enable === '1'
                ? 'AI analysis enabled. Configurations are redacted before transmission.'
                : 'AI analysis disabled. Nothing is sent to any external provider.';
            $message_type = 'success';
        }
    }

    if (isset($_POST['edit_provider'])) {
        $provider_id = $_POST['provider_id'];
        $model = $_POST['model'];
        $api_key = $_POST['api_key'];

        // Blank means 'leave the stored key alone' - the UI never echoes it back.
        if ($api_key === '') {
            $stmt = db()->prepare("UPDATE ai_settings SET model = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$model, $provider_id]);
        } else {
            $stmt = db()->prepare("UPDATE ai_settings SET model = ?, api_key = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$model, opnmgr_encrypt($api_key), $provider_id]);
        }
        $message = "Provider updated successfully!";
        $message_type = 'success';
    }

    if (isset($_POST['delete_provider'])) {
        $stmt = db()->prepare("DELETE FROM ai_settings WHERE id = ?");
        $stmt->execute([$_POST['provider_id']]);
        $message = "Provider deleted successfully!";
        $message_type = 'success';
    }
    

    if (isset($_POST['set_default_provider'])) {
        $provider_id = (int) ($_POST['provider_id'] ?? 0);
        // Deactivate all providers
        db()->query("UPDATE ai_settings SET is_active = FALSE");
        // Activate only the selected one
        $stmt = db()->prepare("UPDATE ai_settings SET is_active = TRUE WHERE id = ?");
        $stmt->execute([$provider_id]);

        // The model is chosen by the second dropdown beside the provider. Picking
        // a provider and picking which of its models to run are two decisions,
        // and making the second one reachable only through the Edit modal meant
        // the page appeared to offer one choice where it holds two.
        $model = trim((string) ($_POST['model'] ?? ''));
        if ($model !== '') {
            $stmt = db()->prepare("UPDATE ai_settings SET model = ? WHERE id = ?");
            $stmt->execute([$model, $provider_id]);
        }
        $message = "AI provider and model updated successfully!";
        $message_type = 'success';
    }
}

// Get all configured providers
$providers = db()->query("SELECT * FROM ai_settings ORDER BY created_at DESC")->fetchAll();

// Available AI providers, their suggested models, and how to discover the rest.
//
// This was a flat list of model ids that went stale where it stood: it still
// offered gpt-4, claude-3-opus-20240229 and gemini-pro long after those stopped
// being the ones anybody would choose, and offered no way to pick anything else.
// A hardcoded catalogue in a self-hosted product is guaranteed to be wrong
// eventually, so there are three ways to choose a model now:
//
//   1. suggestions  - a short curated list with a note on why you would pick it
//   2. discovery    - "Fetch from provider" asks the provider's own API what it
//                     currently serves, which is always more current than this file
//   3. free text    - any model id can be typed, so a release on the provider's
//                     side never needs a release here
//
// 'suggested' marks the default choice for a firewall configuration review:
// enough reasoning to reason about a rule set, without paying for the top tier.
$available_providers = [
    'openai' => [
        'name' => 'OpenAI',
        'icon' => 'fa-brain',
        'discoverable' => true,
        'models' => [
            ['id' => 'gpt-4o',      'note' => 'current generation, 128K context', 'suggested' => true],
            ['id' => 'gpt-4.1',     'note' => 'larger context, stronger reasoning'],
            ['id' => 'gpt-4-turbo', 'note' => '128K context, 4096 output tokens'],
            ['id' => 'gpt-4',       'note' => 'older, smaller context'],
            ['id' => 'gpt-3.5-turbo', 'note' => 'cheapest, weakest reasoning'],
        ],
        'hint' => 'Use "Fetch from provider" for the current list - OpenAI publishes '
                . 'new models faster than this page can be updated.',
    ],
    'anthropic' => [
        'name' => 'Anthropic (Claude)',
        'icon' => 'fa-robot',
        'discoverable' => true,
        'models' => [
            ['id' => 'claude-opus-5',    'note' => '1M context - strongest reasoning for config review', 'suggested' => true],
            ['id' => 'claude-sonnet-5',  'note' => '1M context - cheaper, still strong'],
            ['id' => 'claude-haiku-4-5', 'note' => '200K context - cheapest, for frequent scans'],
            ['id' => 'claude-opus-4-8',  'note' => 'previous generation'],
            ['id' => 'claude-sonnet-4-6','note' => 'previous generation'],
        ],
        'hint' => 'Model ids carry no date suffix.',
    ],
    'google' => [
        'name' => 'Google (Gemini)',
        'icon' => 'fa-google',
        'discoverable' => false,
        'models' => [
            ['id' => 'gemini-2.5-pro',   'note' => 'strongest reasoning', 'suggested' => true],
            ['id' => 'gemini-2.0-flash', 'note' => 'cheaper, for frequent scans'],
            ['id' => 'gemini-pro',       'note' => 'older generation'],
        ],
        'hint' => 'Check ai.google.dev for the current model ids and enter one below.',
    ],
    'azure' => [
        'name' => 'Azure OpenAI',
        'icon' => 'fa-cloud',
        'discoverable' => false,
        'models' => [
            ['id' => 'gpt-4', 'note' => 'deployment name, not model name'],
            ['id' => 'gpt-35-turbo', 'note' => 'deployment name, not model name'],
        ],
        'hint' => 'Azure uses your deployment name, which is whatever you called it '
                . 'in the portal - type it below rather than picking from this list.',
    ],
    'ollama' => [
        'name' => 'Ollama (Local)',
        'icon' => 'fa-server',
        'discoverable' => true,
        'models' => [
            ['id' => 'llama3', 'note' => 'general purpose'],
            ['id' => 'mistral', 'note' => 'smaller, faster'],
        ],
        'hint' => 'Nothing leaves your network with Ollama. "Fetch from provider" '
                . 'lists what you have actually pulled.',
    ],
];
?>

<style>
.ai-container {
    max-width: 1200px;
    margin: 30px auto;
    padding: 0 20px;
}
.ai-card {
    background: var(--bg-elevated);
    border-radius: 8px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    border: 1px solid var(--border);
}
.ai-card h2 {
    color: var(--accent);
    border-bottom: 3px solid var(--accent);
    padding-bottom: 10px;
    margin-bottom: 20px;
}
.ai-card h3 {
    color: #81c784;
    margin-top: 20px;
    margin-bottom: 15px;
}
.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.provider-card {
    background: var(--bg-surface);
    border: 2px solid var(--border);
    border-radius: 8px;
    padding: 20px;
    transition: all 0.3s;
    position: relative;
}
.provider-card:hover {
    border-color: var(--accent);
    transform: translateY(-2px);
}
.provider-card.active {
    border-color: #27ae60;
}
.provider-card.inactive {
    opacity: 0.6;
}
.provider-header {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}
.provider-icon {
    font-size: 32px;
    color: var(--accent);
    margin-right: 15px;
}
.provider-name {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
}
.provider-model {
    color: var(--text-muted);
    font-size: 14px;
    margin-bottom: 10px;
}
.provider-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 10px;
}
.status-active {
    background: #27ae60;
    color: white;
}
.status-inactive {
    background: #95a5a6;
    color: white;
}
.provider-actions {
    margin-top: 15px;
    display: flex;
    gap: 10px;
}
.btn {
    padding: 8px 16px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-block;
}
.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}
.btn-success {
    background: #27ae60;
    color: white;
}
.btn-success:hover {
    background: #229954;
}
.btn-danger {
    background: #e74c3c;
    color: white;
}
.btn-danger:hover {
    background: #c0392b;
}
.btn-warning {
    background: #f39c12;
    color: white;
}
.btn-warning:hover {
    background: #e67e22;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #81c784;
    font-weight: 600;
}
.form-control {
    width: 100%;
    padding: 12px;
    background: var(--input-bg);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text-primary);
    font-size: 14px;
}
.form-control:focus {
    outline: none;
    border-color: var(--accent);
}
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    z-index: 9999;
}
.modal-content {
    background: var(--bg-elevated);
    margin: 50px auto;
    padding: 30px;
    max-width: 600px;
    border-radius: 8px;
}
.modal-header {
    color: var(--accent);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--accent);
}
.close-modal {
    float: right;
    font-size: 28px;
    cursor: pointer;
    color: var(--text-muted);
}
.close-modal:hover {
    color: #e74c3c;
}
.alert {
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}
.alert-success {
    background: rgba(39, 174, 96, 0.2);
    border: 1px solid #27ae60;
    color: #27ae60;
}
.alert-info {
    background: rgba(52, 152, 219, 0.2);
    border: 1px solid #3498db;
    color: #4fc3f7;
}
.info-box {
    background: rgba(52, 152, 219, 0.1);
    border-left: 4px solid #3498db;
    padding: 15px;
    margin: 20px 0;
    border-radius: 4px;
}
.api-key-display {
    font-family: 'Courier New', monospace;
    background: var(--bg-surface);
    padding: 10px;
    border-radius: 4px;
    border: 1px solid var(--border);
    word-break: break-all;
}
</style>

<?php include __DIR__ . '/inc/header.php'; ?>
<?php $disclosure = ai_disclosure(); $aiOn = ai_enabled(); ?>
<div class="ai-container">
<div class="card mb-3" style="border:1px solid <?php echo $aiOn ? '#f0ad4e' : 'rgba(255,255,255,.12)'; ?>">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <strong class="small"><i class="fa fa-shield-halved me-1"></i>What is transmitted externally</strong>
        <span class="badge bg-<?php echo $aiOn ? 'warning text-dark' : 'secondary'; ?>">
            AI is <?php echo $aiOn ? 'ENABLED' : 'disabled'; ?></span>
    </div>
    <div class="card-body">
        <p class="small mb-3">
            AI features are optional. Configuration search, security checks, health monitoring,
            update management, drift detection, alerting and backups all work with AI switched off.
            <?php if ($disclosure['provider']): ?>
                Analysis currently goes to <strong><?php echo htmlspecialchars($disclosure['provider']); ?></strong><?php
                if ($disclosure['model']): ?> (<?php echo htmlspecialchars($disclosure['model']); ?>)<?php endif; ?>.
            <?php endif; ?>
        </p>
        <div class="row">
            <div class="col-md-6">
                <div class="small fw-bold" style="color:#f0ad4e">Sent to the provider</div>
                <ul class="small mb-0">
                    <?php foreach ($disclosure['sent'] as $item): ?>
                        <li><?php echo htmlspecialchars($item); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="col-md-6">
                <div class="small fw-bold" style="color:#5cb85c">Never sent &mdash; removed before transmission</div>
                <ul class="small mb-0">
                    <?php foreach ($disclosure['never_sent'] as $item): ?>
                        <li><?php echo htmlspecialchars($item); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <form method="post" class="mt-3 d-flex align-items-center gap-3 flex-wrap">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="ai_enabled" id="aiEnabled"
                       value="1" <?php echo $aiOn ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="aiEnabled">Enable AI analysis</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="ack_disclosure" id="ackDisc" value="1">
                <label class="form-check-label small" for="ackDisc">I have read what is transmitted</label>
            </div>
            <button class="btn btn-sm btn-primary" name="toggle_ai">Save</button>
        </form>
        <div class="small text-muted mt-2">
            Redaction is applied to every configuration regardless of this setting and cannot be turned off.
        </div>
    </div>
</div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <i class="fa fa-check-circle me-2"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <div class="ai-card">
        <h2><i class="fa fa-brain me-2"></i> AI Provider Configuration</h2>
        
        <div class="info-box">
            <strong><i class="fa fa-info-circle me-2"></i> About AI Scanning</strong>
            <p style="margin: 10px 0 0 0;">
                Configure AI providers to automatically analyze firewall configurations, identify security concerns,
                and provide recommendations. AI scanning can help detect misconfigurations, suggest improvements,
                and generate comprehensive security reports.
            </p>
            <?php
            $active_provider = null;
            foreach ($providers as $p) {
                if ($p['is_active']) {
                    $active_provider = $p;
                    break;
                }
            }
            ?>
            <?php
            // Which LLM actually runs was decided by a "Set as Default" button on
            // whichever provider card you scrolled to, and with more than one
            // configured it was not obvious - or, before the insert was fixed,
            // reliable - which one that was. It is one control, stated plainly,
            // at the top of the page.
            ?>
            <?php if ($active_provider): ?>
                <div style="margin: 14px 0 0 0; padding-top: 12px; border-top: 1px solid #3a3f4b;">
                    <form method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <label for="active_llm" style="margin:0;font-weight:600;white-space:nowrap;">
                            <i class="fa fa-wand-magic-sparkles me-1" style="color:#27ae60;"></i>
                            LLM used for analysis:
                        </label>
                        <select name="provider_id" id="active_llm" class="form-control"
                                style="max-width:260px;width:auto;" onchange="onActiveProviderChanged(this)">
                            <?php foreach ($providers as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= $p['is_active'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($available_providers[$p['provider']]['name'] ?? ucfirst($p['provider'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="active_model" style="margin:0;font-weight:600;white-space:nowrap;">Model:</label>
                        <select name="model" id="active_model" class="form-control"
                                style="max-width:420px;width:auto;"></select>
                        <button type="submit" name="set_default_provider" class="btn btn-success btn-sm">
                            <i class="fa fa-check-circle me-1"></i> Use this
                        </button>
                        <?php if (count($providers) === 1): ?>
                            <small style="color:#95a5a6;">Add another provider to switch between them.</small>
                        <?php endif; ?>
                    </form>
                </div>
            <?php else: ?>
                <p style="margin: 10px 0 0 0; padding-top: 10px; border-top: 1px solid #3a3f4b; color: #f39c12;">
                    <i class="fa fa-exclamation-triangle"></i>
                    <strong>No default provider set.</strong> Click "Set as Default" on a provider below.
                </p>
            <?php endif; ?>
        </div>
        
        <button class="btn btn-primary" onclick="showAddModal()">
            <i class="fa fa-plus me-2"></i> Add AI Provider
        </button>
        
        <h3>Configured Providers</h3>
        <div class="providers-grid">
            <?php foreach ($providers as $provider): ?>
                <div class="provider-card <?= $provider['is_active'] ? 'active' : 'inactive' ?>">
                    <div class="provider-header">
                        <i class="fa <?= $available_providers[$provider['provider']]['icon'] ?? 'fa-brain' ?> provider-icon"></i>
                        <div>
                            <div class="provider-name"><?= htmlspecialchars($available_providers[$provider['provider']]['name'] ?? ucfirst($provider['provider'])) ?></div>
                            <div class="provider-model">Model: <?= htmlspecialchars($provider['model']) ?></div>
                        </div>
                    </div>
                    <div class="api-key-display">
                        <?php // Masked: the stored key is never sent to the browser. ?>
                        <?= htmlspecialchars(opnmgr_mask_secret(opnmgr_decrypt($provider['api_key']) ?? '')) ?>
                    </div>
                    <span class="provider-status status-<?= $provider['is_active'] ? 'active' : 'inactive' ?>">
                        <?= $provider['is_active'] ? 'DEFAULT PROVIDER' : 'INACTIVE' ?>
                    </span>
                    <div class="provider-actions">
                        <?php if (!$provider['is_active']): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="provider_id" value="<?= $provider['id'] ?>">
                                <button type="submit" name="set_default_provider" class="btn btn-success">
                                    <i class="fa fa-check-circle me-1"></i> Set as Default
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-success" disabled style="opacity: 0.7; cursor: not-allowed;">
                                <i class="fa fa-check-circle me-1"></i> Currently Active
                            </button>
                        <?php endif; ?>
                        <button onclick='showEditModal(<?= json_encode($provider) ?>)' class="btn btn-primary">
                            <i class="fa fa-edit me-1"></i> Edit
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this provider?');">
                            <input type="hidden" name="provider_id" value="<?= $provider['id'] ?>">
                            <button type="submit" name="delete_provider" class="btn btn-danger">
                                <i class="fa fa-trash me-1"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($providers)): ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #95a5a6;">
                    <i class="fa fa-brain" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <p>No AI providers configured yet.</p>
                    <p>Click "Add AI Provider" to get started with AI-powered config scanning.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="ai-card">
        <h2><i class="fa fa-book me-2"></i> Provider Documentation</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
            <div style="background: #1a1d23; padding: 15px; border-radius: 6px;">
                <h4 style="color: #4fc3f7; margin-top: 0;">OpenAI</h4>
                <p style="font-size: 13px; color: #95a5a6;">Get API key: <a href="https://platform.openai.com/api-keys" target="_blank" style="color: #3498db;">platform.openai.com</a></p>
            </div>
            <div style="background: #1a1d23; padding: 15px; border-radius: 6px;">
                <h4 style="color: #4fc3f7; margin-top: 0;">Anthropic Claude</h4>
                <p style="font-size: 13px; color: #95a5a6;">Get API key: <a href="https://console.anthropic.com/" target="_blank" style="color: #3498db;">console.anthropic.com</a></p>
            </div>
            <div style="background: #1a1d23; padding: 15px; border-radius: 6px;">
                <h4 style="color: #4fc3f7; margin-top: 0;">Google Gemini</h4>
                <p style="font-size: 13px; color: #95a5a6;">Get API key: <a href="https://makersuite.google.com/app/apikey" target="_blank" style="color: #3498db;">makersuite.google.com</a></p>
            </div>
            <div style="background: #1a1d23; padding: 15px; border-radius: 6px;">
                <h4 style="color: #4fc3f7; margin-top: 0;">Ollama (Local)</h4>
                <p style="font-size: 13px; color: #95a5a6;">Self-hosted: <a href="https://ollama.ai/" target="_blank" style="color: #3498db;">ollama.ai</a></p>
            </div>
        </div>
    </div>
</div>

<!-- Add Provider Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
            <h3>Add AI Provider</h3>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>Provider *</label>
                <select name="provider" class="form-control" required onchange="updateModels(this)">
                    <option value="">Select Provider</option>
                    <?php foreach ($available_providers as $key => $provider): ?>
                        <option value="<?= $key ?>"><?= htmlspecialchars($provider['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Model *</label>
                <select id="model_select" class="form-control" onchange="onModelPicked(this)">
                    <option value="">Select provider first</option>
                </select>
                <div style="display:flex;gap:8px;align-items:center;margin-top:8px;">
                    <button type="button" class="btn btn-secondary btn-sm" id="fetch_models_btn"
                            onclick="fetchModels()" disabled>
                        <i class="fa fa-rotate me-1"></i> Fetch from provider
                    </button>
                    <small id="model_hint" style="color:#95a5a6;"></small>
                </div>
                <?php // Free text so a model released tomorrow needs no code change. ?>
                <input type="text" name="model" id="model_input" class="form-control"
                       style="margin-top:8px;" required
                       placeholder="Model id - pick above, fetch, or type one">
                <small style="color:#95a5a6;display:block;margin-top:4px;">
                    The list is a starting point. Any model id the provider accepts will work.
                </small>
            </div>
            <div class="form-group">
                <label>API Key *</label>
                <input type="text" name="api_key" class="form-control" required placeholder="sk-...">
                <small style="color: #95a5a6; display: block; margin-top: 5px;">
                    Your API key is stored securely and never shared.
                </small>
            </div>
            <button type="submit" name="save_ai_provider" class="btn btn-primary">
                <i class="fa fa-save me-2"></i> Save Provider
            </button>
        </form>
    </div>
</div>

<!-- Edit Provider Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
            <h3>Edit AI Provider</h3>
        </div>
        <form method="POST">
            <input type="hidden" name="provider_id" id="edit_provider_id">
            <div class="form-group">
                <label>Provider</label>
                <input type="text" id="edit_provider_name" class="form-control" readonly style="background: #15181e; opacity: 0.8;">
            </div>
            <div class="form-group">
                <label>Model *</label>
                <select id="edit_model_select" class="form-control" onchange="onEditModelPicked(this)">
                    <option value="">Select a model</option>
                </select>
                <input type="text" name="model" id="edit_model_input" class="form-control"
                       style="margin-top:8px;" required
                       placeholder="Model id - pick above or type one">
                <small style="color:#95a5a6;display:block;margin-top:4px;">
                    Any model id the provider accepts will work, including one newer than this list.
                </small>
            </div>
            <div class="form-group">
                <label>API Key *</label>
                <input type="text" name="api_key" id="edit_api_key" class="form-control" required placeholder="sk-...">
                <small style="color: #95a5a6; display: block; margin-top: 5px;">
                    Your API key is stored securely and never shared.
                </small>
            </div>
            <button type="submit" name="edit_provider" class="btn btn-primary">
                <i class="fa fa-save me-2"></i> Update Provider
            </button>
        </form>
    </div>
</div>

<script>
const providerModels = <?= json_encode($available_providers, JSON_UNESCAPED_SLASHES) ?>;
// The rows that exist, so the model dropdown beside the provider can be filled
// from the catalogue for whichever provider is selected.
const configuredProviders = <?= json_encode(array_map(static function (array $row): array {
    return ['id' => (int)$row['id'], 'provider' => $row['provider'], 'model' => $row['model']];
}, $providers), JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';

// Fill the model dropdown for the selected provider: the curated suggestions for
// its type, plus whatever that row is configured with - which may be a model the
// catalogue has never heard of, and must not be silently dropped from the list
// that claims to show what is in use.
function onActiveProviderChanged(select) {
    const row = configuredProviders.find(r => String(r.id) === String(select.value));
    const modelSelect = document.getElementById('active_model');
    if (!row || !modelSelect) { return; }

    modelSelect.innerHTML = '';
    const seen = new Set();
    const add = (id, label, group) => {
        if (!id || seen.has(id)) { return; }
        seen.add(id);
        const option = document.createElement('option');
        option.value = id;
        option.textContent = label;
        if (id === row.model) { option.selected = true; }
        (group || modelSelect).appendChild(option);
    };

    add(row.model, row.model + '  - in use');

    const entry = providerModels[row.provider];
    const suggestions = (entry && entry.models) || [];
    if (suggestions.length) {
        const group = document.createElement('optgroup');
        group.label = 'Suggested';
        suggestions.forEach(model => add(
            model.id,
            model.id + (model.suggested ? '  - recommended' : '') + (model.note ? '  (' + model.note + ')' : ''),
            group
        ));
        if (group.children.length) { modelSelect.appendChild(group); }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('active_llm');
    if (select) { onActiveProviderChanged(select); }
});

function showAddModal() {
    document.getElementById('addModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// The catalogue is a suggestion, not a constraint: the select fills the free-text
// field, "Fetch from provider" replaces the suggestions with what the provider
// actually serves, and either can be overtyped.
function updateModels(select) {
    const provider = select.value;
    const modelSelect = document.getElementById('model_select');
    const hint = document.getElementById('model_hint');
    const fetchBtn = document.getElementById('fetch_models_btn');

    modelSelect.innerHTML = '<option value="">Select a model</option>';
    hint.textContent = '';
    fetchBtn.disabled = true;

    if (!provider || !providerModels[provider]) {
        return;
    }

    const entry = providerModels[provider];
    const group = document.createElement('optgroup');
    group.label = 'Suggested';

    (entry.models || []).forEach(model => {
        const option = document.createElement('option');
        option.value = model.id;
        option.textContent = model.id
            + (model.suggested ? '  - recommended' : '')
            + (model.note ? '  (' + model.note + ')' : '');
        if (model.suggested) {
            option.selected = true;
            document.getElementById('model_input').value = model.id;
        }
        group.appendChild(option);
    });
    modelSelect.appendChild(group);

    hint.textContent = entry.hint || '';
    fetchBtn.disabled = !entry.discoverable;
    if (!entry.discoverable) {
        fetchBtn.title = 'This provider does not publish a model list';
    }
}

function onEditModelPicked(select) {
    if (select.value) {
        document.getElementById('edit_model_input').value = select.value;
    }
}

function onModelPicked(select) {
    if (select.value) {
        document.getElementById('model_input').value = select.value;
    }
}

function fetchModels() {
    const provider = document.querySelector('select[name="provider"]').value;
    const btn = document.getElementById('fetch_models_btn');
    const hint = document.getElementById('model_hint');
    if (!provider) { return; }

    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Fetching...';
    hint.textContent = '';

    fetch('/api/ai_models.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                   'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ provider: provider, csrf: csrfToken })
    })
    .then(r => r.status === 401
        ? Promise.reject(new Error('Your session has expired. Sign in again.'))
        : r.json())
    .then(data => {
        if (!data.success) {
            hint.textContent = data.error || 'Could not list models.';
            return;
        }
        const select = document.getElementById('model_select');
        const group = document.createElement('optgroup');
        group.label = 'Available from provider (' + data.count + ')';
        data.models.forEach(id => {
            const o = document.createElement('option');
            o.value = id; o.textContent = id;
            group.appendChild(o);
        });
        select.appendChild(group);
        hint.textContent = data.count + ' model(s) returned by the provider.';
    })
    .catch(err => { hint.textContent = err.message || 'Could not reach the provider.'; })
    .finally(() => { btn.disabled = false; btn.innerHTML = original; });
}

function showEditModal(provider) {
    // Set provider ID
    document.getElementById('edit_provider_id').value = provider.id;

    // Set provider name (read-only)
    const providerInfo = providerModels[provider.provider];
    document.getElementById('edit_provider_name').value = providerInfo ? providerInfo.name : provider.provider;

    // Populate model dropdown with available models for this provider
    const editModelSelect = document.getElementById('edit_model_select');
    editModelSelect.innerHTML = '<option value="">Select model</option>';

    // The configured model may not be in the suggestions at all - it could predate
    // them or postdate them - so the text field holds the truth and the list is
    // only a shortcut.
    if (providerInfo && providerInfo.models) {
        providerInfo.models.forEach(model => {
            const option = document.createElement('option');
            option.value = model.id;
            option.textContent = model.id
                + (model.suggested ? '  - recommended' : '')
                + (model.note ? '  (' + model.note + ')' : '');
            if (model.id === provider.model) {
                option.selected = true;
            }
            editModelSelect.appendChild(option);
        });
    }
    document.getElementById('edit_model_input').value = provider.model || '';

    // Set API key
    document.getElementById('edit_api_key').value = provider.api_key;

    // Show modal
    document.getElementById('editModal').style.display = 'block';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php include 'inc/footer.php'; ?>
