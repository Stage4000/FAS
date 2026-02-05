<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Order.php';

$auth = new AdminAuth();
$auth->requireLogin();

use FAS\Config\Database;
use FAS\Models\Order;

$db = Database::getInstance()->getConnection();
$orderModel = new Order($db);

$orderId = $_GET['id'] ?? null;

if (!$orderId) {
    header('Location: orders.php');
    exit;
}

$order = $orderModel->getById($orderId);
if (!$order) {
    header('Location: orders.php');
    exit;
}

$items = $orderModel->getItems($orderId);
$shippingAddress = json_decode($order['shipping_address'], true);
$billingAddress = json_decode($order['billing_address'], true);

// Handle status updates
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'update_status':
            $newStatus = $_POST['order_status'] ?? '';
            $result = $orderModel->update($orderId, ['order_status' => $newStatus]);
            if ($result) {
                $success = 'Order status updated successfully';
                $order['order_status'] = $newStatus;
            } else {
                $error = 'Failed to update order status';
            }
            break;
        
        case 'update_tracking':
            $trackingNumber = $_POST['tracking_number'] ?? '';
            $result = $orderModel->update($orderId, [
                'tracking_number' => $trackingNumber,
                'order_status' => 'processing',
                'shipped_at' => date('Y-m-d H:i:s')
            ]);
            if ($result) {
                $success = 'Tracking information updated successfully';
                $order['tracking_number'] = $trackingNumber;
                $order['order_status'] = 'processing';
            } else {
                $error = 'Failed to update tracking information';
            }
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details - Admin Panel</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Order Details</h1>
        <a href="orders.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Back to Orders
        </a>
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
    
    <div class="row">
        <!-- Order Information -->
        <div class="col-lg-8">
            <!-- Order Header -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="mb-3">Order #<?php echo htmlspecialchars($order['order_number']); ?></h5>
                            <p class="mb-1">
                                <strong>Date:</strong> 
                                <?php 
                                $date = new DateTime($order['created_at']);
                                echo $date->format('F d, Y g:i A'); 
                                ?>
                            </p>
                            <p class="mb-1">
                                <strong>Payment Method:</strong> 
                                <?php echo ucfirst($order['payment_method'] ?? 'paypal'); ?>
                            </p>
                            <?php if ($order['paypal_transaction_id']): ?>
                                <p class="mb-1">
                                    <strong>Transaction ID:</strong> 
                                    <code><?php echo htmlspecialchars($order['paypal_transaction_id']); ?></code>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <?php
                            $statusBadgeClass = match($order['order_status']) {
                                'completed' => 'success',
                                'processing' => 'info',
                                'pending' => 'warning',
                                'cancelled' => 'danger',
                                default => 'secondary'
                            };
                            $paymentBadgeClass = match($order['payment_status']) {
                                'completed' => 'success',
                                'pending' => 'warning',
                                'failed' => 'danger',
                                default => 'secondary'
                            };
                            ?>
                            <div class="mb-2">
                                <span class="badge bg-<?php echo $statusBadgeClass; ?> fs-6">
                                    <?php echo ucfirst($order['order_status']); ?>
                                </span>
                            </div>
                            <div>
                                <span class="badge bg-<?php echo $paymentBadgeClass; ?> fs-6">
                                    Payment: <?php echo ucfirst($order['payment_status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Order Items -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="mb-3">Order Items</h5>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Quantity</th>
                                    <th>Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['product_sku'] ?? 'N/A'); ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td>$<?php echo number_format($item['unit_price'], 2); ?></td>
                                        <td>$<?php echo number_format($item['total_price'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="text-end"><strong>Subtotal:</strong></td>
                                    <td><strong>$<?php echo number_format($order['subtotal'], 2); ?></strong></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end">Shipping:</td>
                                    <td>$<?php echo number_format($order['shipping_cost'], 2); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end">Tax:</td>
                                    <td>$<?php echo number_format($order['tax_amount'], 2); ?></td>
                                </tr>
                                <tr class="table-active">
                                    <td colspan="4" class="text-end"><strong>Total:</strong></td>
                                    <td><strong class="text-danger">$<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Customer Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="mb-3">Customer Information</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?></p>
                            <p class="mb-1"><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($order['customer_email']); ?>"><?php echo htmlspecialchars($order['customer_email']); ?></a></p>
                            <?php if ($order['customer_phone']): ?>
                                <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Shipping Address -->
            <?php if ($shippingAddress): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="mb-3">Shipping Address</h5>
                        <address>
                            <?php echo htmlspecialchars($shippingAddress['address1'] ?? ''); ?><br>
                            <?php if (!empty($shippingAddress['address2'])): ?>
                                <?php echo htmlspecialchars($shippingAddress['address2']); ?><br>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($shippingAddress['city'] ?? ''); ?>, 
                            <?php echo htmlspecialchars($shippingAddress['state'] ?? ''); ?> 
                            <?php echo htmlspecialchars($shippingAddress['zip'] ?? ''); ?><br>
                            <?php echo htmlspecialchars($shippingAddress['country'] ?? ''); ?>
                        </address>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Order Notes -->
            <?php if ($order['notes']): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="mb-3">Order Notes</h5>
                        <p><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Sidebar Actions -->
        <div class="col-lg-4">
            <!-- Update Status -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="mb-3">Update Status</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <div class="mb-3">
                            <label class="form-label">Order Status</label>
                            <select name="order_status" class="form-select" required>
                                <option value="pending" <?php echo $order['order_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo $order['order_status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="completed" <?php echo $order['order_status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $order['order_status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-save me-1"></i>Update Status
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Tracking Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="mb-3">Tracking Information</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_tracking">
                        <div class="mb-3">
                            <label class="form-label">Tracking Number</label>
                            <input type="text" name="tracking_number" class="form-control" 
                                   value="<?php echo htmlspecialchars($order['tracking_number'] ?? ''); ?>"
                                   placeholder="Enter tracking number">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-shipping-fast me-1"></i>Update Tracking
                        </button>
                    </form>
                    <?php if ($order['shipped_at']): ?>
                        <div class="mt-3">
                            <small class="text-muted">
                                Shipped on: <?php echo (new DateTime($order['shipped_at']))->format('F d, Y g:i A'); ?>
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
