<?php
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src \'self\' data: https://picsum.photos https://api.qrserver.com https://*.tile.openstreetmap.org; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;');

require_once __DIR__ . '/auth.php';

// Load settings from DB
$brandNameOverride = 'OPNManager';
$logoOverride = '';
$themeOverride = 'dark';

try {
    if (file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
        if (isset($DB)) {
            $rows = $DB->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($rows['brand_name'])) $brandNameOverride = $rows['brand_name'];
            if (!empty($rows['logo'])) $logoOverride = $rows['logo'];
            // Map legacy themes to dark/light
            if (!empty($rows['theme'])) {
                $themeOverride = ($rows['theme'] === 'light' || $rows['theme'] === 'arctic-white') ? 'light' : 'dark';
            }
        }
    }
} catch (Exception $e) {
    // ignore DB errors in header
}

$logged = isLoggedIn();
$brandName = $brandNameOverride;
$theme = $themeOverride;
$logo = $logoOverride;

// Active page detection
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
function isActive($page) {
    global $currentPage;
    if (is_array($page)) return in_array($currentPage, $page) ? 'active' : '';
    return $currentPage === $page ? 'active' : '';
}
?>
<!doctype html>
<html data-theme="<?php echo $theme ?>" data-bs-theme="<?php echo $theme ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlentities($brandName) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="/assets/css/app.css" rel="stylesheet">
  <script src="/assets/js/theme.js"></script>
</head>
<body data-sidebar-pinned="false">

<?php if ($logged): ?>
<!-- ========== TOP HEADER BAR ========== -->
<header class="app-header">
  <div class="header-left">
    <button class="hamburger-btn" id="hamburger-btn" aria-label="Toggle menu">
      <i class="fas fa-bars"></i>
    </button>
    <a class="header-brand" href="/dashboard.php">
      <?php if (!empty($logo) && file_exists('/var/www/opnsense' . $logo)): ?>
        <img src="<?php echo htmlentities($logo) ?>" alt="Logo" class="logo-header">
      <?php else: ?>
        <span class="brand-icon"><i class="fas fa-shield-halved"></i></span>
      <?php endif; ?>
      <span class="brand-name"><?php echo htmlentities($brandName) ?></span>
    </a>
  </div>
  <div class="header-right">
    <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
      <i class="fas fa-sun"></i>
    </button>
    <div class="dropdown">
      <button class="user-dropdown" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="user-avatar"><?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?></span>
        <span class="user-name"><?php echo htmlentities($_SESSION['username'] ?? '') ?></span>
        <i class="fas fa-chevron-down" style="font-size:0.6rem;color:var(--text-muted)"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
      </ul>
    </div>
  </div>
</header>

