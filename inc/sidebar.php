<?php
// Administration Sidebar Component - DISABLED
// This provides consistent navigation for all administration and main pages
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Administration Sidebar - HIDDEN -->
<div style="display: none;">
    <!-- Sidebar content hidden but structure preserved for easy restoration -->
</div>

<style>
/* Enhanced sidebar styling for better readability */
.nav-link:hover {
    background-color: rgba(255, 255, 255, 0.1) !important;
    color: #ffffff !important;
    border-radius: 5px;
    margin: 2px 8px;
    transform: translateX(5px);
}

.nav-link.active {
    background-color: rgba(40, 167, 69, 0.2) !important;
    color: #ffffff !important;
    border-radius: 5px;
    margin: 2px 8px;
    border-left: 3px solid #28a745;
    font-weight: 600 !important;
}

/* Improve text contrast */
.card-dark .nav-link {
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.8);
}

.text-muted {
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.8);
}

/* Icon enhancements */
.nav-link i {
    width: 20px;
    text-align: center;
}

/* Responsive improvements */
@media (max-width: 768px) {
    .nav-link {
        padding: 12px 20px !important;
        font-size: 16px;
    }
    
    .text-uppercase {
        font-size: 12px;
    }
}
</style>
