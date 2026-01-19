<?php
// Determine active page
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            <i class="bi bi-gear-fill me-2"></i>Flip and Strip Admin
        </a>
        <div class="d-flex align-items-center">
            <span class="navbar-text text-white me-3">
                <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($_SESSION['admin_username']); ?>
            </span>
            <a href="../index.php" class="btn btn-outline-light btn-sm me-2">
                <i class="bi bi-box-arrow-left me-1"></i>Back to Site
            </a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
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
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
                <a href="settings.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                    <i class="bi bi-gear me-2"></i>Settings
                </a>
                <a href="coupons.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'coupons.php' ? 'active' : ''; ?>">
                    <i class="bi bi-tag me-2"></i>Coupons
                </a>
                <a href="password.php" class="list-group-item list-group-item-action <?php echo $currentPage === 'password.php' ? 'active' : ''; ?>">
                    <i class="bi bi-key me-2"></i>Change Password
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-9 col-lg-10">
