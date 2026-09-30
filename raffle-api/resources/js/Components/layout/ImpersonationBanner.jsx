import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { apiPost } from '../../lib/api';

function clock(seconds) {
    const s = Math.max(0, Math.floor(seconds));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/**
 * Shown on every page while an owner is viewing the site as a customer
 * (App\Services\Admin\Impersonation): who they are viewing, how long is left,
 * and the button that ends it. It never shows to anyone else.
 */
export default function ImpersonationBanner() {
    const { impersonating } = usePage().props;
    const [left, setLeft] = useState(impersonating?.seconds_left ?? 0);
    const [stopping, setStopping] = useState(false);
    const stopped = useRef(false);

    async function stop() {
        if (stopped.current) {
            return;
        }

        stopped.current = true;
        setStopping(true);

        try {
            const data = await apiPost('/api/impersonation/stop', {});
            window.location.href = data.redirect || '/admin';
        } catch {
            window.location.href = '/admin';
        }
    }

    useEffect(() => {
        if (! impersonating) {
            return undefined;
        }

        const deadline = Date.now() + impersonating.seconds_left * 1000;
        const tick = () => {
            const remaining = Math.max(0, Math.round((deadline - Date.now()) / 1000));
            setLeft(remaining);

            if (remaining <= 0) {
                stop();
            }
        };

        tick();
        const timer = setInterval(tick, 1000);

        return () => clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [impersonating?.expires_at]);

    if (! impersonating) {
        return null;
    }

    return (
        <div role="status" className="sticky top-0 z-[100] flex items-center justify-center gap-3 bg-amber-400 px-4 py-2 text-xs font-bold text-gray-900 shadow">
            <Eye className="h-4 w-4 flex-shrink-0" aria-hidden="true" />
            <span className="min-w-0 truncate">
                Viewing as {impersonating.name} · view-only · {clock(left)} left
            </span>
            <button
                onClick={stop}
                disabled={stopping}
                className="flex-shrink-0 rounded-lg bg-gray-900 px-3 py-1 text-white active:scale-95 disabled:opacity-60"
            >
                {stopping ? 'Stopping…' : 'Stop'}
            </button>
        </div>
    );
}
