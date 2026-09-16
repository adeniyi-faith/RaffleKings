import { Card } from '../ui/Card';
import ProgressBar from '../ui/ProgressBar';
import { formatNaira } from '../../lib/format';

const PRIZE_TYPE_LABELS = { cash: 'Cash', gadgets: 'Gadgets', vouchers: 'Vouchers', other: 'Other' };

export default function RaffleCard({ raffle }) {
    return (
        <Card className={`flex flex-col gap-3 ${raffle.is_closed ? 'opacity-70' : ''}`}>
            <div className="flex items-start justify-between gap-2">
                <h3 className="font-bold leading-snug">{raffle.title}</h3>
                <span className="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    {PRIZE_TYPE_LABELS[raffle.prize_type] || raffle.prize_type}
                </span>
            </div>

            {raffle.grand_prize && (
                <p className="text-sm text-gray-500 dark:text-gray-400">{raffle.grand_prize}</p>
            )}

            <div className="space-y-1.5">
                <ProgressBar sold={raffle.sold_tickets} max={raffle.max_tickets} />
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    {raffle.sold_tickets}/{raffle.max_tickets} tickets sold
                </p>
            </div>

            <div className="mt-auto flex items-center justify-between pt-2">
                <span className="font-black text-app-primary">{formatNaira(raffle.price)}</span>
                {raffle.is_closed ? (
                    <span className="rounded-lg bg-gray-200 px-3 py-1.5 text-xs font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                        Sold out
                    </span>
                ) : (
                    <span className="rounded-lg bg-app-primary/10 px-3 py-1.5 text-xs font-bold text-app-primary">
                        {raffle.remaining_tickets} left
                    </span>
                )}
            </div>
        </Card>
    );
}
