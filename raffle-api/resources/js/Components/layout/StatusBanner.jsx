import { usePage } from '@inertiajs/react';
import { AlertTriangle, Info, OctagonAlert } from 'lucide-react';

const STYLES = {
    info: ['bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-100', Info],
    degraded: ['bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100', AlertTriangle],
    outage: ['bg-red-600 text-white', OctagonAlert],
};

// The staff's status message (Settings → Backups & status), on every page
// while there's a problem, with a link to the full /status page.
export default function StatusBanner() {
    const banner = usePage().props.site?.status_banner;

    if (! banner || typeof window !== 'undefined' && window.location.pathname === '/status') {
        return null;
    }

    const [classes, Icon] = STYLES[banner.level] ?? STYLES.info;

    return (
        <div className={`flex items-center justify-center gap-2 px-4 py-2 text-center text-xs font-medium ${classes}`}>
            <Icon className="h-3.5 w-3.5 flex-shrink-0" />
            <span>
                {banner.message}{' '}
                <a href="/status" className="font-bold underline">Status</a>
            </span>
        </div>
    );
}
