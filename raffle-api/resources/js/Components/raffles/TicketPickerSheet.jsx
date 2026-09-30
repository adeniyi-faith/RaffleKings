import { useEffect, useState } from 'react';
import { ArrowRight, Minus, Plus, Star, TrendingDown } from 'lucide-react';
import { formatNaira } from '../../lib/format';
import RaffleOdds from './RaffleOdds';

const POPULAR = 3;

// The bundle names players already know. Any size an admin adds that is not
// listed here gets a plain "Bundle discount" label.
const LABELS = {
    1: 'Starter',
    2: 'Double chances',
    3: 'Most popular',
    5: 'Massive savings applied',
    10: 'Unfair advantage',
};

function savingPercent(quote) {
    if (! quote || ! (quote.original > quote.discounted)) {
        return 0;
    }

    return Math.round((1 - quote.discounted / quote.original) * 100);
}

/**
 * Choosing tickets: the bundle buttons (with their real discounts), or any
 * amount the player likes up to the limit (the tickets left, or the admin's
 * "most tickets in one order"), with their chance of winning updating as they
 * choose. Everything comes from the server: prices from the price quote,
 * chances from the odds calculator, the limit from the raffle itself.
 */
export default function TicketPickerSheet({
    raffleId,
    bundles,
    quotes,
    selectedQty,
    onSelect,
    customQuote,
    maxAllowed,
    orderLimit,
    remaining,
    total,
    disabled,
    onProceed,
}) {
    const isBundle = bundles.includes(selectedQty);
    const [draft, setDraft] = useState(String(selectedQty));

    // Keep the box in step when a bundle button changes the amount.
    useEffect(() => {
        setDraft(String(selectedQty));
    }, [selectedQty]);

    function clamp(n) {
        return Math.max(1, Math.min(maxAllowed, n));
    }

    function change(n) {
        onSelect(clamp(n));
    }

    function onType(e) {
        const raw = e.target.value.replace(/[^0-9]/g, '');
        setDraft(raw);

        if (raw !== '') {
            change(parseInt(raw, 10));
        }
    }

    const limitNote = orderLimit && orderLimit <= remaining
        ? `You can order up to ${orderLimit.toLocaleString()} tickets at a time.`
        : `Up to ${maxAllowed.toLocaleString()} (all that are left).`;
    const customSaving = savingPercent(customQuote);

    return (
        <div className="space-y-4">
            <div>
                <p className="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Bundles</p>
                <div className="space-y-2" role="group" aria-label="Ticket bundles">
                    {bundles.map((qty) => {
                        const quote = quotes[qty];
                        const save = savingPercent(quote);
                        const on = selectedQty === qty;
                        const popular = qty === POPULAR;

                        return (
                            <button
                                key={qty}
                                type="button"
                                onClick={() => onSelect(qty)}
                                aria-pressed={on}
                                className={[
                                    'relative flex w-full items-center gap-3 rounded-2xl border-2 px-4 py-3 text-left transition-all active:scale-[0.99]',
                                    on
                                        ? 'border-amber-400 bg-amber-50 shadow-sm dark:bg-amber-900/20'
                                        : popular
                                          ? 'border-amber-200 bg-amber-50/40 dark:border-amber-900/40 dark:bg-dark-bg'
                                          : 'border-gray-200 bg-white hover:border-blue-200 dark:border-gray-700 dark:bg-dark-bg',
                                ].join(' ')}
                            >
                                                                <span className="flex-1">
                                    <span className="block font-extrabold text-gray-900 dark:text-white">{qty} {qty === 1 ? 'ticket' : 'tickets'}</span>
                                    <span className="flex items-center gap-1 text-xs font-semibold text-blue-600 dark:text-blue-400">
                                        {popular && <Star className="h-3 w-3 fill-amber-400 text-amber-500" aria-hidden="true" />}
                                        {LABELS[qty] ?? 'Bundle discount'}
                                    </span>
                                </span>
                                <span className="flex flex-col items-end">
                                    {quote && quote.original > quote.discounted && (
                                        <span className="text-xs text-gray-400 line-through tabular-nums">{formatNaira(quote.original)}</span>
                                    )}
                                    <span className="font-extrabold tabular-nums text-gray-900 dark:text-white">{quote ? formatNaira(quote.discounted) : '…'}</span>
                                    {save > 0 && <span className="text-[10px] font-extrabold text-emerald-600 dark:text-emerald-400">Save {save}%</span>}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className={['rounded-2xl border-2 p-3 transition-colors', ! isBundle ? 'border-amber-400 bg-amber-50/60 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700'].join(' ')}>
                <div className="mb-2">
                    <p className="text-sm font-bold text-gray-900 dark:text-white">Your own amount</p>
                    <p className="text-[11px] text-gray-500 dark:text-gray-400">{limitNote}</p>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => change(selectedQty - 1)}
                        disabled={selectedQty <= 1}
                        className="flex h-11 w-11 flex-none items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-700 active:scale-95 disabled:opacity-40 dark:border-gray-700 dark:bg-dark-bg dark:text-gray-200"
                        aria-label="One fewer ticket"
                    >
                        <Minus className="h-5 w-5" />
                    </button>
                    <input
                        type="text"
                        inputMode="numeric"
                        value={draft}
                        onChange={onType}
                        onBlur={() => setDraft(String(selectedQty))}
                        className="h-11 min-w-0 flex-1 rounded-xl border border-gray-200 bg-white text-center text-lg font-extrabold tabular-nums text-gray-900 outline-none focus:border-blue-500 dark:border-gray-700 dark:bg-dark-bg dark:text-white"
                        aria-label="Number of tickets"
                    />
                    <button
                        type="button"
                        onClick={() => change(selectedQty + 1)}
                        disabled={selectedQty >= maxAllowed}
                        className="flex h-11 w-11 flex-none items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-700 active:scale-95 disabled:opacity-40 dark:border-gray-700 dark:bg-dark-bg dark:text-gray-200"
                        aria-label="One more ticket"
                    >
                        <Plus className="h-5 w-5" />
                    </button>
                </div>
                {! isBundle && customSaving > 0 && (
                    <p className="mt-2 flex items-center gap-1.5 text-xs font-bold text-emerald-700 dark:text-emerald-400">
                        <TrendingDown className="h-3.5 w-3.5" aria-hidden="true" /> You save {formatNaira(customQuote.original - customQuote.discounted)} ({customSaving}%)
                    </p>
                )}
            </div>

            <RaffleOdds raffleId={raffleId} quantity={selectedQty} />

            <button
                type="button"
                onClick={onProceed}
                disabled={disabled}
                className="flex w-full items-center justify-center gap-2 rounded-2xl bg-app-primary py-4 text-base font-extrabold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
            >
                Select numbers {total !== null && <span className="tabular-nums">· {formatNaira(total)}</span>} <ArrowRight className="h-5 w-5" />
            </button>
        </div>
    );
}
