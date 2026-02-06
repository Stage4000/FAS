    <!-- Footer -->
    <footer class="bg-black text-white py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <h5 class="fw-bold">FLIP AND STRIP</h5>
                    <p>Quality motorcycle, ATV/UTV, and boat parts</p>
                    <p class="small">Low miles, tested, and inspected parts from top brands including Harley Davidson, Yamaha, Honda, Kawasaki, Suzuki, BMW, and more.</p>
                </div>
                <div class="col-md-4 mb-3">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li><a href="products" class="text-white-50 text-decoration-none">Shop All Products</a></li>
                        <li><a href="products/motorcycle" class="text-white-50 text-decoration-none">Motorcycle Parts</a></li>
                        <li><a href="products/atv" class="text-white-50 text-decoration-none">ATV/UTV Parts</a></li>
                        <li><a href="products/boat" class="text-white-50 text-decoration-none">Boat Parts</a></li>
                        <li><a href="about" class="text-white-50 text-decoration-none">About Us</a></li>
                        <li><a href="contact" class="text-white-50 text-decoration-none">Contact</a></li>
                        <li><a href="cart" class="text-white-50 text-decoration-none">Shopping Cart</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-3">
                    <h6>Connect</h6>
                    <p><a href="https://www.facebook.com/FLIPANDSTRIPMOTORCYCLES/" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none"><i class="fab fa-facebook"></i> Facebook</a></p>
                    <p><a href="https://www.instagram.com/flipandstrip" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none"><i class="fab fa-instagram"></i> Instagram</a></p>
                    <p><a href="https://www.ebay.com/str/moto800" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none"><i class="fas fa-store"></i> eBay Store</a></p>
                    <div class="mt-3">
                        <h6 class="small">Secure Payment & Shipping</h6>
                        <p class="text-white-50 small"><i class="fas fa-shield-alt"></i> PayPal Checkout</p>
                        <p class="text-white-50 small"><i class="fas fa-shipping-fast"></i> Fast Delivery</p>
                    </div>
                </div>
            </div>
            <hr class="bg-white">
            <div class="text-center">
                <p class="mb-0 small">&copy; <?php echo date('Y'); ?> Flip and Strip. All rights reserved.</p>
            </div>
        </div>
    </footer>

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
        AOS.init({
            duration: 800,
            once: true,
            offset: 100,
            easing: 'ease-in-out'
        });
    </script>
    <!-- Custom JS -->
    <script src="public/js/main.js"></script>
    <!-- Animation & UX Enhancement JS -->
    <script src="public/js/animations.js"></script>
    
    <!-- Theme Toggle Button -->
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode" tabindex="0">
        <i class="fas fa-moon"></i>
    </button>
    
    <!-- Theme Toggle Script -->
    <script src="public/js/theme-toggle.js"></script>
</body>
</html>
