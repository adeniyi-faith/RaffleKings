import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

function Shell({ tone, title, subtitle, children }) {
    return (
        <div role="alertdialog" aria-modal="true" className="fixed inset-0 z-[60] flex items-center justify-center bg-black/85 p-5 backdrop-blur-sm">
            <div className="w-full max-w-sm overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-2xl dark:border-gray-800 dark:bg-dark-card">
                <div className={`px-5 py-4 text-center ${tone === 'red' ? 'bg-red-600' : 'bg-gray-900'}`}>
                    <h2 className="text-xl font-black tracking-tight text-white">{title}</h2>
                    <p className={`mt-0.5 text-xs font-medium ${tone === 'red' ? 'text-red-100' : 'text-gray-300'}`}>{subtitle}</p>
                </div>
                <div className="space-y-3 p-5 text-center">{children}</div>
            </div>
        </div>
    );
}

function repickUrl(raffleId, qty, numbers) {
    return `/raffles/${raffleId}/numbers?${new URLSearchParams({ qty: String(qty), numbers: numbers.join(',') })}`;
}

/** Some of the player's numbers were sold, or are held by someone else. */
export function NumbersTakenNotice({ raffleId, qty, ticketNumbers, unavailable }) {
    const stillFree = ticketNumbers.filter((n) => ! unavailable.includes(n));

    return (
        <Shell tone="red" title="Oh no, too slow! 😕" subtitle="Some of your numbers were taken">
            <div>
                <p className="mb-2 text-[10px] font-bold uppercase tracking-widest text-gray-400">No longer available</p>
                <div className="flex flex-wrap justify-center gap-2">
                    {unavailable.map((n) => (
                        <span key={n} className="flex h-10 min-w-[2.5rem] items-center justify-center rounded-xl bg-gray-100 px-2 text-sm font-black text-gray-400 line-through dark:bg-gray-800">
                            {n}
                        </span>
                    ))}
                </div>
            </div>
            <p className="text-xs leading-snug text-gray-500 dark:text-gray-400">
                Someone else bought these, or is holding them right now.
                {stillFree.length > 0 && ' Your other numbers are still picked. Just choose replacements.'}
            </p>
            <Link
                href={repickUrl(raffleId, qty, stillFree)}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-app-primary py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-500/30 active:scale-[0.98]"
            >
                Pick replacement numbers <ArrowRight className="h-4 w-4" />
            </Link>
        </Shell>
    );
}

/** The hold ran out. The player can try to get the same numbers back. */
export function HoldExpiredNotice({ raffleId, qty, ticketNumbers, onRetry, retrying }) {
    return (
        <Shell tone="dark" title="Time's up ⌛" subtitle="Your numbers were released">
            <p className="text-xs leading-snug text-gray-500 dark:text-gray-400">
                The time ran out. If nobody has taken your numbers yet, you can get them back right now.
            </p>
            <button
                onClick={onRetry}
                disabled={retrying}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-app-primary py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-500/30 active:scale-[0.98] disabled:opacity-60"
            >
                {retrying ? 'Checking…' : 'Get my numbers back'}
            </button>
            <Link href={repickUrl(raffleId, qty, ticketNumbers)} className="block py-1 text-xs font-medium text-gray-400 hover:text-gray-600">
                Pick different numbers
            </Link>
        </Shell>
    );
}
