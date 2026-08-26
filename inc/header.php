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
    <form class="opnmgr-search me-3" action="/search.php" method="get" autocomplete="off" role="search">
      <div class="position-relative">
        <i class="fas fa-magnifying-glass opnmgr-search-icon"></i>
        <input type="text" name="q" id="globalSearch" class="form-control form-control-sm opnmgr-search-input"
               placeholder="Search fleet&hellip;  customer:Acme  tag:critical  192.168.22.0/24"
               aria-label="Search the managed fleet">
        <div id="globalSearchResults" class="opnmgr-search-results d-none"></div>
      </div>
    </form>

    <div class="dropdown">
      <button class="user-dropdown" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="user-avatar"><?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?></span>
        <span class="user-name"><?php echo htmlentities($_SESSION['username'] ?? '') ?></span>
        <i class="fas fa-chevron-down" style="font-size:0.6rem;color:var(--text-muted)"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li class="dropdown-header small">
          Signed in as <strong><?php echo htmlentities($_SESSION['username'] ?? '') ?></strong><br>
          <span class="badge bg-<?php echo function_exists('role_badge_class') ? role_badge_class(current_role()) : 'secondary'; ?>">
            <?php echo htmlspecialchars(function_exists('role_label') ? role_label(current_role()) : ''); ?>
          </span>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
        <li><a class="dropdown-item" href="/twofactor_setup.php"><i class="fas fa-shield-halved me-2"></i>Two-Factor Auth</a></li>
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
      <a class="sidebar-item <?php echo isActive('search.php') ?>" href="/search.php">
        <span class="sidebar-icon"><i class="fas fa-magnifying-glass"></i></span>
        <span class="sidebar-label">Search</span>
      </a>
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
      <?php if (function_exists('can') && can('alert.view')): ?>
      <a class="sidebar-item <?php echo isActive('incidents.php') ?>" href="/incidents.php">
        <span class="sidebar-icon"><i class="fas fa-bell"></i></span>
        <span class="sidebar-label">Incidents</span>
      </a>
      <?php endif; ?>
      <?php if (function_exists('can') && can('maintenance.manage')): ?>
      <a class="sidebar-item <?php echo isActive('maintenance.php') ?>" href="/maintenance.php">
        <span class="sidebar-icon"><i class="fas fa-screwdriver-wrench"></i></span>
        <span class="sidebar-label">Maintenance</span>
      </a>
      <?php endif; ?>
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
      <?php if (function_exists('can') && can('health.view')): ?>
      <a class="sidebar-item <?php echo isActive('firewall_health.php') ?>" href="/firewall_health.php">
        <span class="sidebar-icon"><i class="fas fa-heart-pulse"></i></span>
        <span class="sidebar-label">Health</span>
      </a>
      <?php endif; ?>
      <?php if (function_exists('can') && can('drift.view')): ?>
      <a class="sidebar-item <?php echo isActive('config_drift.php') ?>" href="/config_drift.php">
        <span class="sidebar-icon"><i class="fas fa-code-compare"></i></span>
        <span class="sidebar-label">Config Drift</span>
      </a>
      <?php endif; ?>
      <?php if (function_exists('can') && can('audit.view')): ?>
      <a class="sidebar-item <?php echo isActive('audit_log.php') ?>" href="/audit_log.php">
        <span class="sidebar-icon"><i class="fas fa-clipboard-list"></i></span>
        <span class="sidebar-label">Audit Log</span>
      </a>
      <?php endif; ?>
      <a class="sidebar-item <?php echo isActive(['documentation.php','doc_viewer.php']) ?>" href="/documentation.php">
        <span class="sidebar-icon"><i class="fas fa-book"></i></span>
        <span class="sidebar-label">Documentation</span>
      </a>
    </div>

    <?php
    // Each entry is gated on the capability it needs rather than on isAdmin(),
    // so a technician sees the operational tools they can actually use and a
    // read-only user is not shown links that would only 403.
    $show_admin_section = function_exists('can') && (
        can('user.manage') || can('settings.manage') || can('command.view')
        || can('health.view') || can('system.maintenance')
    );
    ?>
    <?php if ($show_admin_section): ?>
    <!-- ADMIN / OPERATIONS section -->
    <div class="sidebar-section">
      <div class="sidebar-section-label"><?php echo can('settings.manage') ? 'Admin' : 'Operations'; ?></div>
      <?php if (can('user.manage')): ?>
      <a class="sidebar-item <?php echo isActive('users.php') ?>" href="/users.php">
        <span class="sidebar-icon"><i class="fas fa-users"></i></span>
        <span class="sidebar-label">Users</span>
      </a>
      <?php endif; ?>
      <?php if (can('settings.manage')): ?>
      <a class="sidebar-item <?php echo isActive(['settings.php','branding.php','smtp_settings.php','proxy_settings.php']) ?>" href="/settings.php">
        <span class="sidebar-icon"><i class="fas fa-sliders-h"></i></span>
        <span class="sidebar-label">Settings</span>
      </a>
      <a class="sidebar-item <?php echo isActive(['logs.php','nginx_logs.php']) ?>" href="/logs.php">
        <span class="sidebar-icon"><i class="fas fa-list-alt"></i></span>
        <span class="sidebar-label">Logs</span>
      </a>
      <?php endif; ?>
      <?php if (can('command.view')): ?>
      <a class="sidebar-item <?php echo isActive('admin_queue.php') ?>" href="/admin_queue.php">
        <span class="sidebar-icon"><i class="fas fa-tasks"></i></span>
        <span class="sidebar-label">Queue</span>
      </a>
      <?php endif; ?>
      <?php if (can('health.view')): ?>
      <a class="sidebar-item <?php echo isActive('health_monitor.php') ?>" href="/health_monitor.php">
        <span class="sidebar-icon"><i class="fas fa-heartbeat"></i></span>
        <span class="sidebar-label">Health</span>
      </a>
      <?php endif; ?>
      <?php if (can('system.maintenance')): ?>
      <a class="sidebar-item <?php echo isActive(['system_update.php','updates.php']) ?>" href="/system_update.php">
        <span class="sidebar-icon"><i class="fas fa-sync-alt"></i></span>
        <span class="sidebar-label">Update</span>
      </a>
      <a class="sidebar-item <?php echo isActive('system_backup.php') ?>" href="/system_backup.php">
        <span class="sidebar-icon"><i class="fas fa-database"></i></span>
        <span class="sidebar-label">Backup</span>
      </a>
      <?php endif; ?>
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

