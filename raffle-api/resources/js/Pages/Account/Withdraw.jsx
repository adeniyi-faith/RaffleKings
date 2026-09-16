import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ArrowRight, Check, Info, Lock, ThumbsUp } from 'lucide-react';
import { formatNaira } from '../../lib/format';

// Faithful rebuild of withdraw.php + components/financials/withdraw-modals.php
// against the real WithdrawalController/WithdrawalService (items 12/18) —
// same yellow-orange balance card, same destination-account block, same
// quick-amount chips, and the same two-step verification-fee disclosure
// modals (fee upfront via GET /api/withdrawals/requirements, exactly the
// "disclosed, not sprung on the user" fix WithdrawalService's docblock
// describes — this page is what that fix was FOR).
const QUICK_AMOUNTS = [2000, 5000, 10000];

export default function AccountWithdraw() {
    const [earnings, setEarnings] = useState(null);
    const [primaryAccount, setPrimaryAccount] = useState(undefined); // undefined = loading, null = none
    const [amount, setAmount] = useState('');
    const [requirements, setRequirements] = useState(null);
    const [modal, setModal] = useState(null); // null | 'verify' | 'deduct' | 'success' | 'error'
    const [errorMessage, setErrorMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [successMessage, setSuccessMessage] = useState('');

    useEffect(() => {
        fetch('/api/wallet')
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => setEarnings(data ? data.earnings_balance : 0))
            .catch(() => setEarnings(0));

        fetch('/api/bank-accounts')
            .then((res) => (res.ok ? res.json() : { accounts: [] }))
            .then((data) => {
                const accounts = data.accounts || [];
                setPrimaryAccount(accounts.find((a) => a.is_primary) || accounts[0] || null);
            })
            .catch(() => setPrimaryAccount(null));

        fetch('/api/withdrawals/requirements')
            .then((res) => (res.ok ? res.json() : null))
            .then(setRequirements)
            .catch(() => setRequirements(null));
    }, []);

    function setMaxAmount() {
        setAmount(String(Math.floor(earnings || 0)));
    }

    async function submit(authorizeVerificationFee) {
        setErrorMessage('');
        const numeric = Number(amount);

        if (! numeric || numeric <= 0) {
            setErrorMessage('Enter an amount to withdraw.');
            return;
        }

        if (! primaryAccount) {
            setErrorMessage('Add a bank account first.');
            return;
        }

        setSubmitting(true);

        try {
            const response = await fetch('/api/withdrawals', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    amount: numeric,
                    bank_account_id: primaryAccount.id,
                    authorize_verification_fee: authorizeVerificationFee,
                }),
            });

            const data = await response.json();

            if (response.status === 403 && data.requires_verification_fee) {
                setModal('verify');
                return;
            }

            if (! response.ok) {
                throw new Error(data.message || 'Something went wrong. Please try again.');
            }

            setSuccessMessage('Your withdrawal is being processed. Funds usually arrive within 24 hours.');
            setModal('success');
        } catch (err) {
            setErrorMessage(err.message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <>
            <Head title="Withdraw Funds" />
            <div className="relative min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 border-b border-gray-100 bg-white px-5 pb-4 pt-4 shadow-sm dark:border-dark-border dark:bg-dark-bg dark:shadow-none">
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => window.history.back()}
                            className="-ml-1 rounded-full p-1 text-gray-600 transition-colors hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            <ArrowLeft className="h-6 w-6" />
                        </button>
                        <div>
                            <h2 className="text-xl font-bold leading-tight text-gray-900 dark:text-white">Withdraw Funds</h2>
                            <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Transfer winnings to your bank account.</p>
                        </div>
                    </div>
                </div>

                <section className="p-5 pb-2">
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-yellow-500 to-orange-600 p-5 text-white shadow-lg">
                        <div className="absolute right-0 top-0 h-24 w-24 -translate-y-1/2 translate-x-1/2 rounded-full bg-white/10 blur-2xl" />

                        <p className="mb-1 text-xs font-medium text-yellow-100">Withdrawable Earnings</p>
                        <h1 className="mb-4 text-3xl font-bold tracking-tight">
                            {earnings === null ? '…' : formatNaira(earnings)}
                        </h1>

                        <div className="flex w-fit items-center gap-2 rounded-lg bg-white/10 px-2 py-1 text-[10px] text-yellow-100">
                            <Info className="h-3 w-3" />
                            <span>Minimum withdrawal: {requirements ? formatNaira(requirements.minimum_amount) : formatNaira(2000)}</span>
                        </div>
                    </div>
                </section>

                <section className="px-5 py-4">
                    <div className="mb-6">
                        <div className="mb-2 flex items-center justify-between">
                            <label className="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Destination Account
                            </label>
                            <Link href="/account/bank-accounts" className="text-xs font-bold text-app-primary transition-colors hover:text-blue-400">
                                Manage
                            </Link>
                        </div>

                        {primaryAccount === undefined && (
                            <div className="h-16 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />
                        )}

                        {primaryAccount === null && (
                            <Link
                                href="/account/bank-accounts"
                                className="block w-full rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-4 text-center text-sm font-bold text-gray-400 transition-colors hover:border-app-primary hover:text-app-primary dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-500 dark:hover:border-app-primary dark:hover:text-app-primary"
                            >
                                + Add Bank Account
                            </Link>
                        )}

                        {primaryAccount && (
                            <div className="relative flex items-center gap-4 overflow-hidden rounded-xl border border-green-200 bg-white p-4 shadow-sm dark:border-green-900/50 dark:bg-dark-card">
                                <div className="absolute left-0 top-0 rounded-br-lg bg-green-500 px-2 py-0.5 text-[9px] font-bold text-white">
                                    SELECTED
                                </div>
                                <div className="mt-2 flex h-10 w-10 items-center justify-center rounded-full border border-gray-100 bg-gray-50 text-xs font-bold text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                    {primaryAccount.bank_name.slice(0, 2).toUpperCase()}
                                </div>
                                <div className="mt-2 flex-1">
                                    <h4 className="text-sm font-bold text-gray-900 dark:text-white">{primaryAccount.bank_name}</h4>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        {primaryAccount.account_name} • {primaryAccount.account_number}
                                    </p>
                                </div>
                                <div className="mt-2 flex h-6 w-6 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/40">
                                    <Check className="h-3 w-3 stroke-[3] text-green-600 dark:text-green-400" />
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="mb-6">
                        <label className="mb-2 block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Amount to Withdraw
                        </label>
                        <div className="relative">
                            <span className="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-gray-400">₦</span>
                            <input
                                type="number"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                placeholder="0.00"
                                className="w-full rounded-xl border border-gray-200 bg-white py-4 pl-8 pr-16 text-xl font-bold text-gray-900 outline-none transition-all placeholder:text-gray-300 focus:border-app-primary focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-dark-card dark:text-white dark:placeholder:text-gray-600 dark:focus:border-app-primary"
                            />
                            <button
                                onClick={setMaxAmount}
                                className="absolute right-4 top-1/2 -translate-y-1/2 rounded bg-gray-100 px-2 py-1 text-[10px] font-bold text-gray-500 transition-colors hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                            >
                                MAX
                            </button>
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

                    {errorMessage && (
                        <div className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                            {errorMessage}
                        </div>
                    )}

                    <button
                        onClick={() => submit(false)}
                        disabled={! primaryAccount || submitting}
                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-app-primary py-4 font-bold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none"
                    >
                        {submitting ? 'Processing…' : 'Withdraw Funds'} <ArrowRight className="h-4 w-4" />
                    </button>

                    <div className="mt-4 flex items-center justify-center gap-1.5 text-[10px] text-gray-400 dark:text-gray-500">
                        <Lock className="h-3 w-3" />
                        <span>Encrypted &amp; Secure Payment</span>
                    </div>
                </section>
            </div>

            {modal === 'verify' && requirements && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-5 backdrop-blur-md">
                    <div className="w-full max-w-sm rounded-3xl border border-red-100 bg-white p-6 text-center shadow-2xl dark:border-red-900/30 dark:bg-dark-card">
                        <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/20">
                            <Lock className="h-8 w-8 text-red-600 dark:text-red-500" />
                        </div>

                        <h2 className="mb-2 text-lg font-bold text-gray-900 dark:text-white">Account Verification Required</h2>

                        <div className="mb-6 rounded-xl border border-gray-100 bg-gray-50 p-4 text-left dark:border-gray-800 dark:bg-gray-800/50">
                            <p className="mb-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                                To ensure user authenticity and securely process your withdrawal, a total lifetime deposit of{' '}
                                <span className="font-bold text-gray-900 dark:text-white">{formatNaira(requirements.verification_fee)}</span> is
                                required.
                            </p>
                            <div className="flex items-start gap-2">
                                <Info className="mt-0.5 h-3 w-3 flex-shrink-0 text-app-primary" />
                                <p className="text-[10px] italic text-gray-500 dark:text-gray-400">
                                    This money is 100% yours. It is credited to your Spending Wallet for future ticket purchases.
                                </p>
                            </div>
                        </div>

                        <Link
                            href="/account/wallet"
                            className="mb-3 block w-full rounded-xl bg-app-primary py-3.5 font-bold text-white shadow-lg shadow-blue-500/20 transition-transform hover:bg-blue-700 active:scale-95"
                        >
                            Fund {formatNaira(requirements.verification_fee)} Now
                        </Link>

                        <button
                            onClick={() => setModal('deduct')}
                            className="block w-full rounded-xl border border-gray-200 bg-white py-3.5 font-bold text-gray-600 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:bg-transparent dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            Pay {formatNaira(requirements.verification_fee)} from Balance
                        </button>

                        <button
                            onClick={() => setModal(null)}
                            className="mt-4 text-xs font-medium text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                        >
                            Close
                        </button>
                    </div>
                </div>
            )}

            {modal === 'deduct' && requirements && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/90 p-5 backdrop-blur-md">
                    <div className="w-full max-w-sm rounded-3xl border border-orange-200 bg-white p-6 text-center shadow-2xl dark:border-orange-900/30 dark:bg-dark-card">
                        <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-orange-100 dark:bg-orange-900/20">
                            <AlertTriangle className="h-8 w-8 text-orange-600 dark:text-orange-500" />
                        </div>

                        <h2 className="mb-2 text-lg font-bold text-gray-900 dark:text-white">Confirm Deduction</h2>

                        <p className="mb-6 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                            Are you sure? We will deduct{' '}
                            <span className="font-bold text-gray-900 dark:text-white">{formatNaira(requirements.verification_fee)}</span> from
                            your winnings balance to verify your account history. <br />
                            <br />
                            <span className="text-xs italic text-gray-400">This fee will be credited to your spending wallet.</span>
                        </p>

                        <button
                            onClick={() => submit(true)}
                            disabled={submitting}
                            className="mb-3 block w-full rounded-xl bg-orange-600 py-3.5 font-bold text-white shadow-lg shadow-orange-500/20 transition-transform hover:bg-orange-700 active:scale-95 disabled:opacity-60"
                        >
                            {submitting ? 'Processing…' : 'Yes, Deduct & Proceed'}
                        </button>

                        <button
                            onClick={() => setModal(null)}
                            className="w-full rounded-xl border border-gray-200 bg-transparent py-3.5 font-bold text-gray-600 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            )}

            {modal === 'success' && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-5 backdrop-blur-sm">
                    <div className="w-full max-w-sm rounded-3xl border bg-white p-6 text-center dark:border-gray-800 dark:bg-dark-card">
                        <div className="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                            <ThumbsUp className="h-10 w-10 stroke-[2] text-app-primary dark:text-blue-400" />
                        </div>
                        <h2 className="mb-2 text-xl font-bold text-gray-900 dark:text-white">Request Submitted</h2>
                        <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">{successMessage}</p>
                        <button
                            onClick={() => router.visit('/')}
                            className="block w-full rounded-xl bg-gray-100 py-3.5 font-bold text-gray-700 transition-transform active:scale-95 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        >
                            Close
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}
