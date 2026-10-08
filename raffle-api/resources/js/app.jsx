import '../css/app.css';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import SiteNotices from './Components/layout/SiteNotices';
import ImpersonationBanner from './Components/layout/ImpersonationBanner';
import MaintenanceBanner from './Components/layout/MaintenanceBanner';
import StatusBanner from './Components/layout/StatusBanner';
import OfferBanner from './Components/layout/OfferBanner';
import CookieBanner from './Components/layout/CookieBanner';
import AdPopup from './Components/ads/AdPopup';
// Starts tracking pages visited, for the Back buttons (lib/nav.js).
import './lib/nav';
// Reports JavaScript errors to System → Health (lib/errorReporter.js).
import './lib/errorReporter';
// Page views and who's who for PostHog (lib/analytics.js); off until a key is saved in Settings → Analytics.
import { startAnalytics } from './lib/analytics';

startAnalytics();

const appName = import.meta.env.VITE_APP_NAME || 'RaffleKings';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Phase 9: each page is its own small file, downloaded only when that
    // page is opened, instead of one ~700 KB file with every page in it.
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx');
        return pages[`./Pages/${name}.jsx`]();
    },
    setup({ el, App, props }) {
        // SiteNotices sits beside the page (item 45), so announcements show
        // on every page and don't reset on each navigation.
        createRoot(el).render(
            <>
                <App {...props}>
                    {({ Component, props: pageProps, key }) => (
                        <>
                            {/* An owner viewing the site as a customer (view-only). */}
                            <ImpersonationBanner />
                            {/* Maintenance warnings (Settings → On / off). */}
                            <MaintenanceBanner />
                            {/* The staff's status message (Settings → Backups & status). */}
                            <StatusBanner />
                            {/* A comeback offer waiting to be claimed. */}
                            <OfferBanner />
                            <Component key={key} {...pageProps} />
                            {/* Asks first-time visitors about analytics (Settings → Consent & privacy). */}
                            <CookieBanner />
                            {/* The pop-up ad, when there is one (admin: Site → Ads). */}
                            <AdPopup />
                        </>
                    )}
                </App>
                <SiteNotices />
            </>,
        );
    },
});
