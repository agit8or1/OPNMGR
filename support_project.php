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
    padding: 1.5rem;
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

.stats-card {
    background: #1e293b;
    border: 2px solid #334155;
    border-radius: 10px;
    padding: 1.5rem;
    text-align: center;
}

.stats-card h2 {
    color: #60a5fa;
    margin-bottom: 0.5rem;
}

.stats-card p {
    color: #94a3b8;
    margin-bottom: 0;
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
                <p class="lead mb-4">
                    Help keep this project free and open-source for everyone
                </p>
                <p class="mb-0">
                    OPNsense Manager is developed and maintained by passionate developers who believe in making
                    enterprise-grade firewall management accessible to everyone. Your support helps us continue
                    developing new features, fixing bugs, and improving the platform.
                </p>
            </div>
        </div>
    </div>

    <!-- Support Options -->
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="text-light mb-4">
                <i class="fas fa-hands-helping me-2"></i>Ways to Support
            </h2>
        </div>

        <div class="col-md-4 mb-3">
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

        <div class="col-md-4 mb-3">
            <div class="support-option">
                <div class="text-center mb-3">
                    <i class="fas fa-code fa-3x" style="color: #34d399;"></i>
                </div>
                <h4 class="text-center">Contribute Code</h4>
                <p class="text-center">
                    Help improve OPNsense Manager by contributing code, fixing bugs, or improving documentation.
                </p>
                <div class="text-center">
                    <a href="https://github.com/agit8or1/OPNMGR/blob/main/CONTRIBUTING.md" target="_blank" class="btn btn-support">
                        <i class="fas fa-code-branch me-2"></i>Contribute
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="support-option">
                <div class="text-center mb-3">
                    <i class="fas fa-comment-dots fa-3x" style="color: #60a5fa;"></i>
                </div>
                <h4 class="text-center">Share Feedback</h4>
                <p class="text-center">
                    Report bugs, suggest features, or share your use case. Community feedback drives development!
                </p>
                <div class="text-center">
                    <a href="https://github.com/agit8or1/OPNMGR/issues" target="_blank" class="btn btn-support">
                        <i class="fas fa-comment me-2"></i>Give Feedback
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="support-option">
                <div class="text-center mb-3">
                    <i class="fas fa-share-alt fa-3x" style="color: #f472b6;"></i>
                </div>
                <h4 class="text-center">Spread the Word</h4>
                <p class="text-center">
                    Share OPNsense Manager with your network. Write a blog post, tweet about it, or tell a colleague!
                </p>
                <div class="text-center">
                    <a href="https://twitter.com/intent/tweet?text=Check%20out%20OPNsense%20Manager%20-%20Centralized%20firewall%20management!&url=https://github.com/agit8or1/OPNMGR" target="_blank" class="btn btn-support">
                        <i class="fab fa-twitter me-2"></i>Share on Twitter
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="support-option">
                <div class="text-center mb-3">
                    <i class="fas fa-graduation-cap fa-3x" style="color: #a78bfa;"></i>
                </div>
                <h4 class="text-center">Write Tutorials</h4>
                <p class="text-center">
                    Create tutorials, guides, or videos showing how you use OPNsense Manager in your environment.
                </p>
                <div class="text-center">
                    <a href="/documentation.php" class="btn btn-support">
                        <i class="fas fa-book me-2"></i>View Docs
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="support-option">
                <div class="text-center mb-3">
                    <i class="fas fa-briefcase fa-3x" style="color: #fb923c;"></i>
                </div>
                <h4 class="text-center">Hire for Consulting</h4>
                <p class="text-center">
                    Need custom features, support, or consulting? Reach out for professional services and support development.
                </p>
                <div class="text-center">
                    <a href="/support.php" class="btn btn-support">
                        <i class="fas fa-envelope me-2"></i>Contact Us
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Project Stats -->
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="text-light mb-4">
                <i class="fas fa-chart-line me-2"></i>Project Impact
            </h2>
        </div>

        <div class="col-md-3 mb-3">
            <div class="stats-card">
                <h2><i class="fas fa-code"></i></h2>
                <p class="small">Open Source</p>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="stats-card">
                <h2><i class="fas fa-users"></i></h2>
                <p class="small">Community Driven</p>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="stats-card">
                <h2><i class="fas fa-rocket"></i></h2>
                <p class="small">Actively Developed</p>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="stats-card">
                <h2><i class="fas fa-shield-alt"></i></h2>
                <p class="small">Security Focused</p>
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
                        Every contribution, no matter how small, makes a difference. Thank you for being part of the
                        OPNsense Manager community and helping make this project better for everyone.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
