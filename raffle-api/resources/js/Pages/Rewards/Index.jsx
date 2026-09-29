import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Bell,
    CheckCircle2,
    Clock,
    Coins,
    Copy,
    ExternalLink,
    Share2,
    Users,
    Zap,
} from 'lucide-react';
import { formatNaira } from '../../lib/format';
import { isOn, useSite } from '../../lib/site';
import PausedNotice from '../../Components/layout/PausedNotice';
import { usePushPermission } from '../../hooks/usePushPermission';
import BottomNav from '../../Components/layout/BottomNav';
import { setBalances, useBalances } from '../../lib/balances';
import ResultModal from '../../Components/rewards/ResultModal';
import LoadError from '../../Components/ui/LoadError';

// Rebuild of rewards.php (item 28). What's preserved from the legacy
// page: the blue hero with a points badge and a 7-day streak row, the
// white "Wallet Value / Redeem Now" card, and the gradient Spin & Win
// and Refer & Earn cards.
//
// What's fixed, per the checklist bug report: the Spin & Win and Refer
// & Earn cards were hardcoded with a lock icon and a "Soon" badge in the
// legacy markup, even though both already had a complete, real backend
// (item 16's SpinService/PointsService, item 15's
// ReferralCommissionService) — a user could never reach either feature
// through the page meant to be its home. Both cards are now real:
// Spin & Win actually spins against POST /api/rewards/spin and shows the
// item-7 disclosed odds, and Refer & Earn shows this user's own working
// referral link and real stats from GET /api/referrals/stats.
//
// Item 47 ("rewards that feel alive"): the animated wheel is back (driven
// by the server's result), link tasks are "Go" then "Claim" after a short
// wait (the server checks it), today's reward bounces with a countdown to
// the next one, guests see a real preview, and errors look like errors.
const TASK_LABELS = {
    push_notification: { title: 'Enable Notifications', desc: 'Turn on push alerts', icon: Bell },
    join_community: { title: 'Join our Community', desc: 'Join our official group', icon: Users },
    whatsapp_follow: { title: 'Follow on WhatsApp', desc: 'Follow our WhatsApp channel', icon: ExternalLink },
    whatsapp_share: { title: 'Share on WhatsApp', desc: 'Share with your friends (daily)', icon: Share2 },
};

// Re-renders every second while something on the page counts down.
function useNow(active) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (! active) {
            return undefined;
        }

        const interval = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(interval);
    }, [active]);

    return now;
}

function hms(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const pad = (n) => String(n).padStart(2, '0');

    return `${pad(Math.floor(total / 3600))}:${pad(Math.floor((total % 3600) / 60))}:${pad(total % 60)}`;
}

