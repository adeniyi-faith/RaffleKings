import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import { ArrowLeft, Building2, CheckCircle2, ShieldCheck, XCircle } from 'lucide-react';
import { formatNaira } from '../../lib/format';

// Rebuild of topup.php against the item 13 payment-gateway backend
// (item 26). What's preserved from the legacy page: the layout shell
// (sticky header with a live balance chip, a white card, a labelled
// amount field, a bold primary submit button, a full-screen "Verifying…"
// modal while the request is in flight) and its Tailwind classes/colors.
//
// What deliberately did NOT come back, and why: the legacy page's actual
// mechanism was "transfer to this bank account, then upload a screenshot
// for an AI to manually review" — there was no real payment gateway
// behind it. Item 13 already replaced that with a real Paystack (Flutter-
// wave backup) checkout as this app's one settlement path for deposits;
// rebuilding the old manual bank-transfer + screenshot form here would
// be recreating UI for a mechanism this app no longer uses as its
// primary path (the Gemini screenshot check still exists only as a
// manual-review fallback per DepositService's docblock, not as a first-
// class flow with its own page). So this page keeps the legacy page's
// visual identity but replaces the "copy this account number, then prove
// it" form with "enter an amount, then pay" — the real, working version
// of the same job the legacy page was trying to do.
const QUICK_AMOUNTS = [1000, 2000, 5000, 10000];

