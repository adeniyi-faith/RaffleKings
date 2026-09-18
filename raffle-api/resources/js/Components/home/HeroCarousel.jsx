import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Banknote, Car, Clock, Coins, Crown, Lock } from 'lucide-react';

// Same 3 fixed slides, same copy, same autoplay-every-5s + swipe-cancels
// behaviour as the legacy homepage's #hero-carousel (index.php's
// initCarousel()).
export default function HeroCarousel() {
    const trackRef = useRef(null);
    const [active, setActive] = useState(0);

    useEffect(() => {
        const track = trackRef.current;
        if (!track) {
            return undefined;
        }

        const interval = setInterval(() => {
            const width = track.offsetWidth;
            const maxScroll = track.scrollWidth - width;
            const next = track.scrollLeft + width > maxScroll ? 0 : track.scrollLeft + width;
            track.scrollTo({ left: next, behavior: 'smooth' });
        }, 5000);

        const cancelOnTouch = () => clearInterval(interval);
        const updateActive = () => setActive(Math.round(track.scrollLeft / track.offsetWidth));

        track.addEventListener('touchstart', cancelOnTouch);
        track.addEventListener('scroll', updateActive);

        return () => {
            clearInterval(interval);
            track.removeEventListener('touchstart', cancelOnTouch);
            track.removeEventListener('scroll', updateActive);
        };
    }, []);

    return (
        <section className="mt-4 px-5">
            <div ref={trackRef} className="no-scrollbar flex snap-x snap-mandatory gap-4 overflow-x-auto rounded-2xl pb-4">
                <div className="group relative flex h-48 min-w-full snap-center items-center overflow-hidden rounded-2xl bg-gradient-to-br from-green-700 via-green-600 to-emerald-800 p-6 text-white shadow-xl shadow-green-900/30">
                    <div className="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-10" />
                    <div className="relative z-10 w-full">
                        <span className="mb-2 inline-block animate-pulse rounded bg-yellow-400 px-2 py-1 text-[10px] font-extrabold uppercase tracking-wide text-green-900 shadow-sm">
                            Daily Payouts
                        </span>
                        <h2 className="mb-1 text-3xl font-extrabold leading-tight">Win Cash Daily!</h2>
                        <p className="mb-4 max-w-[80%] text-xs font-medium text-green-100">
                            People are winning right now. Don&apos;t wait for Friday!
                        </p>
                        <Link
                            href="/raffles"
                            className="inline-flex items-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-bold text-green-800 shadow-lg transition-transform hover:bg-gray-50 hover:shadow-xl active:scale-95"
                        >
                            Play for Cash <Banknote className="h-4 w-4" />
                        </Link>
                    </div>
                    <div className="absolute -bottom-4 -right-4 opacity-30 transition-transform duration-700 group-hover:scale-110">
                        <Coins className="h-32 w-32 fill-current text-yellow-300" />
                    </div>
                    <div className="absolute right-8 top-4 animate-bounce opacity-40 delay-700">
                        <Banknote className="h-8 w-8 text-green-200" />
                    </div>
                </div>

                <div className="group relative flex h-48 min-w-full snap-center items-center overflow-hidden rounded-2xl border border-yellow-500/30 bg-gradient-to-br from-gray-900 via-gray-800 to-black p-6 text-white shadow-xl shadow-black/50">
                    <div
                        className="absolute inset-0 opacity-20"
                        style={{ backgroundImage: 'radial-gradient(circle at 2px 2px, #EAB308 1px, transparent 0)', backgroundSize: '20px 20px' }}
                    />
                    <div className="relative z-10 w-full">
                        <span className="mb-2 inline-block rounded border border-yellow-500/50 bg-yellow-500/20 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-yellow-300">
                            Premium Access
                        </span>
                        <h2 className="mb-1 bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-2xl font-black leading-tight text-transparent">
                            Executive VIP
                        </h2>
                        <p className="mb-4 max-w-[70%] text-xs text-gray-400">Exclusive high-stakes draws for the elite. Only 100 spots.</p>
                        <button
                            type="button"
                            disabled
                            className="inline-flex cursor-not-allowed items-center gap-2 rounded-xl border border-gray-700 bg-gray-800/80 px-6 py-3 text-sm font-bold text-gray-400 backdrop-blur"
                        >
                            Coming Soon <Lock className="h-4 w-4" />
                        </button>
                    </div>
                    <div className="absolute right-6 top-1/2 -translate-y-1/2 opacity-20">
                        <Crown className="h-36 w-36 fill-current text-yellow-500" />
                    </div>
                </div>

                <div className="relative flex h-48 min-w-full snap-center items-center overflow-hidden rounded-2xl bg-gradient-to-br from-red-800 to-red-600 p-6 text-white shadow-xl shadow-red-900/30">
                    <div className="relative z-10 w-full">
                        <span className="mb-2 inline-block rounded border border-white/20 bg-white/20 px-2 py-1 text-[10px] font-bold uppercase text-white">
                            Dream Ride
                        </span>
                        <h2 className="mb-1 text-2xl font-bold leading-tight">Win a Brand New Car</h2>
                        <p className="mb-4 max-w-[80%] text-xs text-red-100">Drive away in style. The ultimate grand prize awaits.</p>
                        <button
                            type="button"
                            disabled
                            className="inline-flex cursor-not-allowed items-center gap-2 rounded-xl border border-white/10 bg-white/20 px-6 py-3 text-sm font-bold text-white/80 backdrop-blur"
                        >
                            Coming Soon <Clock className="h-4 w-4" />
                        </button>
                    </div>
                    <div className="absolute -right-2 bottom-0 opacity-20">
                        <Car className="h-32 w-32 fill-current text-white" />
                    </div>
                </div>
            </div>

            <div className="-mt-2 mb-2 flex justify-center gap-1.5">
                {[0, 1, 2].map((i) => (
                    <div
                        key={i}
                        className={[
                            'h-1.5 rounded-full transition-all duration-300',
                            i === active ? 'w-4 bg-app-primary' : 'w-1.5 bg-gray-300 dark:bg-gray-700',
                        ].join(' ')}
                    />
                ))}
            </div>
        </section>
    );
}
