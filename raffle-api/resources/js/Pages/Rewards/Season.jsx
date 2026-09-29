import { useEffect, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Clock, Coins, Gift, Lock, Sparkles, Star, Ticket } from 'lucide-react';
import LoadError from '../../Components/ui/LoadError';
import PausedNotice from '../../Components/layout/PausedNotice';
import Confetti from '../../Components/ui/Confetti';
import { apiPost } from '../../lib/api';
import { refreshBalances } from '../../lib/balances';
import { useApi } from '../../lib/useApi';

// Phase 11: the Season Pass — a free 4-week track with 30 levels. Playing,
// daily check-ins, tasks and predictions give XP; every level has a
// reward to collect.
const XP_LABELS = {
    ticket: 'per ticket',
    daily_claim: 'daily reward claim',
    task: 'per task',
    prediction: 'per prediction answered',
    prediction_correct: 'extra for a right prediction',
};

function daysLeft(iso) {
    const ms = new Date(iso).getTime() - Date.now();
    const days = Math.floor(ms / 86400000);
    const hours = Math.floor((ms % 86400000) / 3600000);

    return ms <= 0 ? 'ending now' : days > 0 ? `${days}d ${hours}h left` : `${hours}h left`;
}

export default function Season({ preview }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const { data, setData, failed, reload } = useApi('/api/season', { enabled: !! user });
    const [busy, setBusy] = useState(false);
    const [given, setGiven] = useState(null);
    const state = user ? data : preview;

    useEffect(() => {
        // Scroll the track to the customer's current level.
        if (state?.level) document.getElementById(`lvl-${Math.max(1, state.level - 1)}`)?.scrollIntoView({ block: 'center' });
    }, [state?.level]);

    async function claim() {
        setBusy(true);
        try {
            const result = await apiPost('/api/season/claim', {});
            setData(result.state);
            setGiven(result.given);
            if (result.given.points) refreshBalances();
        } finally {
            setBusy(false);
        }
    }

    const pct = state ? Math.round((state.xp_into_level / state.xp_per_level) * 100) : 0;

    return (
        <>
            <Head title="Season Pass" />
            {given?.levels?.length > 0 && <Confetti />}
            <div className="min-h-screen bg-[#0f0a1f] pb-28 text-white">
                <div className="relative overflow-hidden bg-gradient-to-br from-indigo-700 via-purple-700 to-fuchsia-700 px-5 pb-8 pt-4">
                    <div className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-yellow-300/20 blur-3xl" />
                    <div className="relative z-10 flex items-center justify-between">
                        <button onClick={() => window.history.back()} className="-ml-1 p-1 text-white/70 hover:text-white" aria-label="Back">
                            <ArrowLeft className="h-6 w-6" />
                        </button>
                        {state && (
                            <span className="flex items-center gap-1 rounded-full bg-black/25 px-3 py-1 text-xs font-bold">
                                <Clock className="h-3 w-3" /> {daysLeft(state.ends_at)}
                            </span>
                        )}
                    </div>

                    <div className="relative z-10 mt-4 flex items-center gap-4">
                        <div className="flex h-24 w-24 flex-shrink-0 flex-col items-center justify-center rounded-full border-4 border-yellow-300 bg-black/30 shadow-[0_0_30px_rgba(253,224,71,0.35)]">
                            <span className="text-[10px] font-bold uppercase tracking-widest text-yellow-200">Level</span>
                            <span className="text-4xl font-black leading-none">{state?.level ?? 0}</span>
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="text-xs font-bold uppercase tracking-[0.2em] text-yellow-200">Season {state?.season ?? ''} Pass · Free</p>
                            <h1 className="text-2xl font-black italic leading-tight">Earn XP. Level up. Collect.</h1>
                            <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-black/30">
                                <div className="h-full rounded-full bg-gradient-to-r from-yellow-300 to-amber-500 transition-all" style={{ width: `${pct}%` }} />
                            </div>
                            <p className="mt-1 text-[11px] text-white/80">
                                {state ? `${state.xp_into_level} / ${state.xp_per_level} XP to the next level · ${state.xp} XP this season` : ''}
                            </p>
                        </div>
                    </div>

                    {user && state?.claimable > 0 && (
                        <button
                            onClick={claim}
                            disabled={busy}
                            className="relative z-10 mt-5 flex w-full animate-soft-bounce items-center justify-center gap-2 rounded-2xl bg-gradient-to-b from-yellow-300 to-amber-500 py-3.5 text-sm font-black uppercase text-amber-950 shadow-[0_4px_0_#b45309] active:translate-y-0.5 disabled:opacity-60"
                        >
                            <Gift className="h-5 w-5" /> {busy ? 'Collecting…' : `Collect ${state.claimable} ${state.claimable === 1 ? 'reward' : 'rewards'}`}
                        </button>
                    )}
                    {! user && (
                        <Link href={`/login?redirect=${encodeURIComponent('/rewards/season')}`} className="relative z-10 mt-5 block rounded-2xl bg-white py-3 text-center text-sm font-bold text-indigo-700">
                            Log in to play the Season Pass
                        </Link>
                    )}
                </div>

                <div className="space-y-4 px-5 pt-5">
                    <PausedNotice feature="season_pass" />
                    {failed && <LoadError dark onRetry={reload} />}

                    {given?.levels?.length > 0 && (
                        <div className="rounded-2xl border border-yellow-300/40 bg-yellow-300/10 p-4 text-sm">
                            <p className="font-black text-yellow-200">Collected!</p>
                            <p className="text-white/85">
                                {[
                                    given.points ? `${given.points.toLocaleString()} points` : null,
                                    given.free_spins ? `${given.free_spins} free ${given.free_spins === 1 ? 'spin' : 'spins'}` : null,
                                    given.bonus_entries ? `${given.bonus_entries} free bonus ${given.bonus_entries === 1 ? 'entry' : 'entries'} (added to your next ticket purchase)` : null,
                                    given.badges?.length ? `${given.badges.length} new ${given.badges.length === 1 ? 'badge' : 'badges'}` : null,
                                ].filter(Boolean).join(' · ')}
                            </p>
                        </div>
                    )}

                    {state && (
                        <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <p className="mb-2 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-white/60">
                                <Sparkles className="h-3.5 w-3.5 text-yellow-300" /> How it works
                            </p>
                            <p className="mb-3 text-[11px] text-white/70">
                                XP are progress points. Every {state.xp_per_level} XP moves you up one level. Each level has a reward. When a level shows "Ready", tap Collect at the top.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {Object.entries(state.xp_sources)
                                    .filter(([k]) => XP_LABELS[k])
                                    .map(([k, v]) => (
                                        <span key={k} className="rounded-full bg-white/10 px-3 py-1 text-[11px]">
                                            <b className="text-yellow-200">+{v} XP</b> {XP_LABELS[k]}
                                        </span>
                                    ))}
                            </div>
                            <p className="mt-2 text-[11px] text-white/50">
                                You can earn up to {state.xp_sources.ticket_daily_cap} XP a day from tickets.
                            </p>
                        </div>
                    )}

                    {state && (
                        <ol className="relative space-y-2 before:absolute before:bottom-4 before:left-[27px] before:top-4 before:w-0.5 before:bg-white/10">
                            {state.levels.map((l) => (
                                <li
                                    id={`lvl-${l.level}`}
                                    key={l.level}
                                    className={`relative flex items-center gap-3 rounded-2xl border p-3 ${
                                        l.claimed
                                            ? 'border-white/5 bg-white/5 opacity-70'
                                            : l.reached
                                              ? 'border-yellow-300/60 bg-yellow-300/10 shadow-[0_0_20px_rgba(253,224,71,0.15)]'
                                              : 'border-white/5 bg-white/[0.03]'
                                    }`}
                                >
                                    <span
                                        className={`relative z-10 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-xs font-black ${
                                            l.claimed ? 'bg-green-500' : l.reached ? 'bg-yellow-300 text-amber-950' : 'bg-white/10 text-white/60'
                                        }`}
                                    >
                                        {l.claimed ? <Check className="h-4 w-4" /> : l.level}
                                    </span>
                                    <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 text-xs">
                                        <Chip icon={Coins}>{l.points} pts</Chip>
                                        {l.free_spins > 0 && <Chip icon={Star}>Free spin</Chip>}
                                        {l.bonus_entries > 0 && <Chip icon={Ticket}>Bonus entry</Chip>}
                                        {l.badge_emoji && <Chip>{l.badge_emoji} {l.badge_name}</Chip>}
                                    </div>
                                    {! l.reached && <Lock className="h-4 w-4 flex-shrink-0 text-white/30" />}
                                    {l.reached && ! l.claimed && <span className="text-[10px] font-black uppercase text-yellow-300">Ready</span>}
                                </li>
                            ))}
                        </ol>
                    )}

                    {state && (
                        <p className="pb-4 text-center text-[11px] text-white/40">
                            The Season Pass is free. Collect your rewards before the season ends. A new season then starts from level 0.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

function Chip({ icon: Icon, children }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-white/10 px-2 py-0.5 font-bold">
            {Icon && <Icon className="h-3 w-3 text-yellow-300" />} {children}
        </span>
    );
}
