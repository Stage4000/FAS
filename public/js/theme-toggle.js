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
    const savedTheme = localStorage.getItem('theme');
    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    if (savedTheme === 'dark' || (!savedTheme && systemPrefersDark)) {
        html.setAttribute('data-theme', 'dark');
        updateIcons(true);
    }
    
    // Function to toggle theme
    function toggleTheme() {
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateIcons(newTheme === 'dark');
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
        if (!localStorage.getItem('theme')) {
            const newTheme = e.matches ? 'dark' : 'light';
            html.setAttribute('data-theme', newTheme);
            updateIcons(e.matches);
        }
    });
})();
