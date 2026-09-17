import { Link } from '@inertiajs/react';
import { Banknote, CarFront, Clock, Laptop, Smartphone, Ticket, Timer, XCircle, Zap } from 'lucide-react';
import { formatNaira } from '../../lib/format';

// Same title-keyword → icon guess as raffles.php's buildCard() (icon = 'ticket'
// with phone/cash/car/laptop overrides) — so the same prize reads with the
// same icon on both sites.
function prizeIcon(title = '') {
    const lower = title.toLowerCase();
    if (lower.includes('phone') || lower.includes('iphone')) return Smartphone;
    if (lower.includes('cash') || lower.includes('money')) return Banknote;
    if (lower.includes('car') || lower.includes('benz')) return CarFront;
    if (lower.includes('laptop')) return Laptop;
    return Ticket;
}

// Same "Ending Soon" / "N Days Left" / "Closed" badge thresholds as
// raffles.php's buildCard() (<=1 day, <=3 days, else a bare date).
function ExpiryBadge({ expiry, isClosed }) {
    if (isClosed) {
        return (
            <span className="flex items-center gap-1 rounded-full bg-black/20 px-2.5 py-1 text-[10px] font-bold text-white backdrop-blur-sm">
                <XCircle className="h-3 w-3" /> Closed
            </span>
        );
    }

    if (!expiry) {
        return null;
    }

    const expiryDate = new Date(expiry);
    if (Number.isNaN(expiryDate.getTime())) {
        return null;
    }
    expiryDate.setHours(23, 59, 59, 999);

    const diffDays = Math.ceil(Math.abs(expiryDate - new Date()) / (1000 * 60 * 60 * 24));

    if (diffDays <= 1) {
        return (
            <span className="flex animate-pulse items-center gap-1 rounded-full bg-red-500 px-2.5 py-1 text-[10px] font-bold text-white shadow-sm">
                <Timer className="h-3 w-3" /> Ending Soon
            </span>
        );
    }

    if (diffDays <= 3) {
        return (
            <span className="flex items-center gap-1 rounded-full bg-orange-500 px-2.5 py-1 text-[10px] font-bold text-white shadow-sm">
                <Clock className="h-3 w-3" /> {diffDays} Days Left
            </span>
        );
    }

    return (
        <span className="flex items-center gap-1 rounded-full border border-white/20 bg-white/20 px-2.5 py-1 text-[10px] font-bold text-white backdrop-blur-sm">
            {expiryDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}
        </span>
    );
}

