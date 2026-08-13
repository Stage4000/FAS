<?php
// Apply the site-configured timezone before any date/time output
require_once __DIR__ . '/../src/utils/Timezone.php';
require_once __DIR__ . '/../src/utils/Seo.php';

\FAS\Utils\Timezone::apply();

$seoConfig = [];
$seoConfigPath = __DIR__ . '/../src/config/config.php';
if (file_exists($seoConfigPath)) {
    try {
        $loadedSeoConfig = require $seoConfigPath;
        if (is_array($loadedSeoConfig)) {
            $seoConfig = $loadedSeoConfig;
        }
    } catch (Exception $e) {
        error_log('Site config load error: ' . $e->getMessage());
    }
}

$legacyPageTitle = isset($pageTitle) ? \FAS\Utils\Seo::cleanText($pageTitle) : '';
$metaTitle = isset($metaTitle)
    ? \FAS\Utils\Seo::metaTitle($metaTitle)
    : ($legacyPageTitle !== ''
        ? \FAS\Utils\Seo::metaTitle($legacyPageTitle . ' - Flip and Strip')
        : 'Flip and Strip - Motorcycle, ATV/UTV & Boat Parts');
$metaDescription = isset($metaDescription)
    ? \FAS\Utils\Seo::metaDescription($metaDescription)
    : (isset($pageDescription)
        ? \FAS\Utils\Seo::metaDescription($pageDescription)
        : 'Shop tested used motorcycle, ATV/UTV, boat, and automotive parts from Harley Davidson, Yamaha, Honda, Kawasaki, Suzuki, BMW, and more.');
$canonicalUrl = isset($canonicalUrl)
    ? \FAS\Utils\Seo::canonicalUrl($canonicalUrl)
    : \FAS\Utils\Seo::canonicalUrl(strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
$robotsMeta = isset($robotsMeta) ? \FAS\Utils\Seo::cleanText($robotsMeta) : 'index, follow';
$ogType = isset($ogType) ? \FAS\Utils\Seo::cleanText($ogType) : 'website';
$ogTitle = isset($ogTitle) ? \FAS\Utils\Seo::metaTitle($ogTitle) : $metaTitle;
$ogDescription = isset($ogDescription) ? \FAS\Utils\Seo::metaDescription($ogDescription) : $metaDescription;
$ogImage = isset($ogImage)
    ? \FAS\Utils\Seo::absoluteUrl($ogImage)
    : \FAS\Utils\Seo::absoluteUrl('/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg');
$structuredData = isset($structuredData) && is_array($structuredData) ? $structuredData : [];
array_unshift($structuredData, \FAS\Utils\Seo::organizationSchema($seoConfig));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="author" content="Flip and Strip">
    <meta name="robots" content="<?php echo htmlspecialchars($robotsMeta, ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="<?php echo htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:site_name" content="Flip and Strip">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if (isset($extraHeadMeta)): ?>
    <?php echo $extraHeadMeta; ?>
    <?php endif; ?>
    
    <title><?php echo htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    
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
    
    <!-- JSON-LD Structured Data -->
    <?php foreach ($structuredData as $schema): ?>
    <script type="application/ld+json">
    <?php echo is_string($schema) ? $schema : \FAS\Utils\Seo::schemaJson($schema); ?>
    </script>
    <?php endforeach; ?>
    
    <?php
    // Google Analytics Integration
    $gaEnabled = false;
    $gaMeasurementId = '';
    
    // Try to load from config
    if (isset($seoConfig['google_analytics']) && is_array($seoConfig['google_analytics'])) {
        $gaEnabled = !empty($seoConfig['google_analytics']['enabled']);
        $gaMeasurementId = $seoConfig['google_analytics']['measurement_id'] ?? '';
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

        function autoDismissBanner(bannerId) {
            var el = document.getElementById('banner-' + bannerId);
            if (!el) return;
            el.style.transition = 'opacity 0.3s ease';
            el.style.opacity = '0';
            setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
        }

        function updateCountdowns() {
            var now = Date.now();

            // Auto-dismiss any banner whose ends_at (data-expires) has passed
            var banners = document.querySelectorAll('.alert-banner[data-expires]');
            banners.forEach(function(banner) {
                var expires = new Date(banner.getAttribute('data-expires')).getTime();
                if (expires <= now) {
                    var id = banner.id.replace('banner-', '');
                    autoDismissBanner(id);
                }
            });

            // Update countdown timers
            var spans = document.querySelectorAll('.banner-countdown[data-end]');
            spans.forEach(function(span) {
                var end = new Date(span.getAttribute('data-end')).getTime();
                var diff = end - now;
                if (diff <= 0) {
                    // Countdown has hit zero — dismiss the parent banner immediately
                    var parentBanner = span.closest('.alert-banner');
                    if (parentBanner) {
                        var id = parentBanner.id.replace('banner-', '');
                        autoDismissBanner(id);
                    } else {
                        span.textContent = 'Expired';
                    }
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
id="banner-<?php echo (int) $banner['id']; ?>"
data-analytics-banner="<?php echo (int) $banner['id']; ?>"
data-analytics-campaign="<?php echo htmlspecialchars($banner['message']); ?>"
<?php if (!empty($banner['ends_at'])): ?>data-expires="<?php echo htmlspecialchars(\FAS\Utils\Timezone::toUserIso($banner['ends_at']) ?? '', ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
        <?php echo htmlspecialchars($banner['message']); ?>
        <?php if (!empty($banner['show_countdown']) && !empty($banner['countdown_end'])): ?>
            &nbsp;<span class="banner-countdown fw-bold"
                        data-end="<?php echo htmlspecialchars(\FAS\Utils\Timezone::toUserIso($banner['countdown_end']) ?? '', ENT_QUOTES, 'UTF-8'); ?>"></span>
        <?php endif; ?>
<?php if (!empty($banner['link_url'])): ?>
&nbsp;<a href="<?php echo htmlspecialchars($banner['link_url']); ?>"
class="fw-bold text-<?php echo htmlspecialchars($banner['text_color']); ?>"
data-analytics-banner-link
data-analytics-banner="<?php echo (int) $banner['id']; ?>"
data-analytics-campaign="<?php echo htmlspecialchars($banner['message']); ?>"
data-analytics-target-url="<?php echo htmlspecialchars($banner['link_url']); ?>"><?php echo htmlspecialchars($banner['link_text'] ?: 'Learn more'); ?></a>
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
                        <a class="nav-link<?php echo ($currentPage ?? '') === 'home' ? ' active' : ''; ?>" href="/">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?php echo ($currentPage ?? '') === 'products' ? ' active' : ''; ?>" href="/products">Products</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?php echo ($currentPage ?? '') === 'about' ? ' active' : ''; ?>" href="/about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?php echo ($currentPage ?? '') === 'contact' ? ' active' : ''; ?>" href="/contact">Contact</a>
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
<a class="nav-link" href="https://www.ebay.com/str/moto800" target="_blank" rel="noopener noreferrer" aria-label="eBay Store" data-analytics-source="header_ebay_store">
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
