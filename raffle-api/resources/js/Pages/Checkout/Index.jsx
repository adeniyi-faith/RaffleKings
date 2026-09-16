import { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, Wallet, Award, Check, ShieldCheck } from 'lucide-react';
import { useTicketPriceQuote } from '../../hooks/useTicketPriceQuote';
import { formatNaira } from '../../lib/format';

function generateIdempotencyKey() {
    return typeof crypto !== 'undefined' && crypto.randomUUID
        ? crypto.randomUUID()
        : `key-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export default function CheckoutIndex({ raffle, ticketNumbers, qty }) {
    const { quote, loading: quoteLoading } = useTicketPriceQuote(raffle.id, qty);
    const [wallet, setWallet] = useState(null);
    const [method, setMethod] = useState('wallet');
    const [status, setStatus] = useState('idle'); // idle | processing | success | error
    const [error, setError] = useState(null);
    const idempotencyKey = useRef(generateIdempotencyKey());

    useEffect(() => {
        fetch('/api/wallet')
            .then((res) => (res.ok ? res.json() : null))
            .then(setWallet)
            .catch(() => setWallet(null));
    }, []);

    const price = quote?.discounted ?? 0;
    const balanceFor = (m) => (m === 'wallet' ? wallet?.wallet_balance : wallet?.earnings_balance) ?? 0;
    const canAfford = (m) => balanceFor(m) >= price;

    async function handlePay() {
        setError(null);
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
                    funding_source: method,
                    idempotency_key: idempotencyKey.current,
                }),
            });

            const data = await response.json();

            if (! response.ok) {
                throw new Error(data.message || 'Something went wrong. Please try again.');
            }

            setStatus('success');
        } catch (err) {
            setError(err.message);
            setStatus('error');
        }
    }

    return (
        <>
            <Head title="Secure Checkout" />
            <div className="min-h-screen bg-app-bg pb-32 dark:bg-dark-bg">
                <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur-md dark:border-gray-800 dark:bg-dark-bg/95">
                    <button
                        onClick={() => router.visit(`/raffles/${raffle.id}`)}
                        className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white"
                    >
                        <ArrowLeft className="h-6 w-6" />
                    </button>
                    <h2 className="text-lg font-black tracking-tight text-gray-900 dark:text-white">Secure Checkout</h2>
                    <ShieldCheck className="ml-auto h-4 w-4 text-app-primary" />
                </header>

                <div className="mx-auto max-w-lg p-5">
                    <div className="mb-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                        <h3 className="mb-4 border-b border-gray-50 pb-2 text-xs font-bold uppercase tracking-wider text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            Order Summary
                        </h3>

                        <div className="mb-4 flex items-start justify-between">
                            <div>
                                <p className="font-bold text-gray-900 dark:text-white">{raffle.title}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{qty} ticket{qty > 1 ? 's' : ''}</p>
                            </div>
                            <div className="text-right">
                                <span className="block text-xl font-black tracking-tight text-app-primary">
                                    {quoteLoading ? 'Calculating…' : formatNaira(price)}
                                </span>
                                {quote && quote.original > quote.discounted && (
                                    <span className="text-xs font-medium text-gray-400 line-through">
                                        {formatNaira(quote.original)}
                                    </span>
                                )}
                            </div>
                        </div>

                        <div className="rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-700 dark:bg-dark-bg/50">
                            <p className="mb-2 text-[10px] font-medium uppercase text-gray-400 dark:text-gray-500">
                                Selected Numbers
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {ticketNumbers.map((n) => (
                                    <span
                                        key={n}
                                        className="rounded-md border border-gray-200 bg-white px-2.5 py-1 font-mono text-xs font-bold text-gray-800 shadow-sm dark:border-gray-700 dark:bg-dark-card dark:text-gray-200"
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
                            selected={method === 'wallet'}
                            affordable={canAfford('wallet')}
                            onClick={() => setMethod('wallet')}
                        />
                        <PaymentMethodCard
                            icon={Award}
                            iconClass="bg-yellow-50 text-yellow-600 dark:bg-yellow-900/20 dark:text-yellow-500"
                            title="Winnings"
                            balance={wallet?.earnings_balance}
                            selected={method === 'earnings'}
                            affordable={canAfford('earnings')}
                            onClick={() => setMethod('earnings')}
                        />
                    </div>

                    {error && (
                        <div className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                            {error}
                        </div>
                    )}
                </div>
            </div>

            <div className="fixed bottom-0 left-0 w-full border-t border-gray-100 bg-white p-4 dark:border-gray-800 dark:bg-dark-bg">
                <button
                    onClick={handlePay}
                    disabled={! canAfford(method) || status === 'processing' || quoteLoading}
                    className={[
                        'mx-auto flex w-full max-w-lg items-center justify-center gap-2 rounded-xl py-3.5 text-sm font-bold shadow-lg transition-transform active:scale-[0.98]',
                        canAfford(method) && status !== 'processing'
                            ? 'bg-app-primary text-white shadow-blue-500/30'
                            : 'cursor-not-allowed bg-gray-200 text-gray-400 shadow-none dark:bg-gray-800 dark:text-gray-500',
                    ].join(' ')}
                >
                    {status === 'processing' ? 'Processing…' : `Pay ${formatNaira(price)}`}
                </button>
            </div>

            {status === 'processing' && <ProcessingModal />}
            {status === 'success' && <SuccessModal amount={price} />}
        </>
    );
}

function PaymentMethodCard({ icon: Icon, iconClass, title, balance, selected, affordable, onClick }) {
    return (
        <div
            onClick={onClick}
            role="button"
            tabIndex={0}
            className="relative cursor-pointer overflow-hidden rounded-xl border border-gray-200 bg-white p-4 transition-all active:scale-[0.98] dark:border-gray-700 dark:bg-dark-card"
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
                        <p className="mt-1 text-xs font-bold text-red-500">Insufficient balance</p>
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

function SuccessModal({ amount }) {
    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 p-4 backdrop-blur-md">
            <div className="relative flex w-full max-w-sm flex-col overflow-hidden rounded-[2rem] border border-gray-100 bg-white shadow-2xl dark:border-gray-800 dark:bg-dark-card">
                <div className="absolute inset-0 bg-[radial-gradient(#2563eb_1px,transparent_1px)] opacity-5 [background-size:16px_16px]" />

                <div className="relative flex flex-col items-center px-6 pb-8 pt-10 text-center">
                    <div className="relative mb-6 mt-2 animate-splash-bounce">
                        <div className="absolute inset-0 animate-ping rounded-full bg-green-100 opacity-75 dark:bg-green-900/20" />
                        <div className="absolute -inset-4 animate-pulse rounded-full bg-green-50 dark:bg-green-900/10" />
                        <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-green-100 shadow-inner ring-4 ring-white dark:bg-green-900/30 dark:ring-dark-card">
                            <Check className="h-10 w-10 stroke-[3] text-green-600 dark:text-green-500" />
                        </div>
                    </div>

                    <h2 className="mb-1 text-2xl font-black text-gray-900 dark:text-white">Tickets Secured!</h2>
                    <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        You paid {formatNaira(amount)}. Good luck!
                    </p>

                    <button
                        onClick={() => router.visit('/raffles')}
                        className="w-full rounded-xl bg-gray-900 py-3.5 text-sm font-bold text-white shadow-lg transition-transform active:scale-95 dark:bg-white dark:text-gray-900"
                    >
                        Browse more raffles
                    </button>
                </div>
            </div>
        </div>
    );
}
