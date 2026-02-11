<?php
// Determine active page
$currentPage = basename($_SERVER['PHP_SELF']);
?>
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
            <div class="list-group">
                <a href="index.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                </a>
                <a href="products.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'products.php' ? 'active' : ''; ?>">
                    <i class="fas fa-box me-2"></i>Products
                </a>
                <a href="orders.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'orders.php' ? 'active' : ''; ?>">
                    <i class="fas fa-shopping-cart me-2"></i>Orders
                </a>
                <a href="warehouses.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'warehouses.php' ? 'active' : ''; ?>">
                    <i class="fas fa-warehouse me-2"></i>Warehouses
                </a>
                <a href="settings.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                    <i class="fas fa-cog me-2"></i>Settings
                </a>
                <a href="coupons.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'coupons.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tags me-2"></i>Coupons
                </a>
                <a href="password.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'password.php' ? 'active' : ''; ?>">
                    <i class="fas fa-key me-2"></i>Change Password
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-9 col-lg-10">
