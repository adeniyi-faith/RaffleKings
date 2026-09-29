import { Head, Link } from '@inertiajs/react';
import Header from '../Components/layout/Header';
import BottomNav from '../Components/layout/BottomNav';
import HeroCarousel from '../Components/home/HeroCarousel';
import GoldenBoxBanner from '../Components/raffles/GoldenBoxBanner';
import TrendingCard from '../Components/home/TrendingCard';
import HomeCards from '../Components/home/HomeCards';
import RaffleCard from '../Components/raffles/RaffleCard';

// The customer homepage. The blocks (slides, cards, trending raffles...) and
// their order come from `layout`, which staff arrange in the admin under
// Site → Homepage (see HomeLayoutService). `trending` is the top-10
// closing-soon raffle set shown by the trending block.
export default function Home({ trending, layout = [] }) {
    const activeTrending = trending.filter((r) => !r.is_closed).slice(0, 10);
    // Phase 11: flash raffles get their own strip just above the trending list.
    const flash = activeTrending.filter((r) => r.is_flash);

    return (
        <>
            <Head title="Home" />
            <div className="flex min-h-screen w-full flex-col bg-gray-50 text-gray-900 transition-colors duration-200 dark:bg-dark-bg dark:text-white">
                <Header />

                <div className="no-scrollbar relative flex-1 overflow-y-auto bg-gray-50 pb-28 transition-colors duration-200 dark:bg-dark-bg">
                    {layout.map((section, i) => {
                        switch (section.type) {
                            case 'hero':
                                return <HeroCarousel key={i} items={section.items} />;
                            case 'golden_box':
                                return (
                                    <div key={i} className="px-5 pt-5 empty:hidden">
                                        <GoldenBoxBanner />
                                    </div>
                                );
                            case 'cards':
                                return <HomeCards key={i} section={section} />;
                            case 'trending':
                                return (
                                    <div key={i}>
                                    {flash.length > 0 && (
                                        <section className="mt-2 space-y-3 px-5">
                                            <h2 className="text-lg font-bold tracking-tight text-gray-900 dark:text-white">Flash Raffles ⚡</h2>
                                            {flash.slice(0, 3).map((raffle) => (
                                                <RaffleCard key={raffle.id} raffle={raffle} />
                                            ))}
                                        </section>
                                    )}
                                    <section className="mb-6 mt-2">
                                        <div className="mb-4 flex items-end justify-between px-5">
                                            <div>
                                                <h2 className="text-lg font-bold tracking-tight text-gray-900 dark:text-white">{section.title || 'Trending Now'}</h2>
                                                {section.subtitle && <p className="text-[11px] font-medium text-gray-500 dark:text-gray-400">{section.subtitle}</p>}
                                            </div>
                                            {section.link_url && (
                                                <Link
                                                    href={section.link_url}
                                                    className="rounded bg-blue-50 px-2 py-1 text-xs font-bold text-blue-600 transition-colors hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50"
                                                >
                                                    {section.link_label || 'See All'}
                                                </Link>
                                            )}
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
                                );
                            default:
                                return null;
                        }
                    })}
                </div>

                <BottomNav />
            </div>
        </>
    );
}
