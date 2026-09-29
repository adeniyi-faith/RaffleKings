import '../css/app.css';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import SiteNotices from './Components/layout/SiteNotices';
import MaintenanceBanner from './Components/layout/MaintenanceBanner';

const appName = import.meta.env.VITE_APP_NAME || 'RaffleKings';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        // SiteNotices sits beside the page (item 45), so announcements show
        // on every page and don't reset on each navigation.
        createRoot(el).render(
            <>
                <App {...props}>
                    {({ Component, props: pageProps, key }) => (
                        <>
                            {/* Maintenance warnings (Settings → On / off). */}
                            <MaintenanceBanner />
                            <Component key={key} {...pageProps} />
                        </>
                    )}
                </App>
                <SiteNotices />
            </>,
        );
    },
});
