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
<script>
try {
    localStorage.setItem('fas_admin_analytics_hint', JSON.stringify({
        seen_at: Date.now(),
        expires_at: Date.now() + (30 * 24 * 60 * 60 * 1000)
    }));
} catch (error) {}
</script>

<!-- Theme Toggle Button -->
<button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode" tabindex="0">
    <i class="fas fa-moon"></i>
</button>

<!-- Theme Toggle Script -->
<script src="../public/js/theme-toggle.js"></script>


<!-- PWA Installer Script (Admin only) -->
<script src="/admin/js/pwa-installer.js"></script>
