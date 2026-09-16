import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Hash, Ticket } from 'lucide-react';

// Faithful rebuild of my-tickets.php against the new ledger/wallet/
// bank-account-era account APIs (item 26) — same skeleton loader, same
// grouped-by-raffle card layout, same Active/Expired/Concluded badges.
// See AccountReadService::tickets() for the one real fix over the legacy
// `rk_get_user_tickets()`: "Concluded" now reflects the raffle's REAL
// remaining-ticket count (item 24's fix), not just a manually-set flag
// that could drift from reality.
const STATUS_STYLES = {
    Active: {
        badge: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400',
        box: 'bg-blue-50/50 dark:bg-blue-900/10 border-blue-100 dark:border-blue-900/20',
    },
    Expired: {
        badge: 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-300',
        box: 'bg-gray-50 dark:bg-gray-800/50 border-gray-100 dark:border-gray-700 opacity-75',
    },
    Concluded: {
        badge: 'bg-gray-800 dark:bg-gray-700 text-white dark:text-gray-300',
        box: 'bg-gray-50 dark:bg-gray-800/50 border-gray-100 dark:border-gray-700 opacity-75',
    },
};

export default function AccountTickets() {
    const [state, setState] = useState('loading'); // loading | error | ready
    const [groups, setGroups] = useState([]);
    const [errorMessage, setErrorMessage] = useState('');

    useEffect(() => {
        const controller = new AbortController();

        fetch('/api/account/tickets', { signal: controller.signal, headers: { Accept: 'application/json' } })
            .then((res) => {
                if (res.status === 401) {
                    router.visit('/login?redirect=' + encodeURIComponent('/account/tickets'));
                    return null;
                }
                if (! res.ok) {
                    throw new Error(`Server Error ${res.status}`);
                }
                return res.json();
            })
            .then((payload) => {
                if (! payload) return;
                setGroups(payload.data || []);
                setState('ready');
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                setErrorMessage(err.message);
                setState('error');
            });

        return () => controller.abort();
    }, []);

    return (
        <>
            <Head title="My Tickets" />
            <div className="min-h-screen bg-gray-50 pb-36 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white px-5 pb-4 pt-4 shadow-sm dark:border-dark-border dark:bg-dark-bg dark:shadow-none">
                    <button
                        onClick={() => window.history.back()}
                        className="-ml-1 p-1 text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-200"
                    >
                        <ArrowLeft className="h-5 w-5" />
                    </button>
                    <h2 className="text-lg font-bold text-gray-900 dark:text-white">My Tickets</h2>
                </div>

                <div className="space-y-4 p-5">
                    {state === 'loading' && (
                        <div className="space-y-4">
                            {[0, 1].map((i) => (
                                <div
                                    key={i}
                                    className="animate-pulse rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-dark-border dark:bg-dark-card"
                                >
                                    <div className="mb-3 flex items-start justify-between">
                                        <div className="w-2/3 space-y-2">
                                            <div className="h-4 w-3/4 rounded bg-gray-200 dark:bg-gray-700" />
                                            <div className="h-3 w-1/2 rounded bg-gray-200 dark:bg-gray-700" />
                                        </div>
                                        <div className="w-16 space-y-2">
                                            <div className="h-5 w-full rounded-lg bg-green-100 dark:bg-green-900/30" />
                                            <div className="h-3 w-full rounded bg-gray-200 dark:bg-gray-700" />
                                        </div>
                                    </div>
                                    <div className="h-20 rounded-xl bg-gray-100 dark:bg-gray-800" />
                                </div>
                            ))}
                        </div>
                    )}

                    {state === 'error' && (
                        <div className="py-6 text-center">
                            <p className="mb-2 text-xs font-bold text-red-500">Failed to load tickets.</p>
                            <button
                                onClick={() => window.location.reload()}
                                className="rounded bg-gray-100 px-3 py-1 text-xs text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                Retry
                            </button>
                            <p className="mt-2 font-mono text-[10px] text-gray-400">{errorMessage}</p>
                        </div>
                    )}

                    {state === 'ready' && groups.length === 0 && (
                        <div className="py-10 text-center">
                            <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                                <Ticket className="h-8 w-8 text-gray-400 dark:text-gray-500" />
                            </div>
                            <h3 className="mb-1 font-bold text-gray-900 dark:text-white">No Tickets Yet</h3>
                            <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">You haven't entered any raffles.</p>
                            <Link
                                href="/raffles"
                                className="rounded-xl bg-app-primary px-6 py-2 text-sm font-bold text-white shadow-md transition-transform active:scale-95"
                            >
                                Browse Raffles
                            </Link>
                        </div>
                    )}

                    {state === 'ready' &&
                        groups.map((group) => {
                            const style = STATUS_STYLES[group.status] || STATUS_STYLES.Concluded;

                            return (
                                <div
                                    key={group.raffle_id}
                                    className="relative overflow-hidden rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-dark-border dark:bg-dark-card"
                                >
                                    <div className="mb-3 flex items-start justify-between">
                                        <div>
                                            <h3 className="text-base font-bold text-gray-900 dark:text-white">{group.raffle_title}</h3>
                                            <p className="mt-0.5 text-[10px] text-gray-400 dark:text-gray-500">
                                                Purchased: {group.date ? new Date(group.date).toLocaleDateString() : '—'}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <span className={`rounded-lg px-2 py-1 text-[10px] font-bold ${style.badge}`}>
                                                {group.status}
                                            </span>
                                            <p className="mt-1 text-[10px] text-gray-400 dark:text-gray-500">ID: #{group.raffle_id}</p>
                                        </div>
                                    </div>

                                    <div className={`rounded-xl border p-3 ${style.box}`}>
                                        <p className="mb-2 flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                            <Hash className="h-3 w-3" /> Your Ticket Numbers
                                        </p>
                                        <div className="flex flex-wrap gap-2">
                                            {group.tickets.map((n) => (
                                                <span
                                                    key={n}
                                                    className="rounded border border-gray-200 bg-white px-2 py-1 font-mono text-xs font-bold text-gray-800 shadow-sm dark:border-gray-700 dark:bg-dark-bg dark:text-gray-300"
                                                >
                                                    {n}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                </div>
            </div>
        </>
    );
}
