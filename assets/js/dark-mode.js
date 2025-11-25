/**
 * Dark Mode Toggle Functionality
 * وظيفة تفعيل الوضع الداكن
 */

(function() {
    'use strict';

    // Initialize dark mode
    function initDarkMode() {
        const darkModeToggle = document.getElementById('darkModeToggle');
        const currentTheme = localStorage.getItem('theme') || 'light';
        
        // Set initial theme
        document.documentElement.setAttribute('data-theme', currentTheme);
        
        // Update toggle state
        if (darkModeToggle) {
            darkModeToggle.checked = currentTheme === 'dark';
        }
        
        // Update all toggles on the page
        updateAllToggles(currentTheme === 'dark');
    }

    // Toggle dark mode
    function toggleDarkMode() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        // Save to localStorage
        localStorage.setItem('theme', newTheme);
        
        // Apply theme
        document.documentElement.setAttribute('data-theme', newTheme);
        
        // Update all toggles
        updateAllToggles(newTheme === 'dark');
        
        // Dispatch event for other scripts
        window.dispatchEvent(new CustomEvent('themeChange', { detail: { theme: newTheme } }));
    }

    // Update all dark mode toggles on the page
    function updateAllToggles(isDark) {
        const toggles = document.querySelectorAll('#darkModeToggle');
        toggles.forEach(toggle => {
            toggle.checked = isDark;
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDarkMode);
    } else {
        initDarkMode();
    }

    // Add event listeners to all dark mode toggles
    function attachDarkModeListeners() {
        const darkModeToggles = document.querySelectorAll('#darkModeToggle');
        
        darkModeToggles.forEach(toggle => {
            if (!toggle || !toggle.parentNode) {
                return; // تجاهل إذا كان العنصر غير موجود
            }
            
            try {
                // التحقق من وجود data-listener-added لتجنب إضافة listeners متعددة
                if (toggle.hasAttribute('data-listener-added')) {
                    return; // تم إضافة listeners بالفعل
                }
                
                // إضافة event listener جديد
                toggle.addEventListener('change', function(e) {
                    e.stopPropagation();
                    toggleDarkMode();
                });
                
                // إضافة click listener أيضاً للتأكد
                toggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
                
                // التأكد من أن pointer-events مفعلة
                if (toggle.style) {
                    toggle.style.pointerEvents = 'auto';
                    toggle.style.cursor = 'pointer';
                }
                
                // وضع علامة أن listeners تم إضافتها
                toggle.setAttribute('data-listener-added', 'true');
            } catch (error) {
                console.warn('Error attaching dark mode listeners:', error);
            }
        });
    }
    
    // إضافة event listeners عند تحميل DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachDarkModeListeners);
    } else {
        attachDarkModeListeners();
    }
    
    // إعادة إضافة event listeners بعد تحميل الصفحة بالكامل
    window.addEventListener('load', function() {
        setTimeout(attachDarkModeListeners, 100);
    });

    // Listen for theme changes from other tabs/windows
    window.addEventListener('storage', function(e) {
        if (e.key === 'theme') {
            const newTheme = e.newValue || 'light';
            document.documentElement.setAttribute('data-theme', newTheme);
            updateAllToggles(newTheme === 'dark');
        }
    });

    // Expose toggle function globally
    window.toggleDarkMode = toggleDarkMode;
    window.getCurrentTheme = function() {
        return document.documentElement.getAttribute('data-theme') || 'light';
    };
})();

