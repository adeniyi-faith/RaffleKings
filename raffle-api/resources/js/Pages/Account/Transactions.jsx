import { useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowLeft as BackIcon,
    Coins,
    History,
    Landmark,
    RefreshCw,
    Ticket,
    Trophy,
    Users,
} from 'lucide-react';
import { formatNaira } from '../../lib/format';

// Faithful rebuild of transactions.php + assets/js/financials/transactions.js
// against the merged legacy-transaction + wallet-ledger history (item 26)
// — see AccountReadService::transactions()'s docblock for why both
// sources are merged rather than showing only the new ledger.
const TYPE_META = {
    wallet_deposit: { icon: ArrowDownLeft, color: 'text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/20', title: 'Wallet Deposit', sign: '+', category: 'in' },
    ticket_purchase: { icon: Ticket, color: 'text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900/20', title: 'Ticket Purchase', sign: '-', category: 'out' },
    ticket_purchase_wallet: { icon: Ticket, color: 'text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900/20', title: 'Ticket Purchase', sign: '-', category: 'out' },
    earnings_transfer: { icon: RefreshCw, color: 'text-orange-600 dark:text-orange-400 bg-orange-100 dark:bg-orange-900/20', title: 'Earnings to Wallet', sign: '+', category: 'in' },
    transfer: { icon: ArrowLeftRight, color: 'text-orange-600 dark:text-orange-400 bg-orange-100 dark:bg-orange-900/20', title: 'Earnings to Wallet', sign: '+', category: 'in' },
    withdrawal: { icon: Landmark, color: 'text-red-600 dark:text-red-400 bg-red-100 dark:bg-red-900/20', title: 'Bank Withdrawal', sign: '-', category: 'out' },
    withdrawal_refund: { icon: Landmark, color: 'text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/20', title: 'Withdrawal Refunded', sign: '+', category: 'in' },
    points_redemption: { icon: Coins, color: 'text-yellow-600 dark:text-yellow-400 bg-yellow-100 dark:bg-yellow-900/20', title: 'Points Redeemed', sign: '+', category: 'in' },
    referral_commission: { icon: Users, color: 'text-purple-600 dark:text-purple-400 bg-purple-100 dark:bg-purple-900/20', title: 'Referral Bonus', sign: '+', category: 'in' },
    prize_win: { icon: Trophy, color: 'text-yellow-600 dark:text-yellow-400 bg-yellow-100 dark:bg-yellow-900/20', title: 'Raffle Win', sign: '+', category: 'in' },
    opening_balance: { icon: History, color: 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800', title: 'Opening Balance', sign: '+', category: 'other' },
};

function metaFor(type) {
    if (TYPE_META[type]) return TYPE_META[type];

    const category = type.startsWith('credit_') ? 'in' : type.startsWith('debit_') ? 'out' : 'other';

    return {
        icon: ArrowLeftRight,
        color: 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800',
        title: type.replace(/^(credit_|debit_)/, '').replace(/_/g, ' ').toUpperCase(),
        sign: category === 'in' ? '+' : category === 'out' ? '-' : '',
        category,
    };
}

const FILTERS = [
    { key: 'all', label: 'All' },
    { key: 'in', label: 'Money In' },
    { key: 'out', label: 'Money Out' },
];

export default function AccountTransactions() {
    const [state, setState] = useState('loading');
    const [transactions, setTransactions] = useState([]);
    const [filter, setFilter] = useState('all');

    useEffect(() => {
        const controller = new AbortController();

        fetch('/api/account/transactions', { signal: controller.signal, headers: { Accept: 'application/json' } })
            .then((res) => {
                if (res.status === 401) {
                    router.visit('/login?redirect=' + encodeURIComponent('/account/transactions'));
                    return null;
                }
                if (! res.ok) throw new Error(`Server Error ${res.status}`);
                return res.json();
            })
            .then((payload) => {
                if (! payload) return;
                setTransactions(payload.data || []);
                setState('ready');
            })
            .catch((err) => {
                if (err.name !== 'AbortError') setState('error');
            });

        return () => controller.abort();
    }, []);

    const visible = useMemo(() => {
        if (filter === 'all') return transactions;

        return transactions.filter((tx) => metaFor(tx.type).category === filter);
    }, [transactions, filter]);

    return (
        <>
            <Head title="Transactions" />
            <div className="relative min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white px-5 pb-4 pt-4 shadow-sm dark:border-dark-border dark:bg-dark-bg dark:shadow-none">
                    <button
                        onClick={() => window.history.back()}
                        className="-ml-1 p-1 text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-200"
                    >
                        <BackIcon className="h-5 w-5" />
                    </button>
                    <div>
                        <h2 className="text-xl font-bold text-gray-900 dark:text-white">Transactions</h2>
                        <p className="text-xs text-gray-500 dark:text-gray-400">History of your payments &amp; wins.</p>
                    </div>
                </div>

                <div className="flex gap-2 overflow-x-auto px-5 py-4">
                    {FILTERS.map((f) => (
                        <button
                            key={f.key}
                            onClick={() => setFilter(f.key)}
                            className={[
                                'rounded-full px-4 py-1.5 text-xs font-bold shadow-sm transition-all',
                                filter === f.key
                                    ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'
                                    : 'border border-gray-200 bg-white text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:bg-dark-card dark:text-gray-400 dark:hover:bg-gray-800',
                            ].join(' ')}
                        >
                            {f.label}
                        </button>
                    ))}
                </div>

                <section className="space-y-3 px-5 pb-5">
                    {state === 'loading' &&
                        [0, 1].map((i) => (
                            <div
                                key={i}
                                className="flex animate-pulse items-center justify-between rounded-xl border border-gray-100 bg-white p-4 shadow-sm dark:border-dark-border dark:bg-dark-card"
                            >
                                <div className="flex items-center gap-3">
                                    <div className="h-10 w-10 rounded-full bg-gray-200 dark:bg-gray-700" />
                                    <div className="space-y-2">
                                        <div className="h-3 w-24 rounded bg-gray-200 dark:bg-gray-700" />
                                        <div className="h-2 w-16 rounded bg-gray-200 dark:bg-gray-700" />
                                    </div>
                                </div>
                                <div className="h-4 w-16 rounded bg-gray-200 dark:bg-gray-700" />
                            </div>
                        ))}

                    {state === 'error' && <p className="text-center text-xs text-red-500">Failed to load history.</p>}

                    {state === 'ready' && visible.length === 0 && (
                        <div className="flex flex-col items-center justify-center py-20 text-center">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                                <History className="h-8 w-8 text-gray-400 dark:text-gray-500" />
                            </div>
                            <h3 className="font-bold text-gray-900 dark:text-white">No Transactions Yet</h3>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Your activity will show up here.</p>
                        </div>
                    )}

                    {state === 'ready' &&
                        visible.map((tx) => {
                            const meta = metaFor(tx.type);
                            const Icon = meta.icon;
                            const amountColor =
                                meta.sign === '+' ? 'text-green-600 dark:text-green-400' : 'text-gray-900 dark:text-white';
                            const date = tx.created_at
                                ? new Date(tx.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
                                : '';

                            let statusBadge = null;
                            if (tx.status === 'manual_review' || tx.status === 'pending') {
                                statusBadge = (
                                    <span className="rounded bg-yellow-100 px-1.5 py-0.5 text-[10px] font-bold text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">
                                        Pending
                                    </span>
                                );
                            } else if (tx.status === 'rejected' || tx.status === 'failed' || tx.status === 'amount_mismatch') {
                                statusBadge = (
                                    <span className="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-600 dark:bg-red-900/30 dark:text-red-400">
                                        Failed
                                    </span>
                                );
                            }

                            return (
                                <div
                                    key={tx.id}
                                    className="flex items-center justify-between rounded-xl border border-gray-100 bg-white p-4 shadow-sm transition-transform active:scale-[0.99] dark:border-dark-border dark:bg-dark-card"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className={`flex h-10 w-10 items-center justify-center rounded-full ${meta.color}`}>
                                            <Icon className="h-5 w-5" />
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold leading-tight text-gray-900 dark:text-white">{meta.title}</h4>
                                            <p className="mt-0.5 text-[10px] text-gray-400 dark:text-gray-500">{date}</p>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <p className={`text-sm font-bold ${amountColor}`}>
                                            {meta.sign}
                                            {formatNaira(tx.claimed_amount)}
                                        </p>
                                        {statusBadge}
                                    </div>
                                </div>
                            );
                        })}
                </section>
            </div>
        </>
    );
}
