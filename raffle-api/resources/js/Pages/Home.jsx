import { Head, Link } from '@inertiajs/react';
import { Banknote, ChevronRight, GraduationCap, Lock, Plus, Smartphone } from 'lucide-react';
import Header from '../Components/layout/Header';
import BottomNav from '../Components/layout/BottomNav';
import HeroCarousel from '../Components/home/HeroCarousel';
import TrendingCard from '../Components/home/TrendingCard';

// Faithful rebuild of the legacy homepage (index.php + header.php +
// footer.php): same top bar, hero carousel, "Play & Win" action grid,
// and "Trending Now" rail, in the same order. `trending` is the same
// top-10-closing-soon raffle set the legacy homepage SSR-preloaded as
// $initial_raffles, now via RaffleReadService (see routes/web.php).
export default function Home({ trending }) {
    const activeTrending = trending.filter((r) => !r.is_closed).slice(0, 10);

    return (
        <>
            <Head title="Home" />
            <div className="flex min-h-screen w-full flex-col bg-gray-50 text-gray-900 transition-colors duration-200 dark:bg-dark-bg dark:text-white">
                <Header />

                <div className="no-scrollbar relative flex-1 overflow-y-auto bg-gray-50 pb-28 transition-colors duration-200 dark:bg-dark-bg">
                    <HeroCarousel />

                    <section className="px-5 py-6">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-base font-extrabold tracking-tight text-gray-900 dark:text-white">Play &amp; Win</h3>
                            <span className="rounded-full bg-blue-50 px-2 py-1 text-[10px] font-bold text-blue-600 dark:bg-blue-900/30 dark:text-blue-300">
                                Updated Today
                            </span>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <Link
                                href="/raffles"
                                className="group relative col-span-2 overflow-hidden rounded-2xl border border-blue-500/30 bg-gradient-to-r from-blue-600 to-blue-700 p-5 shadow-lg shadow-blue-500/20 transition-transform active:scale-[0.99] dark:from-blue-700 dark:to-blue-900 dark:shadow-blue-900/20"
                            >
                                <div className="absolute right-0 top-0 h-32 w-32 -translate-y-1/2 translate-x-1/2 rounded-full bg-white/10 blur-2xl" />
                                <div className="relative z-10 flex items-center justify-between">
                                    <div>
                                        <div className="mb-1 flex items-center gap-2">
                                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm">
                                                <Banknote className="h-4 w-4 text-white" />
                                            </div>
                                            <span className="text-xs font-bold uppercase tracking-wider text-blue-100">Active Now</span>
                                        </div>
                                        <h3 className="text-2xl font-black tracking-tight text-white">Cash Draws</h3>
                                        <p className="mt-1 text-xs font-medium text-blue-100 opacity-90">Win up to ₦500,000 Instantly</p>
                                    </div>
                                    <div className="flex h-12 w-12 animate-pulse items-center justify-center rounded-full bg-white shadow-lg transition-transform group-hover:scale-110">
                                        <ChevronRight className="h-6 w-6 text-blue-600" />
                                    </div>
                                </div>
                            </Link>

                            <div className="relative flex h-40 cursor-not-allowed flex-col justify-between rounded-2xl border border-gray-100 bg-white p-4 opacity-75 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                                <div className="absolute inset-0 z-20 flex items-center justify-center rounded-2xl bg-gray-50/50 backdrop-blur-[1px] dark:bg-black/50">
                                    <span className="-rotate-6 transform rounded bg-gray-900 px-2 py-1 text-[10px] font-bold text-white shadow-lg">
                                        COMING SOON
                                    </span>
                                </div>
                                <div>
                                    <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-purple-50 text-purple-600 shadow-inner dark:bg-purple-900/20 dark:text-purple-400">
                                        <Smartphone className="h-5 w-5" />
                                    </div>
                                    <h3 className="text-lg font-bold leading-none text-gray-800 dark:text-gray-200">Gadgets</h3>
                                    <p className="mt-1 text-[10px] font-medium text-gray-500 dark:text-gray-400">iPhones, Laptops &amp; More</p>
                                </div>
                                <div className="flex items-center gap-1 self-start rounded bg-gray-100 px-2 py-1 text-[10px] font-bold text-gray-400 dark:bg-gray-800">
                                    Locked <Lock className="h-3 w-3" />
                                </div>
                            </div>

                            <div className="relative flex h-40 cursor-not-allowed flex-col justify-between rounded-2xl border border-gray-100 bg-white p-4 opacity-75 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                                <div className="absolute inset-0 z-20 flex items-center justify-center rounded-2xl bg-gray-50/50 backdrop-blur-[1px] dark:bg-black/50">
                                    <span className="rotate-3 transform rounded bg-gray-900 px-2 py-1 text-[10px] font-bold text-white shadow-lg">
                                        COMING SOON
                                    </span>
                                </div>
                                <div>
                                    <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-orange-50 text-orange-600 shadow-inner dark:bg-orange-900/20 dark:text-orange-400">
                                        <GraduationCap className="h-5 w-5" />
                                    </div>
                                    <h3 className="text-lg font-bold leading-none text-gray-800 dark:text-gray-200">Grants</h3>
                                    <p className="mt-1 text-[10px] font-medium text-gray-500 dark:text-gray-400">School Fees Support</p>
                                </div>
                                <div className="flex items-center gap-1 self-start rounded bg-gray-100 px-2 py-1 text-[10px] font-bold text-gray-400 dark:bg-gray-800">
                                    Locked <Lock className="h-3 w-3" />
                                </div>
                            </div>

                            <Link
                                href="/account/wallet"
                                className="col-span-2 flex h-28 flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-gray-50 transition-all active:scale-[0.99] hover:bg-gray-100 dark:border-gray-700 dark:bg-dark-card/50 dark:hover:bg-dark-card"
                            >
                                <div className="flex items-center gap-3">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        <Plus className="h-4 w-4" />
                                    </div>
                                    <div className="text-left">
                                        <h3 className="text-sm font-bold text-gray-700 dark:text-gray-200">Top Up Wallet</h3>
                                        <p className="text-[10px] text-gray-500 dark:text-gray-400">Fund your account to play</p>
                                    </div>
                                </div>
                            </Link>
                        </div>
                    </section>

                    <section className="mb-6 mt-2">
                        <div className="mb-4 flex items-end justify-between px-5">
                            <div>
                                <h2 className="text-lg font-bold tracking-tight text-gray-900 dark:text-white">Trending Now 🔥</h2>
                                <p className="text-[11px] font-medium text-gray-500 dark:text-gray-400">Closing soon — don&apos;t miss out</p>
                            </div>
                            <Link
                                href="/raffles"
                                className="rounded bg-blue-50 px-2 py-1 text-xs font-bold text-blue-600 transition-colors hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50"
                            >
                                See All
                            </Link>
                        </div>

                        <div className="no-scrollbar flex gap-4 overflow-x-auto px-5 pb-4">
                            {activeTrending.length === 0 ? (
                                <div className="w-full rounded-xl bg-gray-50 p-6 text-center text-sm text-gray-400 dark:bg-dark-card">
                                    No active raffles right now.
                                </div>
                            ) : (
                                activeTrending.map((raffle) => <TrendingCard key={raffle.id} raffle={raffle} />)
                            )}
                        </div>
                    </section>
                </div>

                <BottomNav />
            </div>
        </>
    );
}
