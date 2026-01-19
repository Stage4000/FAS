    <!-- Footer -->
    <footer class="bg-black text-white py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <h5 class="fw-bold">FLIP AND STRIP</h5>
                    <p>Quality motorcycle and ATV parts</p>
                    <p class="small">Low miles, tested, and inspected parts from top brands including Harley Davidson, Yamaha, Honda, Kawasaki, Suzuki, BMW, and more.</p>
                </div>
                <div class="col-md-4 mb-3">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li><a href="products.php" class="text-white-50 text-decoration-none">Shop All Products</a></li>
                        <li><a href="products.php?category=motorcycle" class="text-white-50 text-decoration-none">Motorcycle Parts</a></li>
                        <li><a href="products.php?category=atv" class="text-white-50 text-decoration-none">ATV/UTV Parts</a></li>
                        <li><a href="products.php?category=boat" class="text-white-50 text-decoration-none">Boat Parts</a></li>
                        <li><a href="about.php" class="text-white-50 text-decoration-none">About Us</a></li>
                        <li><a href="contact.php" class="text-white-50 text-decoration-none">Contact</a></li>
                        <li><a href="cart.php" class="text-white-50 text-decoration-none">Shopping Cart</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-3">
                    <h6>Connect</h6>
                    <p><a href="https://flipandstrip.com" class="text-white-50 text-decoration-none"><i class="bi bi-globe"></i> flipandstrip.com</a></p>
                    <p><a href="https://www.ebay.com/str/moto800" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none"><i class="bi bi-shop"></i> eBay Store</a></p>
                    <div class="mt-3">
                        <h6 class="small">Secure Payment & Shipping</h6>
                        <p class="text-white-50 small"><i class="bi bi-shield-check"></i> PayPal Checkout</p>
                        <p class="text-white-50 small"><i class="bi bi-truck"></i> EasyShip Delivery</p>
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
    if (file_exists(__DIR__ . '/../src/config/config.php')) {
        $config = require __DIR__ . '/../src/config/config.php';
        if (isset($config['tawk'])) {
            $tawkEnabled = !empty($config['tawk']['enabled']);
            $tawkPropertyId = $config['tawk']['property_id'] ?? '';
            $tawkWidgetId = $config['tawk']['widget_id'] ?? '';
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
    <!-- Custom JS -->
    <script src="public/js/main.js"></script>
    <!-- Animation & UX Enhancement JS -->
    <script src="public/js/animations.js"></script>
</body>
</html>
