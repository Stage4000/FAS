        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- AOS (Animate On Scroll) -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    AOS.init({
        duration: 600,
        once: true,
        offset: 50
    });
</script>

<!-- Auto-collapse navbar on mobile when clicking links/buttons -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const navbarCollapse = document.getElementById('adminNavbar');
        const navbarToggler = document.querySelector('.navbar-toggler');
        
        if (navbarCollapse && navbarToggler) {
            // Close navbar when clicking any link or button inside the navbar
            const clickableItems = navbarCollapse.querySelectorAll('a, button:not(#navbarThemeToggle)');
            
            clickableItems.forEach(item => {
                item.addEventListener('click', function() {
                    // Only collapse if navbar is shown (mobile view)
                    if (navbarCollapse.classList.contains('show')) {
                        const bsCollapse = new bootstrap.Collapse(navbarCollapse, {
                            toggle: false
                        });
                        bsCollapse.hide();
                    }
                });
            });
            
            // Also allow clicking the toggler button when expanded to close it
            navbarToggler.addEventListener('click', function() {
                // Bootstrap will handle the toggle automatically
            });
        }
    });
</script>

<!-- Theme Toggle Button -->
<button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode" tabindex="0">
    <i class="fas fa-moon"></i>
</button>

<!-- Theme Toggle Script -->
<script src="../public/js/theme-toggle.js"></script>


<!-- PWA Installer Script (Admin only) -->
<script src="/admin/js/pwa-installer.js"></script>
