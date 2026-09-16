import { useEffect, useRef, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { MessageCircle, Send, ShieldCheck, Trophy, Zap } from 'lucide-react';
import { echoOrNull } from '../../lib/echo';

const REACTIONS = [
    { type: 'fire', emoji: '🔥' },
    { type: 'heart', emoji: '❤️' },
    { type: 'laugh', emoji: '😂' },
    { type: 'wow', emoji: '😮' },
    { type: 'clap', emoji: '👏' },
];

// Rebuild of livedraw.php (item 27) — this is the "shaky" page the site
// owner asked for a real rebuild of, not a port. What was actually wrong
// with the original, concretely (kept here as the record of what got
// fixed, same as item 25's dead-localStorage-token writeup):
//
//   1. `startRevealSequence()` picked a RANDOM participant out of a
//      locally-fetched sample every 100ms for 3 seconds — pure
//      Math.random() with zero connection to the real winners array.
//      Every viewer's browser ran its own independent random flashing;
//      there was nothing to be "in sync" about because nothing was real.
//   2. `showResults()` then revealed the (real) winners one at a time on
//      a hardcoded `setInterval(..., 1200)` — again, entirely local to
//      each browser. Two people watching at the same moment could be on
//      completely different winners depending on when they'd clicked
//      "Reveal Winners" or how their tab had throttled its timers.
//   3. No chat, no reactions — a spectator had nothing to do but watch.
//
// The rebuild keeps the page's real visual identity (the dark
// slate-950/red high-energy palette, the glowing progress bar, the
// staggered reveal-card animation, the rank-1/2/3 medal colors) but
// replaces the mechanism entirely: RunLiveDrawRevealJob is the ONE
// place winners are picked to air, on a real server-side timer,
// broadcasting each step over Reverb — every open tab receives the
// exact same winner at the exact same moment. Comments and reactions are
// both new, real capabilities the legacy page never had at all.
export default function LiveDrawShow({ raffle }) {
    const { auth } = usePage().props;
    const [state, setState] = useState(null);
    const [revealed, setRevealed] = useState([]);
    const [comments, setComments] = useState([]);
    const [reactionCounts, setReactionCounts] = useState({});
    const [commentDraft, setCommentDraft] = useState('');
    const [liveConnected, setLiveConnected] = useState(false);
    const [viewerCount, setViewerCount] = useState(null);
    const [flashType, setFlashType] = useState(null);
    const commentsEndRef = useRef(null);

    useEffect(() => {
        let poll;

        function applyState(data) {
            setState(data);
            setRevealed(data.revealed ?? []);
            setComments(data.comments ?? []);
            setReactionCounts(data.reaction_counts ?? {});
        }

        function fetchState() {
            return fetch(`/api/raffles/${raffle.id}/live-draw`)
                .then((res) => (res.ok ? res.json() : null))
                .then((data) => data && applyState(data))
                .catch(() => {});
        }

        fetchState();

        const echo = echoOrNull();

        if (echo) {
            setLiveConnected(true);

            const channel = echo.channel(`live-draw.${raffle.id}`);

            channel.listen('.winner.revealed', (payload) => {
                setRevealed((prev) => (prev.some((r) => r.sequence === payload.sequence) ? prev : [...prev, payload]));
                setFlashType(payload.winner?.id ?? true);
                setTimeout(() => setFlashType(null), 600);
            });

            channel.listen('.state.changed', (payload) => {
                setState((prev) => (prev ? { ...prev, live_draw_status: payload.status } : prev));
            });

            channel.listen('.comment.posted', (payload) => {
                setComments((prev) => [...prev, payload].slice(-100));
            });

            channel.listen('.reaction.posted', (payload) => {
                setReactionCounts(payload.counts);
            });

            // Presence channel: an honest "N watching" count, replacing
            // the kind of fabricated "1,200+ people are playing" /
            // "3 other people are viewing this" numbers Phase 0 item 7
            // already removed elsewhere in this app. Only resolves for a
            // logged-in viewer (see routes/channels.php) — a guest still
            // sees everything else on this page live either way.
            if (auth?.user) {
                try {
                    const presence = echo.join(`live-draw-presence.${raffle.id}`);
                    presence.here((users) => setViewerCount(users.length));
                    presence.joining(() => setViewerCount((c) => (c ?? 0) + 1));
                    presence.leaving(() => setViewerCount((c) => Math.max(0, (c ?? 1) - 1)));
                } catch {
                    // presence is a nice-to-have — never block the rest of the page on it
                }
            }

            return () => {
                echo.leave(`live-draw.${raffle.id}`);
                if (auth?.user) echo.leave(`live-draw-presence.${raffle.id}`);
            };
        }

        // Documented fallback ONLY — Reverb isn't reachable in this
        // environment, so we poll instead of leaving the page frozen.
        // This is degraded behaviour, not the primary mechanism (see
        // OVERHAUL_CHECKLIST.md item 27 and resources/js/lib/echo.js).
        poll = setInterval(fetchState, 4000);

        return () => clearInterval(poll);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [raffle.id]);

    useEffect(() => {
        commentsEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [comments.length]);

    async function postComment(e) {
        e.preventDefault();
        const body = commentDraft.trim();
        if (! body) return;

        setCommentDraft('');

        try {
            await fetch(`/api/raffles/${raffle.id}/live-draw/comments`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ body }),
            });
        } catch {
            // best-effort — the input already cleared; a failed post just won't appear
        }
    }

    async function postReaction(type) {
        try {
            const res = await fetch(`/api/raffles/${raffle.id}/live-draw/reactions`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ reaction_type: type }),
            });
            if (res.ok) setReactionCounts((await res.json()).counts);
        } catch {
            // best-effort, same as comments
        }
    }

    if (! state) {
        return (
            <div className="flex h-screen w-full flex-col items-center justify-center bg-slate-950 text-white">
                <div className="h-10 w-10 animate-spin rounded-full border-b-2 border-red-600" />
            </div>
        );
    }

    if (! state.is_live_draw_enabled) {
        return (
            <div className="flex h-screen w-full flex-col items-center justify-center gap-4 bg-slate-950 px-6 text-center text-white">
                <Trophy className="h-10 w-10 text-white/30" />
                <h2 className="text-xl font-black uppercase tracking-tight">No live event for this raffle</h2>
                <p className="max-w-xs text-sm text-white/40">
                    This raffle's results are published directly to the Hall of Fame once its draw runs.
                </p>
                <Link href="/hall-of-fame" className="text-xs font-black uppercase tracking-widest text-red-500 hover:underline">
                    View Hall of Fame
                </Link>
            </div>
        );
    }

    const isComplete = state.live_draw_status === 'completed';
    const isRevealing = state.live_draw_status === 'revealing' || isComplete;

    return (
        <>
            <Head title={`Live Draw – ${raffle.title}`} />
            <div className="flex h-screen w-full flex-col overflow-hidden bg-slate-950 text-white">
                <div className="relative flex flex-1 flex-col overflow-hidden">
                    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden">
                        <div className="absolute left-0 top-0 h-full w-full bg-gradient-to-b from-red-900/10 to-transparent" />
                        <div className="absolute bottom-[-10%] right-[-10%] h-[70%] w-[70%] rounded-full bg-red-600/5 blur-[120px]" />
                    </div>

                    {! isRevealing && (
                        <div className="relative z-10 flex flex-1 flex-col items-center justify-center px-6 text-center">
                            <div className="mb-4 inline-block rounded-full border border-red-500/20 bg-red-600/10 px-3 py-1">
                                <span className="text-[9px] font-black uppercase tracking-[0.3em] text-red-500">
                                    {state.draw_has_run ? 'Ready — Waiting to go live' : 'Awaiting draw'}
                                </span>
                            </div>
                            <h2 className="mb-2 text-4xl font-black tracking-tighter md:text-5xl">{raffle.title}</h2>
                            <p className="mb-12 text-[10px] font-medium uppercase tracking-[0.4em] text-white/40">
                                {state.draw_has_run
                                    ? "The admin hasn't started the reveal yet — this page updates the instant they do."
                                    : 'The draw for this raffle has not run yet.'}
                            </p>
                        </div>
                    )}

                    {isRevealing && (
                        <div className="relative z-10 flex min-h-0 flex-1 flex-col overflow-hidden">
                            <div className="sticky top-0 z-20 border-b border-white/5 bg-slate-950/80 p-6 pb-4 text-center backdrop-blur-md">
                                <p className="mb-1 text-[10px] font-black uppercase tracking-[0.5em] text-red-500">
                                    {isComplete ? 'Final Standings' : 'Revealing Now'}
                                </p>
                                <h2 className="text-2xl font-black tracking-tight">{raffle.title}</h2>
                                {viewerCount !== null && (
                                    <p className="mt-1 text-[10px] font-bold uppercase tracking-widest text-white/30">
                                        {viewerCount} watching
                                    </p>
                                )}
                            </div>

                            <div className="flex-1 overflow-y-auto px-4 py-6">
                                <div className="mx-auto max-w-md space-y-4 pb-4">
                                    {[...revealed]
                                        .sort((a, b) => b.sequence - a.sequence)
                                        .map((r) => (
                                            <div
                                                key={r.sequence}
                                                className="flex animate-winner-flash items-center gap-4 rounded-[1.75rem] border border-white/10 bg-white/5 p-4 backdrop-blur-xl"
                                                style={{ boxShadow: '0 0 30px rgba(220, 38, 38, 0.2)' }}
                                            >
                                                <div
                                                    className={`flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl text-2xl font-black ${
                                                        r.winner.prize_rank === 1
                                                            ? 'bg-red-600 text-white shadow-lg'
                                                            : r.winner.prize_rank === 2
                                                              ? 'bg-slate-300 text-slate-900'
                                                              : r.winner.prize_rank === 3
                                                                ? 'bg-orange-600 text-white'
                                                                : 'bg-white/10 text-white/40'
                                                    }`}
                                                >
                                                    {r.winner.prize_rank}
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <h4 className="truncate text-sm font-black">{r.winner.name}</h4>
                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                        <span className="inline-block rounded-md bg-green-600/20 px-2 py-0.5 text-[9px] font-black uppercase tracking-tighter text-green-500">
                                                            {r.winner.prize_name}
                                                        </span>
                                                        <span className="inline-block rounded-md bg-white/5 px-2 py-0.5 font-mono text-[9px] text-white/40">
                                                            #{r.winner.ticket_number}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}

                                    {isComplete && (
                                        <div className="pb-6 pt-8 text-center">
                                            <div className="flex justify-center gap-10">
                                                <Stat label="Winners" value={revealed.length} />
                                                <Stat label="Status" value="Verified" accent="text-green-500" />
                                            </div>
                                            <Link
                                                href={`/raffles/${raffle.id}/verify`}
                                                className="mt-6 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-8 py-4 text-[10px] font-black uppercase tracking-widest transition-transform hover:bg-white/10 active:scale-95"
                                            >
                                                <ShieldCheck className="h-4 w-4" />
                                                Verify This Draw Yourself
                                            </Link>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {! liveConnected && (
                        <div className="absolute bottom-2 left-1/2 z-30 -translate-x-1/2 rounded-full bg-yellow-500/10 px-3 py-1 text-[9px] font-bold uppercase tracking-widest text-yellow-500">
                            Live updates degraded — refreshing periodically
                        </div>
                    )}
                </div>

                {/* Live chat + reactions — new capabilities the legacy page never had */}
                <div className="flex h-64 flex-col border-t border-white/10 bg-slate-950">
                    <div className="flex items-center justify-between border-b border-white/5 px-4 py-2">
                        <div className="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-white/40">
                            <MessageCircle className="h-3.5 w-3.5" /> Live Chat
                        </div>
                        <div className="flex gap-1">
                            {REACTIONS.map((r) => (
                                <button
                                    key={r.type}
                                    onClick={() => (auth?.user ? postReaction(r.type) : (window.location.href = `/login?redirect=${encodeURIComponent(window.location.pathname)}`))}
                                    className={`flex items-center gap-1 rounded-full border border-white/10 px-2 py-1 text-xs transition-transform active:scale-90 ${flashType === r.type ? 'animate-winner-flash' : ''}`}
                                    title={r.type}
                                >
                                    <span>{r.emoji}</span>
                                    <span className="text-[9px] font-bold text-white/50">{reactionCounts[r.type] ?? 0}</span>
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="flex-1 space-y-1.5 overflow-y-auto px-4 py-2">
                        {comments.length === 0 && (
                            <p className="pt-4 text-center text-[10px] uppercase tracking-widest text-white/20">No comments yet — say hi!</p>
                        )}
                        {comments.map((c) => (
                            <p key={c.id} className="text-xs text-white/80">
                                <span className="font-bold text-red-400">{c.user_name ?? 'Someone'}:</span> {c.body}
                            </p>
                        ))}
                        <div ref={commentsEndRef} />
                    </div>

                    <form onSubmit={postComment} className="flex items-center gap-2 border-t border-white/5 p-3">
                        {auth?.user ? (
                            <>
                                <input
                                    value={commentDraft}
                                    onChange={(e) => setCommentDraft(e.target.value)}
                                    maxLength={280}
                                    placeholder="Say something…"
                                    className="flex-1 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs text-white placeholder:text-white/30 focus:border-red-600 focus:outline-none"
                                />
                                <button type="submit" className="rounded-full bg-red-600 p-2 transition-transform active:scale-90">
                                    <Send className="h-4 w-4" />
                                </button>
                            </>
                        ) : (
                            <Link
                                href={`/login?redirect=${encodeURIComponent(window.location.pathname)}`}
                                className="flex w-full items-center justify-center gap-2 rounded-full bg-white/5 py-2 text-[10px] font-black uppercase tracking-widest text-white/60 hover:bg-white/10"
                            >
                                <Zap className="h-3.5 w-3.5" /> Log in to chat and react
                            </Link>
                        )}
                    </form>
                </div>
            </div>
        </>
    );
}

function Stat({ label, value, accent = '' }) {
    return (
        <div className="text-center">
            <p className="mb-0.5 text-[9px] font-black uppercase tracking-widest text-white/20">{label}</p>
            <p className={`text-xs font-black uppercase italic tracking-tighter ${accent}`}>{value}</p>
        </div>
    );
}
