<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/HomepageCategoryMapping.php';
require_once __DIR__ . '/../src/utils/CSRF.php';

use FAS\Config\Database;
use FAS\Models\HomepageCategoryMapping;
use FAS\Utils\CSRF;

$auth = new AdminAuth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$mappingModel = new HomepageCategoryMapping($db);

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_mapping':
                $homepageCategory = $_POST['homepage_category'] ?? '';
                $ebayCat1Name = $_POST['ebay_cat1_name'] ?? '';
                $priority = intval($_POST['priority'] ?? 0);
                
                if (empty($homepageCategory) || empty($ebayCat1Name)) {
                    $error = 'Homepage category and eBay category are required';
                } else {
                    $result = $mappingModel->createOrUpdate($homepageCategory, $ebayCat1Name, $priority);
                    if ($result) {
                        $success = 'Mapping created/updated successfully';
                    } else {
                        $error = 'Failed to create mapping';
                    }
                }
                break;
                
            case 'delete_mapping':
                $mappingId = intval($_POST['mapping_id'] ?? 0);
                if ($mappingId > 0) {
                    $result = $mappingModel->delete($mappingId);
                    if ($result) {
                        $success = 'Mapping deleted successfully';
                    } else {
                        $error = 'Failed to delete mapping';
                    }
                }
                break;
        }
    }
}

// Get all mappings grouped by eBay category
$mappings = $mappingModel->getAllGroupedByEbayCategory();

// Get all eBay categories from products
$ebayCategories = $mappingModel->getEbayCategoriesFromProducts();

// Get available homepage categories
$homepageCategories = HomepageCategoryMapping::HOMEPAGE_CATEGORIES;

$pageTitle = 'Homepage Category Mappings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Admin</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="manifest" href="/admin/manifest.json">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Info Alert -->
            <div class="alert alert-info">
                <h5 class="alert-heading"><i class="fas fa-info-circle"></i> About Homepage Category Mappings</h5>
                <p class="mb-0">
                    Map eBay store categories (level 1) to your website's homepage categories. 
                    This determines which homepage category products appear under based on their eBay categorization.
                    Products are automatically categorized when synced from eBay based on these mappings.
                </p>
            </div>

            <!-- Add New Mapping Form -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Add New Mapping</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="create_mapping">
                        <input type="hidden" name="csrf_token" value="<?php echo CSRF::generateToken(); ?>">
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="ebay_cat1_name" class="form-label">eBay Category (Level 1) *</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="ebay_cat1_name" 
                                           name="ebay_cat1_name" 
                                           list="ebay_categories_list"
                                           placeholder="e.g., MOTORCYCLE, ATV, BOAT"
                                           required>
                                    <datalist id="ebay_categories_list">
                                        <?php foreach ($ebayCategories as $category): ?>
                                            <option value="<?php echo htmlspecialchars($category); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                    <div class="form-text">Enter the eBay store category name (case-insensitive)</div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="homepage_category" class="form-label">Homepage Category *</label>
                                    <select class="form-select" id="homepage_category" name="homepage_category" required>
                                        <option value="">Select a category...</option>
                                        <?php foreach ($homepageCategories as $slug => $name): ?>
                                            <option value="<?php echo htmlspecialchars($slug); ?>">
                                                <?php echo htmlspecialchars($name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <div class="mb-3">
                                    <label for="priority" class="form-label">Priority</label>
                                    <input type="number" 
                                           class="form-control" 
                                           id="priority" 
                                           name="priority" 
                                           value="0" 
                                           min="0" 
                                           max="100">
                                    <div class="form-text">Higher = preferred</div>
                                </div>
                            </div>
                            
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="mb-3 w-100">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-plus"></i> Add Mapping
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Current Mappings -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Current Mappings</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($mappings)): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle"></i> 
                            No mappings configured yet. Add your first mapping above to get started.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>eBay Category (Level 1)</th>
                                        <th>Homepage Category</th>
                                        <th>Priority</th>
                                        <th style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mappings as $ebayCategory => $categoryMappings): ?>
                                        <?php foreach ($categoryMappings as $mapping): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($mapping['ebay_store_cat1_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary">
                                                        <?php echo htmlspecialchars($homepageCategories[$mapping['homepage_category']] ?? $mapping['homepage_category']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($mapping['priority']); ?></td>
                                                <td>
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this mapping?');">
                                                        <input type="hidden" name="action" value="delete_mapping">
                                                        <input type="hidden" name="mapping_id" value="<?php echo $mapping['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo CSRF::generateToken(); ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- eBay Categories from Products (for reference) -->
            <?php if (!empty($ebayCategories)): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Available eBay Categories (from Products)</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-2">
                            These are the unique eBay store category names found in your products. 
                            Use these when creating new mappings.
                        </p>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($ebayCategories as $category): ?>
                                <span class="badge bg-secondary">
                                    <?php echo htmlspecialchars($category); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
