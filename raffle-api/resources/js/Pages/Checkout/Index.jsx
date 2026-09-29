import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Award,
    Check,
    CheckCircle2,
    Eye,
    Gift,
    PlusCircle,
    RefreshCw,
    Share2,
    ShieldCheck,
    Ticket,
    Wallet,
    XCircle,
} from 'lucide-react';
import { useLiveRaffle } from '../../hooks/useLiveRaffle';
import { useTicketPriceQuote } from '../../hooks/useTicketPriceQuote';
import { useTimeLeft } from '../../hooks/useTimeLeft';
import { formatNaira } from '../../lib/format';
import { apiPost } from '../../lib/api';
import PausedNotice from '../../Components/layout/PausedNotice';
import Confetti from '../../Components/ui/Confetti';

function generateIdempotencyKey() {
    return typeof crypto !== 'undefined' && crypto.randomUUID
        ? crypto.randomUUID()
        : `key-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

// This page's own address without `?deposit=`, so a top-up started here
// comes back here (item 46) and a reload doesn't show the result twice.
function checkoutPath() {
    const url = new URL(window.location.href);
    url.searchParams.delete('deposit');

    return url.pathname + url.search;
}

export default function CheckoutIndex({ raffle, ticketNumbers, qty, minimumDeposit = 100 }) {
    const { quote, loading: quoteLoading, refresh: refreshQuote } = useTicketPriceQuote(raffle.id, qty);

    // Item 30: an honest "N viewing" count and live remaining-ticket
    // number, replacing checkout.php's hardcoded "3 other people are
    // viewing this raffle" exit-modal claim (this page is only ever
    // reached by a logged-in user, per its own server-side guard, so
    // the presence channel is always joined).
    const { remainingTickets, viewerCount } = useLiveRaffle(raffle.id, raffle, true);

    const [wallet, setWallet] = useState(null);
    const [method, setMethod] = useState(null); // chosen once the balances load
    const [status, setStatus] = useState('idle'); // idle | processing | topping-up | success | error
    const [error, setError] = useState(null);
    const [takenNumbers, setTakenNumbers] = useState([]);
    const [purchase, setPurchase] = useState(null);
    const [deposit, setDeposit] = useState(null); // a top-up we just came back from
    const idempotencyKey = useRef(generateIdempotencyKey());

    const price = quote?.discounted ?? 0;
    const golden = quote?.golden_box ?? null;
    const walletBalance = wallet?.wallet_balance ?? 0;
    const earningsBalance = wallet?.earnings_balance ?? 0;
    const balanceFor = (m) => (m === 'wallet' ? walletBalance : earningsBalance);
    const canAfford = (m) => wallet !== null && balanceFor(m) >= price;

    function loadWallet() {
        return fetch('/api/wallet', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => {
                setWallet(data);
                return data;
            })
            .catch(() => setWallet(null));
    }

    useEffect(() => {
        loadWallet();

        // Back from Paystack after "Top up ₦X": show how it went, and wait
        // for the money to land before letting the customer pay.
        const depositId = new URLSearchParams(window.location.search).get('deposit');

        if (! depositId) {
            return undefined;
        }

        window.history.replaceState(window.history.state, '', checkoutPath());

        let tries = 0;
        let timer;

        function check() {
            fetch(`/api/deposits/${depositId}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((res) => (res.ok ? res.json() : null))
                .then((data) => {
                    setDeposit(data);

                    if (data?.status === 'pending' && tries++ < 15) {
                        timer = setTimeout(check, 2000);
                    } else {
                        loadWallet();
                    }
                })
                .catch(() => {});
        }

        check();

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Pick the payment method once we know the balances and the price:
    // the wallet if it covers it, otherwise winnings if they do.
    useEffect(() => {
        if (method === null && wallet && quote) {
            setMethod(walletBalance < price && earningsBalance >= price ? 'earnings' : 'wallet');
        }
    }, [wallet, quote, method, walletBalance, earningsBalance, price]);

    const selected = method ?? 'wallet';
    const shortfall = wallet && quote ? Math.max(0, price - balanceFor(selected)) : 0;
    // "Use winnings to cover it": neither balance is enough alone, or the
    // wallet is chosen and short, but wallet + winnings together are
    // enough. The wallet pays and exactly the gap moves from winnings.
    const winningsGap = Math.max(0, price - walletBalance);
    const winningsCanCover = shortfall > 0 && winningsGap > 0 && earningsBalance >= winningsGap
        && (selected === 'wallet' || walletBalance > 0) && ! (selected === 'earnings' && canAfford('wallet'));
    // A top-up lands in the wallet, so it's sized for the wallet's gap.
    const topUpAmount = Math.max(Number(minimumDeposit) || 0, Math.ceil(winningsGap));

    async function pay({ coverWithWinnings = false } = {}) {
        setError(null);
        setTakenNumbers([]);
        setStatus('processing');

        try {
            const response = await fetch('/api/tickets/purchase', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    raffle_id: raffle.id,
                    ticket_numbers: ticketNumbers,
                    unit_price: raffle.price,
                    submitted_amount: price,
                    funding_source: coverWithWinnings ? 'wallet' : selected,
                    use_winnings_for_shortfall: coverWithWinnings,
                    idempotency_key: idempotencyKey.current,
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                if (response.status === 409 && data.unavailable_numbers) {
                    setTakenNumbers(data.unavailable_numbers);
                    throw new Error(
                        data.unavailable_numbers.length === 1
                            ? `Number ${data.unavailable_numbers[0]} was just taken by someone else. Nothing was charged.`
                            : `Numbers ${data.unavailable_numbers.join(', ')} were just taken by someone else. Nothing was charged.`,
                    );
                }

                if (response.status === 402) {
                    loadWallet();
                }

                if (response.status === 422) {
                    refreshQuote();
                }

                throw new Error(data.message || 'Something went wrong. Please try again.');
            }

            setPurchase(data);
            setStatus('success');
        } catch (err) {
            setError(err.message);
            setStatus('error');
        }
    }

    async function topUp() {
        setError(null);
        setStatus('topping-up');

        try {
            const data = await apiPost('/api/deposits', { amount: topUpAmount, return_to: checkoutPath() });
            window.location.href = data.authorization_url;
        } catch (err) {
            setError(err.message);
            setStatus('error');
        }
    }

    const stillFree = ticketNumbers.filter((n) => ! takenNumbers.includes(n));
    const repickUrl = `/raffles/${raffle.id}/numbers?${new URLSearchParams({ qty: String(qty), numbers: stillFree.join(',') })}`;
    const busy = status === 'processing' || status === 'topping-up';
    const ready = wallet !== null && ! quoteLoading && quote !== null;

    return (
        <>
            <Head title="Secure Checkout" />
            <PausedNotice feature="ticket_sales" className="mx-4 mt-3" />
            <div className="min-h-screen bg-app-bg pb-40 dark:bg-dark-bg">
                <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur-md dark:border-gray-800 dark:bg-dark-bg/95">
                    <button
                        onClick={() => router.visit(`/raffles/${raffle.id}`)}
                        className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white"
                        aria-label="Back to raffle"
                    >
                        <ArrowLeft className="h-6 w-6" />
                    </button>
                    <h2 className="text-lg font-black tracking-tight text-gray-900 dark:text-white">Secure Checkout</h2>
                    <ShieldCheck className="ml-auto h-4 w-4 text-app-primary" />
                </header>

                <div className="mx-auto max-w-lg p-5">
                    {deposit && <DepositResult deposit={deposit} />}

                    {golden && <GoldenBoxApplied golden={golden} onEnded={refreshQuote} />}

                    <div className="mb-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                        <div className="mb-4 flex items-center justify-between border-b border-gray-50 pb-2 dark:border-gray-700">
                            <h3 className="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Order Summary</h3>
                            <div className="flex items-center gap-2 text-[10px] font-medium text-gray-400 dark:text-gray-500">
                                <span>{remainingTickets} tickets left</span>
                                {viewerCount !== null && (
                                    <span className="flex items-center gap-1">
                                        <Eye className="h-3 w-3" /> {viewerCount} viewing
                                    </span>
                                )}
                            </div>
                        </div>

                        <div className="mb-4 flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="font-bold text-gray-900 dark:text-white">{raffle.title}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{qty} ticket{qty > 1 ? 's' : ''}</p>
                            </div>
                            <div className="text-right">
                                <span className="block text-xl font-black tracking-tight text-app-primary">
                                    {quoteLoading && ! quote ? 'Calculating…' : formatNaira(price)}
                                </span>
                                {quote && quote.original > quote.discounted && (
                                    <span className="text-xs font-medium text-gray-400 line-through">{formatNaira(quote.original)}</span>
                                )}
                            </div>
                        </div>

                        <div className="rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-700 dark:bg-dark-bg/50">
                            <div className="mb-2 flex items-center justify-between">
                                <p className="text-[10px] font-medium uppercase text-gray-400 dark:text-gray-500">Selected Numbers</p>
                                <Link
                                    href={`/raffles/${raffle.id}/numbers?${new URLSearchParams({ qty: String(qty), numbers: ticketNumbers.join(',') })}`}
                                    className="text-[11px] font-bold text-app-primary"
                                >
                                    Change
                                </Link>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {ticketNumbers.map((n) => (
                                    <span
                                        key={n}
                                        className={[
                                            'rounded-md border px-2.5 py-1 font-mono text-xs font-bold shadow-sm',
                                            takenNumbers.includes(n)
                                                ? 'border-red-200 bg-red-50 text-red-500 line-through dark:border-red-900 dark:bg-red-900/20'
                                                : 'border-gray-200 bg-white text-gray-800 dark:border-gray-700 dark:bg-dark-card dark:text-gray-200',
                                        ].join(' ')}
                                    >
                                        {n}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-3">
                        <PaymentMethodCard
                            icon={Wallet}
                            iconClass="bg-blue-50 text-app-primary dark:bg-blue-900/20"
                            title="Wallet"
                            balance={wallet?.wallet_balance}
                            selected={selected === 'wallet'}
                            affordable={canAfford('wallet')}
                            onClick={() => setMethod('wallet')}
                        />
                        <PaymentMethodCard
                            icon={Award}
                            iconClass="bg-yellow-50 text-yellow-600 dark:bg-yellow-900/20 dark:text-yellow-500"
                            title="Winnings"
                            balance={wallet?.earnings_balance}
                            selected={selected === 'earnings'}
                            affordable={canAfford('earnings')}
                            onClick={() => setMethod('earnings')}
                        />
                    </div>

                    {ready && shortfall > 0 && (
                        <ShortfallPanel
                            shortfall={shortfall}
                            winningsGap={winningsGap}
                            method={selected}
                            winningsCanCover={winningsCanCover}
                            otherCovers={selected === 'earnings' && canAfford('wallet')}
                            topUpAmount={topUpAmount}
                            busy={busy}
                            onCover={() => pay({ coverWithWinnings: true })}
                            onTopUp={topUp}
                            onUseWallet={() => setMethod('wallet')}
                        />
                    )}

                    {error && (
                        <div className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                            {error}
                            {takenNumbers.length > 0 && (
                                <Link href={repickUrl} className="mt-2 block font-bold text-red-800 underline dark:text-red-300">
                                    Pick other numbers
                                </Link>
                            )}
                        </div>
                    )}
                </div>
            </div>

            <div className="fixed bottom-0 left-0 w-full border-t border-gray-100 bg-white p-4 dark:border-gray-800 dark:bg-dark-bg">
                {ready && shortfall > 0 ? (
                    // Never a greyed-out dead end (item 46): when short, the main
                    // button is the way forward instead.
                    <button
                        onClick={winningsCanCover ? () => pay({ coverWithWinnings: true }) : topUp}
                        disabled={busy}
                        className="mx-auto flex w-full max-w-lg items-center justify-center gap-2 rounded-xl bg-app-primary py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-[0.98] disabled:opacity-60"
                    >
                        {status === 'topping-up'
                            ? 'Taking you to Paystack…'
                            : winningsCanCover
                              ? `Pay ${formatNaira(price)} using winnings`
                              : `Top up ${formatNaira(topUpAmount)}`}
                    </button>
                ) : (
                    <button
                        onClick={() => pay()}
                        disabled={! ready || busy}
                        className={[
                            'mx-auto flex w-full max-w-lg items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold shadow-lg transition-transform active:scale-[0.98]',
                            ready && ! busy
                                ? 'bg-app-primary text-white shadow-blue-500/30'
                                : 'cursor-not-allowed bg-gray-200 text-gray-400 shadow-none dark:bg-gray-800 dark:text-gray-500',
                        ].join(' ')}
                    >
                        {status === 'processing' ? 'Processing…' : ready ? `Pay ${formatNaira(price)}` : 'Loading…'}
                    </button>
                )}
            </div>

            {busy && <ProcessingModal />}
            {status === 'success' && <SuccessModal raffle={raffle} amount={price} numbers={purchase?.ticket_numbers ?? ticketNumbers} />}
        </>
    );
}

function ShortfallPanel({ shortfall, winningsGap, method, winningsCanCover, otherCovers, topUpAmount, busy, onCover, onTopUp, onUseWallet }) {
    return (
        <div className="mt-4 rounded-2xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-900/40 dark:bg-orange-900/10">
            <p className="text-sm font-bold text-orange-800 dark:text-orange-300">
                Your {method === 'wallet' ? 'wallet' : 'winnings'} is {formatNaira(shortfall)} short.
            </p>
            <p className="mb-3 text-xs text-orange-700 dark:text-orange-400">Choose how to cover it. Your numbers stay picked.</p>

            <div className="space-y-2">
                {winningsCanCover && (
                    <button
                        onClick={onCover}
                        disabled={busy}
                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-yellow-400 py-3 text-sm font-bold text-gray-900 shadow-sm active:scale-[0.98] disabled:opacity-60"
                    >
                        <RefreshCw className="h-4 w-4" /> Use {formatNaira(winningsGap)} of your winnings to cover it
                    </button>
                )}
                {otherCovers && (
                    <button
                        onClick={onUseWallet}
                        disabled={busy}
                        className="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white py-3 text-sm font-bold text-gray-800 shadow-sm active:scale-[0.98] dark:border-gray-700 dark:bg-dark-card dark:text-gray-200"
                    >
                        <Wallet className="h-4 w-4" /> Pay from your wallet instead
                    </button>
                )}
                <button
                    onClick={onTopUp}
                    disabled={busy}
                    className="flex w-full items-center justify-center gap-2 rounded-xl border border-app-primary bg-white py-3 text-sm font-bold text-app-primary shadow-sm active:scale-[0.98] disabled:opacity-60 dark:bg-dark-card"
                >
                    <PlusCircle className="h-4 w-4" /> Top up {formatNaira(topUpAmount)}
                </button>
            </div>
            <p className="mt-2 text-[11px] text-orange-700/80 dark:text-orange-400/80">
                After paying on Paystack you'll come straight back here to finish.
            </p>
        </div>
    );
}

function GoldenBoxApplied({ golden, onEnded }) {
    const { label, done } = useTimeLeft(golden.ends_at);

    useEffect(() => {
        if (done) {
            onEnded();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [done]);

    return (
        <div className="mb-4 flex items-center gap-3 rounded-2xl bg-gradient-to-r from-yellow-400 via-orange-300 to-yellow-500 p-4 text-gray-900 shadow-lg shadow-orange-500/20">
            <Gift className="h-8 w-8 flex-shrink-0" />
            <div className="flex-1">
                <p className="text-sm font-black">Golden Box: {golden.percent_off}% off applied</p>
                <p className="text-xs font-medium">You save an extra {formatNaira(golden.savings)}. Pay within {label}.</p>
            </div>
        </div>
    );
}

function DepositResult({ deposit }) {
    if (deposit.status === 'successful') {
        return (
            <div className="mb-4 flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-900/30 dark:bg-green-900/20">
                <CheckCircle2 className="h-6 w-6 flex-shrink-0 text-green-600 dark:text-green-400" />
                <div>
                    <p className="text-sm font-bold text-green-800 dark:text-green-300">Top-up confirmed</p>
                    <p className="text-xs text-green-700 dark:text-green-400">{formatNaira(deposit.amount)} was added to your wallet. You can pay now.</p>
                </div>
            </div>
        );
    }

    if (deposit.status === 'pending') {
        return (
            <div className="mb-4 flex items-center gap-3 rounded-xl border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-900/30 dark:bg-yellow-900/20">
                <div className="h-6 w-6 flex-shrink-0 animate-spin rounded-full border-2 border-yellow-500 border-t-transparent" />
                <div>
                    <p className="text-sm font-bold text-yellow-800 dark:text-yellow-300">Confirming your top-up…</p>
                    <p className="text-xs text-yellow-700 dark:text-yellow-400">This usually only takes a few seconds.</p>
                </div>
            </div>
        );
    }

    return (
        <div className="mb-4 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/30 dark:bg-red-900/20">
            <XCircle className="h-6 w-6 flex-shrink-0 text-red-600 dark:text-red-400" />
            <div>
                <p className="text-sm font-bold text-red-800 dark:text-red-300">Top-up not completed</p>
                <p className="text-xs text-red-700 dark:text-red-400">No money was taken for your tickets. You can try again below.</p>
            </div>
        </div>
    );
}

function PaymentMethodCard({ icon: Icon, iconClass, title, balance, selected, affordable, onClick }) {
    return (
        <div
            onClick={onClick}
            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && onClick()}
            role="button"
            tabIndex={0}
            className={[
                'relative cursor-pointer overflow-hidden rounded-xl border bg-white p-4 transition-all active:scale-[0.98] dark:bg-dark-card',
                selected ? 'border-app-primary ring-1 ring-app-primary' : 'border-gray-200 dark:border-gray-700',
            ].join(' ')}
        >
            <div className="flex items-center gap-3">
                <div className={`flex h-10 w-10 items-center justify-center rounded-full ${iconClass}`}>
                    <Icon className="h-5 w-5" />
                </div>
                <div className="flex-1">
                    <p className="font-bold text-gray-900 dark:text-white">{title}</p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        Balance: {balance === undefined || balance === null ? '…' : formatNaira(balance)}
                    </p>
                    {balance !== undefined && balance !== null && ! affordable && (
                        <p className="mt-1 text-xs font-bold text-red-500">Not enough for this order</p>
                    )}
                </div>
                <div
                    className={[
                        'flex h-5 w-5 items-center justify-center rounded-full border-2 transition-all',
                        selected ? 'border-app-primary' : 'border-gray-300 dark:border-gray-600',
                    ].join(' ')}
                >
                    {selected && <div className="h-2.5 w-2.5 rounded-full bg-app-primary" />}
                </div>
            </div>
        </div>
    );
}

function ProcessingModal() {
    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-5 backdrop-blur-sm">
            <div className="relative flex h-20 w-20 items-center justify-center">
                <div className="absolute inset-0 rounded-full border-4 border-gray-200 dark:border-gray-700" />
                <div className="absolute inset-0 animate-spin rounded-full border-4 border-app-primary border-t-transparent" />
                <ShieldCheck className="h-8 w-8 text-app-primary" />
            </div>
        </div>
    );
}

function SuccessModal({ raffle, amount, numbers }) {
    const [shareNote, setShareNote] = useState(null);
    const count = numbers.length;
    const raffleUrl = `${window.location.origin}/raffles/${raffle.id}`;
    const shareText = `I just got ${count} ticket${count === 1 ? '' : 's'} for ${raffle.title} on RaffleKings! Try your luck:`;

    async function share() {
        if (navigator.share) {
            try {
                await navigator.share({ title: raffle.title, text: shareText, url: raffleUrl });
            } catch {
                // closed the share sheet: nothing to do
            }

            return;
        }

        window.open(`https://wa.me/?text=${encodeURIComponent(`${shareText} ${raffleUrl}`)}`, '_blank', 'noopener');
        setShareNote('Opening WhatsApp…');
    }

    return (
        <>
            <Confetti />
            <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 p-4 backdrop-blur-md">
                <div className="relative flex max-h-full w-full max-w-sm flex-col overflow-y-auto rounded-[2rem] border border-gray-100 bg-white shadow-2xl dark:border-gray-800 dark:bg-dark-card">
                    <div className="absolute inset-0 bg-[radial-gradient(#2563eb_1px,transparent_1px)] opacity-5 [background-size:16px_16px]" />

                    <div className="relative flex flex-col items-center px-6 pb-8 pt-10 text-center">
                        <div className="relative mb-6 mt-2 animate-splash-bounce">
                            <div className="absolute inset-0 animate-ping rounded-full bg-green-100 opacity-75 dark:bg-green-900/20" />
                            <div className="absolute -inset-4 animate-pulse rounded-full bg-green-50 dark:bg-green-900/10" />
                            <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-green-100 shadow-inner ring-4 ring-white dark:bg-green-900/30 dark:ring-dark-card">
                                <Check className="h-10 w-10 stroke-[3] text-green-600 dark:text-green-500" />
                            </div>
                        </div>

                        <h2 className="mb-1 text-2xl font-black text-gray-900 dark:text-white">You're in!</h2>
                        <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">
                            You paid {formatNaira(amount)} for {count} ticket{count === 1 ? '' : 's'} in {raffle.title}. Good luck!
                        </p>

                        <div className="mb-6 w-full rounded-2xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-700 dark:bg-dark-bg/50">
                            <p className="mb-2 flex items-center justify-center gap-1 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                <Ticket className="h-3 w-3" /> Your ticket number{count === 1 ? '' : 's'}
                            </p>
                            <div className="flex flex-wrap justify-center gap-2">
                                {numbers.map((n) => (
                                    <span key={n} className="rounded-lg border border-yellow-500 bg-yellow-400 px-3 py-1 font-mono text-sm font-black text-gray-900 shadow-sm">
                                        {n}
                                    </span>
                                ))}
                            </div>
                        </div>

                        <div className="w-full space-y-2">
                            <button
                                onClick={() => router.visit('/account/tickets')}
                                className="w-full rounded-xl bg-app-primary py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-95"
                            >
                                View my tickets
                            </button>
                            <button
                                onClick={share}
                                className="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white py-3.5 text-sm font-bold text-gray-800 transition-transform active:scale-95 dark:border-gray-700 dark:bg-dark-card dark:text-gray-200"
                            >
                                <Share2 className="h-4 w-4" /> Share
                            </button>
                            {shareNote && <p className="text-xs text-gray-500">{shareNote}</p>}
                            <button onClick={() => router.visit('/raffles')} className="w-full py-2 text-sm font-bold text-gray-500 dark:text-gray-400">
                                Browse more raffles
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