export default function RaffleCard({ raffle }) {
    const Icon = prizeIcon(raffle.title);
    const progress = raffle.max_tickets > 0 ? Math.min(100, Math.round((raffle.sold_tickets / raffle.max_tickets) * 100)) : 0;
    // Same ₦200-and-under "Daily 100" gold treatment as raffles.php's isMicro cards.
    const isMicro = raffle.price > 0 && raffle.price <= 200;

    if (raffle.is_closed) {
        return (
            <Link
                href={`/raffles/${raffle.id}`}
                className="group relative overflow-hidden rounded-3xl border border-gray-100 bg-gray-50 p-5 opacity-80 grayscale-[0.5] transition-all hover:opacity-100 hover:grayscale-0 dark:border-gray-800 dark:bg-dark-card/60"
            >
                <div className="pointer-events-none absolute inset-0 z-20 flex items-center justify-center">
                    <span className="-rotate-12 transform rounded-xl border-4 border-white bg-red-600/90 px-6 py-2 text-lg font-black tracking-widest text-white shadow-xl backdrop-blur-sm dark:border-dark-card">
                        SOLD OUT
                    </span>
                </div>
                <div className="relative z-10 mb-4 flex items-start justify-between opacity-50">
                    <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                        <Icon className="h-6 w-6" />
                    </div>
                </div>
                <h3 className="relative z-10 mb-4 truncate pr-2 text-lg font-bold text-gray-600 line-through dark:text-gray-400">
                    {raffle.title}
                </h3>
                <div className="relative z-10 flex items-center justify-between border-t border-gray-100 pt-4 opacity-50 dark:border-gray-700/50">
                    <p className="text-lg font-black text-gray-400 dark:text-gray-500">{formatNaira(raffle.price)}</p>
                    <span className="cursor-not-allowed rounded-lg bg-gray-200 px-4 py-2 text-xs font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                        Closed
                    </span>
                </div>
            </Link>
        );
    }

    if (isMicro) {
        return (
            <Link
                href={`/raffles/${raffle.id}`}
                className="relative block overflow-hidden rounded-2xl bg-gradient-to-r from-yellow-400 to-yellow-500 p-4 shadow-lg shadow-yellow-500/20 transition-transform active:scale-[0.98]"
            >
                <div className="absolute right-0 top-0 h-20 w-20 rounded-full bg-white/20 blur-2xl" />
                <div className="relative z-10 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="rounded-lg bg-black/10 p-2">
                            <Zap className="h-6 w-6 fill-current text-black" />
                        </div>
                        <div>
                            <div className="mb-1 inline-block rounded-md bg-black px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-yellow-400">
                                Daily 100
                            </div>
                            <h3 className="max-w-[150px] truncate text-lg font-black leading-none text-gray-900">
                                {raffle.title}
                            </h3>
                        </div>
                    </div>
                    <div className="text-right">
                        <p className="text-2xl font-black text-gray-900">{formatNaira(raffle.price)}</p>
                        <p className="text-[10px] font-bold text-gray-800 opacity-70">Per Ticket</p>
                    </div>
                </div>
                <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-white/30">
                    <div className="h-full rounded-full bg-black" style={{ width: `${progress}%` }} />
                </div>
                <div className="mt-1.5 flex justify-between text-[10px] font-bold text-gray-900/80">
                    <span>{raffle.sold_tickets} Sold</span>
                    <span className="rounded bg-red-100/50 px-1.5 text-red-700">{raffle.remaining_tickets} Left</span>
                </div>
            </Link>
        );
    }

    return (
        <Link
            href={`/raffles/${raffle.id}`}
            className="group relative block overflow-hidden rounded-3xl bg-gradient-to-br from-green-600 to-emerald-900 p-6 shadow-xl shadow-green-900/20 transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl hover:shadow-green-900/30"
        >
            <div className="absolute right-0 top-0 h-40 w-40 -translate-y-1/2 translate-x-1/2 rounded-full bg-white/10 blur-3xl transition-colors group-hover:bg-white/15" />
            <div className="absolute bottom-0 left-0 h-32 w-32 -translate-x-1/2 translate-y-1/2 rounded-full bg-black/10 blur-2xl" />

            <div className="relative z-10 mb-5 flex items-start justify-between">
                <div className="flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-white/10 shadow-lg backdrop-blur-md transition-transform group-hover:scale-105">
                    <Icon className="h-7 w-7 text-white" />
                </div>
                <ExpiryBadge expiry={raffle.expiry} isClosed={false} />
            </div>

            <div className="relative z-10 mb-6">
                <span className="mb-2 inline-block rounded-md border border-green-500/30 bg-green-900/30 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-widest text-green-100 backdrop-blur-sm">
                    {raffle.prize_type ? `${raffle.prize_type[0].toUpperCase()}${raffle.prize_type.slice(1)} Draw` : 'Exclusive Draw'}
                </span>
                <h3 className="mb-2 text-xl font-black leading-tight text-white drop-shadow-sm">{raffle.title}</h3>
                {raffle.grand_prize && (
                    <p className="truncate text-sm font-medium text-green-100 opacity-90">
                        Win: <span className="border-b border-green-400/50 pb-0.5 font-bold text-white">{raffle.grand_prize}</span>
                    </p>
                )}
            </div>

            <div className="relative z-10 mb-2">
                <div className="mb-2 flex justify-between text-[10px] font-bold">
                    <span className="rounded-md bg-black/20 px-2 py-0.5 text-green-50 backdrop-blur-sm">{raffle.sold_tickets} Sold</span>
                    <span className="rounded-md bg-red-500/80 px-2 py-0.5 text-white shadow-sm backdrop-blur-sm">
                        {raffle.remaining_tickets} Left
                    </span>
                </div>
                <div className="h-2.5 w-full overflow-hidden rounded-full border border-black/5 bg-black/20 backdrop-blur-sm">
                    <div
                        className="h-full rounded-full bg-white shadow-[0_0_10px_rgba(255,255,255,0.7)] transition-all duration-1000 ease-out"
                        style={{ width: `${progress}%` }}
                    />
                </div>
            </div>

            <div className="relative z-10 flex items-center justify-between pt-4">
                <p className="text-2xl font-black text-white">{formatNaira(raffle.price)}</p>
                <span className="rounded-xl bg-white px-4 py-2 text-xs font-bold text-green-800 shadow-md">Enter Now</span>
            </div>
        </Link>
    );
}
