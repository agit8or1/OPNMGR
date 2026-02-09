<?php
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src \'self\' data: https://picsum.photos https://api.qrserver.com https://*.tile.openstreetmap.org; connect-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;');

require_once __DIR__ . '/auth.php';
# try to load settings
$cssOverrides = [];
$brandNameOverride = 'OPNsense Manager';
$logoOverride = '';
$themeOverride = 'professional-dark';

try {
    if (file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
        if (isset($DB)) {
            $rows = $DB->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($rows['brand_name'])) $brandNameOverride = $rows['brand_name'];
            if (!empty($rows['theme'])) $themeOverride = $rows['theme'];
            if (!empty($rows['logo'])) $logoOverride = $rows['logo'];
        }
    }
} catch (Exception $e) {
    # ignore DB errors in header
}

$logged = isLoggedIn();
$brandName = $brandNameOverride;
$theme = $themeOverride;
$logo = $logoOverride ?: 'https://picsum.photos/120/32?random=1';

// Professional theme presets with complete color schemes
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
    ],
    'sunset-orange' => [
        'name' => 'Sunset Orange',
        'bg' => '#1a0f0a',
        'card' => '#2d1810',
        'header' => 'linear-gradient(135deg, #ea580c 0%, #dc2626 100%)',
        'sidebar' => '#2d1810',
        'main' => '#451a03',
        'text' => '#fef3c7',
        'muted' => '#d97706',
        'border' => 'rgba(234,88,12,0.2)',
        'accent' => '#ea580c'
    ],
    'forest-green' => [
        'name' => 'Forest Green',
        'bg' => '#0a1a0f',
        'card' => '#1a2e1a',
        'header' => 'linear-gradient(135deg, #16a34a 0%, #15803d 100%)',
        'sidebar' => '#1a2e1a',
        'main' => '#14532d',
        'text' => '#dcfce7',
        'muted' => '#86efac',
        'border' => 'rgba(22,163,74,0.2)',
        'accent' => '#16a34a'
    ],
    'royal-purple' => [
        'name' => 'Royal Purple',
        'bg' => '#1a0a2e',
        'card' => '#2d1b69',
        'header' => 'linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)',
        'sidebar' => '#2d1b69',
        'main' => '#3730a3',
        'text' => '#e9d5ff',
        'muted' => '#c4b5fd',
        'border' => 'rgba(139,92,246,0.2)',
        'accent' => '#8b5cf6'
    ],
    'midnight-slate' => [
        'name' => 'Midnight Slate',
        'bg' => '#0f172a',
        'card' => '#1e293b',
        'header' => 'linear-gradient(135deg, #475569 0%, #334155 100%)',
        'sidebar' => '#1e293b',
        'main' => '#334155',
        'text' => '#f1f5f9',
        'muted' => '#94a3b8',
        'border' => 'rgba(71,85,105,0.2)',
        'accent' => '#475569'
    ],
    'sunrise-pink' => [
        'name' => 'Sunrise Pink',
        'bg' => '#2d1b2d',
        'card' => '#4c1d4c',
        'header' => 'linear-gradient(135deg, #ec4899 0%, #db2777 100%)',
        'sidebar' => '#4c1d4c',
        'main' => '#831843',
        'text' => '#fce7f3',
        'muted' => '#f9a8d4',
        'border' => 'rgba(236,72,153,0.2)',
        'accent' => '#ec4899'
    ],
    'arctic-white' => [
        'name' => 'Arctic White',
        'bg' => '#f8fafc',
        'card' => '#ffffff',
        'header' => 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)',
        'sidebar' => '#f1f5f9',
        'main' => '#e2e8f0',
        'text' => '#1e293b',
        'muted' => '#64748b',
        'border' => 'rgba(0,0,0,0.06)',
        'accent' => '#3b82f6'
    ]
];

