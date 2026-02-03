<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Warehouse.php';

$auth = new AdminAuth();
$auth->requireLogin();

use FAS\Config\Database;
use FAS\Models\Warehouse;

$db = Database::getInstance()->getConnection();
$warehouseModel = new Warehouse($db);

$success = '';
$error = '';
$action = $_GET['action'] ?? 'list';
$warehouseId = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
            case 'update':
                $warehouseData = [
                    'name' => $_POST['name'] ?? '',
                    'code' => strtoupper($_POST['code'] ?? ''),
                    'address_line1' => $_POST['address_line1'] ?? '',
                    'address_line2' => $_POST['address_line2'] ?? '',
                    'city' => $_POST['city'] ?? '',
                    'state' => $_POST['state'] ?? '',
                    'postal_code' => $_POST['postal_code'] ?? '',
                    'country_code' => $_POST['country_code'] ?? 'US',
                    'phone' => $_POST['phone'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'is_default' => isset($_POST['is_default']) ? 1 : 0
                ];
                
                if ($_POST['action'] === 'create') {
                    try {
                        $result = $warehouseModel->create($warehouseData);
                        if ($result) {
                            $success = 'Warehouse created successfully';
                            $action = 'list';
                        } else {
                            $error = 'Failed to create warehouse';
                        }
                    } catch (Exception $e) {
                        $error = 'Error: ' . $e->getMessage();
                    }
                } else {
                    try {
                        $result = $warehouseModel->update(intval($_POST['warehouse_id']), $warehouseData);
                        if ($result) {
                            $success = 'Warehouse updated successfully';
                            $action = 'list';
                        } else {
                            $error = 'Failed to update warehouse';
                        }
                    } catch (Exception $e) {
                        $error = 'Error: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'set_default':
                try {
                    $result = $warehouseModel->setAsDefault(intval($_POST['warehouse_id']));
                    if ($result) {
                        $success = 'Default warehouse updated successfully';
                    } else {
                        $error = 'Failed to set default warehouse';
                    }
                } catch (Exception $e) {
                    $error = 'Error: ' . $e->getMessage();
                }
                $action = 'list';
                break;
                
            case 'delete':
                try {
                    $result = $warehouseModel->delete(intval($_POST['warehouse_id']));
                    if ($result) {
                        $success = 'Warehouse deleted successfully';
                    } else {
                        $error = 'Failed to delete warehouse';
                    }
                } catch (Exception $e) {
                    $error = 'Error: ' . $e->getMessage();
                }
                $action = 'list';
                break;
        }
    }
}

// Get warehouse for edit
$warehouse = null;
if ($action === 'edit' && $warehouseId) {
    $warehouse = $warehouseModel->getById($warehouseId);
    if (!$warehouse) {
        $error = 'Warehouse not found';
        $action = 'list';
    }
}

