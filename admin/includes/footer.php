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
(function () {
    function readJson(key) {
        try {
            return JSON.parse(localStorage.getItem(key) || '{}');
        } catch (error) {
            return {};
        }
    }

    function readStorage(storage, key) {
        try {
            return storage.getItem(key) || '';
        } catch (error) {
            return '';
        }
    }

    function writeAdminHint() {
        try {
            localStorage.setItem('fas_admin_analytics_hint', JSON.stringify({
                seen_at: Date.now(),
                expires_at: Date.now() + (30 * 24 * 60 * 60 * 1000)
            }));
        } catch (error) {}
    }

    writeAdminHint();

    const sessionState = readJson('fas_session_state');
    const visitorState = readJson('fas_visitor_state');
    const sessionId = String(sessionState.session_id || readStorage(sessionStorage, 'fas_session_id')).replace(/[^A-Za-z0-9_-]/g, '');
    const visitorId = String(visitorState.visitor_id || readStorage(localStorage, 'fas_visitor_id')).replace(/[^A-Za-z0-9_-]/g, '');

    if (!sessionId) {
        return;
    }

    fetch('/admin/mark-analytics-session.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            session_id: sessionId,
            visitor_id: visitorId,
            context: {
                page_path: window.location.pathname + window.location.search,
                admin_page: true
            }
        })
    })
        .then(response => response.ok ? response.json().catch(() => ({})) : {})
        .then(data => {
            if (data && data.admin === true && data.marked === true) {
                try {
                    localStorage.setItem('fas_admin_marked_' + sessionId, '1');
                } catch (error) {}
            }
        })
        .catch(() => {});
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
