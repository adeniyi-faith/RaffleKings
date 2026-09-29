import { useEffect, useState } from 'react';

/**
 * Profile's "Install App" (item 48). Uses the browser's own install offer
 * where there is one (Chrome, Edge, Samsung Internet on Android and
 * desktop), the "Share → Add to Home Screen" steps on an iPhone or iPad,
 * and reports when the app is already installed so the entry can hide.
 */
export function useInstallApp() {
    const standalone = typeof window !== 'undefined'
        && (window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true);
    const ios = typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent);
    const [canPrompt, setCanPrompt] = useState(() => typeof window !== 'undefined' && Boolean(window.__rkInstallPrompt));

    useEffect(() => {
        const update = () => setCanPrompt(Boolean(window.__rkInstallPrompt));
        window.addEventListener('rk-install-available', update);

        return () => window.removeEventListener('rk-install-available', update);
    }, []);

    /** @returns {Promise<'installed'|'dismissed'|'ios'|'manual'>} */
    async function install() {
        const prompt = window.__rkInstallPrompt;

        if (prompt) {
            prompt.prompt();
            const choice = await prompt.userChoice.catch(() => null);
            window.__rkInstallPrompt = null;
            setCanPrompt(false);

            return choice?.outcome === 'accepted' ? 'installed' : 'dismissed';
        }

        if (ios && typeof window.showIosInstallPrompt === 'function') {
            window.showIosInstallPrompt();

            return 'ios';
        }

        return 'manual';
    }

    return { installed: standalone, canPrompt, ios, install };
}
