<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Flip and Strip - Motorcycle Parts, ATV/UTV Parts, Boat Parts, Automotive Parts, and More">
    <title>Flip and Strip - Quality Motorcycle & ATV/UTV Parts</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="public/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/jpeg" href="gallery/FLIPANDSTRIP.COM_d00a_018a.jpg">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="index.php">
                <img src="gallery/FLIPANDSTRIP.COM_d00a_018a.jpg" alt="Flip and Strip" height="40" class="d-inline-block align-text-top me-2">
                FLIP AND STRIP
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Home</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            Shop by Category
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="products.php?category=motorcycle">Motorcycle Parts</a></li>
                            <li><a class="dropdown-item" href="products.php?category=atv">ATV/UTV Parts</a></li>
                            <li><a class="dropdown-item" href="products.php?category=boat">Boat Parts</a></li>
                            <li><a class="dropdown-item" href="products.php?category=automotive">Automotive Parts</a></li>
                            <li><a class="dropdown-item" href="products.php?category=gifts">Biker Gifts</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="products.php">All Products</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="about.php">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="cart.php">
                            <i class="fas fa-shopping-cart"></i> Cart <span class="badge bg-danger" id="cart-count">0</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section py-5" style="background: linear-gradient(rgba(219, 3, 53, 0.85), rgba(36, 38, 41, 0.85)), url('gallery/aaron-huber-KxeFuXta4SE-unsplash-ts1669126250.jpg') center/cover no-repeat; min-height: 500px;">
        <div class="container text-center text-white py-5 d-flex flex-column justify-content-center" style="min-height: 400px;">
            <h1 class="display-3 fw-bold mb-4">FLIP AND STRIP</h1>
            <p class="lead mb-4">Quality Motorcycle, ATV/UTV, Boat & Automotive Parts</p>
            <p class="fs-5 mb-5">Low miles, tested parts from Harley Davidson, Yamaha, Honda, Kawasaki, Suzuki & more</p>
            <div>
                <a href="products.php" class="btn btn-light btn-lg px-5 py-3">Shop Now</a>
            </div>
        </div>
    </section>

    <!-- Featured Categories -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center mb-5 fw-bold animate-fade-in">Shop by Category</h2>
            <!-- Motorcycle Parts - Full Width -->
            <div class="row g-4 mb-4">
                <div class="col-12">
                    <div class="card category-card category-card-featured h-100 border-0 shadow-lg animate-scale">
                        <div class="card-body text-center p-5">
                            <i class="fas fa-motorcycle display-3 text-danger mb-3"></i>
                            <h4 class="card-title fw-bold">Motorcycle Parts</h4>
                            <p class="card-text text-muted fs-5">Harley, Yamaha, Honda, Suzuki, Kawasaki & More</p>
                            <a href="products.php?category=motorcycle" class="btn btn-danger btn-lg px-5">Browse Motorcycle Parts</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Other Categories -->
            <div class="row g-4">
                <div class="col-md-3 col-sm-6">
                    <div class="card category-card h-100 border-0 shadow-sm animate-slide-left">
                        <div class="card-body text-center p-4">
                            <i class="fas fa-car-side display-4 text-danger mb-3"></i>
                            <h5 class="card-title">ATV/UTV Parts</h5>
                            <p class="card-text text-muted">Honda, Yamaha, Kawasaki, Suzuki ATVs & UTVs</p>
                            <a href="products.php?category=atv" class="btn btn-outline-danger">Browse</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card category-card h-100 border-0 shadow-sm animate-slide-left" style="animation-delay: 0.1s;">
                        <div class="card-body text-center p-4">
                            <i class="fas fa-ship display-4 text-danger mb-3"></i>
                            <h5 class="card-title">Boat Parts</h5>
                            <p class="card-text text-muted">Marine & Boat Parts</p>
                            <a href="products.php?category=boat" class="btn btn-outline-danger">Browse</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card category-card h-100 border-0 shadow-sm animate-slide-right" style="animation-delay: 0.2s;">
                        <div class="card-body text-center p-4">
                            <i class="fas fa-truck display-4 text-danger mb-3"></i>
                            <h5 class="card-title">Automotive</h5>
                            <p class="card-text text-muted">Auto & Truck Parts</p>
                            <a href="products.php?category=automotive" class="btn btn-outline-danger">Browse</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card category-card h-100 border-0 shadow-sm animate-slide-right" style="animation-delay: 0.3s;">
                        <div class="card-body text-center p-4">
                            <i class="fas fa-gift display-4 text-danger mb-3"></i>
                            <h5 class="card-title">Biker Gifts</h5>
                            <p class="card-text text-muted">Watches, Clothing & Accessories</p>
                            <a href="products.php?category=gifts" class="btn btn-outline-danger">Browse</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="bg-light py-5">
        <div class="container">
            <div class="row g-4 text-center">
                <div class="col-md-3">
                    <div class="feature-icon-wrapper">
                        <i class="fas fa-shipping-fast display-5 text-danger mb-3"></i>
                    </div>
                    <h5>Fast Shipping</h5>
                    <p class="text-muted">Quick delivery via EasyShip</p>
                </div>
                <div class="col-md-3">
                    <div class="feature-icon-wrapper">
                        <i class="fas fa-shield-alt display-5 text-danger mb-3"></i>
                    </div>
                    <h5>Quality Parts</h5>
                    <p class="text-muted">Tested & inspected</p>
                </div>
                <div class="col-md-3">
                    <div class="feature-icon-wrapper">
                        <i class="fas fa-credit-card display-5 text-danger mb-3"></i>
                    </div>
                    <h5>Secure Payment</h5>
                    <p class="text-muted">PayPal checkout</p>
                </div>
                <div class="col-md-3">
                    <div class="feature-icon-wrapper">
                        <i class="fas fa-headset display-5 text-danger mb-3"></i>
                    </div>
                    <h5>Support</h5>
                    <p class="text-muted">Expert assistance</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Call to Action -->
    <section class="py-5 bg-dark text-white">
        <div class="container text-center">
            <h2 class="mb-4">Find the Parts You Need</h2>
            <p class="lead mb-4">Browse our extensive inventory or contact us for specific parts</p>
            <div class="d-flex gap-3 justify-content-center">
                <a href="products.php" class="btn btn-danger btn-lg">View All Products</a>
                <a href="contact.php" class="btn btn-outline-light btn-lg">Contact Us</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-black text-white py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <h5 class="fw-bold">FLIP AND STRIP</h5>
                    <p>Quality motorcycle, ATV/UTV, and boat parts</p>
                </div>
                <div class="col-md-4 mb-3">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li><a href="products.php" class="text-white-50 text-decoration-none">Shop</a></li>
                        <li><a href="about.php" class="text-white-50 text-decoration-none">About</a></li>
                        <li><a href="contact.php" class="text-white-50 text-decoration-none">Contact</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-3">
                    <h6>Connect</h6>
                    <p><a href="https://flipandstrip.com" class="text-white-50 text-decoration-none">flipandstrip.com</a></p>
                    <p><a href="https://www.ebay.com/str/moto800" target="_blank" class="text-white-50 text-decoration-none">eBay Store</a></p>
                </div>
            </div>
            <hr class="bg-white">
            <div class="text-center">
                <p class="mb-0">&copy; <?php echo date('Y'); ?> Flip and Strip. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <script src="public/js/animations.js"></script>
    <script src="public/js/main.js"></script>
</body>
</html>