$currentTheme = $themes[$theme] ?? $themes['professional-dark'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlentities($brandName) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css?v=1.4" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css?v=1.4" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap&v=1.4" rel="stylesheet">
  <style>
    :root{
        --bg:<?php echo $currentTheme['bg'] ?>;
        --card:<?php echo $currentTheme['card'] ?>;
        --muted:<?php echo $currentTheme['muted'] ?>;
        --text:<?php echo $currentTheme['text'] ?>;
        --border:<?php echo $currentTheme['border'] ?>;
        --header-bg:<?php echo $currentTheme['header'] ?>;
        --sidebar-bg:<?php echo $currentTheme['sidebar'] ?>;
        --main-bg:<?php echo $currentTheme['main'] ?>;
        --accent:<?php echo $currentTheme['accent'] ?>;
    }
    html,body{
        height:100%;
        background:linear-gradient(180deg,var(--bg) 0%,var(--bg) 100%);
        font-family:Inter,system-ui,Segoe UI,Roboto,"Helvetica Neue",Arial;
        color:var(--text);
    }
    .card-ghost{background:transparent;border:1px solid var(--border);padding:8px}
    .card-dark{background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));color:var(--text);border:1px solid var(--border);padding:12px;margin-bottom:12px}
    .nav-brand{font-weight:700;color:#fff;font-size:1.05rem}
    .muted{color:var(--muted);font-size:0.9rem}
    .btn-outline-light{border-color:rgba(255,255,255,0.06);color:#dce9f3;padding:.32rem .6rem}
    /* Sidebar dropdown button hover - using onmouseover/out for guaranteed effect */
    a.text-muted:hover{color:#fff}
    .sidebar{min-height:calc(100vh - 76px);padding-top:.6rem;background:var(--sidebar-bg);position:relative;overflow:visible}
    .list-group-item{background:transparent;border:0;color:#cbd7e6;padding:.45rem .6rem}
    .list-group-item.active{background:var(--card);color:#fff;font-weight:600}
    .nav-gradient{background:var(--header-bg);box-shadow:0 3px 12px rgba(0,0,0,0.28)}
    .brand-badge{background:rgba(255,255,255,0.06);padding:4px 8px;border-radius:6px}
    .logo-header{max-height:32px;max-width:120px;object-fit:contain}
    @media (max-width:767px){.sidebar{display:none}}
    .modal-pre { white-space:pre-wrap; font-family:monospace; font-size:0.85rem }
    
    /* Dropdown fixes */
    .dropdown-toggle::after { margin-left: auto; }
    .dropdown-menu { background-color: var(--card); border: 1px solid var(--border); z-index: 9999!important; position: absolute!important; }
    .dropdown-item { color: var(--text); }
    .dropdown-item:hover { background-color: rgba(138,180,248,0.2)!important; color: #8ab4f8!important; box-shadow: 0 0 10px rgba(138,180,248,0.3)!important; transform: translateX(2px); transition: all 0.2s ease-in-out; }
    
    /* Awaiting Agent Data styling - more noticeable */
    .awaiting-data { 
        color: #ffa726 !important; 
        font-style: italic; 
        opacity: 0.85;
        font-weight: 500;
    }
    
    /* Form elements dark theme fixes */
    select.form-select, select.form-control, input.form-control, textarea.form-control {
        background-color: rgba(255,255,255,0.15)!important;
        border-color: rgba(255,255,255,0.25)!important;
        color: #fff!important;
    }
    select.form-select option, select.form-control option {
        background-color: #1a2332!important;
        color: #fff!important;
    }
    select.form-select:focus, input.form-control:focus, textarea.form-control:focus {
        background-color: rgba(255,255,255,0.20)!important;
        border-color: rgba(255,255,255,0.35)!important;
        color: #fff!important;
    }
    /* Removed global form-label color - let page-specific inline styles work */
    /* label.form-label { color: #cbd7e6!important; } */
    .card-ghost { background-color: rgba(255,255,255,0.03)!important; }
    
    /* Dropdown button hover states - Administration menu - ULTRA SPECIFIC */
    .sidebar .list-group-item .admin-dropdown-btn:hover,
    .sidebar .list-group-item button.dropdown-toggle:hover,
    .sidebar .list-group-item .btn-outline-secondary:hover,
    .sidebar .admin-dropdown-btn:hover,
    .sidebar button.dropdown-toggle:hover,
    .sidebar .btn-outline-secondary:hover,
    button.admin-dropdown-btn:hover,
    .list-group-item .dropdown button:hover {
        background-color: rgba(138,180,248,0.2)!important;
        border-color: rgba(138,180,248,0.6)!important;
        color: #8ab4f8!important;
        box-shadow: 0 0 10px rgba(138,180,248,0.3)!important;
        transform: translateX(2px)!important;
        transition: all 0.2s ease-in-out!important;
    }
    
    /* Firewall table optimization for better visibility without horizontal scroll */
    .table-compact {
        font-size: 0.75rem; /* Smaller font for better fit */
        line-height: 1.2;
    }
    .table-compact th {
        font-size: 0.7rem;
        font-weight: 600;
        padding: 0.4rem 0.25rem;
        white-space: nowrap;
        vertical-align: middle;
    }
    .table-compact td {
        padding: 0.4rem 0.25rem;
        vertical-align: middle;
        max-width: 120px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .table-compact .col-name { max-width: 140px; }
    .table-compact .col-ip { max-width: 100px; }
    .table-compact .col-customer { max-width: 80px; }
    .table-compact .col-version { max-width: 90px; }
    .table-compact .col-tags { max-width: 100px; }
    .table-compact .col-checkin { max-width: 70px; }
    .table-compact .col-uptime { max-width: 80px; }
    .table-compact .col-status { max-width: 60px; }
    .table-compact .col-actions { max-width: 80px; }
    
    /* Badge adjustments for compact view */
    .table-compact .badge {
        font-size: 0.6rem;
        padding: 0.15rem 0.3rem;
    }
    
    /* Button adjustments for compact view */
    .table-compact .btn-sm {
        font-size: 0.65rem;
        padding: 0.15rem 0.3rem;
        margin: 0.05rem;
    }
    
    /* Hover tooltip styles */
    .hover-tooltip {
        position: relative;
        cursor: help;
    }
    /* Disable CSS tooltips to prevent duplicates - using JavaScript tooltips instead */
    .hover-tooltip:hover::after {
        display: none;
    }
    .hover-tooltip:hover::before {
        display: none;
    }
    
    /* Better tooltip positioning with JavaScript */
    .tooltip-container {
        position: relative;
        overflow: visible !important;
    }
    
    /* Awaiting data warning */
    .awaiting-data {
        color: #ff4444 !important;
        font-weight: bold;
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.7; }
        100% { opacity: 1; }
    }

    /* Pulsing heart icon animation */
    @keyframes heart-pulse {
        0% { transform: scale(1); }
        25% { transform: scale(1.15); }
        50% { transform: scale(1); }
        75% { transform: scale(1.15); }
        100% { transform: scale(1); }
    }
    .heart-pulse {
        display: inline-block;
        animation: heart-pulse 1.5s ease-in-out infinite;
    }

    /* Pagination styling */
    .pagination-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 1rem;
        padding: 1rem;
        background: var(--card);
        border-radius: 8px;
        border: 1px solid var(--border);
    }
    .pagination-info {
        color: var(--muted);
        font-size: 0.85rem;
    }
    .page-size-selector select {
        background: var(--sidebar-bg);
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 4px;
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
    }
  </style>
</head>
<body>
<nav class="navbar navbar-expand nav-gradient navbar-dark px-3" style="height:64px">
  <a class="navbar-brand nav-brand d-flex align-items-center" href="/">
    <?php if (!empty($logo) && file_exists('/var/www/opnsense' . $logo)): ?>
      <img src="<?php echo htmlentities($logo) ?>" alt="Logo" class="logo-header me-2">
    <?php else: ?>
      <span class="brand-badge me-2"><i class="fa fa-shield-halved"></i></span>
    <?php endif; ?>
    <span class="nav-brand"><?php echo htmlentities($brandName) ?></span>
  </a>
  <div class="collapse navbar-collapse justify-content-end">
    <ul class="navbar-nav">
      <?php if ($logged): ?>
        <li class="nav-item d-flex align-items-center"><span class="nav-link muted">Signed in as <strong class="ms-1"><?php echo htmlentities($_SESSION['username'] ?? '') ?></strong></span></li>
        <li class="nav-item"><a class="nav-link" href="/logout.php"><i class="fa fa-sign-out-alt"></i> Logout</a></li>
      <?php else: ?>
        <li class="nav-item"><a class="nav-link" href="/login.php"><i class="fa fa-sign-in-alt"></i> Login</a></li>
      <?php endif; ?>
    </ul>
  </div>
</nav>
<div class="container-fluid mt-4">
  <div class="row">
    <?php if ($logged): ?>
    <div class="col-md-3 sidebar">
      <div class="list-group">
        <a class="list-group-item list-group-item-action active" href="/dashboard.php"><i class="fa fa-chart-pie me-2"></i> Dashboard</a>
        <a class="list-group-item list-group-item-action" href="/firewalls.php"><i class="fa fa-network-wired me-2"></i> Firewalls</a>
        <a class="list-group-item list-group-item-action" href="/customers.php"><i class="fa fa-building me-2"></i> Customers</a>
        <a class="list-group-item list-group-item-action" href="/manage_tags_ui.php"><i class="fa fa-tags me-2"></i> Manage Tags</a>
        <a class="list-group-item list-group-item-action" href="/add_firewall_page.php"><i class="fa fa-plus me-2"></i> Add Firewall</a>
        <a class="list-group-item list-group-item-action" href="/profile.php"><i class="fa fa-user-circle me-2"></i> My Profile</a>
        <a class="list-group-item list-group-item-action" href="/twofactor_setup.php"><i class="fa fa-mobile-alt me-2"></i> 2FA Setup</a>
        <a class="list-group-item list-group-item-action" href="/documentation.php"><i class="fa fa-book me-2"></i> User Documentation</a>
        <a class="list-group-item list-group-item-action" href="/about.php"><i class="fa fa-info-circle me-2"></i> About</a>

        <?php if (isAdmin()): ?>
        <div class="list-group-item border-0 px-0">
          <div class="dropdown">
            <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start admin-dropdown-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="fa fa-cog me-2"></i> Administration
            </button>
            <ul class="dropdown-menu w-100">
              <!-- System Administration -->
              <li><a class="dropdown-item" href="/users.php"><i class="fa fa-users me-2"></i> Users</a></li>
              <li><a class="dropdown-item" href="/settings.php"><i class="fa fa-sliders-h me-2"></i> General Settings</a></li>
              <li><a class="dropdown-item" href="/logs.php"><i class="fa fa-list-alt me-2"></i> System Logs</a></li>
              <li><hr class="dropdown-divider"></li>
              <!-- Queue & Health Management -->
              <li><a class="dropdown-item" href="/admin_queue.php"><i class="fa fa-tasks me-2"></i> Queue Manage</a></li>
              <li><a class="dropdown-item" href="/health_monitor.php"><i class="fa fa-heartbeat me-2"></i> Health Report</a></li>
              <li><hr class="dropdown-divider"></li>
              <!-- System & Support -->
              <li><a class="dropdown-item" href="/system_update.php"><i class="fa fa-sync-alt me-2"></i> System Update</a></li>
              <li><a class="dropdown-item" href="/support.php"><i class="fa fa-life-ring me-2"></i> Support</a></li>
            </ul>
          </div>
        </div>
        <?php endif; ?>

        <!-- Support This Project - Bottom of sidebar -->
        <div class="list-group-item border-0 px-0 mt-3">
          <a href="/support_project.php" class="btn btn-outline-danger w-100" style="border: 2px solid #dc3545; font-weight: 600;">
            <i class="fa fa-heart me-2 heart-pulse"></i>Support This Project
          </a>
        </div>
      </div>
    </div>
    <div class="col-md-9">
    <?php else: ?>
    <div class="col-12">
    <?php endif; ?>
