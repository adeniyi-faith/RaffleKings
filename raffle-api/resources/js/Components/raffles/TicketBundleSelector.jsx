import { useState } from 'react';
import { Check, Circle, Flame, ArrowUpRight, TrendingDown } from 'lucide-react';
import { formatNaira } from '../../lib/format';
import NumberStepper from '../ui/NumberStepper';

const TIERS = [
    { qty: 1, label: '1 Ticket', subtitle: 'Starter', icon: Circle },
    { qty: 2, label: '2 Tickets', subtitle: 'Double Chances', icon: Circle },
    { qty: 3, label: '3 Tickets', subtitle: 'Most Popular', icon: Flame, featured: true },
    { qty: 5, label: '5 Tickets', subtitle: 'Massive Savings Applied!', icon: ArrowUpRight, whale: true },
    { qty: 10, label: '10 Tickets', subtitle: 'Unfair Advantage \u{1F680}', icon: Circle },
];

// The ticket-bundle tier cards from raffle-details.php, preserved
// visually as-is per the product owner's direction (the yellow "MOST
// POPULAR" star tier, the purple "WHALE TIER" chip, the dark indigo
// bulk-buy card) — only the underlying price now always comes from the
// server quote instead of being hand-calculated in JS.
export default function TicketBundleSelector({ quotes, selected, onSelect, bulkQty, onBulkQtyChange, bulkQuote }) {
    const [bulkActive, setBulkActive] = useState(false);

    function selectTier(qty) {
        setBulkActive(false);
        onSelect(qty);
    }

    function activateBulk() {
        setBulkActive(true);
        onSelect(bulkQty);
    }

    return (
        <div className="space-y-2">
            <h3 className="mb-4 flex items-center justify-between px-1 text-sm font-bold text-gray-900 dark:text-white">
                Select Ticket Bundle
                <span className="rounded-full bg-green-100 px-2 py-0.5 text-[10px] text-green-600 dark:bg-green-900/30 dark:text-green-400">
                    Discounts Active
                </span>
            </h3>

            {TIERS.map((tier) => {
                const quote = quotes[tier.qty];
                const isSelected = ! bulkActive && selected === tier.qty;
                const Icon = tier.icon;

                return (
                    <div
                        key={tier.qty}
                        onClick={() => selectTier(tier.qty)}
                        role="button"
                        tabIndex={0}
                        className={[
                            'relative cursor-pointer rounded-xl p-4 transition-all',
                            tier.featured
                                ? 'border-2 border-yellow-400 bg-yellow-50/50 shadow-sm dark:bg-yellow-900/20'
                                : tier.whale
                                  ? 'border border-purple-200 bg-purple-50/50 dark:border-purple-900/50 dark:bg-purple-900/10'
                                  : 'border border-gray-200 bg-white hover:border-blue-200 dark:border-gray-800 dark:bg-dark-card dark:hover:border-blue-700',
                        ].join(' ')}
                    >
                        {tier.featured && (
                            <div className="absolute -top-2.5 left-4 flex items-center gap-1 rounded bg-yellow-400 px-2 py-0.5 text-[10px] font-bold text-blue-900 shadow-sm">
                                <Flame className="h-3 w-3 fill-current" /> MOST POPULAR
                            </div>
                        )}

                        <div className="flex items-center gap-3">
                            <div className="flex-1">
                                <div className="flex items-center gap-2">
                                    <p className={tier.featured || tier.whale ? 'text-lg font-bold' : 'font-bold text-gray-900 dark:text-white'}>
                                        {tier.label}
                                    </p>
                                    {tier.whale && (
                                        <span className="rounded bg-red-100 px-1.5 py-0.5 text-[9px] font-bold text-red-600">
                                            WHALE TIER
                                        </span>
                                    )}
                                </div>
                                <p
                                    className={
                                        tier.featured
                                            ? 'text-xs font-bold text-green-600 dark:text-green-400'
                                            : tier.whale
                                              ? 'flex items-center gap-1 text-xs font-bold text-purple-600 dark:text-purple-400'
                                              : 'text-xs text-blue-600 dark:text-blue-400'
                                    }
                                >
                                    {tier.subtitle}
                                </p>
                            </div>

                            <div className="flex flex-col items-end">
                                {quote && quote.original > quote.discounted && (
                                    <span className="text-xs font-medium text-gray-400 line-through">
                                        {formatNaira(quote.original)}
                                    </span>
                                )}
                                <span className="font-bold text-gray-900 dark:text-white">
                                    {quote ? formatNaira(quote.discounted) : '…'}
                                </span>
                            </div>

                            <div
                                className={[
                                    'ml-auto mt-1 flex h-5 w-5 items-center justify-center rounded-full border',
                                    isSelected
                                        ? 'border-app-primary bg-app-primary text-white'
                                        : 'border-gray-300 dark:border-gray-600',
                                ].join(' ')}
                            >
                                {isSelected && <Check className="h-3 w-3" />}
                            </div>
                        </div>
                    </div>
                );
            })}

            {/* Bulk/custom "whale" card — the dark indigo card from raffle-details.php */}
            <div
                onClick={activateBulk}
                role="button"
                tabIndex={0}
                className={[
                    'group relative cursor-pointer overflow-hidden rounded-xl border-2 bg-indigo-950 p-4 shadow-lg transition-all',
                    bulkActive ? 'border-yellow-400' : 'border-indigo-900',
                ].join(' ')}
            >
                <div className="absolute right-0 top-0 h-32 w-32 -translate-y-1/2 translate-x-1/2 rounded-full bg-indigo-800/20 blur-2xl transition-all group-hover:bg-indigo-700/30" />
                <div className="relative flex items-center justify-between">
                    <div>
                        <p className="font-bold text-white">Bulk / Custom 🐋</p>
                        <p className="text-xs text-indigo-300">Go bigger — pick your own quantity</p>
                    </div>
                    <div
                        className={[
                            'flex h-5 w-5 items-center justify-center rounded-full border',
                            bulkActive ? 'border-yellow-400 bg-yellow-400 text-indigo-950' : 'border-indigo-700',
                        ].join(' ')}
                    >
                        {bulkActive && <Check className="h-3 w-3" />}
                    </div>
                </div>

                {bulkActive && (
                    <div className="relative mt-4 border-t border-indigo-800/50 pt-3">
                        <NumberStepper value={bulkQty} min={11} max={50} onChange={onBulkQtyChange} />
                        {bulkQuote && bulkQuote.original > bulkQuote.discounted && (
                            <div className="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-green-400/10 px-2 py-1.5 text-xs text-green-400">
                                <TrendingDown className="h-3 w-3" />
                                <span>
                                    You save:{' '}
                                    <span className="font-bold">{formatNaira(bulkQuote.original - bulkQuote.discounted)}</span>
                                </span>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
