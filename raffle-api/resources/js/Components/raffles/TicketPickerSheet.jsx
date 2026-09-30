import { useEffect, useState } from 'react';
import { ArrowRight, ArrowUpRight, Check, Circle, Crown, Flame, Minus, Plus, Rocket, TrendingDown } from 'lucide-react';
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
    const [bulkOpen, setBulkOpen] = useState(! isBundle);

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

    function pickBundle(qty) {
        setBulkOpen(false);
        onSelect(qty);
    }

    function openBulk() {
        setBulkOpen(true);

        if (isBundle) {
            // Start just above the biggest bundle, so it is clearly "more than the cards".
            onSelect(clamp(Math.max(...bundles) + 1));
        }
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
            <div className="space-y-2.5" role="group" aria-label="Ticket bundles">
                {bundles.map((qty) => {
                    const quote = quotes[qty];
                    const save = savingPercent(quote);
                    const on = ! bulkOpen && selectedQty === qty;
                    const popular = qty === POPULAR;
                    const whale = qty === 5;
                    const Icon = popular ? Flame : whale ? ArrowUpRight : qty === 10 ? Rocket : Circle;

                    return (
                        <button
                            key={qty}
                            type="button"
                            onClick={() => pickBundle(qty)}
                            aria-pressed={on}
                            className={[
                                'relative flex w-full items-center gap-3 rounded-xl border-2 p-4 text-left transition-all active:scale-[0.99]',
                                on
                                    ? 'border-yellow-400 bg-yellow-50 shadow-md shadow-yellow-400/30 dark:bg-yellow-900/20'
                                    : popular
                                      ? 'border-yellow-200 bg-yellow-50/50 dark:border-yellow-900/40 dark:bg-yellow-900/10'
                                      : whale
                                        ? 'border-purple-200 bg-purple-50/60 dark:border-purple-900/50 dark:bg-purple-900/10'
                                        : 'border-gray-200 bg-white hover:border-blue-200 dark:border-gray-700 dark:bg-dark-bg',
                            ].join(' ')}
                        >
                            {popular && (
                                <span className="absolute -top-2.5 left-4 flex items-center gap-1 rounded bg-yellow-400 px-2 py-0.5 text-[10px] font-extrabold text-blue-900 shadow-sm">
                                    <Flame className="h-3 w-3 fill-current" aria-hidden="true" /> MOST POPULAR
                                </span>
                            )}
                            <span className="flex-1">
                                <span className="flex items-center gap-2">
                                    <span className={`font-extrabold text-gray-900 dark:text-white ${popular || whale ? 'text-lg' : ''}`}>{qty} {qty === 1 ? 'Ticket' : 'Tickets'}</span>
                                    {whale && <span className="rounded bg-red-100 px-1.5 py-0.5 text-[9px] font-extrabold text-red-600">WHALE TIER</span>}
                                </span>
                                <span className={`mt-0.5 flex items-center gap-1 text-xs font-bold ${popular ? 'text-green-600 dark:text-green-400' : whale ? 'text-purple-600 dark:text-purple-400' : 'text-blue-600 dark:text-blue-400'}`}>
                                    {(popular || whale || qty === 10) && <Icon className="h-3.5 w-3.5" aria-hidden="true" />}
                                    {LABELS[qty] ?? 'Bundle discount'}
                                </span>
                            </span>
                            <span className="flex flex-col items-end">
                                {quote && quote.original > quote.discounted && (
                                    <span className="text-xs font-medium text-gray-400 line-through tabular-nums">{formatNaira(quote.original)}</span>
                                )}
                                <span className="font-extrabold tabular-nums text-gray-900 dark:text-white">{quote ? formatNaira(quote.discounted) : '…'}</span>
                                {save > 0 && <span className="text-[10px] font-extrabold text-emerald-600 dark:text-emerald-400">Save {save}%</span>}
                            </span>
                            <span className={`flex h-5 w-5 flex-none items-center justify-center rounded-full border ${on ? 'border-app-primary bg-app-primary text-white' : 'border-gray-300 dark:border-gray-600'}`}>
                                {on && <Check className="h-3 w-3" aria-hidden="true" />}
                            </span>
                        </button>
                    );
                })}

                <div className={`relative overflow-hidden rounded-xl border-2 bg-indigo-950 p-4 shadow-lg transition-all ${bulkOpen ? 'border-yellow-400' : 'border-indigo-900'}`}>
                    <div className="pointer-events-none absolute right-0 top-0 h-32 w-32 -translate-y-1/2 translate-x-1/2 rounded-full bg-indigo-700/30 blur-2xl" aria-hidden="true" />
                    <button type="button" onClick={openBulk} aria-expanded={bulkOpen} className="relative flex w-full items-center justify-between text-left">
                        <span>
                            <span className="flex items-center gap-1.5 font-bold text-white"><Crown className="h-4 w-4 text-yellow-300" aria-hidden="true" /> Bulk / Custom</span>
                            <span className="block text-xs text-indigo-300">Go bigger: pick your own quantity</span>
                        </span>
                        <span className={`flex h-5 w-5 flex-none items-center justify-center rounded-full border ${bulkOpen ? 'border-yellow-400 bg-yellow-400 text-indigo-950' : 'border-indigo-700'}`}>
                            {bulkOpen && <Check className="h-3 w-3" aria-hidden="true" />}
                        </span>
                    </button>

                    {bulkOpen && (
                        <div className="relative mt-4 border-t border-indigo-800/60 pt-3">
                            <p className="mb-2 text-[11px] text-indigo-300">{limitNote}</p>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => change(selectedQty - 1)}
                                    disabled={selectedQty <= 1}
                                    className="flex h-11 w-11 flex-none items-center justify-center rounded-xl border border-indigo-700 bg-indigo-900/60 text-white active:scale-95 disabled:opacity-40"
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
                                    className="h-11 min-w-0 flex-1 rounded-xl border border-indigo-700 bg-indigo-900/60 text-center text-lg font-extrabold tabular-nums text-white outline-none focus:border-yellow-400"
                                    aria-label="Number of tickets"
                                />
                                <button
                                    type="button"
                                    onClick={() => change(selectedQty + 1)}
                                    disabled={selectedQty >= maxAllowed}
                                    className="flex h-11 w-11 flex-none items-center justify-center rounded-xl border border-indigo-700 bg-indigo-900/60 text-white active:scale-95 disabled:opacity-40"
                                    aria-label="One more ticket"
                                >
                                    <Plus className="h-5 w-5" />
                                </button>
                            </div>
                            {customSaving > 0 && (
                                <p className="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-green-400/10 px-2 py-1.5 text-xs text-green-400">
                                    <TrendingDown className="h-3.5 w-3.5" aria-hidden="true" /> You save <span className="font-bold">{formatNaira(customQuote.original - customQuote.discounted)}</span> ({customSaving}%)
                                </p>
                            )}
                        </div>
                    )}
                </div>
            </div>

            <RaffleOdds raffleId={raffleId} quantity={selectedQty} />

            <button
                type="button"
                onClick={onProceed}
                disabled={disabled}
                className="rk-shine relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-2xl bg-gradient-to-r from-amber-400 via-orange-500 to-rose-500 py-4 text-base font-black text-white shadow-lg shadow-orange-500/40 transition-transform active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
            >
                Select numbers {total !== null && <span className="tabular-nums">· {formatNaira(total)}</span>} <ArrowRight className="h-5 w-5" />
            </button>
        </div>
    );
}
