import { Link } from '@inertiajs/react';
import { Banknote, ChevronRight, GraduationCap, Smartphone, Zap } from 'lucide-react';
import { formatNaira } from '../../lib/format';

// Same "Daily Drop" vs "Weekly Draw" visual split, and the same
// title-keyword icon guess, as the legacy homepage's renderTrending()
// in index.php — this card is deliberately a different, more compact
// design from RaffleCard (raffles.php's own listing-page card).
function prizeIcon(title = '') {
    const lower = title.toLowerCase();
    if (lower.includes('phone') || lower.includes('gadget') || lower.includes('samsung')) return Smartphone;
    if (lower.includes('school') || lower.includes('fees')) return GraduationCap;
    return Banknote;
}

export default function TrendingCard({ raffle }) {
    const isDaily = raffle.price > 0 && raffle.price <= 200;
    const Icon = isDaily ? Zap : prizeIcon(raffle.title);
    const progress = raffle.max_tickets > 0 ? Math.round((raffle.sold_tickets / raffle.max_tickets) * 100) : 0;
    const price = raffle.price ? formatNaira(raffle.price) : 'Free';

    const badgeClass = isDaily
        ? 'bg-black text-yellow-400 border border-yellow-500/30 shadow-lg'
        : 'bg-blue-50 text-blue-600 border border-blue-100 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800';
    const iconBgClass = isDaily ? 'bg-yellow-400 text-black' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
    const buttonClass = isDaily
        ? 'bg-yellow-400 text-black hover:bg-yellow-500 shadow-yellow-500/20'
        : 'bg-gray-900 text-white hover:opacity-90 dark:bg-white dark:text-black';
    const barClass = isDaily ? 'bg-yellow-400' : 'bg-app-primary';

    return (
        <Link
            href={`/raffles/${raffle.id}`}
            className="relative min-w-[280px] flex-shrink-0 overflow-hidden rounded-2xl border border-gray-100 bg-white p-5 pt-7 shadow-sm transition-all hover:shadow-md active:scale-[0.98] dark:border-gray-800 dark:bg-dark-card"
        >
            <div className={`absolute right-0 top-0 z-10 rounded-bl-xl px-3 py-1 text-[9px] font-bold uppercase tracking-widest shadow-sm ${badgeClass}`}>
                {isDaily ? 'Daily Drop' : 'Weekly Draw'}
            </div>

            <div className="mb-4 flex items-start justify-between">
                <div className={`flex h-12 w-12 items-center justify-center rounded-2xl shadow-sm ${iconBgClass}`}>
                    <Icon className="h-6 w-6" />
                </div>
                <div className="mt-1 text-right">
                    <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Grand Prize</p>
                    <p className="max-w-[120px] truncate text-sm font-black text-gray-900 dark:text-white">
                        {raffle.grand_prize || 'Cash Prize'}
                    </p>
                </div>
            </div>

            <h3 className="mb-2 truncate text-base font-bold leading-tight text-gray-800 dark:text-gray-200">{raffle.title}</h3>

            <div className="mb-2 h-2 w-full rounded-full bg-gray-100 dark:bg-gray-700">
                <div className={`h-2 rounded-full transition-all duration-1000 ${barClass}`} style={{ width: `${progress}%` }} />
            </div>

            <div className="mb-4 flex items-center justify-between text-[11px] font-medium text-gray-500 dark:text-gray-400">
                <span>{raffle.sold_tickets} sold</span>
                <span>{raffle.remaining_tickets} remaining</span>
            </div>

            <span className={`flex w-full items-center justify-center gap-2 rounded-xl py-3 text-xs font-bold shadow-sm transition-transform active:scale-95 ${buttonClass}`}>
                Play @ {price} <ChevronRight className="h-4 w-4" />
            </span>
        </Link>
    );
}
