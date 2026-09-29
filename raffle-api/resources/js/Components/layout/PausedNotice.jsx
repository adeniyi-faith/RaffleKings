import { PauseCircle } from 'lucide-react';
import { isOn, useSite } from '../../lib/site';

// Shown where a feature the admin has paused (Settings → On / off) would
// normally be, with the admin's own message — instead of letting the
// customer fill in a form only to be refused at the end.
export default function PausedNotice({ feature, className = '' }) {
    const site = useSite();

    if (isOn(site, feature)) {
        return null;
    }

    return (
        <div
            role="status"
            className={`flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-200 ${className}`}
        >
            <PauseCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
            <p>{site.paused_message || 'This is paused for a short while. Please try again soon.'}</p>
        </div>
    );
}
