import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, ChevronRight, Crown, PlayCircle, Radio, Trophy } from 'lucide-react';
import BottomNav from '../../Components/layout/BottomNav';
import { goBack } from '../../lib/nav';

// Every live draw in one place (item 48): happening now, coming up, and
// past events. A past event opens the same live-draw page, where it can
// be replayed winner by winner.
export default function LiveDrawIndex({ live = [], upcoming = [], past = [] }) {
    const empty = live.length + upcoming.length + past.length === 0;

    return (
        <>
            <Head title="Live Draws" />
            <div className="min-h-screen bg-slate-950 pb-28 text-white">
                <div className="relative overflow-hidden px-5 pb-8 pt-4">
                    <div aria-hidden="true" className="pointer-events-none absolute inset-0 bg-gradient-to-b from-red-900/30 to-transparent" />
                    <div className="relative flex items-center gap-3">
                        <button onClick={() => goBack('/')} className="-ml-1 p-1 text-white/60 hover:text-white" aria-label="Back">
                            <ArrowLeft className="h-6 w-6" />
                        </button>
                        <h1 className="text-xl font-black tracking-tight">Live Draws</h1>
                    </div>
                    <p className="relative mt-3 max-w-sm text-sm text-white/60">
                        Watch winners revealed live, chat while you watch, or replay a past draw. Every draw can be checked on its "Verify" page.
                    </p>
                </div>

                <div className="mx-auto max-w-lg space-y-8 px-5">
                    {empty && (
                        <div className="rounded-3xl border border-white/10 bg-white/5 p-8 text-center">
                            <Radio className="mx-auto mb-3 h-10 w-10 text-white/30" />
                            <p className="font-bold">No live draws yet</p>
                            <p className="mt-1 text-sm text-white/50">When a raffle has a live draw, it will appear here.</p>
                            <Link href="/hall-of-fame" className="mt-4 inline-block text-xs font-black uppercase tracking-widest text-red-400">
                                See all winners
                            </Link>
                        </div>
                    )}

                    {live.length > 0 && (
                        <Section title="Live now" icon={<span className="h-2.5 w-2.5 animate-pulse rounded-full bg-red-500" />}>
                            {live.map((e) => (
                                <EventCard key={e.id} event={e} badge={<Badge className="bg-red-600 text-white">● Live</Badge>} action="Watch now" highlight />
                            ))}
                        </Section>
                    )}

                    {upcoming.length > 0 && (
                        <Section title="Coming up" icon={<CalendarClock className="h-4 w-4 text-amber-400" />}>
                            {upcoming.map((e) => (
                                <EventCard
                                    key={e.id}
                                    event={e}
                                    badge={<Badge className="bg-amber-400/15 text-amber-300">{e.scheduled_at ? when(e.scheduled_at) : 'Soon'}</Badge>}
                                    action="Open"
                                />
                            ))}
                        </Section>
                    )}

                    {past.length > 0 && (
                        <Section title="Past draws" icon={<PlayCircle className="h-4 w-4 text-white/60" />}>
                            {past.map((e) => (
                                <EventCard
                                    key={e.id}
                                    event={e}
                                    badge={<Badge className="bg-white/10 text-white/70">{e.started_at ? when(e.started_at, false) : 'Finished'}</Badge>}
                                    action="Replay"
                                />
                            ))}
                        </Section>
                    )}
                </div>
            </div>
            <BottomNav />
        </>
    );
}

function when(iso, withTime = true) {
    return new Date(iso).toLocaleString(undefined, withTime
        ? { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }
        : { day: 'numeric', month: 'short', year: 'numeric' });
}

function Section({ title, icon, children }) {
    return (
        <section>
            <h2 className="mb-3 flex items-center gap-2 text-xs font-black uppercase tracking-[0.2em] text-white/60">
                {icon} {title}
            </h2>
            <div className="space-y-3">{children}</div>
        </section>
    );
}

function Badge({ className, children }) {
    return <span className={`inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider ${className}`}>{children}</span>;
}

function EventCard({ event, badge, action, highlight = false }) {
    return (
        <Link
            href={`/raffles/${event.id}/live-draw`}
            className={`flex items-center gap-4 rounded-3xl border p-4 transition-transform active:scale-[0.98] ${
                highlight ? 'border-red-500/50 bg-red-600/10 shadow-[0_0_30px_rgba(220,38,38,0.25)]' : 'border-white/10 bg-white/5'
            }`}
        >
            <div
                className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl"
                style={{ background: `${event.theme_color || '#dc2626'}33` }}
            >
                <Trophy className="h-6 w-6" style={{ color: event.theme_color || '#dc2626' }} />
            </div>
            <div className="min-w-0 flex-1">
                <div className="mb-1">{badge}</div>
                <p className="line-clamp-2 font-black leading-tight">{event.title}</p>
                <p className="mt-0.5 line-clamp-2 text-xs text-white/50">
                    {event.top_winner ? (
                        <>
                            <Crown className="mr-1 inline h-3 w-3 text-yellow-400" />
                            {event.top_winner} won {event.grand_prize || 'the top prize'} · {event.winners} winner{event.winners === 1 ? '' : 's'}
                        </>
                    ) : (
                        event.grand_prize && `Prize: ${event.grand_prize}`
                    )}
                </p>
            </div>
            <span className="flex flex-shrink-0 flex-col items-center gap-1 text-[10px] font-black uppercase text-red-400">
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-red-600/15">
                    {action === 'Replay' ? <PlayCircle className="h-5 w-5" /> : <ChevronRight className="h-5 w-5" />}
                </span>
                {action}
            </span>
        </Link>
    );
}
