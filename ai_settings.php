<?php
// Settings > AI Configuration
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
$page_title = "AI Configuration";
include 'inc/header.php';

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_ai_provider'])) {
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
            $stmt->execute([$api_key, $model, $provider]);
            $message = "AI provider updated successfully!";
        } else {
            // Insert new
            $stmt = db()->prepare("INSERT INTO ai_settings (provider, api_key, model) VALUES (?, ?, ?)");
            $stmt->execute([$provider, $api_key, $model]);
            $message = "AI provider added successfully!";
        }
        $message_type = 'success';
    }
    
    if (isset($_POST['edit_provider'])) {
        $provider_id = $_POST['provider_id'];
        $model = $_POST['model'];
        $api_key = $_POST['api_key'];

        $stmt = db()->prepare("UPDATE ai_settings SET model = ?, api_key = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$model, $api_key, $provider_id]);
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
        // Deactivate all providers
        db()->query("UPDATE ai_settings SET is_active = FALSE");
        // Activate only the selected one
        $stmt = db()->prepare("UPDATE ai_settings SET is_active = TRUE WHERE id = ?");
        $stmt->execute([$_POST['provider_id']]);
        $message = "Default AI provider updated successfully!";
        $message_type = 'success';
    }
}

// Get all configured providers
$providers = db()->query("SELECT * FROM ai_settings ORDER BY created_at DESC")->fetchAll();

// Available AI providers and their models
$available_providers = [
    'openai' => [
        'name' => 'OpenAI',
        'models' => ['gpt-4', 'gpt-4-turbo', 'gpt-3.5-turbo'],
        'icon' => 'fa-brain'
    ],
    'anthropic' => [
        'name' => 'Anthropic (Claude)',
        'models' => ['claude-3-opus-20240229', 'claude-3-sonnet-20240229', 'claude-3-haiku-20240307'],
        'icon' => 'fa-robot'
    ],
    'google' => [
        'name' => 'Google (Gemini)',
        'models' => ['gemini-pro', 'gemini-pro-vision'],
        'icon' => 'fa-google'
    ],
    'azure' => [
        'name' => 'Azure OpenAI',
        'models' => ['gpt-4', 'gpt-35-turbo'],
        'icon' => 'fa-cloud'
    ],
    'ollama' => [
        'name' => 'Ollama (Local)',
        'models' => ['llama2', 'mistral', 'codellama'],
        'icon' => 'fa-server'
    ]
];
?>

<style>
body {
    background: #1a1d23;
    color: #e0e0e0;
}
.ai-container {
    max-width: 1200px;
    margin: 30px auto;
    padding: 0 20px;
}
.ai-card {
    background: #2d3139;
    border-radius: 8px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    border: 1px solid #3a3f4b;
}
.ai-card h2 {
    color: #4fc3f7;
    border-bottom: 3px solid #3498db;
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
    background: #1a1d23;
    border: 2px solid #3a3f4b;
    border-radius: 8px;
    padding: 20px;
    transition: all 0.3s;
    position: relative;
}
.provider-card:hover {
    border-color: #3498db;
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
    color: #4fc3f7;
    margin-right: 15px;
}
.provider-name {
    font-size: 18px;
    font-weight: 600;
    color: #e0e0e0;
}
.provider-model {
    color: #95a5a6;
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
    background: #1a1d23;
    border: 1px solid #3a3f4b;
    border-radius: 4px;
    color: #e0e0e0;
    font-size: 14px;
}
.form-control:focus {
    outline: none;
    border-color: #3498db;
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
    background: #2d3139;
    margin: 50px auto;
    padding: 30px;
    max-width: 600px;
    border-radius: 8px;
}
.modal-header {
    color: #4fc3f7;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #3498db;
}
.close-modal {
    float: right;
    font-size: 28px;
    cursor: pointer;
    color: #95a5a6;
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
    background: #1a1d23;
    padding: 10px;
    border-radius: 4px;
    border: 1px solid #3a3f4b;
    word-break: break-all;
}
</style>

<div class="ai-container">
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
            <?php if ($active_provider): ?>
                <p style="margin: 10px 0 0 0; padding-top: 10px; border-top: 1px solid #3a3f4b;">
                    <i class="fa fa-check-circle" style="color: #27ae60;"></i>
                    <strong>Global Default:</strong>
                    <?= htmlspecialchars($available_providers[$active_provider['provider']]['name'] ?? ucfirst($active_provider['provider'])) ?>
                    (<?= htmlspecialchars($active_provider['model']) ?>)
                </p>
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
                        <?= substr($provider['api_key'], 0, 8) ?>...<?= substr($provider['api_key'], -4) ?>
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
                <select name="model" id="model_select" class="form-control" required>
                    <option value="">Select provider first</option>
                </select>
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
                <select name="model" id="edit_model_select" class="form-control" required>
                    <option value="">Select model</option>
                </select>
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
const providerModels = <?= json_encode($available_providers) ?>;

function showAddModal() {
    document.getElementById('addModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function updateModels(select) {
    const provider = select.value;
    const modelSelect = document.getElementById('model_select');
    modelSelect.innerHTML = '<option value="">Select model</option>';

    if (provider && providerModels[provider]) {
        providerModels[provider].models.forEach(model => {
            const option = document.createElement('option');
            option.value = model;
            option.textContent = model;
            modelSelect.appendChild(option);
        });
    }
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

    if (providerInfo && providerInfo.models) {
        providerInfo.models.forEach(model => {
            const option = document.createElement('option');
            option.value = model;
            option.textContent = model;
            if (model === provider.model) {
                option.selected = true;
            }
            editModelSelect.appendChild(option);
        });
    }

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
