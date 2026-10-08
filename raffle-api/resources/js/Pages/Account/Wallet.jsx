import { useEffect, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Award,
    CheckCircle2,
    ChevronRight,
    Clock,
    Eye,
    EyeOff,
    Headphones,
    Lock,
    RefreshCw,
    ShieldCheck,
    Wallet as WalletIcon,
    XCircle,
    Zap,
} from 'lucide-react';
import { formatNaira } from '../../lib/format';
import { apiPost, topUpKey } from '../../lib/api';
import { refreshBalances, setBalances, useBalanceHidden, useBalances } from '../../lib/balances';
import PausedNotice from '../../Components/layout/PausedNotice';
import TransferWinningsModal from '../../Components/wallet/TransferWinningsModal';
import { goBack } from '../../lib/nav';
import BottomNav from '../../Components/layout/BottomNav';
import AdSlot from '../../Components/ads/AdSlot';

// Top up (rebuilt from topup.php, items 26 and 48): enter an amount, pay
// on Paystack's hosted checkout, come back here to see it land. Item 48
// makes it fuller: both balances up top, amount buttons that always fit
// on a phone, the real smallest top-up from Settings, how it works, and
// the customer's last few top-ups.
const QUICK_AMOUNTS = [500, 1000, 2000, 5000, 10000, 20000];

