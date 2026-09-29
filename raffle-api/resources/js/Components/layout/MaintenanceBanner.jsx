import { usePage } from '@inertiajs/react';
import { CalendarClock, Wrench } from 'lucide-react';

function when(iso) {
    return new Date(iso).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
}

// Two strips, set in the admin's Settings → On / off → Maintenance mode:
//  - staff browsing while maintenance is ON (customers see the maintenance
//    page instead), so nobody forgets to switch it back off;
//  - everyone, a few hours before PLANNED maintenance.
export default function MaintenanceBanner() {
    const maintenance = usePage().props.site?.maintenance;

    if (maintenance?.active && maintenance?.is_staff) {
        return (
            <div className="sticky top-0 z-[60] flex items-center justify-center gap-2 bg-red-600 px-4 py-2 text-center text-xs font-semibold text-white">
                <Wrench className="h-3.5 w-3.5 flex-shrink-0" />
                <span>
                    Maintenance mode is ON. Customers see the "back soon" page.{' '}
                    <a href="/admin/settings" className="underline">Switch it off</a>
                </span>
            </div>
        );
    }

    if (maintenance?.upcoming) {
        const { starts_at: startsAt, back_at: backAt } = maintenance.upcoming;

        return (
            <div className="flex items-center justify-center gap-2 bg-amber-100 px-4 py-2 text-center text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                <CalendarClock className="h-3.5 w-3.5 flex-shrink-0" />
                <span>
                    Planned maintenance from {when(startsAt)}
                    {backAt ? ` until ${when(backAt)}` : ''}. The site will be briefly unavailable.
                </span>
            </div>
        );
    }

    return null;
}
