import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Clock, Gift, Star, Ticket } from 'lucide-react';
import { apiPost } from '../../lib/api';
import { refreshBalances } from '../../lib/balances';
import { goBack } from '../../lib/nav';
import Confetti from '../../Components/ui/Confetti';

// A personal comeback offer (App\Services\Retention\ComebackOffers): what
// it is, a live countdown, and one Claim button. Money offers land as
// ticket credit in the wallet; points go to the rewards balance.
function useSecondsLeft(expiresAt) {
    const left = () => Math.max(0, Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000));
    const [seconds, setSeconds] = useState(left);

    useEffect(() => {
        const timer = setInterval(() => setSeconds(left()), 1000);

        return () => clearInterval(timer);
    }, [expiresAt]);

    return seconds;
}

function Clockface({ seconds }) {
    const parts = [Math.floor(seconds / 3600), Math.floor((seconds % 3600) / 60), seconds % 60];

    return (
        <div className="flex items-center justify-center gap-1.5 font-mono text-2xl font-bold tabular-nums">
            {parts.map((n, i) => (
                <span key={i} className="flex items-center gap-1.5">
                    <span className="rounded-lg bg-gray-900 px-2.5 py-1.5 text-white dark:bg-white dark:text-gray-900">{String(n).padStart(2, '0')}</span>
                    {i < 2 && <span className="text-gray-400">:</span>}
                </span>
            ))}
        </div>
    );
}

export default function OfferShow({ offer }) {
    const seconds = useSecondsLeft(offer.expires_at);
    const [state, setState] = useState(offer.status);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null);
    const [error, setError] = useState(null);
    const [next, setNext] = useState(null);

    const open = state === 'open' && seconds > 0;
    const Icon = offer.kind === 'points' ? Star : offer.kind === 'raffle_ticket' ? Ticket : Gift;

    function claim() {
        setBusy(true);
        setError(null);
        apiPost(`/api/offers/${offer.token}/claim`)
            .then((data) => {
                setState('claimed');
                setMessage(data.message);
                setNext(data.redirect);
                refreshBalances(true);
            })
            .catch((e) => setError(e.message))
            .finally(() => setBusy(false));
    }

    return (
        <>
            <Head title="Your offer" />
            {message && <Confetti />}
            <div className="min-h-screen bg-gradient-to-b from-orange-50 to-gray-50 pb-16 dark:from-dark-bg dark:to-dark-bg">
                <div className="flex items-center gap-3 px-5 pt-4">
                    <button onClick={() => goBack('/')} className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <ArrowLeft className="h-5 w-5" />
                    </button>
                    <span className="text-sm font-semibold text-gray-500 dark:text-gray-400">Just for you</span>
                </div>

                <div className="mx-auto mt-6 max-w-md px-5">
                    <div className="overflow-hidden rounded-3xl bg-white shadow-lg ring-1 ring-black/5 dark:bg-dark-card dark:ring-white/10">
                        <div className="bg-gradient-to-br from-orange-500 to-rose-500 px-6 pb-8 pt-7 text-center text-white">
                            <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-white/20">
                                <Icon className="h-7 w-7" />
                            </div>
                            <p className="text-4xl font-extrabold tracking-tight">{offer.prize_text}</p>
                            <h1 className="mt-3 text-lg font-bold leading-snug">{offer.headline}</h1>
                        </div>

                        <div className="space-y-5 px-6 py-6">
                            <p className="text-center text-sm leading-6 text-gray-600 dark:text-gray-300">{offer.body}</p>

                            {offer.raffle && (
                                <Link href={`/raffles/${offer.raffle.id}`} className="block rounded-2xl bg-gray-50 p-4 text-sm dark:bg-white/5">
                                    <span className="block font-semibold text-gray-900 dark:text-white">{offer.raffle.title}</span>
                                    <span className="text-gray-500 dark:text-gray-400">
                                        {offer.raffle.prize ? `Prize: ${offer.raffle.prize} · ` : ''}₦{Number(offer.raffle.price).toLocaleString()} per ticket
                                    </span>
                                </Link>
                            )}

                            {open && (
                                <div className="text-center">
                                    <p className="mb-2 flex items-center justify-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-rose-600">
                                        <Clock className="h-3.5 w-3.5" /> Ends in
                                    </p>
                                    <Clockface seconds={seconds} />
                                </div>
                            )}

                            {error && <p className="rounded-xl bg-red-50 p-3 text-center text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">{error}</p>}

                            {state === 'claimed' ? (
                                <div className="space-y-3 text-center">
                                    <p className="font-semibold text-emerald-600">{message || 'You claimed this offer.'}</p>
                                    <button
                                        onClick={() => router.visit(next || (offer.kind === 'points' ? '/rewards' : '/raffles'))}
                                        className="w-full rounded-2xl bg-app-primary py-4 text-base font-bold text-white"
                                    >
                                        {offer.kind === 'points' ? 'See my rewards' : 'Pick my tickets'}
                                    </button>
                                </div>
                            ) : open ? (
                                <button
                                    onClick={claim}
                                    disabled={busy}
                                    className="w-full animate-pulse rounded-2xl bg-gradient-to-r from-orange-500 to-rose-500 py-4 text-base font-extrabold text-white shadow-lg disabled:animate-none disabled:opacity-60"
                                >
                                    {busy ? 'Claiming…' : `Claim ${offer.prize_text}`}
                                </button>
                            ) : (
                                <div className="space-y-3 text-center">
                                    <p className="text-sm text-gray-500 dark:text-gray-400">
                                        {state === 'cancelled' ? 'This offer is no longer available.' : 'Sorry, this offer has run out.'}
                                    </p>
                                    <Link href="/raffles" className="block w-full rounded-2xl bg-app-primary py-4 text-base font-bold text-white">
                                        See today's raffles
                                    </Link>
                                </div>
                            )}

                            {offer.kind !== 'points' && (
                                <p className="text-center text-xs text-gray-400">Ticket credit can be spent on any raffle. It can't be withdrawn.</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