const STATUS = {
    successful: { label: 'Paid', className: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' },
    pending: { label: 'Waiting', className: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' },
    amount_mismatch: { label: 'Checking', className: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' },
    failed: { label: 'Not paid', className: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' },
};

export default function AccountWallet({ minimumDeposit = 100, recentTopups = [] }) {
    const balances = useBalances();
    const [hidden, toggleHidden] = useBalanceHidden();
    const [amount, setAmount] = useState('');
    const topUpHolder = useRef({});
    const [status, setStatus] = useState('idle'); // idle | submitting
    const [error, setError] = useState(null);
    const [returned, setReturned] = useState(null); // the top-up we came back from, if any
    const [transferOpen, setTransferOpen] = useState(false);

    const minimum = Number(minimumDeposit) || 0;
    const quick = QUICK_AMOUNTS.filter((a) => a >= minimum).slice(0, 6);

    useEffect(() => {
        const depositId = new URLSearchParams(window.location.search).get('deposit');
        if (! depositId) return undefined;

        // Back from Paystack: show the result, and keep checking for a few
        // seconds while the payment is being confirmed.
        let tries = 0;
        let timer;

        function check() {
            fetch(`/api/deposits/${depositId}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((res) => (res.ok ? res.json() : null))
                .then((data) => {
                    setReturned(data);
                    if (data?.status === 'pending' && tries++ < 15) timer = setTimeout(check, 2000);
                    else refreshBalances(true);
                })
                .catch(() => {});
        }

        check();

        return () => clearTimeout(timer);
    }, []);

    const numeric = Number(amount);

    async function handlePay() {
        setError(null);

        if (! numeric || numeric <= 0) {
            setError('Enter an amount to top up.');
            return;
        }

        if (numeric < minimum) {
            setError(`The smallest top-up is ${formatNaira(minimum)}.`);
            return;
        }

        setStatus('submitting');

        try {
            const data = await apiPost('/api/deposits', { amount: numeric, idempotency_key: topUpKey(topUpHolder.current, numeric) });
            window.location.href = data.authorization_url;
        } catch (err) {
            setError(err.message);
            setStatus('idle');
        }
    }

    const show = (value) => (hidden ? '₦ ••••' : formatNaira(value));

    return (
        <>
            <Head title="Top Up Wallet" />
            <div className="min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-10 flex items-center gap-3 border-b border-gray-100 bg-white px-5 py-4 dark:border-dark-border dark:bg-dark-bg">
                    <button
                        onClick={() => goBack('/profile')}
                        className="-ml-1 p-1 text-gray-400 transition-colors hover:text-gray-900 dark:hover:text-white"
                        aria-label="Back"
                    >
                        <ArrowLeft className="h-6 w-6" />
                    </button>
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white">Top Up Wallet</h2>
                </div>

                <PausedNotice feature="deposits" className="mx-5 mt-4" />

                <div className="mx-auto max-w-lg space-y-5 p-5">
                    {/* Ad spot (admin: Site → Ads → "Wallet page"). */}
                    <AdSlot placement="wallet" />
                    {/* Balances */}
                    <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-blue-600 to-indigo-700 p-5 text-white shadow-lg shadow-blue-500/25">
                        <div aria-hidden="true" className="pointer-events-none absolute -right-8 -top-8 h-32 w-32 rounded-full bg-white/10 blur-2xl" />
                        <div className="relative flex items-start justify-between">
                            <div>
                                <p className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-blue-100">
                                    <WalletIcon className="h-3.5 w-3.5" /> Spending wallet
                                </p>
                                <p data-rk-mask className="mt-1 text-3xl font-black tracking-tight">{balances ? show(balances.wallet) : '…'}</p>
                            </div>
                            <button
                                type="button"
                                onClick={toggleHidden}
                                className="rounded-full bg-white/15 p-2 backdrop-blur"
                                aria-label={hidden ? 'Show balances' : 'Hide balances'}
                            >
                                {hidden ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                            </button>
                        </div>
                        <div className="relative mt-4 flex items-center justify-between gap-3 rounded-2xl bg-white/10 px-4 py-3 backdrop-blur">
                            <div className="min-w-0">
                                <p className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-blue-100">
                                    <Award className="h-3 w-3" /> Winnings
                                </p>
                                <p data-rk-mask className="text-lg font-black">{balances ? show(balances.earnings) : '…'}</p>
                            </div>
                            {balances?.earnings > 0 && (
                                <button
                                    type="button"
                                    onClick={() => setTransferOpen(true)}
                                    className="flex flex-shrink-0 items-center gap-1.5 rounded-xl bg-white px-3 py-2 text-xs font-bold text-blue-700 active:scale-95"
                                >
                                    <RefreshCw className="h-3.5 w-3.5" /> Move to wallet
                                </button>
                            )}
                        </div>
                    </section>

                    {returned && <DepositStatusBanner deposit={returned} />}

                    {/* Amount */}
                    <section className="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm dark:border-dark-border dark:bg-dark-card">
                        <div className="mb-2 flex items-baseline justify-between">
                            <label htmlFor="topup-amount" className="text-sm font-bold text-gray-900 dark:text-white">
                                How much?
                            </label>
                            <span className="text-xs text-gray-500 dark:text-gray-400">Minimum {formatNaira(minimum)}</span>
                        </div>
                        <div className="relative">
                            <span className="absolute left-4 top-1/2 -translate-y-1/2 text-2xl font-black text-gray-400">₦</span>
                            <input
                                id="topup-amount"
                                type="number"
                                inputMode="numeric"
                                value={amount}
                                onChange={(e) => {
                                    setAmount(e.target.value);
                                    setError(null);
                                }}
                                placeholder="Enter amount"
                                min={minimum}
                                className="w-full rounded-2xl border-2 border-gray-100 bg-gray-50 py-4 pl-11 pr-4 text-2xl font-black text-gray-900 outline-none transition-colors placeholder:text-base placeholder:font-medium placeholder:text-gray-400 focus:border-app-primary focus:bg-white dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:bg-gray-900"
                            />
                        </div>

                        {/* Always fits a phone: a 3-column grid, not a row that scrolls off. */}
                        <div className="mt-3 grid grid-cols-3 gap-2">
                            {quick.map((qa) => (
                                <button
                                    key={qa}
                                    type="button"
                                    onClick={() => {
                                        setAmount(String(qa));
                                        setError(null);
                                    }}
                                    className={[
                                        'rounded-xl border py-2.5 text-sm font-bold transition-colors active:scale-95',
                                        numeric === qa
                                            ? 'border-app-primary bg-blue-50 text-app-primary dark:bg-blue-900/30'
                                            : 'border-gray-200 bg-white text-gray-700 hover:border-app-primary dark:border-gray-700 dark:bg-dark-card dark:text-gray-200',
                                    ].join(' ')}
                                >
                                    {formatNaira(qa)}
                                </button>
                            ))}
                        </div>

                        {error && (
                            <div role="alert" className="mt-4 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                                {error}
                            </div>
                        )}

                        <button
                            onClick={handlePay}
                            disabled={status === 'submitting'}
                            className="mt-5 flex w-full items-center justify-center gap-2 rounded-2xl bg-app-primary py-4 text-base font-bold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <Lock className="h-4 w-4" />
                            {status === 'submitting' ? 'Starting payment…' : numeric >= minimum && numeric > 0 ? `Pay ${formatNaira(numeric)} securely` : 'Pay securely'}
                        </button>

                        <div className="mt-4 grid grid-cols-3 gap-2 text-center">
                            <Trust icon={ShieldCheck} text="Secured by Paystack" />
                            <Trust icon={Zap} text="Added instantly" />
                            <Trust icon={Headphones} text="Help if stuck" />
                        </div>
                    </section>

                    {/* How it works */}
                    <section className="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm dark:border-dark-border dark:bg-dark-card">
                        <h3 className="mb-3 text-sm font-bold text-gray-900 dark:text-white">How it works</h3>
                        <ol className="space-y-3">
                            {[
                                'Enter an amount or tap one of the buttons.',
                                "Pay on Paystack's secure page with your card, bank transfer or USSD.",
                                'You come straight back here and the money is in your wallet, ready for tickets.',
                            ].map((text, i) => (
                                <li key={text} className="flex items-start gap-3">
                                    <span className="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-black text-app-primary dark:bg-blue-900/30">
                                        {i + 1}
                                    </span>
                                    <span className="text-sm text-gray-600 dark:text-gray-300">{text}</span>
                                </li>
                            ))}
                        </ol>
                        <p className="mt-4 text-xs text-gray-400 dark:text-gray-500">
                            Money in your spending wallet is for tickets and can't be withdrawn. Winnings can be withdrawn.
                        </p>
                    </section>

                    {/* Recent top-ups */}
                    <section className="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm dark:border-dark-border dark:bg-dark-card">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-sm font-bold text-gray-900 dark:text-white">Recent top-ups</h3>
                            <Link href="/account/transactions" className="flex items-center text-xs font-bold text-app-primary">
                                All transactions <ChevronRight className="h-3.5 w-3.5" />
                            </Link>
                        </div>
                        {recentTopups.length === 0 ? (
                            <p className="py-3 text-center text-sm text-gray-400">No top-ups yet.</p>
                        ) : (
                            <ul className="divide-y divide-gray-50 dark:divide-gray-800">
                                {recentTopups.map((t) => {
                                    const st = STATUS[t.status] ?? STATUS.pending;

                                    return (
                                        <li key={t.id} className="flex items-center justify-between py-2.5">
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-800">
                                                    <Clock className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p className="text-sm font-bold text-gray-900 dark:text-white">{formatNaira(t.amount)}</p>
                                                    <p className="text-[11px] text-gray-400">
                                                        {t.created_at ? new Date(t.created_at).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : ''}
                                                    </p>
                                                </div>
                                            </div>
                                            <span className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${st.className}`}>{st.label}</span>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>

                    <p className="text-center text-xs text-gray-400">
                        Payment taken but not showing?{' '}
                        <Link href="/support?new=1" className="font-bold text-app-primary">
                            Tell us
                        </Link>{' '}
                        and we'll sort it out.
                    </p>
                </div>
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

            <TransferWinningsModal
                open={transferOpen}
                onClose={() => setTransferOpen(false)}
                earnings={balances?.earnings ?? 0}
                onDone={(data) => setBalances(data)}
            />
            <BottomNav />
        </>
    );
}

function Trust({ icon: Icon, text }) {
    return (
        <div className="flex flex-col items-center gap-1 rounded-xl bg-gray-50 px-1 py-2 dark:bg-gray-800/50">
            <Icon className="h-4 w-4 text-green-600 dark:text-green-400" />
            <span className="text-[10px] font-medium leading-tight text-gray-500 dark:text-gray-400">{text}</span>
        </div>
    );
}

function DepositStatusBanner({ deposit }) {
    if (deposit.status === 'successful') {
        return (
            <div className="flex items-center gap-3 rounded-2xl border border-green-200 bg-green-50 p-4 dark:border-green-900/30 dark:bg-green-900/20">
                <CheckCircle2 className="h-6 w-6 flex-shrink-0 text-green-600 dark:text-green-400" />
                <div>
                    <p className="text-sm font-bold text-green-800 dark:text-green-300">Payment confirmed</p>
                    <p className="text-xs text-green-700 dark:text-green-400">{formatNaira(deposit.amount)} has been added to your wallet.</p>
                </div>
            </div>
        );
    }

    if (deposit.status === 'pending') {
        return (
            <div className="flex items-center gap-3 rounded-2xl border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-900/30 dark:bg-yellow-900/20">
                <div className="h-6 w-6 flex-shrink-0 animate-spin rounded-full border-2 border-yellow-500 border-t-transparent" />
                <div>
                    <p className="text-sm font-bold text-yellow-800 dark:text-yellow-300">Confirming your payment…</p>
                    <p className="text-xs text-yellow-700 dark:text-yellow-400">This usually only takes a few seconds.</p>
                </div>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 dark:border-red-900/30 dark:bg-red-900/20">
            <XCircle className="h-6 w-6 flex-shrink-0 text-red-600 dark:text-red-400" />
            <div>
                <p className="text-sm font-bold text-red-800 dark:text-red-300">Payment not completed</p>
                <p className="text-xs text-red-700 dark:text-red-400">No money was taken. You can try again below.</p>
            </div>
        </div>
    );
}
