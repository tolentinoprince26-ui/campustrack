(() => {
    'use strict';

    const STORAGE_KEY = 'campustrack-theme';
    const THEME_CSS_ID = 'campustrack-global-theme-css';

    // LOAD GLOBAL THEME STYLESHEET
    // This makes dark mode available across all
    // portal pages that include portal_header.php.

    if (!document.getElementById(THEME_CSS_ID)) {

        const stylesheet = document.createElement('link');

        stylesheet.id = THEME_CSS_ID;
        stylesheet.rel = 'stylesheet';
        stylesheet.href = 'assets/css/theme.css';

        document.head.appendChild(stylesheet);
    }

    // READ SAVED THEME

    function readTheme() {

        try {

            return localStorage.getItem(STORAGE_KEY) === 'dark'
                ? 'dark'
                : 'light';

        } catch (error) {

            return 'light';
        }
    }

    // SAVE THEME

    function saveTheme(theme) {

        try {

            localStorage.setItem(
                STORAGE_KEY,
                theme
            );

        } catch (error) {

            // Theme still works for this session.
        }
    }

    // APPLY THEME TO THE ENTIRE PAGE

    function applyTheme(theme) {

        const mode = theme === 'dark'
            ? 'dark'
            : 'light';

        document.documentElement.setAttribute(
            'data-theme',
            mode
        );

        const toggle = document.getElementById(
            'campustrack-theme-toggle'
        );

        if (toggle) {

            toggle.textContent = mode === 'dark'
                ? 'Light mode'
                : 'Dark mode';

            toggle.setAttribute(
                'aria-pressed',
                String(mode === 'dark')
            );

            toggle.setAttribute(
                'aria-label',
                mode === 'dark'
                    ? 'Switch to light mode'
                    : 'Switch to dark mode'
            );
        }

        // Notify other components.

        document.dispatchEvent(
            new CustomEvent(
                'campustrack:themechange',
                {
                    detail: { theme: mode }
                }
            )
        );
    }

    // INITIALIZE ON EVERY PORTAL PAGE

    function initTheme() {

        const toggle = document.getElementById(
            'campustrack-theme-toggle'
        );

        applyTheme(readTheme());

        if (!toggle) {
            return;
        }

        // The working Dashboard analytics component
        // already has its own theme-change handler.
        // Do not attach a second handler there.

        if (
            document.getElementById('ct-insight-data')
        ) {
            return;
        }

        // CLASSES, ENROLLMENT, ATTENDANCE,
        // HISTORY, REPORTS, MY ATTENDANCE

        toggle.addEventListener('click', () => {

            const current =
                document.documentElement.getAttribute(
                    'data-theme'
                );

            const next = current === 'dark'
                ? 'light'
                : 'dark';

            saveTheme(next);
            applyTheme(next);

        });
    }

    if (document.readyState === 'loading') {

        document.addEventListener(
            'DOMContentLoaded',
            initTheme,
            { once: true }
        );

    } else {

        initTheme();
    }

})();
