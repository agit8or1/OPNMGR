<?php
/**
 * Deployment Package Management Page
 */

require_once __DIR__ . '/inc/bootstrap.php';

requireLogin();
requireAdmin();

$page_title = "Deployment Packages";
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo $page_title; ?> - OPNManager</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #1a1a1a; color: #e0e0e0; }
        .card { background-color: #2d2d2d; border: 1px solid #404040; }
        .btn-primary { background-color: #0066cc; border-color: #0066cc; }
        .btn-primary:hover { background-color: #0052a3; }
        .btn-danger { background-color: #cc0000; border-color: #cc0000; }
        .btn-danger:hover { background-color: #990000; }
        .table { color: #e0e0e0; border-color: #404040; }
        .table thead { border-color: #404040; }
        .modal-content { background-color: #2d2d2d; color: #e0e0e0; border: 1px solid #404040; }
        .code-block { background-color: #1a1a1a; border: 1px solid #404040; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <?php include 'inc/header.php'; ?>

    <div class="container-fluid p-4">
        <div class="row mb-4">
            <div class="col">
                <h2><i class="fas fa-box"></i> Deployment Packages</h2>
                <p class="text-muted">Manage deployment packages for deploying OPNManager to new servers</p>
            </div>
        </div>

        <!-- Package Generation Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-plus"></i> Generate New Package</h5>
            </div>
            <div class="card-body">
                <p>Create a new deployment package that can be installed on remote servers.</p>
                <button class="btn btn-primary" id="generateBtn">
                    <i class="fas fa-download"></i> Generate Package
                </button>
                <div id="generateStatus" class="alert alert-info mt-3" style="display:none;"></div>
            </div>
        </div>

        <!-- Installer Script Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-terminal"></i> Installer Script (Copy & Paste)</h5>
            </div>
            <div class="card-body">
                <p>Use this script to automatically install OPNManager on a new server:</p>
                <div class="form-group">
                    <label>For Ubuntu/Debian servers:</label>
                    <div class="code-block">
                        <code>bash &lt;(curl -s https://<?php echo $_SERVER['HTTP_HOST']; ?>/deploy/installer.sh)</code>
                    </div>
                    <button class="btn btn-sm btn-secondary mt-2" onclick="copyToClipboard(this)">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
                <div class="form-group">
                    <label>Or using wget:</label>
                    <div class="code-block">
                        <code>wget -qO- https://<?php echo $_SERVER['HTTP_HOST']; ?>/deploy/installer.sh | bash</code>
                    </div>
                    <button class="btn btn-sm btn-secondary mt-2" onclick="copyToClipboard(this)">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
                <div class="alert alert-warning mt-3">
                    <strong>Note:</strong> The installer script will:
                    <ul class="mb-0 mt-2">
                        <li>Check system requirements (PHP, MySQL, nginx)</li>
                        <li>Download the latest deployment package</li>
                        <li>Extract and configure the application</li>
                        <li>Set up the database</li>
                        <li>Configure nginx</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Existing Packages -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Existing Packages</h5>
            </div>
            <div class="card-body">
                <div id="packagesLoading" class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading packages...</p>
                </div>
                <table id="packagesTable" class="table table-striped" style="display:none;">
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <div id="noPackages" class="alert alert-info" style="display:none;">
                    No deployment packages found. <a href="#" onclick="generatePackage()">Generate one now</a>.
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Package</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this package?</p>
                    <p><strong id="deleteFilename"></strong></p>
                    <p class="text-warning">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let deleteModal;
        let packageToDelete;

        document.addEventListener('DOMContentLoaded', function() {
            deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            loadPackages();
            document.getElementById('generateBtn').addEventListener('click', generatePackage);
            document.getElementById('confirmDeleteBtn').addEventListener('click', confirmDelete);
        });

        function loadPackages() {
            fetch('/api/deployment_packages.php?action=list')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('packagesLoading').style.display = 'none';
                    
                    if (!data.success) {
                        alert('Error loading packages: ' + data.error);
                        return;
                    }
                    
                    if (data.count === 0) {
                        document.getElementById('noPackages').style.display = 'block';
                    } else {
                        document.getElementById('packagesTable').style.display = 'table';
                        const tbody = document.querySelector('#packagesTable tbody');
                        tbody.innerHTML = '';
                        
                        data.packages.forEach(pkg => {
                            const row = document.createElement('tr');
                            row.innerHTML = `
                                <td><code>${pkg.filename}</code></td>
                                <td>${pkg.size_mb} MB</td>
                                <td>${pkg.created_date}</td>
                                <td>
                                    <a href="${pkg.download_url}" class="btn btn-sm btn-info" title="Download">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button class="btn btn-sm btn-danger" onclick="showDeleteModal('${pkg.filename}')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            `;
                            tbody.appendChild(row);
                        });
                    }
                })
                .catch(error => {
                    document.getElementById('packagesLoading').innerHTML = '<div class="alert alert-danger">Error loading packages: ' + error + '</div>';
                });
        }

        function generatePackage() {
            const btn = document.getElementById('generateBtn');
            const status = document.getElementById('generateStatus');
            
            btn.disabled = true;
            status.style.display = 'block';
            status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating package...';
            
            fetch('/api/deployment_packages.php?action=generate', { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    if (data.success) {
                        status.className = 'alert alert-success';
                        status.innerHTML = '<i class="fas fa-check"></i> ' + data.message;
                        setTimeout(() => {
                            loadPackages();
                        }, 2000);
                    } else {
                        status.className = 'alert alert-danger';
                        status.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.error;
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    status.className = 'alert alert-danger';
                    status.innerHTML = '<i class="fas fa-exclamation-circle"></i> Error: ' + error;
                });
        }

        function showDeleteModal(filename) {
            packageToDelete = filename;
            document.getElementById('deleteFilename').textContent = filename;
            deleteModal.show();
        }

        function confirmDelete() {
            const btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            
            fetch('/api/deployment_packages.php?action=delete&filename=' + encodeURIComponent(packageToDelete))
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = 'Delete';
                    deleteModal.hide();
                    
                    if (data.success) {
                        alert('Package deleted successfully');
                        loadPackages();
                    } else {
                        alert('Error deleting package: ' + data.error);
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    btn.innerHTML = 'Delete';
                    alert('Error: ' + error);
                });
        }

        function copyToClipboard(btn) {
            const codeBlock = btn.previousElementSibling;
            const code = codeBlock.querySelector('code').textContent;
            navigator.clipboard.writeText(code).then(() => {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                }, 2000);
            });
        }
    </script>
</body>
</html>
