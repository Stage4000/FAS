<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Product.php';
require_once __DIR__ . '/../src/models/Warehouse.php';

$auth = new AdminAuth();
$auth->requireLogin();

use FAS\Config\Database;
use FAS\Models\Product;
use FAS\Models\Warehouse;

// Configuration constants
define('MAX_ADDITIONAL_IMAGES', 10);

$db = Database::getInstance()->getConnection();
$productModel = new Product($db);
$warehouseModel = new Warehouse($db);

$success = '';
$error = '';
$action = $_GET['action'] ?? 'list';
$productId = $_GET['id'] ?? null;

// AJAX endpoint for immediate image removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_remove_image'])) {
    header('Content-Type: application/json');
    
    $productIdToUpdate = $_POST['product_id'] ?? null;
    $imagePathToRemove = $_POST['image_path'] ?? null;
    
    if ($productIdToUpdate && $imagePathToRemove) {
        try {
            $productData = $productModel->getById($productIdToUpdate);
            if ($productData) {
                $currentAdditionalImgs = is_string($productData['images']) ? 
                    json_decode($productData['images'], true) : 
                    ($productData['images'] ?? []);
                
                // Remove the specified image from the array
                $updatedImgList = array_values(array_filter($currentAdditionalImgs, function($img) use ($imagePathToRemove) {
                    return $img !== $imagePathToRemove;
                }));
                
                // Update product with new image list
                $updateSuccess = $productModel->updateImages($productIdToUpdate, json_encode($updatedImgList));
                
                // Try to delete the physical file if it's a local upload
                if ($updateSuccess && (strpos($imagePathToRemove, 'gallery/uploads/') === 0 || strpos($imagePathToRemove, '/gallery/uploads/') === 0)) {
                    $physicalPath = __DIR__ . '/../' . ltrim($imagePathToRemove, '/');
                    if (file_exists($physicalPath)) {
                        @unlink($physicalPath);
                    }
                }
                
                echo json_encode(['success' => $updateSuccess]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Product not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    }
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
            case 'update':
                // Handle image upload
                $imageUrl = $_POST['image_url'] ?? '';
                $additionalImages = [];
                
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
                            // Use absolute path from document root
                            $imageUrl = '/gallery/uploads/' . $filename;
                        } else {
                            $error = 'Failed to upload image file';
                        }
                    } else {
                        $error = 'Invalid image file. Please upload a valid JPEG, PNG, GIF, or WebP image.';
                    }
                }
                
                // Handle multiple additional images
                if (isset($_FILES['additional_images']) && isset($_FILES['additional_images']['name'])) {
                    $uploadDir = __DIR__ . '/../gallery/uploads/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    $allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                    
                    // Check if files were actually uploaded (name array has non-empty values)
                    $fileCount = is_array($_FILES['additional_images']['name']) ? count($_FILES['additional_images']['name']) : 0;
                    for ($i = 0; $i < $fileCount && $i < MAX_ADDITIONAL_IMAGES; $i++) {
                        // Skip if no file was selected for this input
                        if (empty($_FILES['additional_images']['name'][$i])) {
                            continue;
                        }
                        
                        if ($_FILES['additional_images']['error'][$i] === UPLOAD_ERR_OK) {
                            $extension = strtolower(pathinfo($_FILES['additional_images']['name'][$i], PATHINFO_EXTENSION));
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mimeType = finfo_file($finfo, $_FILES['additional_images']['tmp_name'][$i]);
                            finfo_close($finfo);
                            
                            $imageInfo = @getimagesize($_FILES['additional_images']['tmp_name'][$i]);
                            
                            if (in_array($extension, $allowedExtensions) && 
                                in_array($mimeType, $allowedMimeTypes) && 
                                $imageInfo !== false) {
                                $filename = 'product_' . time() . '_' . uniqid() . '.' . $extension;
                                $targetPath = $uploadDir . $filename;
                                
                                if (move_uploaded_file($_FILES['additional_images']['tmp_name'][$i], $targetPath)) {
                                    $additionalImages[] = '/gallery/uploads/' . $filename;
                                }
                            }
                        }
                    }
                }
                
                // Merge with existing images if updating
                if ($_POST['action'] === 'update' && isset($product) && $product) {
                    $existingImages = json_decode($product['images'] ?? '[]', true) ?: [];
                    $additionalImages = array_merge($existingImages, $additionalImages);
                }
                
                if (empty($error)) {
                    // Validate required fields for shipping calculator
                    if (empty($_POST['weight']) || floatval($_POST['weight']) <= 0) {
                        $error = 'Weight is required for accurate shipping calculations';
                    } elseif (empty($_POST['length']) || floatval($_POST['length']) <= 0) {
                        $error = 'Length is required for accurate shipping calculations';
                    } elseif (empty($_POST['width']) || floatval($_POST['width']) <= 0) {
                        $error = 'Width is required for accurate shipping calculations';
                    } elseif (empty($_POST['height']) || floatval($_POST['height']) <= 0) {
                        $error = 'Height is required for accurate shipping calculations';
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
                        'length' => !empty($_POST['length']) ? floatval($_POST['length']) : null,
                        'width' => !empty($_POST['width']) ? floatval($_POST['width']) : null,
                        'height' => !empty($_POST['height']) ? floatval($_POST['height']) : null,
                        'warehouse_id' => !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null,
                        'image_url' => $imageUrl,
                        'images' => $additionalImages,
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
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="manifest" href="/admin/manifest.json">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>
    
    <?php if ($action === 'list'): ?>
        <!-- Product List View -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2">Products</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <a href="?action=create" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-1"></i> Add Product
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
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
                                                            <?php 
                                                            // Check if URL is external (starts with http:// or https://)
                                                            $imgSrc = (strpos($prod['image_url'], 'http://') === 0 || strpos($prod['image_url'], 'https://') === 0) 
                                                                ? $prod['image_url'] 
                                                                : '../' . $prod['image_url'];
                                                            ?>
                                                            <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="" style="width: 50px; height: 50px; object-fit: cover;">
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
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-outline-danger delete-product" 
                                                                    data-product-id="<?php echo $prod['id']; ?>"
                                                                    data-product-name="<?php echo htmlspecialchars($prod['name']); ?>" title="Delete">
                                                                <i class="fas fa-trash"></i>
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
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
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
                            <div class="col-md-5">
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
                                            <label class="form-label">Weight (lbs) <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" name="weight" step="0.01" 
                                                   value="<?php echo $product && $product['weight'] ? $product['weight'] : ''; ?>" required>
                                            <small class="text-muted">Required for shipping calculations</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Length (inches) <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" name="length" step="0.01" 
                                                   value="<?php echo $product && $product['length'] ? $product['length'] : ''; ?>"
                                                   placeholder="Package length" required>
                                            <small class="text-muted">Required for shipping calculations</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Width (inches) <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" name="width" step="0.01" 
                                                   value="<?php echo $product && $product['width'] ? $product['width'] : ''; ?>"
                                                   placeholder="Package width" required>
                                            <small class="text-muted">Required for shipping calculations</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Height (inches) <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" name="height" step="0.01" 
                                                   value="<?php echo $product && $product['height'] ? $product['height'] : ''; ?>"
                                                   placeholder="Package height" required>
                                            <small class="text-muted">Required for shipping calculations</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" name="category">
                                        <option value="">Select Category</option>
                                        <option value="motorcycle" <?php echo $product && $product['category'] === 'motorcycle' ? 'selected' : ''; ?>>Motorcycle Parts</option>
                                        <option value="atv" <?php echo $product && $product['category'] === 'atv' ? 'selected' : ''; ?>>ATV/UTV Parts</option>
                                        <option value="boat" <?php echo $product && $product['category'] === 'boat' ? 'selected' : ''; ?>>Boat Parts</option>
                                        <option value="automotive" <?php echo $product && $product['category'] === 'automotive' ? 'selected' : ''; ?>>Automotive Parts</option>
                                        <option value="gifts" <?php echo $product && $product['category'] === 'gifts' ? 'selected' : ''; ?>>Gifts</option>
                                        <option value="other" <?php echo $product && $product['category'] === 'other' ? 'selected' : ''; ?>>Other</option>
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
                                    <label class="form-label">Warehouse Location</label>
                                    <select class="form-select" name="warehouse_id">
                                        <option value="">Default Warehouse</option>
                                        <?php
                                        $warehouses = $warehouseModel->getAll();
                                        foreach ($warehouses as $wh):
                                        ?>
                                            <option value="<?php echo $wh['id']; ?>" 
                                                <?php echo $product && $product['warehouse_id'] == $wh['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($wh['name']); ?> (<?php echo htmlspecialchars($wh['code']); ?>)
                                                <?php if ($wh['is_default']): ?> - Default<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Used to calculate shipping costs from the warehouse location</small>
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

                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Product Images</label>
                                    
                                    <?php if ($product && $product['image_url']): ?>
                                        <div class="mb-2">
                                            <?php 
                                            // Check if URL is external (starts with http:// or https://)
                                            $imgSrc = (strpos($product['image_url'], 'http://') === 0 || strpos($product['image_url'], 'https://') === 0) 
                                                ? $product['image_url'] 
                                                : '../' . $product['image_url'];
                                            ?>
                                            <img src="<?php echo htmlspecialchars($imgSrc); ?>" 
                                                 alt="Current product image" 
                                                 style="max-width: 200px; max-height: 200px; object-fit: cover;"
                                                 class="border rounded">
                                            <div class="small text-muted mt-1">Main image</div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product && !empty($product['images'])): ?>
                                        <?php 
                                        $additionalImages = is_string($product['images']) ? json_decode($product['images'], true) : $product['images'];
                                        if ($additionalImages && is_array($additionalImages) && count($additionalImages) > 0):
                                        ?>
                                            <div class="mb-2">
                                                <div class="small fw-bold mb-1">Additional images (<?php echo count($additionalImages); ?>):</div>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <?php foreach ($additionalImages as $idx => $img): ?>
                                                        <?php 
                                                        $imgSrc = (strpos($img, 'http://') === 0 || strpos($img, 'https://') === 0) 
                                                            ? $img 
                                                            : '../' . $img;
                                                        ?>
                                                        <div class="position-relative image-hover-container" style="width: 80px; height: 80px;">
                                                            <img src="<?php echo htmlspecialchars($imgSrc); ?>" 
                                                                 alt="" 
                                                                 style="width: 100%; height: 100%; object-fit: cover;"
                                                                 class="border rounded">
                                                            <button type="button" 
                                                                    class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 image-delete-btn" 
                                                                    style="display: none; padding: 0.25rem 0.4rem; z-index: 10;"
                                                                    data-image-index="<?php echo $idx; ?>"
                                                                    data-image-url="<?php echo htmlspecialchars($img); ?>">
                                                                <i class="fas fa-trash-alt" style="font-size: 0.75rem;"></i>
                                                            </button>
                                                            <input type="hidden" name="existing_images[]" value="<?php echo htmlspecialchars($img); ?>" class="existing-image-input">
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <div class="mb-2">
                                        <label class="form-label small">Main Image (Upload File)</label>
                                        <input type="file" class="form-control" name="image_file" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                                        <small class="text-muted">Upload main product image (JPEG, PNG, GIF, or WebP)</small>
                                    </div>
                                    
                                    <div class="text-muted text-center my-2">OR</div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label small">Main Image (URL)</label>
                                        <input type="text" class="form-control" name="image_url" 
                                               value="<?php echo $product ? htmlspecialchars($product['image_url']) : ''; ?>"
                                               placeholder="https://example.com/image.jpg or gallery/uploads/image.png">
                                        <small class="text-muted">Enter a full URL or relative path to an image</small>
                                    </div>
                                    
                                    <hr class="my-3">
                                    
                                    <div>
                                        <label class="form-label small">Additional Images (Multiple)</label>
                                        <input type="file" class="form-control" name="additional_images[]" multiple accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" id="additionalImagesInput">
                                        <small class="text-muted">Upload up to <?php echo MAX_ADDITIONAL_IMAGES; ?> additional product images. Select multiple files at once.</small>
                                        
                                        <!-- Preview container for newly selected images -->
                                        <div id="imagePreviewContainer" class="d-flex flex-wrap gap-2 mt-3" style="display: none !important;"></div>
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
                    <form method="POST" action="products.php" id="deleteForm">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="product_id" id="deleteProductId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Remove Confirmation Modal -->
    <div class="modal fade" id="imgRemoveModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h6 class="modal-title">Remove Image</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="fas fa-exclamation-triangle text-warning fs-1 mb-3"></i>
                    <p class="mb-0">Remove this product image?</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Keep It</button>
                    <button type="button" class="btn btn-danger btn-sm" id="confirmImgRemove">Yes, Remove</button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

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

        // Enhanced Image Management with Modal Confirmation
        let targetImgElement = null;
        const imgRemovalDialog = new bootstrap.Modal(document.getElementById('imgRemoveModal'));
        
        // Setup existing image deletion with modal
        document.querySelectorAll('.image-hover-container').forEach(wrapper => {
            const trashIcon = wrapper.querySelector('.image-delete-btn');
            
            wrapper.addEventListener('mouseenter', () => {
                if (trashIcon) trashIcon.style.display = 'block';
            });
            
            wrapper.addEventListener('mouseleave', () => {
                if (trashIcon) trashIcon.style.display = 'none';
            });
            
            if (trashIcon) {
                trashIcon.addEventListener('click', () => {
                    targetImgElement = wrapper;
                    imgRemovalDialog.show();
                });
            }
        });
        
        // Confirm image removal - now with immediate deletion via AJAX
        document.getElementById('confirmImgRemove').addEventListener('click', async () => {
            if (targetImgElement) {
                const deleteBtn = targetImgElement.querySelector('.image-delete-btn');
                const imagePath = deleteBtn ? deleteBtn.dataset.imageUrl : null;
                const prodId = <?php echo $product ? $product['id'] : 'null'; ?>;
                
                // If editing an existing product, delete immediately via AJAX
                if (prodId && imagePath) {
                    try {
                        const response = await fetch('products.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                ajax_remove_image: '1',
                                product_id: prodId,
                                image_path: imagePath
                            })
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            targetImgElement.remove();
                        } else {
                            alert('Failed to remove image: ' + (result.error || 'Unknown error'));
                        }
                    } catch (err) {
                        alert('Error communicating with server');
                    }
                } else {
                    // For new products or fallback, just remove from DOM
                    const formField = targetImgElement.querySelector('.existing-image-input');
                    if (formField) formField.remove();
                    targetImgElement.remove();
                }
                
                targetImgElement = null;
            }
            imgRemovalDialog.hide();
        });

        // Multi-file accumulation system for new uploads
        const uploadField = document.getElementById('additionalImagesInput');
        const previewZone = document.getElementById('imagePreviewContainer');
        
        if (uploadField && previewZone) {
            let gatheredFiles = [];
            
            uploadField.addEventListener('change', (evt) => {
                const newBatch = Array.from(evt.target.files);
                
                // Accumulate instead of replace
                gatheredFiles.push(...newBatch);
                refreshInputField(); // Update the actual input with accumulated files
                renderPreviews();
                previewZone.style.display = gatheredFiles.length > 0 ? 'flex' : 'none';
            });
            
            function renderPreviews() {
                previewZone.innerHTML = '';
                
                gatheredFiles.forEach((fileObj, position) => {
                    const loader = new FileReader();
                    
                    loader.onload = (readEvt) => {
                        const box = document.createElement('div');
                        box.className = 'position-relative image-hover-container';
                        box.style.cssText = 'width: 80px; height: 80px;';
                        
                        const thumbnail = document.createElement('img');
                        thumbnail.src = readEvt.target.result;
                        thumbnail.alt = fileObj.name;
                        thumbnail.style.cssText = 'width: 100%; height: 100%; object-fit: cover;';
                        thumbnail.className = 'border rounded';
                        
                        const removeIcon = document.createElement('button');
                        removeIcon.type = 'button';
                        removeIcon.className = 'btn btn-danger btn-sm position-absolute top-0 end-0 m-1';
                        removeIcon.style.cssText = 'display: none; padding: 0.25rem 0.4rem; z-index: 10;';
                        removeIcon.innerHTML = '<i class="fas fa-trash-alt" style="font-size: 0.75rem;"></i>';
                        removeIcon.dataset.pos = position;
                        
                        box.addEventListener('mouseenter', () => removeIcon.style.display = 'block');
                        box.addEventListener('mouseleave', () => removeIcon.style.display = 'none');
                        
                        removeIcon.addEventListener('click', function() {
                            const idx = parseInt(this.dataset.pos);
                            gatheredFiles.splice(idx, 1);
                            refreshInputField();
                            renderPreviews();
                            
                            if (gatheredFiles.length === 0) {
                                previewZone.style.display = 'none';
                            }
                        });
                        
                        box.appendChild(thumbnail);
                        box.appendChild(removeIcon);
                        previewZone.appendChild(box);
                    };
                    
                    loader.readAsDataURL(fileObj);
                });
            }
            
            function refreshInputField() {
                const transfer = new DataTransfer();
                gatheredFiles.forEach(f => transfer.items.add(f));
                uploadField.files = transfer.files;
            }
        }
    </script>
</body>
</html>
