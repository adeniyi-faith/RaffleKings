// A real, derived ticket-sold progress bar — replacing the legacy
// raffles.php's manually-toggled `is_sold_out` flag (which could drift
// from reality) with the same always-accurate sold/max the API now
// derives live from actual ticket entries (RaffleReadService).
export default function ProgressBar({ sold, max, className = '' }) {
    const pct = max > 0 ? Math.min(100, Math.round((sold / max) * 100)) : 0;

    return (
        <div className={['h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800', className].join(' ')}>
            <div
                className="h-full rounded-full bg-app-primary transition-all"
                style={{ width: `${pct}%` }}
                role="progressbar"
                aria-valuenow={pct}
                aria-valuemin={0}
                aria-valuemax={100}
            />
        </div>
    );
}
