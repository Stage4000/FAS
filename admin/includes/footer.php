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
        
        if (navbarCollapse) {
            // Get the Bootstrap Collapse instance (will be created by Bootstrap on first toggle)
            // Close navbar when clicking navigation links or action buttons
            const navigationItems = navbarCollapse.querySelectorAll('a, button');
            
            navigationItems.forEach(item => {
                // Don't auto-collapse for theme toggle button
                if (item.id === 'navbarThemeToggle') {
                    return;
                }
                
                item.addEventListener('click', function(e) {
                    // Only collapse if navbar is currently shown (mobile expanded state)
                    if (navbarCollapse.classList.contains('show')) {
                        // Get the existing Bootstrap Collapse instance
                        const collapseInstance = bootstrap.Collapse.getInstance(navbarCollapse);
                        if (collapseInstance) {
                            collapseInstance.hide();
                        } else {
                            // If instance doesn't exist yet, create one and hide
                            const newCollapse = new bootstrap.Collapse(navbarCollapse, { toggle: false });
                            newCollapse.hide();
                        }
                    }
                });
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
