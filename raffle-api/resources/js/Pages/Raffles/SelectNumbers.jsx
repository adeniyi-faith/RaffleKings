import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Shuffle, X } from 'lucide-react';
import { useTicketPriceQuote } from '../../hooks/useTicketPriceQuote';
import { formatNaira } from '../../lib/format';

export default function SelectNumbers({ raffle, qty, takenNumbers, maxTickets }) {
    const [selected, setSelected] = useState([]);
    const takenSet = useMemo(() => new Set(takenNumbers), [takenNumbers]);
    const { quote } = useTicketPriceQuote(raffle.id, qty);

    const numbers = useMemo(() => Array.from({ length: maxTickets }, (_, i) => i + 1), [maxTickets]);

    function toggle(n) {
        if (takenSet.has(n)) {
            return;
        }

        setSelected((prev) => {
            if (prev.includes(n)) {
                return prev.filter((x) => x !== n);
            }

            if (prev.length >= qty) {
                return prev;
            }

            return [...prev, n];
        });
    }

    function quickPick() {
        const available = numbers.filter((n) => ! takenSet.has(n));

        for (let i = available.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [available[i], available[j]] = [available[j], available[i]];
        }

        setSelected(available.slice(0, qty));
    }

    function clearAll() {
        setSelected([]);
    }

    function confirm() {
        const params = new URLSearchParams({
            raffle_id: raffle.id,
            qty: String(qty),
            numbers: selected.join(','),
        });

        router.visit(`/checkout?${params}`);
    }

    const remaining = qty - selected.length;

    return (
        <>
            <Head title={`Pick your numbers — ${raffle.title}`} />
            <div className="min-h-screen bg-app-bg pb-32 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur-md dark:border-dark-border dark:bg-dark-bg/95">
                    <Link href={`/raffles/${raffle.id}`} className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <div className="flex-1">
                        <h2 className="truncate text-lg font-bold text-gray-900 dark:text-white">{raffle.title}</h2>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            {remaining > 0 ? `Pick ${remaining} more number${remaining === 1 ? '' : 's'}` : 'All numbers picked!'}
                        </p>
                    </div>
                    <button
                        onClick={quickPick}
                        className="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-bold text-gray-700 shadow-sm active:scale-95 dark:border-dark-border dark:bg-dark-card dark:text-gray-200"
                    >
                        <Shuffle className="h-3.5 w-3.5 text-yellow-500" /> Quick Pick
                    </button>
                </div>

                {selected.length > 0 && (
                    <div className="flex justify-end px-5 pt-2">
                        <button
                            onClick={clearAll}
                            className="flex items-center gap-1 text-[10px] font-semibold text-red-500 hover:text-red-600 dark:text-red-400"
                        >
                            <X className="h-3 w-3" /> Unselect All
                        </button>
                    </div>
                )}

                <div className="flex items-center justify-center gap-4 px-5 py-3 text-[11px] text-gray-500 dark:text-gray-400">
                    <span className="flex items-center gap-1.5">
                        <span className="h-3 w-3 rounded border border-gray-300 bg-white dark:border-gray-600 dark:bg-dark-card" /> Available
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-3 w-3 rounded border border-yellow-500 bg-yellow-400" /> Yours
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-3 w-3 rounded border border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-900/20" /> Sold
                    </span>
                </div>

                <div className="mx-auto grid max-w-lg grid-cols-5 gap-2 p-3 pb-32">
                    {numbers.map((n) => {
                        const isTaken = takenSet.has(n);
                        const isSelected = selected.includes(n);

                        return (
                            <button
                                key={n}
                                onClick={() => toggle(n)}
                                disabled={isTaken}
                                className={[
                                    'relative flex h-12 w-full select-none items-center justify-center rounded-xl text-sm font-bold transition-all active:scale-90',
                                    isTaken
                                        ? 'cursor-not-allowed border border-red-200 bg-red-50 text-red-400 dark:border-red-900 dark:bg-red-900/20 dark:text-red-400'
                                        : isSelected
                                          ? 'scale-105 transform border-yellow-500 bg-yellow-400 text-lg text-gray-900 shadow-lg shadow-yellow-200/50 ring-2 ring-yellow-400 ring-offset-1'
                                          : 'border border-gray-200 bg-gray-50 text-gray-500 hover:bg-gray-100 dark:border-dark-border dark:bg-dark-card dark:text-gray-400 dark:hover:bg-gray-800',
                                ].join(' ')}
                            >
                                {n}
                                {isTaken && (
                                    <span className="absolute bottom-0.5 left-1/2 -translate-x-1/2 text-[8px] font-extrabold tracking-wide text-red-300">
                                        SOLD
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>

            {remaining === 0 && (
                <div className="fixed bottom-0 left-0 w-full px-5 pb-6">
                    <div className="mx-auto flex max-w-md items-center gap-4 rounded-2xl border border-gray-800 bg-gray-900 p-4 text-white shadow-2xl dark:border-dark-border dark:bg-dark-card">
                        <div className="flex-1">
                            <p className="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                Total Pay
                            </p>
                            <p className="text-xl font-bold leading-none text-white">
                                {quote ? formatNaira(quote.discounted) : '…'}
                            </p>
                        </div>
                        <button
                            onClick={confirm}
                            className="flex items-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-bold text-gray-900 shadow-lg transition-transform active:scale-95 dark:bg-app-primary dark:text-white"
                        >
                            Checkout Now <ArrowRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}
