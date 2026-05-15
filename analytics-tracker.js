// PWA Install Tracking for Google Analytics 4

// 1. Track "Eligible to Install" (Impression)
window.addEventListener('beforeinstallprompt', (e) => {
    // Defines that the user is on a device capable of installing the PWA
    if (typeof gtag === 'function') {
        gtag('event', 'pwa_install_eligible', {
            'event_category': 'PWA',
            'event_label': 'Install Prompt Available'
        });
    }
});

// 2. Track "Successful Install" (Conversion)
window.addEventListener('appinstalled', () => {
    // Fired when the app is successfully added to the home screen
    if (typeof gtag === 'function') {
        gtag('event', 'pwa_install_success', {
            'event_category': 'PWA',
            'event_label': 'App Installed'
        });
    }
    console.log("PWA Install tracked via GA4");
});

// 3. Track Standalone Mode Usage (Launches)
// Check if the app is currently running in standalone mode (installed)
if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
    if (typeof gtag === 'function') {
        gtag('event', 'pwa_launch', {
            'event_category': 'PWA',
            'event_label': 'Launched from Home Screen'
        });
    }
}