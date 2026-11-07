/**
 * Theme Toggle with Auto-Detection
 * Detects system preference and allows manual toggle
 * Persists user preference in localStorage
 * Supports both floating button and navbar button
 */
(function() {
    const themeToggle = document.getElementById('themeToggle');
    const navbarThemeToggle = document.getElementById('navbarThemeToggle');
    const html = document.documentElement;
    
    // Get icons from both buttons if they exist
    const floatingIcon = themeToggle ? themeToggle.querySelector('i') : null;
    const navbarIcon = navbarThemeToggle ? navbarThemeToggle.querySelector('i') : null;

    let storedTheme = null;
    let transientTheme = null;

    function getThemePreference() {
        // A failed save must not let system changes undo the manual choice.
        if (transientTheme) return transientTheme;
        try {
            storedTheme = window.localStorage.getItem('theme');
        } catch (e) {
            // Keep the last known preference while storage is unavailable.
        }
        return storedTheme;
    }

    function saveThemePreference(theme) {
        transientTheme = theme;
        try {
            window.localStorage.setItem('theme', theme);
            storedTheme = theme;
            transientTheme = null;
        } catch (e) {
            // The visible theme still works without persistent storage.
        }
    }
    
    // Function to update all icons
    function updateIcons(isDark) {
        [floatingIcon, navbarIcon].forEach(icon => {
            if (icon) {
                if (isDark) {
                    icon.classList.remove('fa-moon');
                    icon.classList.add('fa-sun');
                } else {
                    icon.classList.remove('fa-sun');
                    icon.classList.add('fa-moon');
                }
            }
        });
    }
    
    // Check for saved theme preference or default to system preference
    const savedTheme = getThemePreference();
    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    const initialDark = savedTheme === 'dark' || (!savedTheme && systemPrefersDark);
    html.setAttribute('data-theme', initialDark ? 'dark' : 'light');
    updateIcons(initialDark);
    
    // Function to toggle theme
    function toggleTheme() {
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        updateIcons(newTheme === 'dark');
        saveThemePreference(newTheme);
    }
    
    // Add click listeners to both buttons
    if (themeToggle) {
        themeToggle.addEventListener('click', toggleTheme);
    }
    
    if (navbarThemeToggle) {
        navbarThemeToggle.addEventListener('click', toggleTheme);
    }
    
    // Listen for system theme changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        if (!getThemePreference()) {
            const newTheme = e.matches ? 'dark' : 'light';
            html.setAttribute('data-theme', newTheme);
            updateIcons(e.matches);
        }
    });
})();