// Get all warehouses for list view
$warehouses = [];
if ($action === 'list') {
    $warehouses = $warehouseModel->getAll(true); // Include inactive
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Management - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>
    
    <h1 class="mb-4">
        <i class="fas fa-warehouse me-2"></i>Warehouse Management
    </h1>
    
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
    
    <?php if ($action === 'list'): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Warehouses</h5>
                    <a href="?action=create" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i>Add Warehouse
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($warehouses)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-inbox display-1"></i>
                        <p class="mt-3">No warehouses found. Add your first warehouse to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name / Code</th>
                                    <th>Address</th>
                                    <th>Contact</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($warehouses as $wh): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($wh['name']); ?></strong>
                                            <?php if ($wh['is_default']): ?>
                                                <span class="badge bg-primary ms-2">Default</span>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted">Code: <?php echo htmlspecialchars($wh['code']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($wh['address_line1']); ?>
                                            <?php if ($wh['address_line2']): ?>
                                                <br><?php echo htmlspecialchars($wh['address_line2']); ?>
                                            <?php endif; ?>
                                            <br><?php echo htmlspecialchars($wh['city']); ?>, <?php echo htmlspecialchars($wh['state']); ?> <?php echo htmlspecialchars($wh['postal_code']); ?>
                                            <br><?php echo htmlspecialchars($wh['country_code']); ?>
                                        </td>
                                        <td>
                                            <?php if ($wh['phone']): ?>
                                                <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($wh['phone']); ?><br>
                                            <?php endif; ?>
                                            <?php if ($wh['email']): ?>
                                                <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($wh['email']); ?>
                                            <?php endif; ?>
                                            <?php if (!$wh['phone'] && !$wh['email']): ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($wh['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?action=edit&id=<?php echo $wh['id']; ?>" class="btn btn-outline-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if (!$wh['is_default']): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Set this warehouse as default?');">
                                                        <input type="hidden" name="action" value="set_default">
                                                        <input type="hidden" name="warehouse_id" value="<?php echo $wh['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-secondary" title="Set as Default">
                                                            <i class="bi bi-star"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this warehouse? Products assigned to it will be set to no warehouse.');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="warehouse_id" value="<?php echo $wh['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-outline-secondary" disabled title="Cannot delete default warehouse">
                                                        <i class="bi bi-star-fill"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    
    <?php elseif ($action === 'create' || $action === 'edit'): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><?php echo $action === 'create' ? 'Add New' : 'Edit'; ?> Warehouse</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="<?php echo $action === 'create' ? 'create' : 'update'; ?>">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="warehouse_id" value="<?php echo $warehouse['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Warehouse Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?php echo htmlspecialchars($warehouse['name'] ?? ''); ?>">
                            <small class="text-muted">E.g., "Main Warehouse - Florida"</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Warehouse Code <span class="text-danger">*</span></label>
                            <input type="text" name="code" class="form-control" required pattern="[A-Z0-9-]+"
                                   value="<?php echo htmlspecialchars($warehouse['code'] ?? ''); ?>"
                                   <?php echo $action === 'edit' ? 'readonly' : ''; ?>>
                            <small class="text-muted">Unique code (e.g., "FL-MAIN")</small>
                        </div>
                    </div>
                    
                    <h6 class="mt-4 mb-3">Address Information</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Address Line 1 <span class="text-danger">*</span></label>
                            <input type="text" name="address_line1" class="form-control" required
                                   value="<?php echo htmlspecialchars($warehouse['address_line1'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" name="address_line2" class="form-control"
                                   value="<?php echo htmlspecialchars($warehouse['address_line2'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">City <span class="text-danger">*</span></label>
                            <input type="text" name="city" class="form-control" required
                                   value="<?php echo htmlspecialchars($warehouse['city'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">State/Province <span class="text-danger">*</span></label>
                            <input type="text" name="state" class="form-control" required maxlength="2" pattern="[A-Z]{2}"
                                   value="<?php echo htmlspecialchars($warehouse['state'] ?? ''); ?>" style="text-transform: uppercase;">
                            <small class="text-muted">2-letter code (e.g., "FL")</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Postal Code <span class="text-danger">*</span></label>
                            <input type="text" name="postal_code" class="form-control" required
                                   value="<?php echo htmlspecialchars($warehouse['postal_code'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Country Code <span class="text-danger">*</span></label>
                            <select name="country_code" class="form-select" required>
                                <option value="US" <?php echo ($warehouse['country_code'] ?? 'US') === 'US' ? 'selected' : ''; ?>>United States (US)</option>
                                <option value="CA" <?php echo ($warehouse['country_code'] ?? '') === 'CA' ? 'selected' : ''; ?>>Canada (CA)</option>
                                <option value="MX" <?php echo ($warehouse['country_code'] ?? '') === 'MX' ? 'selected' : ''; ?>>Mexico (MX)</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?php echo htmlspecialchars($warehouse['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars($warehouse['email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <h6 class="mt-4 mb-3">Settings</h6>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="is_active" value="1"
                                   <?php echo ($warehouse['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">
                                Active (warehouse is available for shipping)
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_default" class="form-check-input" id="is_default" value="1"
                                   <?php echo ($warehouse['is_default'] ?? 0) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_default">
                                Set as default warehouse (used for new products)
                            </label>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> City and state are required by EasyShip for accurate shipping rate calculations. 
                        Products without an assigned warehouse will use the default warehouse for shipping calculations.
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check-circle me-1"></i>Save Warehouse
                        </button>
                        <a href="warehouses.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
    </div>
    </div>
    
    <?php include __DIR__ . '/includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-uppercase state input
        document.querySelector('input[name="state"]')?.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });
    </script>
</body>
</html>
