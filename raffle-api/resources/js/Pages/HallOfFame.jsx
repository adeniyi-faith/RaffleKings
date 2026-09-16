import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Crown, Play, Trophy } from 'lucide-react';

// Rebuild of winners.php (item 27) against GET /api/hall-of-fame
// (HallOfFameController). Preserved faithfully: the sticky header with
// the live winner-count chip, the "Big Winners" horizontal-scroll strip
// (top 5 by prize amount) with the yellow prize badge / crown / ticket
// card, the vertical "Recent Wins" list, the loading skeletons, and the
// empty state — same Tailwind classes/colors as the legacy page.
//
// What changed and why:
//  - The CTA banner's copy dropped the hardcoded "LIVE 8PM Daily" claim
//    (nothing in this app runs a real recurring 8pm schedule — that was
//    fabricated urgency, the same class of dark pattern Phase 0 item 7
//    already tore out of index.php/raffles.php/checkout.php). It now
//    reads simply "Watch The Live Draw" and links to whichever raffle
//    actually has a live-draw event enabled right now, or is hidden
//    entirely if none does — never a fake schedule.
//  - Each winner's decorative "verification hash" (sha256 of already-
//    public fields with a hardcoded salt — proves nothing, see
//    HallOfFameController's docblock) is replaced with a real "Verify
//    this draw" link into the item 14 provably-fair verification page
//    for that winner's raffle.
export default function HallOfFame() {
    const [isLoading, setIsLoading] = useState(true);
    const [featured, setFeatured] = useState([]);
    const [recent, setRecent] = useState([]);
    const [totalWinners, setTotalWinners] = useState(0);
    const [liveRaffleId, setLiveRaffleId] = useState(null);

    useEffect(() => {
        fetch('/api/hall-of-fame')
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => {
                if (! data) return;
                setFeatured(data.featured ?? []);
                setRecent(data.recent ?? []);
                setTotalWinners(data.total_count ?? 0);
                setLiveRaffleId(data.recent?.[0]?.raffle_native_id ?? null);
            })
            .catch(() => {})
            .finally(() => setIsLoading(false));
    }, []);

    return (
        <>
            <Head title="Hall of Fame" />
            <div className="relative flex h-screen w-full flex-col overflow-y-auto bg-gray-50 pb-40 transition-colors duration-200 dark:bg-dark-bg">
                <div className="sticky top-0 z-30 flex items-center justify-between border-b border-gray-100 bg-white px-5 pb-4 pt-4 shadow-sm backdrop-blur-md transition-colors duration-200 dark:border-dark-border dark:bg-dark-bg/95">
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white">Hall of Fame 🏆</h2>
                    {! isLoading && (
                        <span className="rounded-full border border-gray-200 bg-gray-100 px-2 py-1 text-xs font-medium text-gray-500 dark:border-gray-700 dark:bg-dark-card dark:text-gray-400">
                            {totalWinners} Winners
                        </span>
                    )}
                </div>

                <div className="space-y-6 p-5">
                    {liveRaffleId && (
                        <Link
                            href={`/raffles/${liveRaffleId}/live-draw`}
                            className="relative block transform overflow-hidden rounded-2xl shadow-xl transition-transform active:scale-[0.98]"
                        >
                            <div className="absolute inset-0 animate-gradient-x bg-gradient-to-r from-red-600 via-orange-500 to-red-600 [background-size:200%_200%]" />
                            <div className="absolute top-0 left-[-100%] h-full w-1/2 -skew-x-12 animate-shine bg-gradient-to-r from-white/0 via-white/30 to-white/0" />
                            <div className="relative z-10 flex items-center justify-between p-5">
                                <div className="text-white">
                                    <div className="mb-1 flex items-center gap-2">
                                        <span className="flex items-center gap-1 rounded border border-white/10 bg-white/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider backdrop-blur-sm">
                                            <span className="h-1.5 w-1.5 animate-ping rounded-full bg-red-400" /> LIVE
                                        </span>
                                    </div>
                                    <h3 className="mb-1 text-lg font-black leading-tight">Watch The Live Draw</h3>
                                    <p className="text-xs text-white/90">See the winners revealed!</p>
                                </div>
                                <div className="flex h-12 w-12 animate-pulse items-center justify-center rounded-full bg-white text-red-600 shadow-lg">
                                    <Play className="h-5 w-5 fill-current" />
                                </div>
                            </div>
                        </Link>
                    )}

                    {isLoading && (
                        <div className="space-y-4">
                            {[0, 1, 2, 3].map((i) => (
                                <div
                                    key={i}
                                    className="flex animate-pulse items-center gap-4 rounded-xl border border-gray-100 bg-white p-4 dark:border-gray-800 dark:bg-dark-card"
                                >
                                    <div className="h-12 w-12 rounded-full bg-gray-200 dark:bg-gray-700" />
                                    <div className="flex-1 space-y-2">
                                        <div className="h-3 w-1/2 rounded bg-gray-200 dark:bg-gray-700" />
                                        <div className="h-2 w-1/3 rounded bg-gray-200 dark:bg-gray-700" />
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {! isLoading && featured.length > 0 && (
                        <div className="space-y-4">
                            <h3 className="pl-1 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                Big Winners
                            </h3>
                            <div className="-mx-5 flex gap-4 overflow-x-auto px-5 pb-2">
                                {featured.map((winner) => (
                                    <div
                                        key={winner.id}
                                        className="relative min-w-[260px] overflow-hidden rounded-2xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-dark-card"
                                    >
                                        <div className="absolute right-0 top-0 rounded-bl-xl bg-yellow-400 px-3 py-1 text-[10px] font-bold text-yellow-900 shadow-sm">
                                            {winner.prize}
                                        </div>
                                        <div className="mt-2 flex flex-col items-center text-center">
                                            <div className="relative mb-3 h-16 w-16 rounded-full border-2 border-yellow-400 p-1">
                                                <img
                                                    src={winner.avatar ?? `https://api.dicebear.com/7.x/initials/svg?seed=${winner.name}`}
                                                    className="h-full w-full rounded-full bg-gray-100 object-cover dark:bg-gray-700"
                                                    alt={winner.name}
                                                />
                                                <div className="absolute -bottom-1 -right-1 rounded-full border border-gray-100 bg-white p-1 shadow-sm dark:border-gray-700 dark:bg-dark-card">
                                                    <Crown className="h-3 w-3 fill-current text-yellow-500" />
                                                </div>
                                            </div>
                                            <h4 className="text-sm font-bold text-gray-900 dark:text-white">{winner.name}</h4>
                                            <div className="mt-3 w-full rounded-lg border border-gray-100 bg-gray-50 py-2 dark:border-gray-800 dark:bg-dark-bg">
                                                <p className="mb-0.5 text-[9px] font-bold uppercase text-gray-400">Winning Ticket</p>
                                                <p className="font-mono text-base font-bold tracking-widest text-gray-800 dark:text-gray-200">
                                                    #{winner.ticket}
                                                </p>
                                                {winner.raffle_native_id && (
                                                    <Link
                                                        href={`/raffles/${winner.raffle_native_id}/verify`}
                                                        className="mt-1 block text-[9px] font-bold text-app-primary hover:underline"
                                                    >
                                                        Verify this draw
                                                    </Link>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {! isLoading && recent.length > 0 && (
                        <div className="space-y-3">
                            <h3 className="mt-2 pl-1 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                Recent Wins
                            </h3>
                            {recent.map((winner) => (
                                <div
                                    key={winner.id}
                                    className="flex items-center gap-3 rounded-xl border border-gray-100 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-dark-card"
                                >
                                    <img
                                        src={winner.avatar ?? `https://api.dicebear.com/7.x/initials/svg?seed=${winner.name}`}
                                        className="h-10 w-10 shrink-0 rounded-full bg-gray-50 object-cover dark:bg-gray-700"
                                        alt={winner.name}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="mb-1 flex items-center justify-between">
                                            <h4 className="truncate pr-2 text-sm font-bold text-gray-900 dark:text-white">{winner.name}</h4>
                                            <span className="shrink-0 whitespace-nowrap rounded border border-gray-200 bg-gray-100 px-2 py-0.5 font-mono text-[10px] font-bold text-gray-600 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                                #{winner.ticket}
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <p className="flex items-center gap-1 text-xs font-medium text-green-600 dark:text-green-400">
                                                Won: {winner.prize}
                                            </p>
                                            {winner.raffle_native_id && (
                                                <Link
                                                    href={`/raffles/${winner.raffle_native_id}/verify`}
                                                    className="shrink-0 text-[10px] font-bold text-app-primary hover:underline"
                                                >
                                                    Verify
                                                </Link>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {! isLoading && featured.length === 0 && recent.length === 0 && (
                        <div className="py-10 text-center">
                            <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-dark-card dark:text-gray-500">
                                <Trophy className="h-8 w-8" />
                            </div>
                            <p className="text-sm text-gray-500 dark:text-gray-400">No winners announced yet.</p>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
