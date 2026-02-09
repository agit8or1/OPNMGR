<?php
require_once __DIR__ . '/inc/auth.php';
requireLogin();

$page_title = "Support This Project";

require_once __DIR__ . '/inc/header.php';
?>

<style>
.support-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.support-option {
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    border: 2px solid rgba(255,255,255,0.2);
    border-radius: 10px;
    padding: 2rem;
    transition: all 0.3s ease;
    height: 100%;
}

.support-option:hover {
    background: rgba(255,255,255,0.2);
    border-color: rgba(255,255,255,0.4);
    transform: translateY(-5px);
}

.support-option h4 {
    color: white !important;
    margin-bottom: 1rem;
}

.support-option p {
    color: rgba(255,255,255,0.9) !important;
    margin-bottom: 1.5rem;
}

.btn-support {
    background: white;
    color: #667eea;
    font-weight: bold;
    border: none;
    padding: 0.75rem 2rem;
    border-radius: 50px;
    transition: all 0.3s ease;
}

.btn-support:hover {
    background: #f0f0f0;
    transform: scale(1.05);
    color: #667eea;
}
</style>

<div class="container-fluid mt-4">
    <!-- Hero Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="support-card text-center">
                <h1 class="display-4 mb-3">
                    <i class="fas fa-heart me-3"></i>Support OPNsense Manager
                </h1>
                <p class="lead mb-0">
                    Help keep this project free and open-source for everyone
                </p>
            </div>
        </div>
    </div>

    <!-- Support Options -->
    <div class="row mb-4 justify-content-center">
        <div class="col-md-5 mb-3">
            <div class="support-option">
                <div class="text-center mb-3">
                    <i class="fas fa-star fa-3x" style="color: #fbbf24;"></i>
                </div>
                <h4 class="text-center">Star on GitHub</h4>
                <p class="text-center">
                    Give us a star on GitHub! It helps increase visibility and shows other users this project is valuable.
                </p>
                <div class="text-center">
                    <a href="https://github.com/agit8or1/OPNMGR" target="_blank" class="btn btn-support">
                        <i class="fab fa-github me-2"></i>Star on GitHub
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-5 mb-3">
            <div class="support-option">
                <div class="text-center mb-3">
                    <i class="fas fa-briefcase fa-3x" style="color: #60a5fa;"></i>
                </div>
                <h4 class="text-center">Support My Business</h4>
                <p class="text-center">
                    Need IT services, consulting, or managed solutions? Supporting the business helps fund continued development of this project.
                </p>
                <div class="text-center">
                    <a href="https://mspreboot.com" target="_blank" class="btn btn-support">
                        <i class="fas fa-external-link-alt me-2"></i>Visit MSP Reboot
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Thank You Note -->
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-body text-center">
                    <h3 class="text-light mb-3">
                        <i class="fas fa-heart text-danger me-2"></i>Thank You!
                    </h3>
                    <p class="text-light mb-0">
                        Your support makes a difference. Thank you for being part of the OPNMGR community.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