export default function AccountWallet() {
    const [wallet, setWallet] = useState(null);
    const [amount, setAmount] = useState('');
    const [status, setStatus] = useState('idle'); // idle | submitting | error
    const [error, setError] = useState(null);
    const [returned, setReturned] = useState(null); // deposit status object, if we came back from a gateway

    useEffect(() => {
        fetch('/api/wallet')
            .then((res) => (res.ok ? res.json() : null))
            .then(setWallet)
            .catch(() => setWallet(null));

        const params = new URLSearchParams(window.location.search);
        const depositId = params.get('deposit');
        if (depositId) {
            fetch(`/api/deposits/${depositId}`)
                .then((res) => (res.ok ? res.json() : null))
                .then(setReturned)
                .catch(() => setReturned(null));
        }
    }, []);

    async function handlePay() {
        setError(null);
        const numeric = Number(amount);

        if (! numeric || numeric <= 0) {
            setError('Enter an amount to fund.');
            return;
        }

        setStatus('submitting');

        try {
            const response = await fetch('/api/deposits', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ amount: numeric }),
            });

            const data = await response.json();

            if (! response.ok) {
                throw new Error(data.message || 'Could not start this deposit. Please try again.');
            }

            window.location.href = data.authorization_url;
        } catch (err) {
            setError(err.message);
            setStatus('idle');
        }
    }

    return (
        <>
            <Head title="Top Up Wallet" />
            <div className="relative min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-10 border-b border-gray-100 bg-white px-5 pb-4 pt-4 dark:border-dark-border dark:bg-dark-bg">
                    <div className="mb-2 flex items-center gap-3">
                        <button
                            onClick={() => window.history.back()}
                            className="-ml-1 p-1 text-gray-400 transition-colors hover:text-gray-900 dark:hover:text-white"
                        >
                            <ArrowLeft className="h-6 w-6" />
                        </button>
                        <h2 className="text-xl font-bold text-gray-900 dark:text-white">Top Up Wallet</h2>
                    </div>

                    <div className="flex items-center justify-between pl-1">
                        <p className="text-xs text-gray-500 dark:text-gray-400">Fund your account securely via Paystack.</p>
                        <span className="rounded bg-blue-50 px-2 py-1 text-xs font-bold text-app-primary dark:bg-blue-900/30 dark:text-blue-300">
                            Bal: {wallet ? formatNaira(wallet.wallet_balance) : '…'}
                        </span>
                    </div>
                </div>

                {returned && (
                    <section className="px-5 pt-5">
                        <DepositStatusBanner deposit={returned} />
                    </section>
                )}

                <section className="p-5">
                    <div className="relative overflow-hidden rounded-2xl border border-gray-100 bg-white p-6 shadow-sm dark:border-dark-border dark:bg-dark-card">
                        <div className="pointer-events-none absolute -bottom-6 -right-6 text-gray-900 opacity-5 dark:text-white dark:opacity-10">
                            <Building2 className="h-32 w-32" />
                        </div>

                        <h3 className="mb-2 flex items-center gap-2 font-bold text-gray-900 dark:text-white">
                            <ShieldCheck className="h-5 w-5 text-green-500" /> Fund Your Wallet
                        </h3>
                        <p className="mb-6 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            Enter an amount, then complete payment on Paystack's secure checkout. Your balance updates
                            automatically once payment is confirmed.
                        </p>

                        <div className="mb-4">
                            <div className="mb-1 flex justify-between">
                                <label className="block text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Amount
                                </label>
                                <span className="text-xs font-bold text-red-500">Minimum: {formatNaira(100)}</span>
                            </div>
                            <div className="relative">
                                <span className="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-gray-400">₦</span>
                                <input
                                    type="number"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                    placeholder="1000"
                                    min="100"
                                    className="w-full rounded-xl border border-transparent bg-gray-50 py-3 pl-10 pr-4 font-bold text-gray-800 outline-none transition-colors focus:border-app-primary focus:ring-2 focus:ring-app-primary/20 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500"
                                />
                            </div>

                            <div className="mt-3 flex gap-2 overflow-x-auto">
                                {QUICK_AMOUNTS.map((qa) => (
                                    <button
                                        key={qa}
                                        onClick={() => setAmount(String(qa))}
                                        className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-500 transition-colors hover:border-app-primary hover:text-app-primary dark:border-gray-700 dark:bg-dark-card dark:text-gray-400 dark:hover:border-app-primary dark:hover:text-app-primary"
                                    >
                                        {formatNaira(qa)}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {error && (
                            <div className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                                {error}
                            </div>
                        )}

                        <button
                            onClick={handlePay}
                            disabled={status === 'submitting'}
                            className="flex w-full items-center justify-center gap-2 rounded-xl bg-app-primary py-3.5 font-bold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {status === 'submitting' ? 'Starting Payment…' : 'Pay with Paystack'}
                        </button>
                    </div>
                </section>
            </div>

            {status === 'submitting' && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-5 backdrop-blur-sm">
                    <div className="w-full max-w-sm rounded-3xl border bg-white p-8 text-center dark:border-dark-border dark:bg-dark-card">
                        <div className="mx-auto mb-4 h-10 w-10 animate-spin rounded-full border-b-2 border-app-primary" />
                        <h2 className="mb-1 text-xl font-bold text-gray-900 dark:text-white">Redirecting…</h2>
                        <p className="text-sm text-gray-500 dark:text-gray-400">Taking you to Paystack's secure checkout.</p>
                    </div>
                </div>
            )}
        </>
    );
}

function DepositStatusBanner({ deposit }) {
    if (deposit.status === 'successful') {
        return (
            <div className="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-900/30 dark:bg-green-900/20">
                <CheckCircle2 className="h-6 w-6 flex-shrink-0 text-green-600 dark:text-green-400" />
                <div>
                    <p className="text-sm font-bold text-green-800 dark:text-green-300">Payment confirmed</p>
                    <p className="text-xs text-green-700 dark:text-green-400">
                        {formatNaira(deposit.amount)} has been added to your wallet.
                    </p>
                </div>
            </div>
        );
    }

    if (deposit.status === 'pending') {
        return (
            <div className="flex items-center gap-3 rounded-xl border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-900/30 dark:bg-yellow-900/20">
                <div className="h-6 w-6 flex-shrink-0 animate-spin rounded-full border-2 border-yellow-500 border-t-transparent" />
                <div>
                    <p className="text-sm font-bold text-yellow-800 dark:text-yellow-300">Confirming your payment…</p>
                    <p className="text-xs text-yellow-700 dark:text-yellow-400">This usually only takes a few seconds.</p>
                </div>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/30 dark:bg-red-900/20">
            <XCircle className="h-6 w-6 flex-shrink-0 text-red-600 dark:text-red-400" />
            <div>
                <p className="text-sm font-bold text-red-800 dark:text-red-300">Payment not completed</p>
                <p className="text-xs text-red-700 dark:text-red-400">{deposit.failure_reason || 'Please try again.'}</p>
            </div>
        </div>
    );
}