<style>
.opnmgr-search { width: 380px; max-width: 38vw; }
.opnmgr-search-input { padding-left: 30px; }
.opnmgr-search-icon {
  position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
  font-size: .75rem; color: var(--text-muted, #9aa0a6); pointer-events: none;
}
.opnmgr-search-results {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 1050;
  max-height: 70vh; overflow-y: auto; border-radius: 8px;
  background: var(--bg-surface, #1a1d23);
  border: 1px solid var(--border, rgba(255,255,255,.12));
  box-shadow: 0 8px 28px rgba(0,0,0,.45);
}
.opnmgr-search-results .sr-group {
  padding: 6px 12px; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em;
  color: var(--text-muted, #9aa0a6); border-bottom: 1px solid var(--border, rgba(255,255,255,.08));
}
.opnmgr-search-results a {
  display: flex; justify-content: space-between; gap: 10px; align-items: center;
  padding: 8px 12px; text-decoration: none; color: inherit; font-size: .85rem;
}
.opnmgr-search-results a:hover, .opnmgr-search-results a.active { background: rgba(255,255,255,.07); }
.opnmgr-search-results .sr-meta { font-size: .75rem; color: var(--text-muted, #9aa0a6); white-space: nowrap; }
.sr-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
@media (max-width: 992px) { .opnmgr-search { display: none; } }
</style>
<script>
(function () {
  var input = document.getElementById('globalSearch');
  var panel = document.getElementById('globalSearchResults');
  if (!input || !panel) return;

  var timer = null, controller = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function hide() { panel.classList.add('d-none'); panel.innerHTML = ''; }

  function render(data) {
    var html = '';
    if (data.customers && data.customers.length) {
      html += '<div class="sr-group">Customers</div>';
      data.customers.forEach(function (c) {
        html += '<a href="' + esc(c.url) + '"><span><i class="fas fa-building me-2"></i>' +
                esc(c.name) + '</span><span class="sr-meta">' + c.count + ' firewalls</span></a>';
      });
    }
    if (data.sites && data.sites.length) {
      html += '<div class="sr-group">Sites</div>';
      data.sites.forEach(function (s) {
        html += '<a href="' + esc(s.url) + '"><span><i class="fas fa-location-dot me-2"></i>' +
                esc(s.customer) + ' / ' + esc(s.name) + '</span><span class="sr-meta">' +
                s.count + ' firewalls</span></a>';
      });
    }
    if (data.firewalls && data.firewalls.length) {
      html += '<div class="sr-group">Firewalls' +
              (data.total > data.firewalls.length ? ' (' + data.firewalls.length + ' of ' + data.total + ')' : '') +
              '</div>';
      data.firewalls.forEach(function (f) {
        var colour = f.status === 'online' ? '#22c55e' : '#ef4444';
        var where = [f.customer, f.site].filter(Boolean).join(' / ');
        html += '<a href="' + esc(f.url) + '"><span><span class="sr-dot" style="background:' + colour + '"></span>' +
                esc(f.hostname) + (where ? ' <span class="sr-meta">' + esc(where) + '</span>' : '') +
                '</span><span class="sr-meta">' + esc(f.wan_ip || '') + '</span></a>';
      });
    }
    if (!html) {
      html = '<div class="sr-group">No matches</div>';
    } else if (data.total > (data.firewalls || []).length) {
      html += '<a href="/search.php?q=' + encodeURIComponent(input.value) +
              '"><span><i class="fas fa-arrow-right me-2"></i>See all ' + data.total + ' results</span></a>';
    }
    panel.innerHTML = html;
    panel.classList.remove('d-none');
  }

  input.addEventListener('input', function () {
    var q = input.value.trim();
    if (timer) clearTimeout(timer);
    if (q.length < 2) { hide(); return; }

    timer = setTimeout(function () {
      // Abort the previous request so a slow early query cannot overwrite the
      // results of a later one.
      if (controller) controller.abort();
      controller = new AbortController();

      fetch('/api/search.php?q=' + encodeURIComponent(q), {
        signal: controller.signal,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) { if (d && d.success) render(d); })
        .catch(function () { /* aborted or offline */ });
    }, 200);
  });

  input.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
  document.addEventListener('click', function (e) {
    if (!panel.contains(e.target) && e.target !== input) hide();
  });

  // "/" focuses search, the way most fleet tools behave.
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
      e.preventDefault();
      input.focus();
    }
  });
})();
</script>
