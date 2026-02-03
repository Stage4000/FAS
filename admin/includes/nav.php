<?php
// Determine active page
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            <i class="fas fa-cog me-2"></i>Flip and Strip Admin
        </a>
        <div class="d-flex align-items-center">
            <button class="btn btn-link" id="navbarThemeToggle" aria-label="Toggle dark mode">
                <i class="fas fa-moon"></i>
            </button>
            <span class="navbar-text text-white me-3">
                <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['admin_username']); ?>
            </span>
            <a href="../index.php" class="btn btn-outline-light btn-sm me-2">
                <i class="fas fa-arrow-left me-1"></i>Back to Site
            </a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">
                <i class="fas fa-sign-out-alt me-1"></i>Logout
            </a>
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
