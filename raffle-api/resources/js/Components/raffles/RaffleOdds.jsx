import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDown, Zap } from 'lucide-react';
import { useRaffleOdds } from '../../hooks/useRaffleOdds';
import { formatNaira } from '../../lib/format';

/**
 * "Your chances": the chance of winning something with the tickets the player
 * has picked, and the chance for each prize level. It is built to drop into
 * any raffle page layout: `defaultOpen` shows the per-prize list straight
 * away (a Prizes tab or sheet), otherwise it starts folded under the headline.
 */
export default function RaffleOdds({ raffleId, quantity, defaultOpen = false, className = '' }) {
    const { odds, failed } = useRaffleOdds(raffleId, quantity);
    const [open, setOpen] = useState(defaultOpen);

    if (failed && ! odds) {
        return null; // odds are extra information: never block the page for them
    }

    if (! odds) {
        return <div className={`h-16 animate-pulse rounded-xl bg-blue-50 dark:bg-blue-900/20 ${className}`} aria-hidden="true" />;
    }

    const best = Math.max(...odds.tiers.map((t) => t.probability), 0.000001);
    const tickets = `${odds.quantity} ticket${odds.quantity === 1 ? '' : 's'}`;

    return (
        <div className={`rounded-xl border border-blue-100 bg-blue-50 dark:border-blue-900/30 dark:bg-blue-900/20 ${className}`}>
            <button
                type="button"
                onClick={() => setOpen((v) => ! v)}
                className="flex w-full items-center gap-3 p-3 text-left"
                aria-expanded={open}
            >
                <Zap className="h-4 w-4 flex-shrink-0 text-blue-600 dark:text-blue-400" />
                <span className="flex-1 text-xs leading-snug text-blue-800 dark:text-blue-300">
                    <span className="block font-bold">Your chance of winning something with {tickets}</span>
                    <span className="block opacity-80">
                        {odds.any.one_in >= 2 ? `About 1 in ${odds.any.one_in.toLocaleString()}` : ''}{odds.any.one_in >= 2 && odds.tiers.length > 1 ? ' · ' : ''}{odds.tiers.length > 1 ? 'tap for each prize' : ''}
                    </span>
                </span>
                <span className="text-xl font-extrabold tabular-nums text-blue-700 dark:text-blue-300" aria-live="polite">{odds.any.percent}</span>
                <ChevronDown className={`h-4 w-4 flex-shrink-0 text-blue-500 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="border-t border-blue-100 px-3 pb-3 dark:border-blue-900/30">
                    {odds.tiers.map((tier) => (
                        <div key={tier.name} className="border-b border-blue-100/70 py-3 last:border-b-0 dark:border-blue-900/30">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-bold text-gray-900 dark:text-white">{tier.name}</p>
                                    <p className="text-[11px] text-gray-500 dark:text-gray-400">
                                        {tier.winners} winner{tier.winners === 1 ? '' : 's'}{tier.description ? ` · ${tier.description}` : ''}
                                    </p>
                                </div>
                                {tier.value > 0 && <p className="whitespace-nowrap text-sm font-extrabold text-gray-900 dark:text-white">{formatNaira(tier.value)}</p>}
                            </div>
                            <div className="mt-1.5 flex items-baseline justify-between gap-3 text-xs tabular-nums">
                                <span className="text-gray-500 dark:text-gray-400">{tier.one_in ? `1 in ${tier.one_in.toLocaleString()} per ticket` : '–'}</span>
                                <span className="font-bold text-blue-700 dark:text-blue-300">{tier.percent} with {odds.quantity}</span>
                            </div>
                            <div className="mt-1 h-2 overflow-hidden rounded-full bg-white dark:bg-blue-950/40" aria-hidden="true">
                                <div className="h-full min-w-[3px] rounded-full bg-blue-600 transition-all dark:bg-blue-400" style={{ width: `${Math.max(1, (tier.probability / best) * 100)}%` }} />
                            </div>
                        </div>
                    ))}
                    <p className="pt-2 text-[11px] leading-relaxed text-blue-800/80 dark:text-blue-300/80">
                        Based on all {odds.pool.toLocaleString()} tickets being sold. If fewer sell, your chances are better. It's a game of chance: only spend what you can afford.{' '}
                        <Link href="/account/play-limits" className="font-bold underline">Set a spending limit</Link>
                    </p>
                </div>
            )}
        </div>
    );
}
