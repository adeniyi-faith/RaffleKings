import { useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Info, OctagonAlert, PauseCircle, Wrench } from 'lucide-react';

const OVERALL = {
    ok: ['All systems working', 'bg-green-600', CheckCircle2],
    degraded: ['Some things aren\'t working right now', 'bg-amber-500', AlertTriangle],
    outage: ['We\'re having a major problem', 'bg-red-600', OctagonAlert],
    maintenance: ['Down for planned maintenance', 'bg-indigo-600', Wrench],
};

const PART = {
    working: ['Working', 'text-green-600 dark:text-green-400', CheckCircle2],
    partly: ['Partly paused', 'text-amber-600 dark:text-amber-400', AlertTriangle],
    paused: ['Paused', 'text-amber-600 dark:text-amber-400', PauseCircle],
    maintenance: ['Maintenance', 'text-indigo-600 dark:text-indigo-400', Wrench],
};

const MESSAGE = {
    info: 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/40 dark:bg-blue-900/20 dark:text-blue-100',
    degraded: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-100',
    outage: 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-100',
};

// Public /status page: is the site working, and if not, what's affected
// and what staff say about it. Refreshes itself every minute.
export default function Status({ status }) {
    useEffect(() => {
        const timer = setInterval(() => router.reload({ only: ['status'] }), 60 * 1000);

        return () => clearInterval(timer);
    }, []);

    const [title, colour, Icon] = OVERALL[status.overall] ?? OVERALL.ok;

    return (
        <>
            <Head title="Site status" />
            <div className="min-h-screen bg-gray-50 pb-16 dark:bg-dark-bg">
                <div className={`${colour} px-6 pb-10 pt-12 text-center text-white`}>
                    <Icon className="mx-auto h-10 w-10" />
                    <h1 className="mt-3 text-2xl font-black">{title}</h1>
                    <p className="mt-1 text-xs text-white/80">Checked {new Date(status.checked_at).toLocaleTimeString()}</p>
                </div>

                <div className="mx-auto -mt-6 max-w-lg space-y-4 px-5">
                    {status.message && (
                        <div className={`flex gap-3 rounded-2xl border p-4 text-sm ${MESSAGE[status.level] ?? MESSAGE.info}`}>
                            <Info className="mt-0.5 h-4 w-4 flex-shrink-0" />
                            <p>{status.message}</p>
                        </div>
                    )}

                    {status.maintenance && (
                        <div className="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900 dark:border-indigo-900/40 dark:bg-indigo-900/20 dark:text-indigo-100">
                            <p>{status.maintenance.message}</p>
                            {status.maintenance.back_at && <p className="mt-1 font-bold">Back at {new Date(status.maintenance.back_at).toLocaleString()}</p>}
                        </div>
                    )}

                    <div className="divide-y divide-gray-50 rounded-2xl border border-gray-100 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-dark-card">
                        {status.parts.map((part) => {
                            const [label, textColour, PartIcon] = PART[part.state] ?? PART.working;

                            return (
                                <div key={part.name} className="flex items-center justify-between px-4 py-3.5">
                                    <span className="text-sm font-semibold text-gray-900 dark:text-white">{part.name}</span>
                                    <span className={`flex items-center gap-1.5 text-xs font-bold ${textColour}`}>
                                        <PartIcon className="h-4 w-4" /> {label}
                                    </span>
                                </div>
                            );
                        })}
                    </div>

                    <p className="text-center text-xs text-gray-500 dark:text-gray-400">
                        Money you've already paid is always safe and is credited as soon as things are back.
                    </p>

                    <div className="text-center">
                        <Link href="/" className="text-sm font-bold text-app-primary">Back to raffles</Link>
                    </div>
                </div>
            </div>
        </>
    );
}
