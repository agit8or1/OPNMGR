<?php
/**
 * Customers and Sites.
 *
 * A customer is an MSP customer organisation used to group the firewalls you
 * manage for them. Customers have no accounts and do not log in. Sites sit
 * optionally beneath a customer: Customer -> Site -> Firewall(s).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/customers.php';

// Authorise BEFORE any output. inc/header.php was previously included first,
// so the page shell was already on the wire by the time requireLogin() tried to
// send a Location header - the redirect silently failed and a half-rendered
// page was returned instead.
require_permission('customer.view');

$message = '';
$can_manage = can('customer.manage');

// Handle customer and site actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } elseif (!$can_manage) {
        $message = '<div class="alert alert-danger">Your role does not permit changing customers.</div>';
    } elseif (isset($_POST['add_customer']) || isset($_POST['edit_customer'])) {
        $editing = isset($_POST['edit_customer']);
        $result  = customer_save($_POST, $editing ? (int)$_POST['customer_id'] : null);

        $message = $result['ok']
            ? '<div class="alert alert-success">Customer ' . ($editing ? 'updated' : 'added') . '.</div>'
            : '<div class="alert alert-danger">' . htmlspecialchars($result['error']) . '</div>';

    } elseif (isset($_POST['add_site']) || isset($_POST['edit_site'])) {
        $editing = isset($_POST['edit_site']);
        $result  = site_save($_POST, $editing ? (int)$_POST['site_id'] : null);

        $message = $result['ok']
            ? '<div class="alert alert-success">Site ' . ($editing ? 'updated' : 'added') . '.</div>'
            : '<div class="alert alert-danger">' . htmlspecialchars($result['error']) . '</div>';

    } elseif (isset($_POST['delete_customer'])) {
        $customer_id = (int)$_POST['customer_id'];

        try {
            // Counted through customer_id. The previous guard matched
            // firewalls.customer_name against the customer name, which is empty
            // on firewalls linked via customer_group - so a customer with
            // firewalls could be deleted and orphan them.
            $stmt = db()->prepare('SELECT COUNT(*) FROM firewalls WHERE customer_id = ?');
            $stmt->execute([$customer_id]);
            $firewall_count = (int)$stmt->fetchColumn();

            $stmt = db()->prepare('SELECT COUNT(*) FROM sites WHERE customer_id = ?');
            $stmt->execute([$customer_id]);
            $site_count = (int)$stmt->fetchColumn();

            if ($firewall_count > 0) {
                $message = '<div class="alert alert-danger">Cannot delete: ' . $firewall_count
                         . ' firewall(s) are assigned to this customer. Reassign them first.</div>';
            } else {
                $customer = customer_get($customer_id);
                db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customer_id]);
                audit_log('customer.delete', [
                    'object_type' => 'customer', 'object_id' => (string)$customer_id,
                    'customer_id' => $customer_id,
                    'message' => 'Customer deleted: ' . ($customer['name'] ?? $customer_id)
                              . ($site_count ? " (and {$site_count} site(s))" : ''),
                ]);
                $message = '<div class="alert alert-success">Customer deleted.</div>';
            }
        } catch (PDOException $e) {
            error_log('customers.php delete: ' . $e->getMessage());
            $message = '<div class="alert alert-danger">Could not delete the customer.</div>';
        }

    } elseif (isset($_POST['delete_site'])) {
        $site_id = (int)$_POST['site_id'];
        try {
            $stmt = db()->prepare('SELECT COUNT(*) FROM firewalls WHERE site_id = ?');
            $stmt->execute([$site_id]);
            $count = (int)$stmt->fetchColumn();

            if ($count > 0) {
                $message = '<div class="alert alert-danger">Cannot delete: ' . $count
                         . ' firewall(s) are assigned to this site.</div>';
            } else {
                db()->prepare('DELETE FROM sites WHERE id = ?')->execute([$site_id]);
                audit_log('site.delete', [
                    'object_type' => 'site', 'object_id' => (string)$site_id, 'site_id' => $site_id,
                    'message' => 'Site deleted',
                ]);
                $message = '<div class="alert alert-success">Site deleted.</div>';
            }
        } catch (PDOException $e) {
            error_log('customers.php delete_site: ' . $e->getMessage());
            $message = '<div class="alert alert-danger">Could not delete the site.</div>';
        }
    }
}

// customer_list() counts firewalls through customer_id. The previous query
// joined on c.name = f.customer_name, which was empty for every firewall linked
// by customer_group, so the page reported zero firewalls for customers that had
// them.
$customers = customer_list();
$sites_by_customer = [];
foreach (site_list() as $site) {
    $sites_by_customer[(int)$site['customer_id']][] = $site;
}
$highlight = (int)($_GET['highlight'] ?? 0);

require_once __DIR__ . '/inc/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-4">
                        <small class="fw-bold mb-0">
                            <i class="fas fa-building me-1"></i>Customer Management
                        </small>
                    </div>
                    
                    <?php echo $message; ?>
                    
                    <!-- Add Customer Section -->
                    <?php if ($can_manage): ?>
                    <div class="mb-4">
                        <h6>Add New Customer</h6>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <div class="col-md-3">
                                <input type="text" class="form-control" name="name" placeholder="Customer Name" required>
                            </div>
                            <div class="col-md-3">
                                <input type="text" class="form-control" name="contact_person" placeholder="Contact Person">
                            </div>
                            <div class="col-md-2">
                                <input type="email" class="form-control" name="email" placeholder="Email">
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control" name="phone" placeholder="Phone">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="add_customer" class="btn btn-success">
                                    <i class="fa fa-plus me-2"></i>Add Customer
                                </button>
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control" name="code" placeholder="Code (e.g. ACME)" maxlength="32">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="timezone">
                                    <option value="">Timezone (optional)</option>
                                    <?php foreach (timezone_identifiers_list() as $tz): ?>
                                        <option value="<?php echo htmlspecialchars($tz); ?>"><?php echo htmlspecialchars($tz); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="text" class="form-control" name="tags" placeholder="Tags (comma separated)">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control" name="address" placeholder="Address">
                            </div>
                            <div class="col-md-8">
                                <input type="text" class="form-control" name="notes" placeholder="Notes">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1 text-muted">Default maintenance window</label>
                                <div class="input-group input-group-sm">
                                    <input type="time" class="form-control" name="maintenance_window_start">
                                    <span class="input-group-text">to</span>
                                    <input type="time" class="form-control" name="maintenance_window_end">
                                </div>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="addActive" value="1" checked>
                                    <label class="form-check-label" for="addActive">Active</label>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <hr>
                    
                    <!-- Customers List -->
                    <h6>Current Customers</h6>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th>Contact</th>
                                    <th>Timezone</th>
                                    <th>Maintenance</th>
                                    <th>Sites</th>
                                    <th>Firewalls</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer):
                                    $cid = (int)$customer['id'];
                                    $csites = $sites_by_customer[$cid] ?? [];
                                ?>
                                <tr<?php echo $highlight === $cid ? ' class="table-active"' : ''; ?>>
                                    <td>
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                        <?php if (!$customer['is_active']): ?>
                                            <span class="badge bg-warning text-dark ms-1">Inactive</span>
                                        <?php endif; ?>
                                        <?php foreach (array_filter(explode(',', (string)$customer['tags'])) as $t): ?>
                                            <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($t); ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="small"><?php echo htmlspecialchars($customer['code'] ?? ''); ?></td>
                                    <td class="small">
                                        <?php echo htmlspecialchars($customer['contact_person'] ?? ''); ?>
                                        <?php if (!empty($customer['email'])): ?>
                                            <br><span class="text-muted"><?php echo htmlspecialchars($customer['email']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($customer['phone'])): ?>
                                            <br><span class="text-muted"><?php echo htmlspecialchars($customer['phone']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?php echo htmlspecialchars($customer['timezone'] ?: '—'); ?></td>
                                    <td class="small text-muted">
                                        <?php if ($customer['maintenance_window_start']): ?>
                                            <?php echo htmlspecialchars(substr($customer['maintenance_window_start'], 0, 5)); ?>&ndash;<?php echo htmlspecialchars(substr($customer['maintenance_window_end'], 0, 5)); ?>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php if ($csites): ?>
                                            <?php foreach ($csites as $st): ?>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span><i class="fas fa-location-dot me-1 text-muted"></i><?php echo htmlspecialchars($st['name']); ?>
                                                        <span class="text-muted">(<?php echo (int)$st['firewall_count']; ?>)</span></span>
                                                    <?php if ($can_manage && (int)$st['firewall_count'] === 0): ?>
                                                    <form method="post" class="d-inline ms-2" onsubmit="return confirm('Delete this site?')">
                                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                        <input type="hidden" name="site_id" value="<?php echo (int)$st['id']; ?>">
                                                        <button type="submit" name="delete_site" class="btn btn-link btn-sm p-0 text-danger" title="Delete site">
                                                            <i class="fa fa-times"></i>
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                        <?php if ($can_manage): ?>
                                        <form method="post" class="mt-1 d-flex gap-1">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="customer_id" value="<?php echo $cid; ?>">
                                            <input type="hidden" name="is_active" value="1">
                                            <input type="text" name="name" class="form-control form-control-sm" placeholder="Add site" style="max-width:130px">
                                            <button type="submit" name="add_site" class="btn btn-sm btn-outline-secondary" title="Add site">
                                                <i class="fa fa-plus"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a class="badge bg-info text-decoration-none"
                                           href="search.php?q=<?php echo urlencode('customer:' . $customer['name']); ?>"
                                           title="Show these firewalls"><?php echo (int)$customer['firewall_count']; ?></a>
                                    </td>
                                    <td>
                                        <?php if ($can_manage): ?>
                                        <button type="button" class="btn btn-sm btn-primary me-1" onclick="editCustomer(<?php echo $customer['id']; ?>)">
                                            <i class="fa fa-edit"></i> Edit
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($can_manage && $customer['firewall_count'] == 0): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this customer?')">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                            <button type="submit" name="delete_customer" class="btn btn-sm btn-danger">
                                                <i class="fa fa-trash"></i> Delete
                                            </button>
                                        </form>
                                        <?php elseif ($customer['firewall_count'] > 0): ?>
                                        <span class="text-muted small">Has firewalls</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editCustomerForm" method="post">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="customer_id" id="editCustomerId">
                    <div class="mb-3">
                        <label for="editName" class="form-label fw-bold">Customer Name</label>
                        <input type="text" class="form-control" id="editName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="editContactPerson" class="form-label fw-bold">Contact Person</label>
                        <input type="text" class="form-control" id="editContactPerson" name="contact_person">
                    </div>
                    <div class="mb-3">
                        <label for="editEmail" class="form-label fw-bold">Email</label>
                        <input type="email" class="form-control" id="editEmail" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="editPhone" class="form-label fw-bold">Phone</label>
                        <input type="text" class="form-control" id="editPhone" name="phone">
                    </div>
                    <div class="mb-3">
                        <label for="editAddress" class="form-label fw-bold">Address</label>
                        <input type="text" class="form-control" id="editAddress" name="address">
                    </div>
                    <div class="mb-3">
                        <label for="editCode" class="form-label fw-bold">Customer Code</label>
                        <input type="text" class="form-control" id="editCode" name="code" maxlength="32">
                    </div>
                    <div class="mb-3">
                        <label for="editTimezone" class="form-label fw-bold">Timezone</label>
                        <select class="form-select" id="editTimezone" name="timezone">
                            <option value="">Not set</option>
                            <?php foreach (timezone_identifiers_list() as $tz): ?>
                                <option value="<?php echo htmlspecialchars($tz); ?>"><?php echo htmlspecialchars($tz); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="editTags" class="form-label fw-bold">Tags</label>
                        <input type="text" class="form-control" id="editTags" name="tags" placeholder="comma separated">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Default Maintenance Window</label>
                        <div class="input-group">
                            <input type="time" class="form-control" id="editMwStart" name="maintenance_window_start">
                            <span class="input-group-text">to</span>
                            <input type="time" class="form-control" id="editMwEnd" name="maintenance_window_end">
                        </div>
                        <div class="form-text">Used to suppress alerts during planned work.</div>
                    </div>
                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" id="editIsActive" name="is_active" value="1">
                        <label class="form-check-label" for="editIsActive">Active</label>
                    </div>
                    <div class="mb-3">
                        <label for="editNotes" class="form-label fw-bold">Notes</label>
                        <textarea class="form-control" id="editNotes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="editCustomerForm" name="edit_customer" class="btn btn-primary">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>
function editCustomer(customerId) {
    // Get customer data via AJAX
    fetch('/get_customer.php?id=' + customerId)
        .then(response => response.json())
        .then(data => {
            document.getElementById('editCustomerId').value = data.id;
            document.getElementById('editName').value = data.name || '';
            document.getElementById('editContactPerson').value = data.contact_person || '';
            document.getElementById('editEmail').value = data.email || '';
            document.getElementById('editPhone').value = data.phone || '';
            document.getElementById('editAddress').value = data.address || '';
            document.getElementById('editNotes').value = data.notes || '';
            document.getElementById('editCode').value = data.code || '';
            document.getElementById('editTimezone').value = data.timezone || '';
            document.getElementById('editTags').value = data.tags || '';
            // TIME columns come back as HH:MM:SS; <input type="time"> wants HH:MM.
            document.getElementById('editMwStart').value = (data.maintenance_window_start || '').substring(0, 5);
            document.getElementById('editMwEnd').value = (data.maintenance_window_end || '').substring(0, 5);
            document.getElementById('editIsActive').checked = String(data.is_active) === '1';

            new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading customer data');
        });
}
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>