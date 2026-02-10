<?php
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

$notice = '';
$logoUrl = '/assets/img/logo.png';

// Simplified theme presets
$themes = [
    'professional-dark' => [
        'name' => 'Professional Dark',
        'bg' => '#0a0e1a',
        'card' => '#1a1f35',
        'header' => 'linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)',
        'sidebar' => '#0f172a',
        'main' => '#1e293b',
        'text' => '#f1f5f9',
        'muted' => '#94a3b8',
        'border' => 'rgba(255,255,255,0.08)',
        'accent' => '#3b82f6'
    ],
    'ocean-blue' => [
        'name' => 'Ocean Blue',
        'bg' => '#0c1425',
        'card' => '#1e293b',
        'header' => 'linear-gradient(135deg, #06b6d4 0%, #0891b2 100%)',
        'sidebar' => '#1e293b',
        'main' => '#334155',
        'text' => '#f1f5f9',
        'muted' => '#94a3b8',
        'border' => 'rgba(6,182,212,0.2)',
        'accent' => '#06b6d4'
    ]
];

// Load settings
$rows = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$brand = $rows['brand_name'] ?? 'OPNsense Manager';
$theme = $rows['theme'] ?? 'professional-dark';
$login_bg = $rows['login_background'] ?? '';

if (!isset($themes[$theme])) {
    $theme = 'professional-dark';
}
$logo = $rows['logo'] ?? '';

function save_setting($k, $v) {
    $s = db()->prepare('INSERT INTO settings (`name`,`value`) VALUES (:k,:v) ON DUPLICATE KEY UPDATE `value` = :v2');
    $s->execute([':k' => $k, ':v' => $v, ':v2' => $v]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $notice = 'Bad CSRF';
    } else {
        if (!empty($_POST['save_brand'])) {
            $brand = trim($_POST['brand'] ?? $brand);
            $theme = trim($_POST['theme'] ?? $theme);

            save_setting('brand_name', $brand);
            save_setting('theme', $theme);

            if (!empty($_FILES['logo']['tmp_name'])) {
                $allowed = ['image/png','image/jpeg','image/svg+xml'];
                if (in_array($_FILES['logo']['type'], $allowed) && $_FILES['logo']['size'] < 2000000) {
                    if (!is_dir(__DIR__.'/assets/img')) mkdir(__DIR__.'/assets/img', 0755, true);
                    $logo_path = __DIR__.'/assets/img/logo.png';
                    move_uploaded_file($_FILES['logo']['tmp_name'], $logo_path);
                    chown($logo_path, 'www-data');
                    chmod($logo_path, 0644);
                    $logo = '/assets/img/logo.png';
                    save_setting('logo', $logo);
                } else {
                    $notice = 'Logo must be PNG/JPG/SVG and <2MB';
                }
            }
            $notice = 'Branding settings saved successfully.';
        }
    }
}

require_once __DIR__ . '/inc/header.php';
?>

<div class="container">
    <h1>Branding</h1>
    
    <?php if ($notice): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-md-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h5>Branding Settings</h5>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="save_brand" value="1">
                        
                        <div class="mb-3">
                            <label for="brand" class="form-label">Brand Name</label>
                            <input type="text" name="brand" id="brand" class="form-control" 
                                   value="<?php echo htmlspecialchars($brand); ?>" 
                                   placeholder="Enter your brand name">
                            <small class="text-muted">This will replace "OPNsense Manager" in the header</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="theme" class="form-label">Theme</label>
                            <select name="theme" id="theme" class="form-select">
                                <?php foreach($themes as $key => $themeData): ?>
                                    <option value="<?php echo $key; ?>" 
                                            <?php echo ($theme === $key) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($themeData['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="logo" class="form-label">Logo</label>
                            <input type="file" name="logo" id="logo" class="form-control" 
                                   accept="image/png,image/jpeg,image/svg+xml">
                            <small class="text-muted">PNG, JPG, or SVG, max 2MB</small>
                        <div class="mb-3">
                            <label for="login_bg" class="form-label">Login Background Image</label>
                            <input type="file" name="login_bg" id="login_bg" class="form-control" accept="image/png,image/jpeg">
                            <small class="text-muted">PNG or JPG, max 5MB</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="favicon" class="form-label">Favicon</label>
                            <input type="file" name="favicon" id="favicon" class="form-control" accept="image/png,image/ico">
                            <small class="text-muted">PNG or ICO, max 100KB</small>
                        </div>
                            <?php if (!empty($logo) && file_exists(__DIR__.'/assets/img/logo.png')): ?>
                                <div class="mt-2">
                                    <img src="<?php echo htmlspecialchars($logo); ?>" 
                                         alt="Current logo" style="height: 40px;">
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h5>Preview</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong>Brand:</strong> <?php echo htmlspecialchars($brand); ?><br>
                        <strong>Theme:</strong> <?php echo htmlspecialchars($themes[$theme]['name']); ?>
                    </div>
                    <?php if (!empty($logo) && file_exists(__DIR__.'/assets/img/logo.png')): ?>
                        <div class="text-center">
                            <img src="<?php echo htmlspecialchars($logo); ?>" 
                                 alt="Logo preview" style="max-height: 100px;">
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h5>Advanced Options</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="custom_css" class="form-label">Custom CSS</label>
                        <textarea name="custom_css" id="custom_css" class="form-control" rows="6" placeholder="Add custom CSS rules here..."><?php echo htmlspecialchars($rows['custom_css'] ?? ''); ?></textarea>
                        <small class="text-muted">Advanced users only. Custom CSS will be applied globally.</small>
                    </div>
                    
                    <button type="submit" name="save_advanced" class="btn btn-secondary">
                        <i class="fas fa-save me-2"></i>Save Advanced Settings
                    </button>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
