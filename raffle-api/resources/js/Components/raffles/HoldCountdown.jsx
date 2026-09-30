import { Timer } from 'lucide-react';
import { clock } from '../../lib/numberHolds';

/** The "your numbers are held for 9:41" pill. Pulses in the last two minutes. */
export default function HoldCountdown({ seconds, className = '' }) {
    return (
        <div
            title="Your numbers are held for you until the timer runs out"
            className={[
                'flex items-center gap-1.5 rounded-full border border-red-100 bg-red-50 px-2.5 py-1 text-xs font-black tabular-nums text-red-600 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-400',
                seconds <= 120 ? 'animate-pulse' : '',
                className,
            ].join(' ')}
        >
            <Timer className="h-3.5 w-3.5" aria-hidden="true" />
            <span aria-label={`${clock(seconds)} left to keep your numbers`}>{clock(seconds)}</span>
        </div>
    );
}
