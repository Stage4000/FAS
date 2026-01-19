<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';

$auth = new AdminAuth();
$auth->requireLogin();

use FAS\Config\Database;
use FAS\Models\Product;

$db = Database::getInstance()->getConnection();
$productModel = new Product($db);

$success = '';
$error = '';
$action = $_GET['action'] ?? 'list';
$productId = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
            case 'update':
                // Handle image upload
                $imageUrl = $_POST['image_url'] ?? '';
                
                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    // Create uploads directory if it doesn't exist
                    $uploadDir = __DIR__ . '/../gallery/uploads/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    // Validate file type by extension and MIME type
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    $allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                    
                    $extension = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $_FILES['image_file']['tmp_name']);
                    finfo_close($finfo);
                    
                    // Additional validation: verify it's actually an image
                    $imageInfo = @getimagesize($_FILES['image_file']['tmp_name']);
                    
                    if (in_array($extension, $allowedExtensions) && 
                        in_array($mimeType, $allowedMimeTypes) && 
                        $imageInfo !== false) {
                        $filename = 'product_' . time() . '_' . uniqid() . '.' . $extension;
                        $targetPath = $uploadDir . $filename;
                        
                        if (move_uploaded_file($_FILES['image_file']['tmp_name'], $targetPath)) {
                            // Use relative path from document root
                            $imageUrl = 'gallery/uploads/' . $filename;
                        } else {
                            $error = 'Failed to upload image file';
                        }
                    } else {
                        $error = 'Invalid image file. Please upload a valid JPEG, PNG, GIF, or WebP image.';
                    }
                }
                
                if (empty($error)) {
                    $productData = [
                        'name' => $_POST['name'] ?? '',
                        'sku' => $_POST['sku'] ?? '',
                        'description' => $_POST['description'] ?? '',
                        'price' => floatval($_POST['price'] ?? 0),
                        'sale_price' => !empty($_POST['sale_price']) ? floatval($_POST['sale_price']) : null,
                        'quantity' => intval($_POST['quantity'] ?? 1),
                        'category' => $_POST['category'] ?? '',
                        'manufacturer' => $_POST['manufacturer'] ?? '',
                        'model' => $_POST['model'] ?? '',
                        'condition_name' => $_POST['condition_name'] ?? 'New',
                        'weight' => !empty($_POST['weight']) ? floatval($_POST['weight']) : null,
                        'image_url' => $imageUrl,
                        'source' => $_POST['source'] ?? 'manual',
                        'show_on_website' => isset($_POST['show_on_website']) ? 1 : 0
                    ];
                    
                    if ($_POST['action'] === 'create') {
                        $result = $productModel->create($productData);
                        if ($result) {
                            $success = 'Product created successfully';
                            $action = 'list';
                        } else {
                            $error = 'Failed to create product';
                        }
                    } else {
                        $result = $productModel->update(intval($_POST['product_id']), $productData);
                        if ($result) {
                            $success = 'Product updated successfully';
                            $action = 'list';
                        } else {
                            $error = 'Failed to update product';
                        }
                    }
                }
                break;
                
            case 'toggle_visibility':
                $productId = intval($_POST['product_id']);
                $result = $productModel->toggleWebsiteVisibility($productId);
                if ($result) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to toggle visibility']);
                }
                exit;
                
            case 'delete':
                $productId = intval($_POST['product_id']);
                $result = $productModel->delete($productId);
                if ($result) {
                    $success = 'Product deleted successfully';
                } else {
                    $error = 'Failed to delete product';
                }
                $action = 'list';
                break;
        }
    }
}

// Get product data for edit
$product = null;
if ($action === 'edit' && $productId) {
    $product = $productModel->getById($productId, true); // true = include inactive
}

// Get products list
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$search = $_GET['search'] ?? '';
$sourceFilter = $_GET['source'] ?? '';
$products = [];
$totalProducts = 0;

