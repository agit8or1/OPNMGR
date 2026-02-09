<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';
requireLogin();
requireAdmin();

include __DIR__ . '/inc/header.php';

// Get all tags
try {
    $stmt = $DB->query('SELECT t.*, COUNT(ft.firewall_id) as firewall_count FROM tags t LEFT JOIN firewall_tags ft ON t.id = ft.tag_id GROUP BY t.id ORDER BY t.name');
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tags = [];
}
?>

<div class="row">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-tags me-2"></i>Manage Tags
                </h5>
            </div>
            <div class="card-body">
                <!-- Add Tag Form -->
                <div class="mb-4">
                    <h6 style="color: #e2e8f0;">Add New Tag</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <input type="text" id="newTagName" class="form-control" placeholder="Tag name" required>
                        </div>
                        <div class="col-md-3">
                            <input type="color" id="newTagColor" class="form-control" value="#007bff">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-primary" onclick="addTag()">
                                <i class="fas fa-plus me-1"></i>Add Tag
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tags List -->
                <div id="tagsList">
                    <?php foreach ($tags as $tag): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2 p-3 border rounded" id="tag-<?php echo $tag['id']; ?>">
                        <div class="d-flex align-items-center">
                            <span class="badge me-3" style="background-color: <?php echo htmlspecialchars($tag['color']); ?>; color: white; font-size: 14px; padding: 6px 12px;">
                                <?php echo htmlspecialchars($tag['name']); ?>
                            </span>
                            <small class="me-3" style="color: #94a3b8;">
                                <?php echo $tag['firewall_count']; ?> firewall<?php echo $tag['firewall_count'] != 1 ? 's' : ''; ?>
                            </small>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary btn-sm" onclick="startEditTag(<?php echo $tag['id']; ?>, '<?php echo htmlspecialchars($tag['name']); ?>', '<?php echo htmlspecialchars($tag['color']); ?>')">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteTag(<?php echo $tag['id']; ?>, '<?php echo htmlspecialchars($tag['name']); ?>')" <?php echo $tag['firewall_count'] > 0 ? 'disabled title="Cannot delete tag with existing firewalls"' : ''; ?>>
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Edit Tag Modal -->
                <div class="modal fade" id="editTagModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content" style="background-color: #1e2936; color: #fff; border: 1px solid rgba(255,255,255,0.15);">
                            <div class="modal-header" style="border-bottom: 1px solid rgba(255,255,255,0.15);">
                                <h5 class="modal-title" style="color: #fff;">Edit Tag</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label" style="color: #cbd7e6; font-weight: 600;">Tag Name</label>
                                    <input type="text" class="form-control" id="editTagName" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" style="color: #cbd7e6; font-weight: 600;">Tag Color</label>
                                    <input type="color" class="form-control" id="editTagColor" value="#007bff">
                                </div>
                            </div>
                            <div class="modal-footer" style="border-top: 1px solid rgba(255,255,255,0.15);">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" onclick="saveEditTag()">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function addTag() {
    const tagName = document.getElementById('newTagName').value.trim();
    const tagColor = document.getElementById('newTagColor').value;

    if (!tagName) {
        alert('Please enter a tag name');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'add_tag');
    formData.append('tag_name', tagName);
    formData.append('tag_color', tagColor);
    formData.append('csrf', '<?php echo csrf_token(); ?>');

    fetch('/manage_tags.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Add to tags list
            const tagsList = document.getElementById('tagsList');
            const tagDiv = document.createElement('div');
            tagDiv.className = 'd-flex justify-content-between align-items-center mb-2 p-3 border rounded';
            tagDiv.id = 'tag-' + data.tag_id;
            tagDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <span class="badge me-3" style="background-color: ${tagColor}; color: white; font-size: 14px; padding: 6px 12px;">
                        ${data.tag_name}
                    </span>
                    <small class="text-muted me-3">0 firewalls</small>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary btn-sm" onclick="startEditTag(${data.tag_id}, '${data.tag_name}', '${tagColor}')">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm" onclick="deleteTag(${data.tag_id}, '${data.tag_name}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            tagsList.appendChild(tagDiv);
            document.getElementById('newTagName').value = '';

            // Reload page to update dropdowns
            setTimeout(() => window.location.reload(), 500);
        } else {
            alert('Error adding tag: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

function deleteTag(tagId, tagName) {
    if (!confirm(`Are you sure you want to delete the tag "${tagName}"?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_tag');
    formData.append('tag_id', tagId);
    formData.append('csrf', '<?php echo csrf_token(); ?>');

    fetch('/manage_tags.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove from tags list
            const tagDiv = document.getElementById('tag-' + data.tag_id);
            if (tagDiv) tagDiv.remove();

            // Reload page to update dropdowns
            setTimeout(() => window.location.reload(), 500);
        } else {
            alert('Error deleting tag: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

let currentEditTagId = null;

function startEditTag(tagId, tagName, tagColor) {
    currentEditTagId = tagId;
    document.getElementById('editTagName').value = tagName;
    document.getElementById('editTagColor').value = tagColor;

    const modal = new bootstrap.Modal(document.getElementById('editTagModal'));
    modal.show();
}

function saveEditTag() {
    const tagName = document.getElementById('editTagName').value.trim();
    const tagColor = document.getElementById('editTagColor').value;

    if (!tagName) {
        alert('Please enter a tag name');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'edit_tag');
    formData.append('tag_id', currentEditTagId);
    formData.append('tag_name', tagName);
    formData.append('tag_color', tagColor);
    formData.append('csrf', '<?php echo csrf_token(); ?>');

    fetch('/manage_tags.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('editTagModal'));
            modal.hide();

            // Reload page to show updated tag
            setTimeout(() => window.location.reload(), 500);
        } else {
            alert('Error updating tag: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
