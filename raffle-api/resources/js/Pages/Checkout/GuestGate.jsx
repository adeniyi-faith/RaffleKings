import { useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ArrowRight, ShieldCheck } from 'lucide-react';
import { useNumberHold } from '../../hooks/useNumberHold';
import { useTicketPriceQuote } from '../../hooks/useTicketPriceQuote';
import { formatNaira } from '../../lib/format';
import { clock } from '../../lib/numberHolds';
import { track } from '../../lib/analytics';
import HoldCountdown from '../../Components/raffles/HoldCountdown';
import { HoldExpiredNotice, NumbersTakenNotice } from '../../Components/raffles/HoldNotices';

/**
 * What a visitor who is not signed in sees at checkout. Their picked numbers
 * are held for a few minutes (the same hold a signed-in customer gets), and
 * signing in or signing up brings them straight back to this same order.
 */
export default function GuestGate({ raffle, qty, ticketNumbers, returnTo }) {
    const hold = useNumberHold(raffle.id, ticketNumbers);
    const { quote } = useTicketPriceQuote(raffle.id, qty);
    const redirect = encodeURIComponent(returnTo);
    const held = hold.phase === 'held';

    useEffect(() => {
        track('guest_checkout_viewed', { raffle_id: raffle.id, ticket_count: ticketNumbers.length });
    }, [raffle.id]);

    const changeUrl = `/raffles/${raffle.id}/numbers?${new URLSearchParams({ qty: String(qty), numbers: ticketNumbers.join(',') })}`;

    return (
        <>
            <Head title="Sign in to keep your numbers" />
            <div className="min-h-screen bg-app-bg pb-10 dark:bg-dark-bg">
                <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur-md dark:border-gray-800 dark:bg-dark-bg/95">
                    <Link href={changeUrl} className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white" aria-label="Back to your numbers">
                        <ArrowLeft className="h-6 w-6" />
                    </Link>
                    <h2 className="text-lg font-black tracking-tight text-gray-900 dark:text-white">Almost there!</h2>
                    <div className="ml-auto flex items-center gap-3">
                        {held && <HoldCountdown seconds={hold.secondsLeft} />}
                        <ShieldCheck className="h-4 w-4 text-app-primary" />
                    </div>
                </header>

                <div className="mx-auto max-w-lg space-y-5 p-5">
                    <div className="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                        <div className="mb-4 flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="font-bold text-gray-900 dark:text-white">{raffle.title}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{qty} ticket{qty > 1 ? 's' : ''}</p>
                            </div>
                            <span className="text-xl font-black tracking-tight text-app-primary">{quote ? formatNaira(quote.discounted) : '…'}</span>
                        </div>

                        <p className="mb-2 text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Your lucky numbers</p>
                        <div className="flex flex-wrap gap-2">
                            {ticketNumbers.map((n) => (
                                <span key={n} className="flex h-10 min-w-[2.5rem] items-center justify-center rounded-xl bg-yellow-400 px-2 text-sm font-black text-gray-900 shadow">
                                    {n}
                                </span>
                            ))}
                        </div>
                    </div>

                    <div className="flex items-start gap-3 rounded-xl border border-yellow-100 bg-yellow-50 p-3 dark:border-yellow-900/40 dark:bg-yellow-900/20">
                        <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0 text-yellow-600" />
                        <p className="text-xs leading-snug text-yellow-800 dark:text-yellow-300">
                            {held
                                ? <>We&apos;re holding these numbers for you for <span className="font-black tabular-nums">{clock(hold.secondsLeft)}</span>. </>
                                : 'We\'ll bring you straight back here after you sign in. '}
                            <span className="font-bold">Sign in before the timer runs out.</span> After that, your numbers go back to
                            the pool and someone else can take them.
                        </p>
                    </div>

                    <div className="space-y-3">
                        <Link
                            href={`/register?redirect=${redirect}`}
                            className="flex w-full items-center justify-center gap-2 rounded-xl bg-app-primary py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-500/30 active:scale-[0.98]"
                        >
                            Create free account <ArrowRight className="h-4 w-4" />
                        </Link>
                        <Link
                            href={`/login?redirect=${redirect}`}
                            className="flex w-full items-center justify-center gap-2 rounded-xl bg-gray-900 py-3.5 text-sm font-bold text-white shadow-lg shadow-gray-900/20 active:scale-[0.98] dark:bg-white dark:text-gray-900 dark:shadow-none"
                        >
                            I already have an account
                        </Link>
                        <Link href={changeUrl} className="block py-1 text-center text-xs font-medium text-gray-400 hover:text-gray-600">
                            Change my numbers
                        </Link>
                    </div>
                </div>
            </div>

            {hold.phase === 'unavailable' && (
                <NumbersTakenNotice raffleId={raffle.id} qty={qty} ticketNumbers={ticketNumbers} unavailable={hold.unavailable} />
            )}
            {hold.phase === 'expired' && (
                <HoldExpiredNotice raffleId={raffle.id} qty={qty} ticketNumbers={ticketNumbers} onRetry={hold.retry} retrying={hold.retrying} />
            )}
        </>
    );
}
