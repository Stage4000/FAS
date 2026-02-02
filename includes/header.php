<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo isset($pageDescription) ? $pageDescription : 'Flip and Strip - Quality motorcycle parts, ATV/UTV parts, boat parts, and automotive accessories. Low miles, tested parts from Harley Davidson, Yamaha, Honda, Kawasaki, Suzuki, BMW and more.'; ?>">
    <meta name="keywords" content="motorcycle parts, ATV/UTV parts, boat parts, Harley Davidson parts, Yamaha parts, Honda parts, Kawasaki parts, Suzuki parts, BMW parts, motorcycle accessories, ATV/UTV accessories, boat accessories, used motorcycle parts">
    <meta name="author" content="Flip and Strip">
    <meta name="robots" content="index, follow">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com') . ($_SERVER['REQUEST_URI'] ?? ''); ?>">
    <meta property="og:title" content="<?php echo isset($pageTitle) ? $pageTitle . ' - Flip and Strip' : 'Flip and Strip - Quality Motorcycle & ATV/UTV Parts'; ?>">
    <meta property="og:description" content="<?php echo isset($pageDescription) ? $pageDescription : 'Quality motorcycle, ATV/UTV, and boat parts. Low miles, tested parts from top brands.'; ?>">
    <meta property="og:image" content="<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com'); ?>/gallery/aaron-huber-KxeFuXta4SE-unsplash-ts1669126250.jpg">
    
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com') . ($_SERVER['REQUEST_URI'] ?? ''); ?>">
    <meta property="twitter:title" content="<?php echo isset($pageTitle) ? $pageTitle . ' - Flip and Strip' : 'Flip and Strip - Quality Motorcycle & ATV/UTV Parts'; ?>">
    <meta property="twitter:description" content="<?php echo isset($pageDescription) ? $pageDescription : 'Quality motorcycle, ATV/UTV, and boat parts from top brands.'; ?>">
    <meta property="twitter:image" content="<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com'); ?>/gallery/aaron-huber-KxeFuXta4SE-unsplash-ts1669126250.jpg">
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'); ?>">
    
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?>Flip and Strip - Quality Motorcycle & ATV/UTV Parts</title>
    
    <!-- Preconnect to CDNs -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="public/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/jpeg" href="gallery/FLIPANDSTRIP.COM_d00a_018a.jpg">
    
    <!-- Enhanced JSON-LD Structured Data -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "Flip and Strip",
        "url": "<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com'); ?>",
        "logo": "<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com'); ?>/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg",
        "description": "Quality motorcycle parts, ATV/UTV parts, boat parts, and automotive accessories from top brands",
        "address": {
            "@type": "PostalAddress",
            "addressCountry": "US"
        },
        "sameAs": []
    }
    </script>
    
    <?php if (isset($productSchema)): ?>
    <!-- JSON-LD Structured Data for Products -->
    <script type="application/ld+json">
    <?php echo $productSchema; ?>
    </script>
    <?php endif; ?>
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
                        <a class="nav-link" href="index.php">Home</a>
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
