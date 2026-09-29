import { Link, usePage } from '@inertiajs/react';
import { setConsent, useConsent } from '../../lib/consent';

// Asks first-time visitors whether analytics may run (Settings → Consent & privacy).
// Shown only when tracking is set up AND the banner is switched on; a visitor who
// has answered is never asked again (they can change their mind on the Privacy Policy page).
export default function CookieBanner() {
    const analytics = usePage().props.analytics;
    const status = useConsent();

    if (! analytics?.consent?.required || status !== null) {
        return null;
    }

    return (
        <div
            role="dialog"
            aria-label="Cookie choice"
            className="fixed inset-x-3 bottom-20 z-[70] mx-auto max-w-md rounded-2xl border border-gray-200 bg-white p-4 shadow-2xl dark:border-dark-border dark:bg-dark-card"
        >
            <p className="text-sm leading-relaxed text-gray-700 dark:text-gray-200">{analytics.consent.message}</p>
            <Link href="/privacy-policy" className="mt-1 inline-block text-xs font-bold text-app-primary">
                Read our Privacy Policy
            </Link>
            <div className="mt-3 flex gap-2">
                <button
                    type="button"
                    onClick={() => setConsent('denied')}
                    className="flex-1 rounded-xl border border-gray-200 py-2.5 text-sm font-bold text-gray-700 dark:border-dark-border dark:text-gray-200"
                >
                    Decline
                </button>
                <button
                    type="button"
                    onClick={() => setConsent('granted')}
                    className="flex-1 rounded-xl bg-app-primary py-2.5 text-sm font-bold text-white"
                >
                    Accept
                </button>
            </div>
        </div>
    );
}
