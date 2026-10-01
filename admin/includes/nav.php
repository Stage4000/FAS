<?php
// Determine active page
$currentPage = basename($_SERVER['PHP_SELF']);
if ($currentPage === 'product-content.php') $currentPage = 'product-quality.php';
$compactQualitySidebar = in_array($currentPage, ['product-quality.php', 'shipping-label.php', 'shipping-operations.php'], true);
$ajaxPage = basename($_SERVER['PHP_SELF']);
$ajaxEnabled = in_array($ajaxPage, [
    'error-monitor.php', 'products.php', 'orders.php', 'order-details.php',
    'warehouses.php', 'coupons.php', 'banners.php', 'sale.php', 'free-shipping.php',
    'homepage-categories.php', 'settings.php', 'password.php', 'security.php',
    'growth.php', 'product-content.php', 'product-quality.php', 'stale-inventory.php',
    'ebay-sync-health.php', 'analytics.php', 'administrators.php',
    'shipping-operations.php', 'merchant-feed-health.php',
], true);
$ajaxUrl = '';
if (in_array($ajaxPage, ['products.php', 'warehouses.php'], true) && isset($action)) {
    $ajaxParams = $_GET;
    $ajaxParams['action'] = $action;
    if ($action === 'list') unset($ajaxParams['id']);
    $ajaxUrl = $ajaxPage . '?' . http_build_query($ajaxParams);
}
?>
<?php if ($ajaxEnabled): ?>
<script defer src="js/admin-ajax.js?v=<?php echo filemtime(__DIR__ . '/../js/admin-ajax.js'); ?>"></script>
<?php endif; ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            <i class="fas fa-cog me-2"></i>Flip and Strip Admin
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbar">
            <div class="navbar-nav ms-auto d-flex align-items-lg-center flex-column flex-lg-row">
                <button class="btn btn-link nav-link" id="navbarThemeToggle" aria-label="Toggle dark mode">
                    <i class="fas fa-moon"></i>
                </button>
                <!-- PWA Install Button (hidden by default, shown by JS when available) -->
                <button class="btn btn-outline-info btn-sm me-lg-2 mb-2 mb-lg-0" id="pwaInstallBtn" style="display: none;" title="Install Admin App">
                    <i class="fas fa-download me-1"></i>Install App
                </button>
                <span class="navbar-text text-white me-lg-3 mb-2 mb-lg-0">
                    <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['admin_username']); ?>
                </span>
                <a href="../index.php" class="btn btn-outline-light btn-sm me-lg-2 mb-2 mb-lg-0">
                    <i class="fas fa-arrow-left me-1"></i>Back to Site
                </a>
                <a href="logout.php" class="btn btn-outline-danger btn-sm mb-2 mb-lg-0">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-lg-2">
            <?php if ($compactQualitySidebar): ?>
                <button class="btn btn-outline-secondary d-md-none mb-3 w-100" type="button" data-bs-toggle="collapse" data-bs-target="#qualitySidebar" aria-expanded="false" aria-controls="qualitySidebar">Admin sections</button>
            <?php endif; ?>
            <div class="list-group <?php echo $compactQualitySidebar ? 'collapse d-md-flex' : ''; ?>" <?php echo $compactQualitySidebar ? 'id="qualitySidebar"' : ''; ?>>
    <a href="index.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
    </a>
<a href="products.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'products.php' ? 'active' : ''; ?>">
<i class="fas fa-box me-2"></i>Products
</a>
<a href="ebay-sync-health.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'ebay-sync-health.php' ? 'active' : ''; ?>">
            <i class="fas fa-rotate me-2"></i>eBay Sync Health
</a>
<a href="product-quality.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'product-quality.php' ? 'active' : ''; ?>">
<i class="fas fa-clipboard-check me-2"></i>Product Quality
</a>
<a href="merchant-feed-health.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'merchant-feed-health.php' ? 'active' : ''; ?>">
<i class="fas fa-store me-2"></i>Merchant Feed Health
</a>
<a href="stale-inventory.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'stale-inventory.php' ? 'active' : ''; ?>">
<i class="fas fa-fire me-2"></i>Stale Inventory
</a>
        <a href="orders.php" class="list-group-item list-group-item-action <?php echo in_array($currentPage, ['orders.php', 'order-details.php', 'shipping-label.php'], true) ? 'active' : ''; ?>">
            <i class="fas fa-shopping-cart me-2"></i>Orders
        </a>
        <a href="shipping-operations.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'shipping-operations.php' ? 'active' : ''; ?>">
            <i class="fas fa-truck me-2"></i>Shipping Review
        </a>
    <a href="warehouses.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'warehouses.php' ? 'active' : ''; ?>">
        <i class="fas fa-warehouse me-2"></i>Warehouses
    </a>
    <a href="coupons.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'coupons.php' ? 'active' : ''; ?>">
        <i class="fas fa-tags me-2"></i>Coupons
    </a>
                <a href="banners.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'banners.php' ? 'active' : ''; ?>">
                    <i class="fas fa-bullhorn me-2"></i>Banners
                </a>
        <a href="sale.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'sale.php' ? 'active' : ''; ?>">
            <i class="fas fa-percent me-2"></i>Site-Wide Sale
        </a>
    <a href="free-shipping.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'free-shipping.php' ? 'active' : ''; ?>">
        <i class="fas fa-truck-fast me-2"></i>Free Shipping
    </a>
<a href="analytics.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'analytics.php' ? 'active' : ''; ?>">
<i class="fas fa-chart-line me-2"></i>Analytics
</a>
<a href="growth.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'growth.php' ? 'active' : ''; ?>">
    <i class="fas fa-envelope-open-text me-2"></i>Sales &amp; Email
</a>
<a href="error-monitor.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'error-monitor.php' ? 'active' : ''; ?>">
<i class="fas fa-triangle-exclamation me-2"></i>Error Monitor
</a>
<a href="security.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'security.php' ? 'active' : ''; ?>">
    <i class="fas fa-shield-halved me-2"></i>Security
</a>
<a href="settings.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
<i class="fas fa-cog me-2"></i>Settings
</a>
<a href="administrators.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'administrators.php' ? 'active' : ''; ?>">
    <i class="fas fa-user-shield me-2"></i>Administrators
</a>
    <a href="homepage-categories.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'homepage-categories.php' ? 'active' : ''; ?>">
        <i class="fas fa-sitemap me-2"></i>Homepage Categories
    </a>
    <a href="password.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'password.php' ? 'active' : ''; ?>">
        <i class="fas fa-key me-2"></i>Change Password
    </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-9 col-lg-10" <?php if ($ajaxEnabled): ?>id="admin-content" data-admin-url="<?php echo htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8'); ?>" data-admin-page="<?php echo htmlspecialchars($ajaxPage, ENT_QUOTES, 'UTF-8'); ?>" data-admin-error="<?php echo htmlspecialchars((string)($error ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-admin-notice="<?php echo htmlspecialchars((string)(($success ?? '') ?: ($notice ?? ($notices[$saved ?? ''] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
