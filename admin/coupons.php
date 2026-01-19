<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Coupon.php';

use FAS\Config\Database;
use FAS\Models\Coupon;

$db = Database::getInstance()->getConnection();
$couponModel = new Coupon($db);

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                try {
                    $couponModel->create($_POST);
                    $success = 'Coupon created successfully!';
                } catch (Exception $e) {
                    $error = 'Error creating coupon: ' . $e->getMessage();
                }
                break;
            
            case 'update':
                try {
                    $couponModel->update($_POST['id'], $_POST);
                    $success = 'Coupon updated successfully!';
                } catch (Exception $e) {
                    $error = 'Error updating coupon: ' . $e->getMessage();
                }
                break;
            
            case 'delete':
                try {
                    $couponModel->delete($_POST['id']);
                    $success = 'Coupon deleted successfully!';
                } catch (Exception $e) {
                    $error = 'Error deleting coupon: ' . $e->getMessage();
                }
                break;
        }
    }
}

$coupons = $couponModel->getAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coupon Management - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-tag"></i> Coupon Management</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCouponModal">
                <i class="bi bi-plus-circle"></i> Create New Coupon
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Description</th>
                                <th>Discount</th>
                                <th>Min Purchase</th>
                                <th>Usage</th>
                                <th>Expires</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($coupons as $coupon): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($coupon['code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($coupon['description']); ?></td>
                                    <td>
                                        <?php if ($coupon['discount_type'] === 'percentage'): ?>
                                            <?php echo $coupon['discount_value']; ?>%
                                        <?php else: ?>
                                            $<?php echo number_format($coupon['discount_value'], 2); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>$<?php echo number_format($coupon['minimum_purchase'], 2); ?></td>
                                    <td>
                                        <?php echo $coupon['times_used']; ?>
                                        <?php if ($coupon['max_uses']): ?>
                                            / <?php echo $coupon['max_uses']; ?>
                                        <?php else: ?>
                                            / Unlimited
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($coupon['expires_at']): ?>
                                            <?php echo date('Y-m-d', strtotime($coupon['expires_at'])); ?>
                                        <?php else: ?>
                                            Never
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($coupon['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $coupon['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($coupons)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No coupons created yet</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Coupon Modal -->
    <div class="modal fade" id="createCouponModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title">Create New Coupon</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Coupon Code *</label>
                            <input type="text" name="code" class="form-control" required 
                                   placeholder="e.g., SAVE10"
                                   oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')">
                            <small class="text-muted">Letters and numbers only (automatically converted to uppercase)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control" 
                                   placeholder="e.g., 10% off all orders">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Discount Type *</label>
                            <select name="discount_type" class="form-select" required>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount ($)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Discount Value *</label>
                            <input type="number" name="discount_value" class="form-control" 
                                   required min="0" step="0.01" placeholder="e.g., 10">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Minimum Purchase</label>
                            <input type="number" name="minimum_purchase" class="form-control" 
                                   min="0" step="0.01" value="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Max Uses</label>
                            <input type="number" name="max_uses" class="form-control" 
                                   min="1" placeholder="Leave empty for unlimited">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Expiration Date</label>
                            <input type="datetime-local" name="expires_at" class="form-control">
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" 
                                   id="isActive" checked>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Coupon</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