if ($action === 'list') {
    $products = $productModel->getAllProducts($page, $perPage, $search, $sourceFilter);
    $totalProducts = $productModel->getCountAll($search, $sourceFilter);
    $totalPages = ceil($totalProducts / $perPage);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <?php if ($action === 'list'): ?>
                    <!-- Product List View -->
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">Products</h1>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <a href="?action=create" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-1"></i> Add Product
                            </a>
                        </div>
                    </div>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Search and Filter -->
                    <div class="card mb-3">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-6">
                                    <input type="text" class="form-control" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" name="source">
                                        <option value="">All Sources</option>
                                        <option value="ebay" <?php echo $sourceFilter === 'ebay' ? 'selected' : ''; ?>>eBay Products</option>
                                        <option value="manual" <?php echo $sourceFilter === 'manual' ? 'selected' : ''; ?>>Manual Products</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary w-100">Search</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Products Table -->
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Image</th>
                                            <th>Name</th>
                                            <th>SKU</th>
                                            <th>Price</th>
                                            <th>Stock</th>
                                            <th>Source</th>
                                            <th>Visible</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($products)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-4">
                                                    No products found. <a href="?action=create">Add your first product</a>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($products as $prod): ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($prod['image_url']): ?>
                                                            <img src="<?php echo htmlspecialchars($prod['image_url']); ?>" alt="" style="width: 50px; height: 50px; object-fit: cover;">
                                                        <?php else: ?>
                                                            <div style="width: 50px; height: 50px; background: #eee; display: flex; align-items: center; justify-content: center;">
                                                                <i class="bi bi-image text-muted"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($prod['name']); ?></td>
                                                    <td><?php echo htmlspecialchars($prod['sku'] ?? '-'); ?></td>
                                                    <td>$<?php echo number_format($prod['price'], 2); ?></td>
                                                    <td><?php echo $prod['quantity']; ?></td>
                                                    <td>
                                                        <?php if ($prod['source'] === 'ebay'): ?>
                                                            <span class="badge bg-primary">eBay</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success">Manual</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input visibility-toggle" type="checkbox" 
                                                                   data-product-id="<?php echo $prod['id']; ?>"
                                                                   <?php echo $prod['show_on_website'] ? 'checked' : ''; ?>>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="?action=edit&id=<?php echo $prod['id']; ?>" class="btn btn-outline-primary" title="Edit">
                                                                <i class="bi bi-pencil"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-outline-danger delete-product" 
                                                                    data-product-id="<?php echo $prod['id']; ?>"
                                                                    data-product-name="<?php echo htmlspecialchars($prod['name']); ?>" title="Delete">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <nav>
                                    <ul class="pagination justify-content-center">
                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&source=<?php echo urlencode($sourceFilter); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($action === 'create' || $action === 'edit'): ?>
                    <!-- Product Form -->
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2"><?php echo $action === 'create' ? 'Add' : 'Edit'; ?> Product</h1>
                        <a href="?action=list" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Back to List
                        </a>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="<?php echo $action === 'create' ? 'create' : 'update'; ?>">
                                <?php if ($product): ?>
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                <?php endif; ?>

                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label class="form-label">Product Name *</label>
                                            <input type="text" class="form-control" name="name" required 
                                                   value="<?php echo $product ? htmlspecialchars($product['name']) : ''; ?>">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control" name="description" rows="4"><?php echo $product ? htmlspecialchars($product['description']) : ''; ?></textarea>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Price *</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">$</span>
                                                        <input type="number" class="form-control" name="price" step="0.01" required 
                                                               value="<?php echo $product ? $product['price'] : ''; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Sale Price</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">$</span>
                                                        <input type="number" class="form-control" name="sale_price" step="0.01" 
                                                               value="<?php echo $product && $product['sale_price'] ? $product['sale_price'] : ''; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">SKU</label>
                                                    <input type="text" class="form-control" name="sku" 
                                                           value="<?php echo $product ? htmlspecialchars($product['sku']) : ''; ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Quantity</label>
                                                    <input type="number" class="form-control" name="quantity" 
                                                           value="<?php echo $product ? $product['quantity'] : '1'; ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Weight (lbs)</label>
                                                    <input type="number" class="form-control" name="weight" step="0.01" 
                                                           value="<?php echo $product && $product['weight'] ? $product['weight'] : ''; ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Category</label>
                                            <select class="form-select" name="category">
                                                <option value="">Select Category</option>
                                                <option value="motorcycle" <?php echo $product && $product['category'] === 'motorcycle' ? 'selected' : ''; ?>>Motorcycle Parts</option>
                                                <option value="atv" <?php echo $product && $product['category'] === 'atv' ? 'selected' : ''; ?>>ATV/UTV Parts</option>
                                                <option value="boat" <?php echo $product && $product['category'] === 'boat' ? 'selected' : ''; ?>>Boat Parts</option>
                                                <option value="automotive" <?php echo $product && $product['category'] === 'automotive' ? 'selected' : ''; ?>>Automotive Parts</option>
                                                <option value="gifts" <?php echo $product && $product['category'] === 'gifts' ? 'selected' : ''; ?>>Biker Gifts</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Manufacturer</label>
                                            <input type="text" class="form-control" name="manufacturer" 
                                                   value="<?php echo $product ? htmlspecialchars($product['manufacturer']) : ''; ?>">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Model</label>
                                            <input type="text" class="form-control" name="model" 
                                                   value="<?php echo $product ? htmlspecialchars($product['model']) : ''; ?>">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Condition</label>
                                            <select class="form-select" name="condition_name">
                                                <option value="New" <?php echo $product && $product['condition_name'] === 'New' ? 'selected' : ''; ?>>New</option>
                                                <option value="Used" <?php echo $product && $product['condition_name'] === 'Used' ? 'selected' : ''; ?>>Used</option>
                                                <option value="Refurbished" <?php echo $product && $product['condition_name'] === 'Refurbished' ? 'selected' : ''; ?>>Refurbished</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Product Image</label>
                                            
                                            <?php if ($product && $product['image_url']): ?>
                                                <div class="mb-2">
                                                    <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                                         alt="Current product image" 
                                                         style="max-width: 200px; max-height: 200px; object-fit: cover;"
                                                         class="border rounded">
                                                    <div class="small text-muted mt-1">Current image</div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="mb-2">
                                                <label class="form-label small">Upload Image File</label>
                                                <input type="file" class="form-control" name="image_file" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                                                <small class="text-muted">Upload a product image (JPEG, PNG, GIF, or WebP)</small>
                                            </div>
                                            
                                            <div class="text-muted text-center my-2">OR</div>
                                            
                                            <div>
                                                <label class="form-label small">Image URL</label>
                                                <input type="url" class="form-control" name="image_url" 
                                                       value="<?php echo $product ? htmlspecialchars($product['image_url']) : ''; ?>"
                                                       placeholder="https://example.com/image.jpg">
                                                <small class="text-muted">Enter a URL to an external image</small>
                                            </div>
                                        </div>

                                        <?php if (!$product || $product['source'] === 'manual'): ?>
                                            <input type="hidden" name="source" value="manual">
                                        <?php else: ?>
                                            <input type="hidden" name="source" value="<?php echo $product['source']; ?>">
                                        <?php endif; ?>

                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="show_on_website" id="show_on_website" 
                                                       <?php echo (!$product || $product['show_on_website']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="show_on_website">
                                                    Show on Website
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="?action=list" class="btn btn-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save me-1"></i> <?php echo $action === 'create' ? 'Create' : 'Update'; ?> Product
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete "<span id="productName"></span>"?
                </div>
                <div class="modal-footer">
                    <form method="POST" id="deleteForm">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="product_id" id="deleteProductId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Visibility toggle
        document.querySelectorAll('.visibility-toggle').forEach(toggle => {
            toggle.addEventListener('change', async function() {
                const productId = this.dataset.productId;
                const formData = new FormData();
                formData.append('action', 'toggle_visibility');
                formData.append('product_id', productId);

                try {
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (!result.success) {
                        alert('Failed to update visibility');
                        this.checked = !this.checked;
                    }
                } catch (error) {
                    alert('Error updating visibility');
                    this.checked = !this.checked;
                }
            });
        });

        // Delete confirmation
        document.querySelectorAll('.delete-product').forEach(button => {
            button.addEventListener('click', function() {
                const productId = this.dataset.productId;
                const productName = this.dataset.productName;
                
                document.getElementById('productName').textContent = productName;
                document.getElementById('deleteProductId').value = productId;
                
                const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
                modal.show();
            });
        });
    </script>
</body>
</html>
