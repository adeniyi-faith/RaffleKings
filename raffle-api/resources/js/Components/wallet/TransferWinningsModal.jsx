import { useEffect, useState } from 'react';
import { ArrowDown, Award, CheckCircle2, Wallet } from 'lucide-react';
import Modal from '../ui/Modal';
import { apiPost } from '../../lib/api';
import { formatNaira } from '../../lib/format';
import { setBalances } from '../../lib/balances';

// Move winnings into the spending wallet (item 46): free, instant, one
// way. Used by the Profile page's "Transfer" button, which used to only
// open the top-up page.
export default function TransferWinningsModal({ open, onClose, earnings, onDone }) {
    const [amount, setAmount] = useState('');
    const [status, setStatus] = useState('idle'); // idle | sending | done
    const [error, setError] = useState(null);
    const [moved, setMoved] = useState(0);

    useEffect(() => {
        if (open) {
            setAmount('');
            setStatus('idle');
            setError(null);
        }
    }, [open]);

    const available = Number(earnings) || 0;
    const numeric = Number(amount);

    async function submit() {
        setError(null);

        if (! numeric || numeric <= 0) {
            setError('Enter an amount to move.');
            return;
        }

        if (numeric > available) {
            setError(`You only have ${formatNaira(available)} in your winnings.`);
            return;
        }

        setStatus('sending');

        try {
            const data = await apiPost('/api/wallet/transfer', { amount: numeric });
            setMoved(data.moved);
            setStatus('done');
            setBalances(data);
            onDone?.(data);
        } catch (err) {
            setError(err.message);
            setStatus('idle');
        }
    }

    return (
        <Modal open={open} onClose={status === 'idle' ? onClose : undefined} title={status === 'done' ? null : 'Move winnings to wallet'}>
            {status === 'done' ? (
                <div className="text-center">
                    <CheckCircle2 className="mx-auto mb-3 h-12 w-12 text-green-500" />
                    <h3 className="mb-1 text-lg font-bold text-gray-900 dark:text-white">Done!</h3>
                    <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
                        {formatNaira(moved)} is now in your spending wallet, ready for tickets.
                    </p>
                    <button onClick={onClose} className="w-full rounded-xl bg-app-primary py-3 text-sm font-bold text-white active:scale-95">
                        OK
                    </button>
                </div>
            ) : (
                <>
                    <div className="mb-4 rounded-2xl border border-gray-100 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-dark-bg/50">
                        <p className="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-200">
                            <Award className="h-4 w-4 text-yellow-500" /> Winnings: {formatNaira(available)}
                        </p>
                        <ArrowDown className="my-1 ml-0.5 h-4 w-4 text-gray-400" />
                        <p className="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-200">
                            <Wallet className="h-4 w-4 text-app-primary" /> Spending wallet
                        </p>
                    </div>

                    <label className="mb-1 block text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Amount</label>
                    <div className="relative mb-2">
                        <span className="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-gray-400">₦</span>
                        <input
                            type="number"
                            inputMode="decimal"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                            placeholder="Amount"
                            min="0"
                            className="w-full rounded-xl border border-transparent bg-gray-50 py-3 pl-10 pr-20 font-bold text-gray-800 outline-none focus:border-app-primary focus:ring-2 focus:ring-app-primary/20 dark:bg-gray-800 dark:text-white"
                        />
                        <button
                            type="button"
                            onClick={() => setAmount(String(available))}
                            disabled={available <= 0}
                            className="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg bg-white px-2.5 py-1 text-xs font-bold text-app-primary shadow-sm disabled:opacity-50 dark:bg-dark-card"
                        >
                            All
                        </button>
                    </div>
                    <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">
                        Free and instant. Money in your spending wallet can be used for tickets but can't be withdrawn, and can't be moved back.
                    </p>

                    {error && <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">{error}</div>}

                    <button
                        onClick={submit}
                        disabled={status === 'sending' || available <= 0}
                        className="w-full rounded-xl bg-app-primary py-3 text-sm font-bold text-white shadow-lg shadow-blue-500/30 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {status === 'sending' ? 'Moving…' : available <= 0 ? 'No winnings to move yet' : 'Move to wallet'}
                    </button>
                </>
            )}
        </Modal>
    );
}
