(() => {
    'use strict';

    // Register the service worker only
    // in supported, secure environments.

    if (
        !('serviceWorker' in navigator) ||
        !window.isSecureContext
    ) {
        return;
    }

    window.addEventListener(
        'load',
        async () => {

            try {

                await navigator.serviceWorker.register(
                    'sw.js',
                    {
                        scope: './'
                    }
                );

            } catch (error) {

                console.warn(
                    'CampusTrack PWA unavailable:',
                    error
                );

            }

        }
    );

})();
