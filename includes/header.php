<?php
// Apply the site-configured timezone before any date/time output
require_once __DIR__ . '/../src/utils/Timezone.php';
\FAS\Utils\Timezone::apply();
?>
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
    <meta property="og:type" content="<?php echo isset($ogType) ? htmlspecialchars($ogType) : 'website'; ?>">
    <meta property="og:url" content="<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com') . ($_SERVER['REQUEST_URI'] ?? ''); ?>">
    <meta property="og:title" content="<?php echo isset($pageTitle) ? $pageTitle . ' - Flip and Strip' : 'Flip and Strip - Quality Motorcycle & ATV/UTV Parts'; ?>">
    <meta property="og:description" content="<?php echo isset($pageDescription) ? $pageDescription : 'Quality motorcycle, ATV/UTV, and boat parts. Low miles, tested parts from top brands.'; ?>">
    <meta property="og:image" content="<?php echo isset($ogImage) ? htmlspecialchars($ogImage) : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com') . '/gallery/aaron-huber-KxeFuXta4SE-unsplash-ts1669126250.jpg'; ?>">
    
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com') . ($_SERVER['REQUEST_URI'] ?? ''); ?>">
    <meta property="twitter:title" content="<?php echo isset($pageTitle) ? $pageTitle . ' - Flip and Strip' : 'Flip and Strip - Quality Motorcycle & ATV/UTV Parts'; ?>">
    <meta property="twitter:description" content="<?php echo isset($pageDescription) ? $pageDescription : 'Quality motorcycle, ATV/UTV, and boat parts from top brands.'; ?>">
    <meta property="twitter:image" content="<?php echo isset($ogImage) ? htmlspecialchars($ogImage) : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'flipandstrip.com') . '/gallery/aaron-huber-KxeFuXta4SE-unsplash-ts1669126250.jpg'; ?>">
    
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
    <!-- AOS (Animate On Scroll) -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/public/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/gallery/favicons/favicon.png">
    
    <!-- Theme Color for Mobile Browsers -->
    <meta name="theme-color" content="#db0335">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="msapplication-TileColor" content="#db0335">
    
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
    
    <?php
    // Google Analytics Integration
    $gaEnabled = false;
    $gaMeasurementId = '';
    
    // Try to load from config
    $configPath = __DIR__ . '/../src/config/config.php';
    if (file_exists($configPath)) {
        try {
            $config = require $configPath;
            if (isset($config['google_analytics']) && is_array($config['google_analytics'])) {
                $gaEnabled = !empty($config['google_analytics']['enabled']);
                $gaMeasurementId = $config['google_analytics']['measurement_id'] ?? '';
            }
        } catch (Exception $e) {
            // Silently fail if config has errors
            error_log('Google Analytics config error: ' . $e->getMessage());
        }
    }
    
    if ($gaEnabled && !empty($gaMeasurementId)):
    ?>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($gaMeasurementId); ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?php echo htmlspecialchars($gaMeasurementId); ?>');
    </script>
    <?php endif; ?>
    <!-- Banner dismiss + countdown helpers -->
    <script>
    function dismissBanner(id) {
        var el = document.getElementById('banner-' + id);
        if (el) {
            el.style.transition = 'opacity 0.3s ease';
            el.style.opacity = '0';
            setTimeout(function() { el.remove(); }, 300);
        }
        try { localStorage.setItem('banner_dismissed_' + id, '1'); } catch(e) {}
    }

    // Live countdown ticker for banners with data-end attribute
    (function() {
        function pad(n) { return n < 10 ? '0' + n : n; }

        function updateCountdowns() {
            var spans = document.querySelectorAll('.banner-countdown[data-end]');
            var now = Date.now();
            spans.forEach(function(span) {
                var end = new Date(span.getAttribute('data-end')).getTime();
                var diff = end - now;
                if (diff <= 0) {
                    span.textContent = 'Timer expired';
                    return;
                }
                var days  = Math.floor(diff / 86400000);
                var hours = Math.floor((diff % 86400000) / 3600000);
                var mins  = Math.floor((diff % 3600000)  / 60000);
                var secs  = Math.floor((diff % 60000)    / 1000);
                var parts = [];
                if (days  > 0) parts.push(days  + 'd');
                if (hours > 0) parts.push(hours + 'h');
                parts.push(mins + 'm');
                parts.push(pad(secs) + 's');
                span.textContent = parts.join(' ');
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateCountdowns();
            setInterval(updateCountdowns, 1000);
        });
    })();
    </script>
</head>
<body>
    <?php
    // ── Alert Banners ──────────────────────────────────────────────────────────
    // Show active banners above the navbar.  We load them on every page; the
    // table may not exist yet on fresh installs so we silently catch exceptions.
    $activeBanners = [];
    try {
        $bannerConfigPath = __DIR__ . '/../src/config/Database.php';
        $bannerModelPath  = __DIR__ . '/../src/models/Banner.php';
        if (file_exists($bannerConfigPath) && file_exists($bannerModelPath)) {
            if (!class_exists('FAS\\Config\\Database')) {
                require_once $bannerConfigPath;
            }
            if (!class_exists('FAS\\Models\\Banner')) {
                require_once $bannerModelPath;
            }
            $bannerDb    = \FAS\Config\Database::getInstance()->getConnection();
            $bannerModel = new \FAS\Models\Banner($bannerDb);
            $activeBanners = $bannerModel->getActive();
        }
    } catch (Exception $e) {
        // Table may not exist yet – silently ignore
    }
    ?>
    <?php foreach ($activeBanners as $banner): ?>
    <div class="alert-banner bg-<?php echo htmlspecialchars($banner['bg_color']); ?> text-<?php echo htmlspecialchars($banner['text_color']); ?> text-center mb-0 rounded-0 border-0 py-2"
         role="alert"
         id="banner-<?php echo (int) $banner['id']; ?>">
        <?php echo htmlspecialchars($banner['message']); ?>
        <?php if (!empty($banner['show_countdown']) && !empty($banner['countdown_end'])): ?>
            &nbsp;<span class="banner-countdown fw-bold"
                        data-end="<?php echo htmlspecialchars(date('c', strtotime($banner['countdown_end']))); ?>"></span>
        <?php endif; ?>
        <?php if (!empty($banner['link_url'])): ?>
            &nbsp;<a href="<?php echo htmlspecialchars($banner['link_url']); ?>"
               class="fw-bold text-<?php echo htmlspecialchars($banner['text_color']); ?>"><?php echo htmlspecialchars($banner['link_text'] ?: 'Learn more'); ?></a>
        <?php endif; ?>
        <?php if ($banner['is_dismissible']): ?>
        <button type="button"
                class="btn-close<?php echo $banner['text_color'] === 'white' ? ' btn-close-white' : ''; ?> float-end"
                aria-label="Close"
                onclick="dismissBanner(<?php echo (int) $banner['id']; ?>)"></button>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="/" aria-label="Flip and Strip Home">
                <img src="/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg" alt="Flip and Strip Logo" height="40" class="d-inline-block align-text-top me-2 rounded-circle">
                FLIP AND STRIP
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/products">Products</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/contact">Contact</a>
                    </li>
                    <li class="nav-item navbar-inline-mobile">
                        <a class="nav-link" href="/cart">
                            <i class="fas fa-shopping-cart"></i> Cart <span class="badge bg-danger" id="cart-count">0</span>
                        </a>
                    </li>
                    <li class="nav-item navbar-inline-mobile">
                        <a class="nav-link" href="https://www.facebook.com/FLIPANDSTRIPMOTORCYCLES/" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                            <i class="fab fa-facebook"></i>
                        </a>
                    </li>
                    <li class="nav-item navbar-inline-mobile">
                        <a class="nav-link" href="https://www.instagram.com/flipandstrip" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                    </li>
                    <li class="nav-item navbar-inline-mobile">
                        <a class="nav-link" href="https://www.tiktok.com/@user802164683" target="_blank" rel="noopener noreferrer" aria-label="TikTok">
                            <i class="fab fa-tiktok"></i>
                        </a>
                    </li>
                    <li class="nav-item navbar-inline-mobile">
                        <a class="nav-link" href="https://www.ebay.com/str/moto800" target="_blank" rel="noopener noreferrer" aria-label="eBay Store">
                            <i class="fas fa-store"></i>
                        </a>
                    </li>
                    <li class="nav-item navbar-inline-mobile">
                        <button class="nav-link btn btn-link" id="navbarThemeToggle" aria-label="Toggle dark mode">
                            <i class="fas fa-moon"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