<!-- ========== COLLAPSIBLE SIDEBAR ========== -->
<aside class="app-sidebar" id="sidebar">
  <div class="sidebar-inner">
    <!-- Pin button -->
    <div class="sidebar-pin-wrap">
      <button class="sidebar-pin" id="sidebar-pin" title="Pin sidebar">
        <i class="fas fa-thumbtack"></i>
      </button>
    </div>

    <!-- MAIN section -->
    <div class="sidebar-section">
      <div class="sidebar-section-label">Main</div>
      <a class="sidebar-item <?php echo isActive('dashboard.php') ?>" href="/dashboard.php">
        <span class="sidebar-icon"><i class="fas fa-chart-pie"></i></span>
        <span class="sidebar-label">Dashboard</span>
      </a>
      <a class="sidebar-item <?php echo isActive(['firewalls.php','firewall_view.php','firewall_details.php','firewall_edit.php']) ?>" href="/firewalls.php">
        <span class="sidebar-icon"><i class="fas fa-network-wired"></i></span>
        <span class="sidebar-label">Firewalls</span>
      </a>
      <a class="sidebar-item <?php echo isActive('customers.php') ?>" href="/customers.php">
        <span class="sidebar-icon"><i class="fas fa-building"></i></span>
        <span class="sidebar-label">Customers</span>
      </a>
      <a class="sidebar-item <?php echo isActive('manage_tags_ui.php') ?>" href="/manage_tags_ui.php">
        <span class="sidebar-icon"><i class="fas fa-tags"></i></span>
        <span class="sidebar-label">Tags</span>
      </a>
      <a class="sidebar-item <?php echo isActive(['add_firewall_page.php','add_firewall.php','enroll_firewall_new.php']) ?>" href="/add_firewall_page.php">
        <span class="sidebar-icon"><i class="fas fa-plus-circle"></i></span>
        <span class="sidebar-label">Add Firewall</span>
      </a>
    </div>

    <!-- MONITORING section -->
    <div class="sidebar-section">
      <div class="sidebar-section-label">Monitoring</div>
      <a class="sidebar-item <?php echo isActive(['alerts.php','alert_history.php']) ?>" href="/alerts.php">
        <span class="sidebar-icon"><i class="fas fa-bell"></i></span>
        <span class="sidebar-label">Alerts</span>
      </a>
      <a class="sidebar-item <?php echo isActive('network_tools.php') ?>" href="/network_tools.php">
        <span class="sidebar-icon"><i class="fas fa-stethoscope"></i></span>
        <span class="sidebar-label">Network Tools</span>
      </a>
      <a class="sidebar-item <?php echo isActive('security_scan.php') ?>" href="/security_scan.php">
        <span class="sidebar-icon"><i class="fas fa-shield-virus"></i></span>
        <span class="sidebar-label">Security Scan</span>
      </a>
    </div>

    <!-- ACCOUNT section -->
    <div class="sidebar-section">
      <div class="sidebar-section-label">Account</div>
      <a class="sidebar-item <?php echo isActive('profile.php') ?>" href="/profile.php">
        <span class="sidebar-icon"><i class="fas fa-user-circle"></i></span>
        <span class="sidebar-label">Profile</span>
      </a>
      <a class="sidebar-item <?php echo isActive('twofactor_setup.php') ?>" href="/twofactor_setup.php">
        <span class="sidebar-icon"><i class="fas fa-mobile-alt"></i></span>
        <span class="sidebar-label">2FA Setup</span>
      </a>
      <a class="sidebar-item <?php echo isActive(['documentation.php','doc_viewer.php']) ?>" href="/documentation.php">
        <span class="sidebar-icon"><i class="fas fa-book"></i></span>
        <span class="sidebar-label">Documentation</span>
      </a>
    </div>

    <?php if (isAdmin()): ?>
    <!-- ADMIN section -->
    <div class="sidebar-section">
      <div class="sidebar-section-label">Admin</div>
      <a class="sidebar-item <?php echo isActive('users.php') ?>" href="/users.php">
        <span class="sidebar-icon"><i class="fas fa-users"></i></span>
        <span class="sidebar-label">Users</span>
      </a>
      <a class="sidebar-item <?php echo isActive(['settings.php','branding.php','smtp_settings.php','proxy_settings.php']) ?>" href="/settings.php">
        <span class="sidebar-icon"><i class="fas fa-sliders-h"></i></span>
        <span class="sidebar-label">Settings</span>
      </a>
      <a class="sidebar-item <?php echo isActive(['logs.php','nginx_logs.php']) ?>" href="/logs.php">
        <span class="sidebar-icon"><i class="fas fa-list-alt"></i></span>
        <span class="sidebar-label">Logs</span>
      </a>
      <a class="sidebar-item <?php echo isActive('admin_queue.php') ?>" href="/admin_queue.php">
        <span class="sidebar-icon"><i class="fas fa-tasks"></i></span>
        <span class="sidebar-label">Queue</span>
      </a>
      <a class="sidebar-item <?php echo isActive('health_monitor.php') ?>" href="/health_monitor.php">
        <span class="sidebar-icon"><i class="fas fa-heartbeat"></i></span>
        <span class="sidebar-label">Health</span>
      </a>
      <a class="sidebar-item <?php echo isActive(['system_update.php','updates.php']) ?>" href="/system_update.php">
        <span class="sidebar-icon"><i class="fas fa-sync-alt"></i></span>
        <span class="sidebar-label">Update</span>
      </a>
      <a class="sidebar-item <?php echo isActive('system_backup.php') ?>" href="/system_backup.php">
        <span class="sidebar-icon"><i class="fas fa-database"></i></span>
        <span class="sidebar-label">Backup</span>
      </a>
      <a class="sidebar-item <?php echo isActive('about.php') ?>" href="/about.php">
        <span class="sidebar-icon"><i class="fas fa-info-circle"></i></span>
        <span class="sidebar-label">About</span>
      </a>
    </div>
    <?php endif; ?>

    <!-- Sidebar footer -->
    <div class="sidebar-footer">
      <a class="sidebar-item" href="/support_project.php">
        <span class="sidebar-icon"><i class="fas fa-heart heart-pulse" style="color:var(--danger)"></i></span>
        <span class="sidebar-label">Support</span>
      </a>
    </div>
  </div>
</aside>

<!-- Sidebar backdrop for mobile -->
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>

<!-- ========== MAIN CONTENT ========== -->
<main class="app-content">

<?php else: ?>
<!-- Not logged in - no sidebar/header, just content -->
<main class="app-content-full">
<?php endif; ?>
