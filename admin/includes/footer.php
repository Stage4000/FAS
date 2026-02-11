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

<!-- Force navbar collapse on mobile -->
<script>
(function() {
    // Wait for Bootstrap to load
    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap not loaded');
        return;
    }
    
    // On mobile, ensure clicking the toggler actually works
    const toggler = document.querySelector('.navbar-toggler');
    const navbarCollapse = document.getElementById('adminNavbar');
    
    if (!toggler || !navbarCollapse) {
        return;
    }
    
    // Ensure Bootstrap collapse is initialized
    window.addEventListener('load', function() {
        // Force re-initialization if needed
        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(navbarCollapse, {
            toggle: false
        });
    });
})();
</script>

<!-- Theme Toggle Button -->
<button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode" tabindex="0">
    <i class="fas fa-moon"></i>
</button>

<!-- Theme Toggle Script -->
<script src="../public/js/theme-toggle.js"></script>


<!-- PWA Installer Script (Admin only) -->
<script src="/admin/js/pwa-installer.js"></script>
