import { useEffect, useRef, useState } from 'react';
import { Lock } from 'lucide-react';
import { ICONS, SmartLink, themeOf } from './homeTheme';
import { useAdView } from '../../hooks/useAds';
import { track } from '../../lib/ads';

// Counts a view of an ad slide (Site → Ads → homepage slide) while it is the one showing.
function AdViewMarker({ ad, active }) {
    const ref = useRef(null);
    useAdView(ref, ad, active);

    return (
        <span ref={ref} className="pointer-events-none absolute inset-0">
            <span className="absolute bottom-2 right-3 text-[9px] font-semibold uppercase tracking-wider text-white/50">Ad</span>
        </span>
    );
}

// The swiping banner at the top: one slide per item staff set up in the
// admin (Site → Homepage), plus any ads set to "Homepage: a slide in the top
// banner" (Site → Ads), which come after them. Autoplays every 5s; a swipe stops the autoplay.
export default function HeroCarousel({ items }) {
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
    }, [items.length]);

    if (items.length === 0) {
        return null;
    }

    return (
        <section className="mt-4 px-5">
            <div ref={trackRef} className="no-scrollbar flex snap-x snap-mandatory gap-4 overflow-x-auto rounded-2xl pb-4">
                {items.map((slide, index) => {
                    const theme = themeOf(slide.theme);
                    const Icon = ICONS[slide.icon];

                    return (
                        <div
                            key={index}
                            className={`group relative flex h-48 min-w-full snap-center items-center overflow-hidden rounded-2xl p-6 text-white shadow-xl ${theme.slide}`}
                            style={slide.image_url ? { backgroundImage: `linear-gradient(rgba(0,0,0,.45), rgba(0,0,0,.45)), url("${slide.image_url}")`, backgroundSize: 'cover', backgroundPosition: 'center' } : undefined}
                        >
                            <div className="relative z-10 w-full">
                                {slide.badge && (
                                    <span className={`mb-2 inline-block rounded px-2 py-1 text-[10px] font-extrabold uppercase tracking-wide shadow-sm ${theme.badge}`}>
                                        {slide.badge}
                                    </span>
                                )}
                                <h2 className="mb-1 text-2xl font-extrabold leading-tight">{slide.title}</h2>
                                {slide.text && <p className="mb-4 max-w-[80%] text-xs font-medium text-white/80">{slide.text}</p>}
                                {slide.locked ? (
                                    <button
                                        type="button"
                                        disabled
                                        className="inline-flex cursor-not-allowed items-center gap-2 rounded-xl border border-white/10 bg-white/20 px-6 py-3 text-sm font-bold text-white/80 backdrop-blur"
                                    >
                                        {slide.locked_label} <Lock className="h-4 w-4" />
                                    </button>
                                ) : (
                                    slide.link_url && (
                                        <SmartLink
                                            href={slide.link_url}
                                            onClick={slide.ad ? () => track(slide.ad, 'click') : undefined}
                                            className={`inline-flex items-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-bold shadow-lg transition-transform hover:bg-gray-50 hover:shadow-xl active:scale-95 ${theme.button}`}
                                        >
                                            {slide.link_label || 'Open'} {Icon && <Icon className="h-4 w-4" />}
                                        </SmartLink>
                                    )
                                )}
                            </div>
                            {slide.ad && <AdViewMarker ad={slide.ad} active={index === active} />}
                            {Icon && !slide.image_url && (
                                <div className="absolute -bottom-4 -right-4 opacity-20 transition-transform duration-700 group-hover:scale-110">
                                    <Icon className={`h-32 w-32 fill-current ${theme.watermark}`} />
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>

            <div className="-mt-2 mb-2 flex justify-center gap-1.5">
                {items.map((_, i) => (
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
