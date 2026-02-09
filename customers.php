<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/header.php';

requireLogin();
requireAdmin();

$message = '';

// Handle customer actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } elseif (isset($_POST['add_customer'])) {
        $name = trim($_POST['name']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $notes = trim($_POST['notes']);
        
        if (empty($name)) {
            $message = '<div class="alert alert-danger">Customer name is required.</div>';
        } else {
            try {
                $stmt = $DB->prepare("INSERT INTO customers (name, contact_person, email, phone, address, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $contact_person, $email, $phone, $address, $notes]);
                $message = '<div class="alert alert-success">Customer added successfully!</div>';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $message = '<div class="alert alert-danger">A customer with this name already exists.</div>';
                } else {
                    error_log("customers.php error: " . $e->getMessage());
                    $message = '<div class="alert alert-danger">An internal error occurred while adding the customer.</div>';
                }
            }
        }
    } elseif (isset($_POST['edit_customer'])) {
        $customer_id = (int)$_POST['customer_id'];
        $name = trim($_POST['name']);
        $contact_person = trim($_POST['contact_person']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $notes = trim($_POST['notes']);
        
        if (empty($name)) {
            $message = '<div class="alert alert-danger">Customer name is required.</div>';
        } else {
            try {
                $stmt = $DB->prepare("UPDATE customers SET name = ?, contact_person = ?, email = ?, phone = ?, address = ?, notes = ? WHERE id = ?");
                $stmt->execute([$name, $contact_person, $email, $phone, $address, $notes, $customer_id]);
                $message = '<div class="alert alert-success">Customer updated successfully!</div>';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $message = '<div class="alert alert-danger">A customer with this name already exists.</div>';
                } else {
                    error_log("customers.php error: " . $e->getMessage());
                    $message = '<div class="alert alert-danger">An internal error occurred while updating the customer.</div>';
                }
            }
        }
    } elseif (isset($_POST['delete_customer'])) {
        $customer_id = (int)$_POST['customer_id'];
        
        try {
            // Check if customer has firewalls
            $stmt = $DB->prepare("SELECT COUNT(*) FROM firewalls WHERE customer_name = (SELECT name FROM customers WHERE id = ?)");
            $stmt->execute([$customer_id]);
            $firewall_count = $stmt->fetchColumn();
            
            if ($firewall_count > 0) {
                $message = '<div class="alert alert-danger">Cannot delete customer: ' . $firewall_count . ' firewall(s) are assigned to this customer.</div>';
            } else {
                $stmt = $DB->prepare("DELETE FROM customers WHERE id = ?");
                $stmt->execute([$customer_id]);
                $message = '<div class="alert alert-success">Customer deleted successfully!</div>';
            }
        } catch (PDOException $e) {
            error_log("customers.php error: " . $e->getMessage());
            $message = '<div class="alert alert-danger">An internal error occurred while deleting the customer.</div>';
        }
    }
}

// Get all customers
try {
    $stmt = $DB->query("SELECT c.*, COUNT(f.id) as firewall_count FROM customers c LEFT JOIN firewalls f ON c.name = f.customer_name GROUP BY c.id ORDER BY c.name");
    $customers = $stmt->fetchAll();
} catch (Exception $e) {
    $customers = [];
    error_log("customers.php error: " . $e->getMessage());
    $message = '<div class="alert alert-danger">An internal error occurred while loading customers.</div>';
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-4">
                        <small class="text-light fw-bold mb-0">
                            <i class="fas fa-building me-1"></i>Customer Management
                        </small>
                    </div>
                    
                    <?php echo $message; ?>
                    
                    <!-- Add Customer Section -->
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
                            <div class="col-md-6">
                                <input type="text" class="form-control" name="address" placeholder="Address">
                            </div>
                            <div class="col-md-6">
                                <input type="text" class="form-control" name="notes" placeholder="Notes">
                            </div>
                        </form>
                    </div>
                    
                    <hr>
                    
                    <!-- Customers List -->
                    <h6>Current Customers</h6>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Contact Person</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Address</th>
                                    <th>Firewalls</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['contact_person']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['address']); ?></td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $customer['firewall_count']; ?></span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($customer['created_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary me-1" onclick="editCustomer(<?php echo $customer['id']; ?>)">
                                            <i class="fa fa-edit"></i> Edit
                                        </button>
                                        <?php if ($customer['firewall_count'] == 0): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this customer?')">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                            <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                            <button type="submit" name="delete_customer" class="btn btn-sm btn-danger">
                                                <i class="fa fa-trash"></i> Delete
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-muted">Has Firewalls</span>
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
        <div class="modal-content bg-dark text-light">
            <div class="modal-header bg-dark border-secondary">
                <h5 class="modal-title text-light">Edit Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-dark">
                <form id="editCustomerForm" method="post">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="customer_id" id="editCustomerId">
                    <div class="mb-3">
                        <label for="editName" class="form-label text-light fw-bold">Customer Name</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" id="editName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="editContactPerson" class="form-label text-light fw-bold">Contact Person</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" id="editContactPerson" name="contact_person">
                    </div>
                    <div class="mb-3">
                        <label for="editEmail" class="form-label text-light fw-bold">Email</label>
                        <input type="email" class="form-control bg-dark text-light border-secondary" id="editEmail" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="editPhone" class="form-label text-light fw-bold">Phone</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" id="editPhone" name="phone">
                    </div>
                    <div class="mb-3">
                        <label for="editAddress" class="form-label text-light fw-bold">Address</label>
                        <input type="text" class="form-control bg-dark text-light border-secondary" id="editAddress" name="address">
                    </div>
                    <div class="mb-3">
                        <label for="editNotes" class="form-label text-light fw-bold">Notes</label>
                        <textarea class="form-control bg-dark text-light border-secondary" id="editNotes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-dark border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="editCustomerForm" name="edit_customer" class="btn btn-primary">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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
            
            new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading customer data');
        });
}
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>