export default function RewardsIndex({ referralCode, preview = null }) {
    const { auth } = usePage().props;
    const site = useSite();
    // Both set in the admin's Settings → Rewards.
    const pointsPerNaira = site.points_per_naira || 10;
    const minRedeem = site.minimum_redeem_points || 100;
    const isGuest = ! auth?.user;
    const pageBalances = useBalances();
    const [state, setState] = useState(null);
    const [referral, setReferral] = useState(null);
    const [busy, setBusy] = useState(null); // id of whatever action is in flight
    const [stateFailed, setStateFailed] = useState(false);
    const [modal, setModal] = useState(null); // { kind, title, message, balance?, confetti? }
    const [copied, setCopied] = useState(false);
    const [taskReadyAt, setTaskReadyAt] = useState({}); // task id → when Claim unlocks (ms)
    const { requestPermission } = usePushPermission();

    const referralLink = referralCode ? `${window.location.origin}/register?ref=${encodeURIComponent(referralCode)}` : null;

    useEffect(() => {
        // A guest has no state/referral stats to fetch -- same as the
        // legacy page, which only ever calls its authenticated endpoints
        // when is_user_logged_in() is true.
        if (isGuest) {
            return;
        }

        loadState();
        fetch('/api/referrals/stats', { credentials: 'same-origin' })
            .then((res) => (res.ok ? res.json() : null))
            .then(setReferral)
            .catch(() => setReferral(null));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function loadState() {
        setStateFailed(false);

        return fetch('/api/rewards/state', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((res) => {
                if (! res.ok) throw new Error();
                return res.json();
            })
            .then((data) => {
                setState(data);
                if (data) setBalances({ points: data.points });
                // Keep a "Claim in 8s" countdown going after a reload.
                const ready = {};
                (data?.tasks ?? []).forEach((t) => {
                    if (t.claimable_at && ! t.completed) ready[t.task_id] = new Date(t.claimable_at).getTime();
                });
                setTaskReadyAt(ready);
            })
            // Phase 9: say so (with Try again) instead of showing an empty streak and tasks.
            .catch(() => setStateFailed(true));
    }

    async function post(url) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));

        if (! response.ok) {
            const error = new Error(data.message || 'Something went wrong. Please try again.');
            error.status = response.status;
            error.data = data;
            throw error;
        }

        return data;
    }

    async function claimDaily() {
        setBusy('daily');
        try {
            const result = await post('/api/rewards/daily-claim');
            setModal({
                kind: 'success',
                title: 'Streak Claimed!',
                message: `You earned ${result.points_added} points. Day ${result.new_streak} streak. Come back tomorrow for more.`,
                balance: result.new_total_points,
                confetti: result.new_streak === 7,
            });
            loadState();
            router.reload({ only: ['auth'] }); // clears the red dot on the bottom nav
        } catch (err) {
            setModal({ kind: 'error', title: 'Not claimed', message: err.message });
        } finally {
            setBusy(null);
        }
    }

    // Where each task actually sends the customer (Settings → General →
    // Links). Opened straight from the tap, before any waiting, so phone
    // browsers don't block it as a pop-up.
    function openTaskLink(taskId) {
        const shareText = `Join me on ${site.name || 'RaffleKings'} and win amazing prizes! ${referralLink || window.location.origin}`;
        const url = {
            join_community: site.links?.community,
            whatsapp_follow: site.links?.whatsapp_channel,
            whatsapp_share: `https://wa.me/?text=${encodeURIComponent(shareText)}`,
        }[taskId];

        if (url) {
            window.open(url, '_blank', 'noopener');
        }
    }

    // Step 1 of a link task: open the link (straight from the tap, so it
    // isn't blocked as a pop-up), then tell the server, which starts the wait.
    async function goTask(taskId) {
        openTaskLink(taskId);
        setBusy(taskId);
        try {
            const result = await post(`/api/rewards/tasks/${taskId}/start`);
            setTaskReadyAt((prev) => ({ ...prev, [taskId]: new Date(result.claimable_at).getTime() }));
        } catch (err) {
            setModal({ kind: 'error', title: 'Could not start this task', message: err.message });
        } finally {
            setBusy(null);
        }
    }

    async function claimTask(taskId) {
        setBusy(taskId);
        try {
            // Item 31: the "Enable Notifications" reward is honestly tied
            // to actually granting permission — a real browser prompt
            // via OneSignal, not a reward for clicking a button. This
            // never gates anything else (the daily streak claim above
            // calls its own endpoint with no dependency on this at all),
            // unlike the legacy daily-claim flow's push-permission trap.
            if (taskId === 'push_notification') {
                const granted = await requestPermission();

                if (! granted) {
                    setModal({
                        kind: 'error',
                        title: 'Notifications not enabled',
                        message: 'Please allow notifications in your browser to claim this reward.',
                    });
                    return;
                }
            }

            const result = await post(`/api/rewards/tasks/${taskId}/claim`);
            setModal({ kind: 'success', title: 'Task Complete!', message: `You earned ${result.points_added} points.`, balance: result.new_total_points });
            loadState();
        } catch (err) {
            // Too soon: the server says how long is left, so the button counts down again.
            if (err.status === 425 && err.data?.seconds_left) {
                setTaskReadyAt((prev) => ({ ...prev, [taskId]: Date.now() + err.data.seconds_left * 1000 }));
            } else if (err.status === 425) {
                setTaskReadyAt((prev) => {
                    const next = { ...prev };
                    delete next[taskId];
                    return next;
                });
            }
            setModal({ kind: err.status === 425 ? 'info' : 'error', title: err.status === 425 ? 'Not yet' : 'Could not claim this task', message: err.message });
        } finally {
            setBusy(null);
        }
    }

    async function redeem() {
        setBusy('redeem');
        try {
            const result = await post('/api/rewards/redeem');
            setModal({
                kind: 'success',
                title: 'Redeemed!',
                message: `${result.redeemed_points} points converted to ${formatNaira(result.wallet_added)} in your wallet.`,
            });
            loadState();
        } catch (err) {
            setModal({ kind: 'error', title: 'Could not redeem', message: err.message });
        } finally {
            setBusy(null);
        }
    }

    function copyLink() {
        navigator.clipboard?.writeText(referralLink).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    // A guest sees the real rewards (item 47) instead of blank rows.
    const data = isGuest ? preview : state;
    // Sent with the page, so the badge shows the real number straight away.
    const points = state?.points ?? pageBalances?.points ?? 0;
    const streak = state?.streak ?? 0;
    const claimedToday = state?.is_claimed_today ?? false;
    const schedule = data?.daily_schedule ?? [];
    const tasks = data?.tasks ?? [];
    const spinOdds = data?.spin?.odds ?? [];
    const spinCost = data?.spin?.cost ?? 50;
    const loginUrl = `/login?redirect=${encodeURIComponent('/rewards')}`;

    const counting = (! isGuest && claimedToday) || Object.keys(taskReadyAt).length > 0;
    const now = useNow(counting);
    const nextReset = state?.next_reset_at ? new Date(state.next_reset_at).getTime() - now : null;

    // The new day started while the page was open: show today's reward.
    useEffect(() => {
        if (claimedToday && nextReset !== null && nextReset <= 0) {
            loadState();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [claimedToday, nextReset !== null && nextReset <= 0]);

    return (
        <>
            <Head title="Rewards" />
            <div className="relative min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="relative overflow-hidden bg-blue-900 px-5 pb-16 pt-4 dark:bg-blue-950">
                    <div className="pointer-events-none absolute right-0 top-0 h-64 w-64 -translate-y-1/2 translate-x-1/2 rounded-full bg-white/5 blur-3xl" />

                    <div className="relative z-10 mb-6 flex items-center justify-between">
                        <div className="flex min-w-0 items-center gap-3">
                            <button onClick={() => window.history.back()} className="-ml-1 flex-shrink-0 p-1 text-white/70 hover:text-white" aria-label="Back">
                                <ArrowLeft className="h-6 w-6" />
                            </button>
                            <div>
                                <h2 className="text-xl font-bold text-white">Rewards</h2>
                                {! isGuest && state && (
                                    <p className="flex items-center gap-1 text-xs text-blue-200">
                                        <Clock className="h-3 w-3" />
                                        {claimedToday && nextReset !== null ? (
                                            <>
                                                Next reward in <span className="font-mono font-bold text-white">{hms(nextReset)}</span>
                                            </>
                                        ) : (
                                            <span className="font-bold text-yellow-300">Today's reward is ready!</span>
                                        )}
                                    </p>
                                )}
                                {isGuest && <p className="text-xs text-blue-200">Log in to collect points every day.</p>}
                            </div>
                        </div>

                        {isGuest ? (
                            <Link href={loginUrl} className="rounded-full bg-yellow-400 px-4 py-1.5 text-xs font-bold text-gray-900 shadow-md active:scale-95">
                                Log in
                            </Link>
                        ) : (
                            <div className="ml-2 flex flex-shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-white/20 bg-white/10 px-3 py-1.5 backdrop-blur-md">
                                <Coins className="h-4 w-4 flex-shrink-0 fill-current text-yellow-400" />
                                <span className="text-sm font-bold tabular-nums text-white">{points.toLocaleString()} pts</span>
                            </div>
                        )}
                    </div>

                    <div className="relative z-10 flex justify-between gap-2">
                        {schedule.map((reward, i) => {
                            const day = i + 1;
                            const isDone = ! isGuest && (day < streak || (day === streak && claimedToday));
                            const isToday = ! isGuest && day === streak && ! claimedToday && isOn(site, 'daily_claim');
                            const Tag = isGuest ? Link : 'button';

                            return (
                                <Tag
                                    key={day}
                                    {...(isGuest ? { href: loginUrl } : { onClick: isToday ? claimDaily : undefined, disabled: ! isToday || busy === 'daily' })}
                                    aria-label={isToday ? `Claim day ${day} reward: ${reward} points` : undefined}
                                    className={`relative flex-1 rounded-xl border py-2 text-center transition-transform ${
                                        isToday
                                            ? 'animate-soft-bounce border-yellow-400 bg-yellow-400/30 shadow-lg shadow-yellow-400/30 active:scale-95'
                                            : isDone
                                              ? 'border-white/20 bg-white/10'
                                              : 'border-white/10 bg-white/5 opacity-60'
                                    }`}
                                >
                                    {isToday && <span className="absolute -right-1 -top-1 h-2.5 w-2.5 animate-ping rounded-full bg-yellow-300" />}
                                    <p className="text-[9px] font-bold uppercase text-blue-200">Day {day}</p>
                                    <p className="text-xs font-bold text-white">{reward}</p>
                                    {isDone && <CheckCircle2 className="mx-auto mt-0.5 h-3 w-3 text-green-400" />}
                                    {isToday && <p className="text-[8px] font-black uppercase text-yellow-300">{busy === 'daily' ? '…' : 'Claim'}</p>}
                                </Tag>
                            );
                        })}
                    </div>
                </div>

                <div className="relative z-20 -mt-6 space-y-5 px-5">
                    {stateFailed && ! state && <LoadError onRetry={loadState} message="Couldn't load your rewards. Check your connection and try again." />}
                    {site.points_boost && (
                        <div className="flex items-center gap-3 rounded-2xl bg-gradient-to-r from-amber-400 to-orange-500 p-4 text-white shadow-lg shadow-orange-500/20">
                            <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-white/20 text-lg font-black">
                                ×{site.points_boost.multiplier}
                            </div>
                            <div className="min-w-0">
                                <p className="text-sm font-bold">{site.points_boost.label}</p>
                                <p className="text-[11px] text-white/85">
                                    Daily claim and task points are boosted until{' '}
                                    {new Date(site.points_boost.ends_at).toLocaleString(undefined, { weekday: 'short', hour: 'numeric', minute: '2-digit' })}.
                                </p>
                            </div>
                        </div>
                    )}
                    {state?.loyalty && <LoyaltyCard loyalty={state.loyalty} />}
                    {/* Redeem card */}
                    <div className="flex items-center justify-between rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                        <div>
                            <p className="text-[10px] font-bold uppercase text-gray-400 dark:text-gray-500">Wallet Value</p>
                            <h3 className="text-xl font-bold text-gray-900 dark:text-white">{formatNaira(Math.floor(points / pointsPerNaira))}</h3>
                            <p className="text-[10px] text-green-600 dark:text-green-400">Rate: {pointsPerNaira} Pts = ₦1</p>
                        </div>
                        <button
                            onClick={redeem}
                            disabled={busy === 'redeem' || points < minRedeem || ! isOn(site, 'point_redemption')}
                            className="flex items-center gap-2 rounded-xl bg-green-600 px-5 py-2.5 text-xs font-bold text-white shadow-md shadow-green-200 transition-transform active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-green-700 dark:shadow-none"
                        >
                            {busy === 'redeem' ? 'Redeeming…' : 'Redeem Now'} <ArrowRight className="h-3 w-3" />
                        </button>
                    </div>
                    {points < minRedeem && (
                        <p className="-mt-3 text-[10px] text-gray-400 dark:text-gray-500">Minimum redemption is {minRedeem} points.</p>
                    )}
                    <PausedNotice feature="point_redemption" />
                    <PausedNotice feature="daily_claim" />

                    <PausedNotice feature="spin" />
                    {/* Spin & Win lives on its own full-screen game page. */}
                    <Link
                        href="/rewards/spin"
                        className="group relative block overflow-hidden rounded-2xl bg-gradient-to-br from-purple-700 via-indigo-700 to-[#1e1b4b] p-5 text-white shadow-lg shadow-purple-500/25 transition-transform active:scale-[0.98]"
                    >
                        <div className="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-yellow-400/20 blur-2xl" />
                        <div className="relative z-10 flex items-center gap-4">
                            <MiniWheel odds={spinOdds} />
                            <div className="min-w-0 flex-1">
                                <p className="text-[10px] font-black uppercase tracking-[0.2em] text-yellow-300">Game</p>
                                <h3 className="text-xl font-black italic">Spin &amp; Win</h3>
                                <p className="text-xs text-purple-100">
                                    {spinCost} points a spin
                                    {spinOdds.length > 0 && ` · top prize ${Math.max(...spinOdds.map((o) => o.payout))} pts`}
                                </p>
                            </div>
                            <span className="flex items-center gap-1 rounded-full bg-gradient-to-b from-yellow-300 to-amber-500 px-4 py-2 text-sm font-black uppercase text-amber-950 shadow-[0_4px_0_#b45309]">
                                Play <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
                            </span>
                        </div>
                    </Link>

                    {/* Refer & Earn — real, working feature */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-orange-500 to-red-600 p-5 text-white shadow-lg shadow-orange-500/20 dark:from-orange-700 dark:to-red-800">
                        <div className="pointer-events-none absolute -bottom-4 -right-4 h-24 w-24 rounded-full bg-white/10 blur-xl" />

                        <div className="relative z-10 mb-3 flex items-center gap-2">
                            <Users className="h-4 w-4 text-yellow-300" />
                            <h3 className="text-lg font-bold">Refer & Earn</h3>
                        </div>

                        <p className="relative z-10 mb-3 max-w-[220px] text-xs text-orange-100">
                            Earn commission on your friend's first deposit when they sign up with your link.
                        </p>

                        {referralLink ? (
                            <div className="relative z-10 mb-3 flex items-center gap-2 rounded-xl border border-white/20 bg-black/20 px-3 py-2">
                                <p className="flex-1 truncate text-[11px] text-white/90">{referralLink}</p>
                                <button
                                    onClick={copyLink}
                                    className="flex items-center gap-1 rounded-lg bg-white/20 px-2 py-1 text-[10px] font-bold uppercase"
                                >
                                    {copied ? <CheckCircle2 className="h-3 w-3" /> : <Copy className="h-3 w-3" />}
                                    {copied ? 'Copied' : 'Copy'}
                                </button>
                            </div>
                        ) : (
                            <Link
                                href={`/login?redirect=${encodeURIComponent('/rewards')}`}
                                className="relative z-10 mb-3 flex items-center justify-center rounded-xl border border-white/20 bg-black/20 px-3 py-2 text-[11px] font-bold text-white/90"
                            >
                                Log in to get your referral link
                            </Link>
                        )}

                        {referral && (
                            <div className="relative z-10 grid grid-cols-3 gap-2 border-t border-white/10 pt-3 text-center">
                                <div>
                                    <p className="text-sm font-bold">{referral.referral_count}</p>
                                    <p className="text-[9px] text-orange-100">Referred</p>
                                </div>
                                <div>
                                    <p className="text-sm font-bold">{referral.pending_count}</p>
                                    <p className="text-[9px] text-orange-100">Pending</p>
                                </div>
                                <div>
                                    <p className="text-sm font-bold">{formatNaira(referral.total_earned)}</p>
                                    <p className="text-[9px] text-orange-100">Earned</p>
                                </div>
                            </div>
                        )}
                    </div>

                    <PausedNotice feature="tasks" />
                    {/* Quick Tasks */}
                    <div>
                        <h3 className="mb-1 flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
                            <Zap className="h-4 w-4 text-app-primary" /> Quick Tasks
                        </h3>
                        <p className="mb-3 text-[11px] text-gray-400 dark:text-gray-500">
                            Tap <span className="font-bold">Go</span>, do the task, then come back and tap <span className="font-bold">Claim</span>.
                        </p>
                        <div className="space-y-3">
                            {tasks.map((task) => {
                                const label = TASK_LABELS[task.task_id] ?? { title: task.task_id, desc: '', icon: Zap };
                                const Icon = label.icon;

                                return (
                                    <div
                                        key={task.task_id}
                                        className="flex items-center justify-between rounded-2xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-dark-card"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-app-primary dark:bg-blue-900/30">
                                                <Icon className="h-4 w-4" />
                                            </div>
                                            <div>
                                                <p className="text-sm font-bold text-gray-900 dark:text-white">{label.title}</p>
                                                <p className="text-[11px] text-gray-400 dark:text-gray-500">
                                                    +{task.points} points{label.desc ? ` · ${label.desc}` : ''}
                                                </p>
                                            </div>
                                        </div>

                                        <TaskAction
                                            task={task}
                                            isGuest={isGuest}
                                            loginUrl={loginUrl}
                                            busy={busy === task.task_id}
                                            readyAt={taskReadyAt[task.task_id]}
                                            now={now}
                                            onGo={() => goTask(task.task_id)}
                                            onClaim={() => claimTask(task.task_id)}
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>

            <ResultModal modal={modal} onClose={() => setModal(null)} />

            <BottomNav />
        </>
    );
}

function TaskAction({ task, isGuest, loginUrl, busy, readyAt, now, onGo, onClaim }) {
    const button = 'rounded-lg px-3 py-1.5 text-xs font-bold active:scale-95 disabled:opacity-60';

    if (task.completed) {
        return (
            <span className="flex flex-shrink-0 items-center gap-1 text-xs font-bold text-green-600 dark:text-green-400">
                <CheckCircle2 className="h-4 w-4" /> {task.repeatable ? 'Done today' : 'Done'}
            </span>
        );
    }

    if (isGuest) {
        return (
            <Link href={loginUrl} className={`${button} flex-shrink-0 bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200`}>
                Log in
            </Link>
        );
    }

    if (! task.needs_visit) {
        return (
            <button onClick={onClaim} disabled={busy} className={`${button} flex-shrink-0 bg-gray-900 text-white dark:bg-white dark:text-gray-900`}>
                {busy ? 'Checking…' : 'Turn on'}
            </button>
        );
    }

    if (! readyAt) {
        return (
            <button onClick={onGo} disabled={busy} className={`${button} flex flex-shrink-0 items-center gap-1 bg-app-primary text-white`}>
                {busy ? 'Opening…' : <>Go <ExternalLink className="h-3 w-3" /></>}
            </button>
        );
    }

    const secondsLeft = Math.ceil((readyAt - now) / 1000);

    return (
        <div className="flex flex-shrink-0 items-center gap-2">
            {secondsLeft > 0 ? (
                <span className={`${button} bg-gray-100 font-mono text-gray-500 dark:bg-gray-800 dark:text-gray-400`}>Claim in {secondsLeft}s</span>
            ) : (
                <button onClick={onClaim} disabled={busy} className={`${button} animate-soft-bounce bg-green-600 text-white`}>
                    {busy ? 'Claiming…' : 'Claim'}
                </button>
            )}
            <button onClick={onGo} disabled={busy} className="text-[10px] font-bold text-app-primary underline" aria-label="Open the link again">
                Open again
            </button>
        </div>
    );
}

// A small, slowly turning picture of the real wheel for the "Play" card.
function MiniWheel({ odds }) {
    const colors = { loss: '#475569', tie: '#8b5cf6', win: '#16a34a', jackpot: '#facc15' };
    const total = odds.reduce((sum, o) => sum + o.probability, 0) || 1;
    let cursor = 0;
    const stops = odds
        .map((o) => {
            const from = (cursor / total) * 360;
            cursor += o.probability;
            return `${colors[o.outcome] ?? '#2563eb'} ${from}deg ${(cursor / total) * 360}deg`;
        })
        .join(', ');

    return (
        <div className="relative h-16 w-16 flex-shrink-0">
            <div
                className="h-full w-full animate-[spin_8s_linear_infinite] rounded-full border-4 border-yellow-300 shadow-[0_0_18px_rgba(250,204,21,0.5)]"
                style={{ background: stops ? `conic-gradient(${stops})` : '#6d28d9' }}
            />
            <span className="absolute left-1/2 top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-amber-200 bg-white" />
            <span className="absolute -top-1 left-1/2 h-0 w-0 -translate-x-1/2 border-x-[6px] border-t-[9px] border-x-transparent border-t-red-600" />
        </div>
    );
}

const TIER_STYLES = {
    bronze: 'from-amber-700 to-orange-800',
    silver: 'from-slate-400 to-slate-600',
    gold: 'from-yellow-400 to-amber-500',
    diamond: 'from-cyan-400 to-blue-600',
};

// Raffle Rules Engine: the customer's loyalty tier, what it gives, and
// exactly what the next tier still needs.
function LoyaltyCard({ loyalty }) {
    const { tier, next, active_weeks: weeks, tickets, window_weeks: windowWeeks } = loyalty;
    const weeksPct = next ? Math.min(100, Math.round((weeks / Math.max(1, next.min_active_weeks)) * 100)) : 100;
    const ticketsPct = next ? Math.min(100, Math.round((tickets / Math.max(1, next.min_tickets)) * 100)) : 100;

    return (
        <div className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-dark-card">
            <div className={`flex items-center gap-3 bg-gradient-to-r ${TIER_STYLES[tier.key] ?? TIER_STYLES.bronze} p-4 text-white`}>
                <div className="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-white/20 text-lg font-black">
                    {tier.name.charAt(0)}
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-[10px] font-bold uppercase tracking-wider text-white/80">Loyalty tier</p>
                    <h3 className="text-lg font-black">{tier.name}</h3>
                </div>
                {tier.bonus_entries > 0 && (
                    <span className="rounded-full bg-white/20 px-3 py-1 text-[11px] font-bold">
                        +{tier.bonus_entries} bonus {tier.bonus_entries === 1 ? 'entry' : 'entries'}
                    </span>
                )}
            </div>
            <div className="space-y-3 p-4 text-xs text-gray-600 dark:text-gray-300">
                <p>
                    In the last {windowWeeks} weeks you played in <strong>{weeks}</strong> {weeks === 1 ? 'week' : 'weeks'} and bought{' '}
                    <strong>{tickets}</strong> {tickets === 1 ? 'ticket' : 'tickets'}.
                </p>
                {next ? (
                    <>
                        <p className="font-bold text-gray-900 dark:text-white">
                            Next: {next.name}
                            {next.bonus_entries > 0 && ` (+${next.bonus_entries} free bonus ${next.bonus_entries === 1 ? 'entry' : 'entries'} in bonus raffles)`}
                        </p>
                        <Progress label={`Weeks played: ${weeks} of ${next.min_active_weeks}`} pct={weeksPct} />
                        <Progress label={`Tickets: ${tickets} of ${next.min_tickets}`} pct={ticketsPct} />
                    </>
                ) : (
                    <p className="font-bold text-gray-900 dark:text-white">You're at the top tier. Keep playing each week to stay here.</p>
                )}
                <p className="text-[11px] text-gray-400">
                    Play a little each week to move up. Bonus entries are free extra chances in raffles marked for them, shown on each raffle's "How this draw works".
                </p>
            </div>
        </div>
    );
}

function Progress({ label, pct }) {
    return (
        <div>
            <p className="mb-1 text-[11px]">{label}</p>
            <div className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                <div className="h-full rounded-full bg-app-primary transition-all" style={{ width: `${pct}%` }} />
            </div>
        </div>
    );
}
