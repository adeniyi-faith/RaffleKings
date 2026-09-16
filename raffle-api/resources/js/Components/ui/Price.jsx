import { Tag } from 'lucide-react';
import { formatNaira } from '../../lib/format';

// The per-tier price + strikethrough pair repeated for every ticket-bundle
// option in raffle-details.php (`.price-display` / `.strikethrough-display`).
export function PriceTag({ original, discounted, className = '' }) {
    const hasDiscount = original > discounted;

    return (
        <div className={['flex flex-col items-end', className].filter(Boolean).join(' ')}>
            {hasDiscount && (
                <span className="text-xs font-medium text-gray-400 line-through">{formatNaira(original)}</span>
            )}
            <span className="font-bold text-gray-900 dark:text-white">{formatNaira(discounted)}</span>
        </div>
    );
}

// The green "SAVED ₦X (N% OFF)" badge from checkout.php's order summary.
export function DiscountBadge({ original, discounted, className = '' }) {
    if (discounted >= original) {
        return null;
    }

    const savings = original - discounted;
    const pct = Math.round((savings / original) * 100);

    return (
        <div
            className={[
                'inline-flex items-center gap-1 rounded bg-green-100 px-2 py-1 text-[10px] font-bold uppercase text-green-700 dark:bg-green-900/30 dark:text-green-400',
                className,
            ]
                .filter(Boolean)
                .join(' ')}
        >
            <Tag className="h-3 w-3" /> Saved {formatNaira(savings)} ({pct}% off)
        </div>
    );
}

// The big total-amount line with an optional strikethrough original,
// reused for checkout.php's order-summary total and the sticky footer
// total on both checkout.php and raffle-details.php.
export function PriceSummaryFooter({ label, original, discounted, loading = false, className = '' }) {
    return (
        <div className={['flex items-center justify-between', className].filter(Boolean).join(' ')}>
            {label && <span className="text-sm font-medium text-gray-500 dark:text-gray-400">{label}</span>}
            <div className="text-right">
                <span className="block text-xl font-black tracking-tight text-app-primary">
                    {loading ? 'Calculating…' : formatNaira(discounted)}
                </span>
                {! loading && original > discounted && (
                    <span className="text-xs font-medium text-gray-400 line-through">{formatNaira(original)}</span>
                )}
            </div>
        </div>
    );
}
