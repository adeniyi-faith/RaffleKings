import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Clock, Trophy, Gift, Zap, Lock, ArrowRight, ArrowLeft, TrendingUp, Eye } from 'lucide-react';
import { Card } from '../../Components/ui/Card';
import ProgressBar from '../../Components/ui/ProgressBar';
import TicketBundleSelector from '../../Components/raffles/TicketBundleSelector';
import { useCountdown } from '../../hooks/useCountdown';
import { useLiveRaffle } from '../../hooks/useLiveRaffle';
import { useTicketPriceQuotes } from '../../hooks/useTicketPriceQuotes';
import { useTicketPriceQuote } from '../../hooks/useTicketPriceQuote';
import { formatNaira } from '../../lib/format';

const FIXED_QUANTITIES = [1, 2, 3, 5, 10];

export default function RaffleShow({ raffle }) {
    const { auth } = usePage().props;
    const [selectedQty, setSelectedQty] = useState(3);
    const [bulkQty, setBulkQty] = useState(15);

    const { quotes } = useTicketPriceQuotes(raffle.id, FIXED_QUANTITIES);
    const { quote: bulkQuote } = useTicketPriceQuote(raffle.id, bulkQty);
    const activeQuote = quotes[selectedQty] || (selectedQty === bulkQty ? bulkQuote : null);

    const timeLeft = useCountdown(raffle.expiry);

    // Item 30: a live sold/remaining count and an honest "N viewing"
    // number — replacing the audit's fabricated per-raffle "viewing
    // count" (raffles.php) with a real one, sourced the instant anyone,
    // anywhere, actually buys a ticket for this raffle.
    const { soldTickets, remainingTickets, isClosed, viewerCount } = useLiveRaffle(raffle.id, raffle, !! auth.user);
    const progressPct = raffle.max_tickets > 0 ? Math.min(100, Math.round((soldTickets / raffle.max_tickets) * 100)) : 0;

    function handleProceed() {
        const qty = selectedQty;
        const params = new URLSearchParams({ raffle_id: raffle.id, qty: String(qty) });

        if (! auth.user) {
            // Real signal now, not the dead localStorage 'token' check that
            // used to send every visitor — logged in or not — to the
            // register page after picking numbers (item 25).
            router.visit(`/register?redirect=${encodeURIComponent(`/raffles/${raffle.id}/numbers?${params}`)}`);
            return;
        }

        router.visit(`/raffles/${raffle.id}/numbers?${params}`);
    }

    return (
        <>
            <Head title={raffle.title} />
            <div className="min-h-screen bg-app-bg pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur-md dark:border-dark-border dark:bg-dark-bg/95">
                    <Link href="/raffles" className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <h2 className="truncate pr-4 text-lg font-bold text-gray-900 dark:text-white">{raffle.title}</h2>
                </div>

                <section className="p-5 pb-2">
                    <div
                        className={[
                            'relative overflow-hidden rounded-3xl p-6 text-center text-white shadow-xl transition-all duration-500',
                            isClosed
                                ? 'bg-gradient-to-br from-gray-700 to-gray-900'
                                : 'bg-gradient-to-br from-green-600 to-emerald-800 shadow-green-900/20',
                        ].join(' ')}
                    >
                        <div className="absolute right-0 top-0 h-40 w-40 -translate-y-1/2 translate-x-1/2 rounded-full bg-white/10 blur-3xl" />

                        <div className="mb-3 flex flex-wrap items-center justify-center gap-2">
                            <span
                                className={[
                                    'rounded-full px-3 py-1 text-[10px] font-bold shadow-sm',
                                    isClosed ? 'bg-red-600 text-white' : 'animate-pulse bg-yellow-400 text-green-900',
                                ].join(' ')}
                            >
                                {isClosed ? 'RAFFLE CLOSED' : 'LIVE POOL ACTIVE'}
                            </span>
                            {! isClosed && timeLeft && (
                                <span className="flex items-center gap-1 rounded-full border border-white/20 bg-black/30 px-3 py-1 text-[10px] font-bold text-white backdrop-blur-md">
                                    <Clock className="h-3 w-3" /> {timeLeft}
                                </span>
                            )}
                            {viewerCount !== null && (
                                <span className="flex items-center gap-1 rounded-full border border-white/20 bg-black/30 px-3 py-1 text-[10px] font-bold text-white backdrop-blur-md">
                                    <Eye className="h-3 w-3" /> {viewerCount} viewing
                                </span>
                            )}
                        </div>

                        <p className="mb-1 text-xs font-medium uppercase tracking-wide text-green-100">Grand Prize</p>
                        <h1 className="mb-2 text-3xl font-extrabold leading-tight tracking-tight">{raffle.grand_prize}</h1>

                        <div className="mb-6 inline-flex items-center gap-1.5 rounded-lg border border-green-400/30 bg-green-900/30 px-3 py-1.5 backdrop-blur-sm">
                            <TrendingUp className="h-3 w-3 text-green-300" />
                            <span className="text-xs text-green-100">
                                More Tickets = <span className="font-bold text-white">More Wins</span>
                            </span>
                        </div>

                        <div className="mb-2 h-2 w-full overflow-hidden rounded-full bg-black/20">
                            <div
                                className="h-full rounded-full bg-yellow-400 shadow-[0_0_10px_rgba(250,204,21,0.6)] transition-all duration-500"
                                style={{ width: `${isClosed ? 100 : progressPct}%` }}
                            />
                        </div>
                        <div className="flex justify-between text-[10px] font-medium text-green-100 opacity-90">
                            <span>{soldTickets} Sold</span>
                            <span>{remainingTickets} Left</span>
                        </div>
                    </div>
                </section>

                <section className="px-5 py-4">
                    <Card className="space-y-5">
                        <h3 className="border-b border-gray-100 pb-3 text-sm font-bold text-gray-900 dark:border-gray-700 dark:text-white">
                            What You Can Win
                        </h3>
                        <div className="flex items-center gap-4">
                            <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-yellow-200 bg-yellow-100 text-yellow-600 shadow-sm dark:border-yellow-700/50 dark:bg-yellow-900/30 dark:text-yellow-500">
                                <Trophy className="h-5 w-5" />
                            </div>
                            <div className="flex-1">
                                <p className="text-sm font-bold text-gray-800 dark:text-white">{raffle.grand_prize}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">The Grand Prize Winner</p>
                            </div>
                        </div>

                        {raffle.prize_list.length > 0 && (
                            <div className="space-y-3 pt-2">
                                {raffle.prize_list.map((prize, i) => (
                                    <div key={i} className="flex items-center gap-3">
                                        <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-50 dark:bg-gray-700">
                                            <Gift className="h-4 w-4 text-gray-500 dark:text-gray-300" />
                                        </div>
                                        <p className="text-sm text-gray-700 dark:text-gray-300">{prize}</p>
                                    </div>
                                ))}
                            </div>
                        )}

                        <div className="mt-2 flex items-start gap-3 rounded-xl border border-blue-100 bg-blue-50 p-3 dark:border-blue-900/30 dark:bg-blue-900/20">
                            <Zap className="mt-0.5 h-4 w-4 flex-shrink-0 text-blue-600 dark:text-blue-400" />
                            <p className="text-xs leading-relaxed text-blue-800 dark:text-blue-300">
                                <strong>Increase your odds:</strong> more tickets means more chances to win. Lock in your bundle now!
                            </p>
                        </div>
                    </Card>
                </section>

                {isClosed ? (
                    <section className="px-5 py-10 text-center">
                        <div className="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full border-4 border-gray-50 bg-gray-100 shadow-inner dark:border-gray-800 dark:bg-dark-card">
                            <Lock className="h-10 w-10 text-gray-400 dark:text-gray-500" />
                        </div>
                        <h3 className="mb-2 text-2xl font-black text-gray-900 dark:text-white">Raffle Closed</h3>
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            All tickets for this raffle have been claimed.
                        </p>
                    </section>
                ) : (
                    <section className="px-5 py-4">
                        <TicketBundleSelector
                            quotes={quotes}
                            selected={selectedQty}
                            onSelect={setSelectedQty}
                            bulkQty={bulkQty}
                            onBulkQtyChange={(qty) => {
                                setBulkQty(qty);
                                setSelectedQty(qty);
                            }}
                            bulkQuote={bulkQuote}
                        />
                    </section>
                )}
            </div>

            {! isClosed && (
                <div className="fixed bottom-0 left-0 z-50 w-full border-t border-gray-100 bg-white/95 p-4 shadow-[0_-5px_20px_rgba(0,0,0,0.05)] backdrop-blur-md dark:border-dark-border dark:bg-dark-bg/95">
                    <div className="mx-auto flex max-w-md items-center gap-4">
                        <div className="flex-1">
                            <p className="text-[10px] font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                Total ({selectedQty} Tickets)
                            </p>
                            <p className="text-xl font-bold text-gray-900 dark:text-white">
                                {activeQuote ? formatNaira(activeQuote.discounted) : '…'}
                            </p>
                        </div>
                        <button
                            onClick={handleProceed}
                            className="flex flex-[2] items-center justify-center gap-2 rounded-xl bg-app-primary py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-[0.98]"
                        >
                            Select Numbers <ArrowRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}
