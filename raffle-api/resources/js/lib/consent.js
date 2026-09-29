import { useEffect, useState } from 'react';

// The visitor's tracking choice, kept in their own browser.
// null = not asked yet, 'granted' = accepted, 'denied' = declined.
const KEY = 'rk_consent';

export function consentStatus() {
    try {
        const value = localStorage.getItem(KEY);

        return value === 'granted' || value === 'denied' ? value : null;
    } catch {
        return null;
    }
}

export function setConsent(value) {
    try {
        localStorage.setItem(KEY, value);
    } catch {
        /* private mode: the choice lasts until the page is closed */
    }

    window.dispatchEvent(new Event('rk-consent-changed'));
}

export function useConsent() {
    const [status, setStatus] = useState(consentStatus);

    useEffect(() => {
        const update = () => setStatus(consentStatus());
        window.addEventListener('rk-consent-changed', update);

        return () => window.removeEventListener('rk-consent-changed', update);
    }, []);

    return status;
}
