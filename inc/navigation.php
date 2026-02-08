<?php
// Main Navigation Component
// This file provides consistent navigation across all pages
?>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%) !important;">
    <div class="container-fluid">
        <a class="navbar-brand" href="firewalls.php" style="color: #ffffff !important; font-weight: 600;">
            <i class="fas fa-shield-alt me-2" style="color: #64b5f6;"></i>
        </a>
        <div class="navbar-nav">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'firewalls.php' ? 'active' : ''; ?>" 
               href="firewalls.php" 
               style="color: #ffffff !important; font-weight: 500; transition: all 0.3s ease;">
                <i class="fas fa-network-wired me-1" style="color: #81c784;"></i>Firewalls
            </a>
        </div>
    </div>
</nav>

<style>
/* Enhanced navigation styling for better readability */
.navbar-nav .nav-link:hover {
    color: #e3f2fd !important;
    background-color: rgba(255, 255, 255, 0.1) !important;
    border-radius: 5px;
    transform: translateY(-1px);
}

.navbar-nav .nav-link.active {
    color: #fff !important;
    background-color: rgba(255, 255, 255, 0.2) !important;
    border-radius: 5px;
    font-weight: 600 !important;
}

.navbar-brand:hover {
    transform: scale(1.05);
    transition: all 0.3s ease;
}

/* Ensure all navigation text is readable */
.navbar-dark .navbar-nav .nav-link {
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
}

.navbar-brand {
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
}
</style>