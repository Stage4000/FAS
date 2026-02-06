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
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <!-- AOS (Animate On Scroll) -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="public/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/jpeg" href="gallery/FLIPANDSTRIP.COM_d00a_018a.jpg">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="index.php" aria-label="Flip and Strip Home">
                <img src="gallery/FLIPANDSTRIP.COM_d00a_018a.jpg" alt="Flip and Strip Logo" height="40" class="d-inline-block align-text-top me-2 rounded-circle">
                FLIP AND STRIP
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Home</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Shop by Category
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="products.php?category=motorcycle">Motorcycle Parts</a></li>
                            <li><a class="dropdown-item" href="products.php?category=atv">ATV/UTV Parts</a></li>
                            <li><a class="dropdown-item" href="products.php?category=boat">Boat Parts</a></li>
                            <li><a class="dropdown-item" href="products.php?category=automotive">Automotive Parts</a></li>
                            <li><a class="dropdown-item" href="products.php?category=gifts">Gifts</a></li>
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
                    <li class="nav-item">
                        <button class="nav-link btn btn-link" id="navbarThemeToggle" aria-label="Toggle dark mode">
                            <i class="fas fa-moon"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section parallax-section py-5">
        <div class="container text-center text-white py-5 d-flex flex-column justify-content-center" style="min-height: 600px;">
            <div class="mb-4" data-aos="fade-down" data-aos-duration="800">
                <img src="gallery/hero-image.png" alt="Flip and Strip" style="max-width: 300px; height: auto;" class="img-fluid">
            </div>
            <h1 class="display-2 fw-bold mb-4" data-aos="fade-down" data-aos-duration="1000">FLIP AND STRIP</h1>
            <p class="lead mb-4 fs-3" data-aos="fade-up" data-aos-delay="200" data-aos-duration="1000">Quality Motorcycle, ATV/UTV, Boat & Automotive Parts</p>
            <p class="fs-4 mb-5" data-aos="fade-up" data-aos-delay="400" data-aos-duration="1000">High quality new & used, tested parts from Harley Davidson, Yamaha, Honda, Kawasaki, Suzuki & more!</p>
            <div data-aos="zoom-in" data-aos-delay="600" data-aos-duration="1000">
                <a href="products.php" class="btn btn-light btn-lg px-5 py-3 shadow-lg">
                    <i class="fas fa-search me-2"></i>Shop Now
                </a>
            </div>
        </div>
    </section>

    <!-- Featured Categories -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center mb-5 fw-bold" data-aos="fade-up" data-aos-duration="800">Shop by Category</h2>
            <!-- Motorcycle Parts - Full Width -->
            <div class="row g-4 mb-4">
                <div class="col-12" data-aos="fade-up" data-aos-duration="800">
                    <div class="card category-card category-card-featured h-100 border-0 shadow-lg">
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
                <div class="col-md-3 col-sm-6" data-aos="fade-up" data-aos-delay="100" data-aos-duration="800">
                    <div class="card category-card h-100 border-0 shadow-sm">
                        <div class="card-body text-center p-4">
                            <i class="fas fa-truck display-4 text-danger mb-3"></i>
                            <h5 class="card-title">ATV/UTV Parts</h5>
                            <p class="card-text text-muted">ATV & UTV parts</p>
                            <a href="products.php?category=atv" class="btn btn-outline-danger">Browse</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6" data-aos="fade-up" data-aos-delay="200" data-aos-duration="800">
                    <div class="card category-card h-100 border-0 shadow-sm">
                        <div class="card-body text-center p-4">
                            <i class="fas fa-ship display-4 text-danger mb-3"></i>
                            <h5 class="card-title">Boat Parts</h5>
                            <p class="card-text text-muted">Marine & Boat Parts</p>
                            <a href="products.php?category=boat" class="btn btn-outline-danger">Browse</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6" data-aos="fade-up" data-aos-delay="300" data-aos-duration="800">
                    <div class="card category-card h-100 border-0 shadow-sm">
                        <div class="card-body text-center p-4">
                            <i class="fas fa-car-side display-4 text-danger mb-3"></i>
                            <h5 class="card-title">Automotive Parts</h5>
                            <p class="card-text text-muted">Auto & Truck Parts</p>
                            <a href="products.php?category=automotive" class="btn btn-outline-danger">Browse</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6" data-aos="fade-up" data-aos-delay="400" data-aos-duration="800">
                    <div class="card category-card h-100 border-0 shadow-sm">
                        <div class="card-body text-center p-4">
                            <i class="fas fa-gift display-4 text-danger mb-3"></i>
                            <h5 class="card-title">Gifts</h5>
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
                <div class="col-md-3" data-aos="zoom-in" data-aos-delay="100" data-aos-duration="800">
                    <div class="feature-icon-wrapper">
                        <i class="fas fa-shipping-fast display-5 text-danger mb-3"></i>
                    </div>
                    <h5>Fast Shipping</h5>
                    <p class="text-muted">Quick and reliable delivery</p>
                </div>
                <div class="col-md-3" data-aos="zoom-in" data-aos-delay="200" data-aos-duration="800">
                    <div class="feature-icon-wrapper">
                        <i class="fas fa-shield-alt display-5 text-danger mb-3"></i>
                    </div>
                    <h5>Quality Parts</h5>
                    <p class="text-muted">Tested & inspected</p>
                </div>
                <div class="col-md-3" data-aos="zoom-in" data-aos-delay="300" data-aos-duration="800">
                    <div class="feature-icon-wrapper">
                        <i class="fas fa-credit-card display-5 text-danger mb-3"></i>
                    </div>
                    <h5>Secure Payment</h5>
                    <p class="text-muted">PayPal checkout</p>
                </div>
                <div class="col-md-3" data-aos="zoom-in" data-aos-delay="400" data-aos-duration="800">
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
    <section class="py-5 cta-section">
        <div class="container text-center">
            <h2 class="mb-4">Find the Parts You Need</h2>
            <p class="lead mb-4">Browse our extensive inventory or contact us for specific parts</p>
            <div class="d-flex gap-3 justify-content-center">
                <a href="products.php" class="btn btn-danger btn-lg">View All Products</a>
                <a href="contact.php" class="btn btn-outline-danger btn-lg">Contact Us</a>
            </div>
        </div>
    </section>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
