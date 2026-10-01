<?php
require_once __DIR__ . '/ebay-seller-rating.php';
$sellerRating = fasGetCachedSellerRating();
$sellerRatingConfig = fasGetSellerRatingConfig();
?>
    <!-- Footer -->
    <section class="container my-5" aria-labelledby="newsletter-heading">
        <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4 p-lg-5">
            <div class="row g-4 align-items-center">
                <div class="col-lg-5"><h2 id="newsletter-heading" class="h4 fw-bold">Find your next part.</h2><p class="text-muted mb-0">Get new arrivals and offers from Flip and Strip in your inbox.</p></div>
                <div class="col-lg-7"><form data-newsletter-signup>
                    <label for="newsletter-email" class="form-label">Email address</label>
                    <div class="d-flex flex-column flex-sm-row gap-2"><input id="newsletter-email" name="email" type="email" autocomplete="email" maxlength="254" required class="form-control"><button type="submit" class="btn btn-danger text-nowrap">Sign up</button></div>
                    <div class="form-check mt-3"><input id="newsletter-consent" name="consent" type="checkbox" class="form-check-input" required><label for="newsletter-consent" class="form-check-label small">Email me new arrivals and offers from Flip and Strip. I can unsubscribe anytime.</label></div>
                    <p data-growth-status class="small mt-2 mb-0" role="status" aria-live="polite"></p>
                </form></div>
            </div>
        </div></div>
    </section>
    <footer class="bg-black text-white py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <h5 class="fw-bold">FLIP AND STRIP</h5>
                    <p>Motorcycle, ATV/UTV, boat, and automotive parts</p>
                    <p class="small">New and used parts from Harley Davidson, Yamaha, Honda, Kawasaki, Suzuki, BMW, and more. Review each listing for condition details.</p>
                </div>
                <div class="col-md-4 mb-3">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li><a href="/products" class="text-white-50 text-decoration-none">Shop All Products</a></li>
                        <li><a href="/products/motorcycle" class="text-white-50 text-decoration-none">Motorcycle Parts</a></li>
                        <li><a href="/products/atv" class="text-white-50 text-decoration-none">ATV/UTV Parts</a></li>
                        <li><a href="/products/boat" class="text-white-50 text-decoration-none">Boat Parts</a></li>
                        <li><a href="/about" class="text-white-50 text-decoration-none">About Us</a></li>
                        <li><a href="/contact" class="text-white-50 text-decoration-none">Contact</a></li>
                        <li><a href="/cart" class="text-white-50 text-decoration-none">Shopping Cart</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-3">
                    <h6>Connect</h6>
            <p><a href="https://www.facebook.com/FLIPANDSTRIPMOTORCYCLES/" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none"><i class="fab fa-facebook"></i> Facebook</a></p>
            <p><a href="https://www.instagram.com/flipandstrip" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none"><i class="fab fa-instagram"></i> Instagram</a></p>
            <p><a href="https://www.tiktok.com/@user802164683" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none"><i class="fab fa-tiktok"></i> TikTok</a></p>
            <p><a href="mailto:FLIPANDSTRIPCYCLES@GMAIL.COM" class="text-white-50 text-decoration-none"><i class="fas fa-envelope"></i> FLIPANDSTRIPCYCLES@GMAIL.COM</a></p>
            <p><a href="sms:+14073085294" class="text-white-50 text-decoration-none"><i class="fas fa-mobile-alt"></i> 407 308 5294</a></p>
            <?php if ($sellerRating): ?>
                <?php echo fasRenderSellerRatingBlock($sellerRating, 'footer'); ?>
            <?php endif; ?>
                </div>
            </div>
            <hr class="bg-white">
            <div class="text-center">
                <p class="mb-0 small">&copy; <?php echo date('Y'); ?> Flip and Strip. All rights reserved.</p>
            </div>
        </div>
</footer>

<script src="/public/js/runtime-guard.js"></script>

<?php
// Tawk.to Live Chat Integration
    $tawkEnabled = false;
    $tawkPropertyId = '';
    $tawkWidgetId = '';
    
    // Try to load from config
    $configPath = __DIR__ . '/../src/config/config.php';
    if (file_exists($configPath)) {
        try {
            $config = require $configPath;
            if (isset($config['tawk']) && is_array($config['tawk'])) {
                $tawkEnabled = !empty($config['tawk']['enabled']);
                $tawkPropertyId = $config['tawk']['property_id'] ?? '';
                $tawkWidgetId = $config['tawk']['widget_id'] ?? '';
            }
        } catch (Exception $e) {
            // Silently fail if config has errors
            error_log('Tawk.to config error: ' . $e->getMessage());
        }
    }
    
    if ($tawkEnabled && !empty($tawkPropertyId) && !empty($tawkWidgetId)):
    ?>
    <!--Start of Tawk.to Script-->
    <script type="text/javascript">
    var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
    (function(){
    var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
    s1.async=true;
    s1.src='https://embed.tawk.to/<?php echo htmlspecialchars($tawkPropertyId); ?>/<?php echo htmlspecialchars($tawkWidgetId); ?>';
    s1.charset='UTF-8';
    s1.setAttribute('crossorigin','*');
    s0.parentNode.insertBefore(s1,s0);
    })();
    </script>
    <!--End of Tawk.to Script-->
    <?php endif; ?>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- AOS (Animate On Scroll) -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        if (window.AOS) {
        AOS.init({
            disable: () => window.matchMedia('(max-width: 991px), (prefers-reduced-motion: reduce)').matches,
            duration: 800,
            once: true,
            offset: 100,
            easing: 'ease-in-out'
        });
        document.documentElement.classList.add('aos-ready');
        }
    </script>
<!-- Custom JS -->
<script src="/public/js/timezone.js?v=<?php echo filemtime(__DIR__ . '/../public/js/timezone.js'); ?>"></script>
<script src="/public/js/analytics.js?v=<?php echo filemtime(__DIR__ . '/../public/js/analytics.js'); ?>"></script>
<script src="/public/js/address-autofill.js?v=<?php echo filemtime(__DIR__ . '/../public/js/address-autofill.js'); ?>"></script>
<script src="/public/js/main.js?v=<?php echo filemtime(__DIR__ . '/../public/js/main.js'); ?>"></script>
<script src="/public/js/growth.js?v=20260930-1"></script>
    <!-- Animation & UX Enhancement JS -->
    <script src="/public/js/animations.js?v=<?php echo filemtime(__DIR__ . '/../public/js/animations.js'); ?>"></script>
    
    <!-- Theme Toggle Button -->
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode" tabindex="0">
        <i class="fas fa-moon"></i>
    </button>
    
    <!-- Theme Toggle Script -->
    <script src="/public/js/theme-toggle.js"></script>
</body>
</html>
