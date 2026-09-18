import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    Coins,
    Copy,
    Sparkles,
    Users,
    Zap,
} from 'lucide-react';
import { formatNaira } from '../../lib/format';
import { usePushPermission } from '../../hooks/usePushPermission';
import BottomNav from '../../Components/layout/BottomNav';

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
const TASK_LABELS = {
    push_notification: { title: 'Enable Notifications', desc: 'Turn on push alerts', icon: Zap },
    join_community: { title: 'Join our Community', desc: 'Follow our official group', icon: Users },
    whatsapp_follow: { title: 'Follow on WhatsApp', desc: 'Follow our WhatsApp channel', icon: Zap },
    whatsapp_share: { title: 'Share on WhatsApp', desc: 'Share with your friends (daily)', icon: Zap },
};

export default function RewardsIndex({ referralCode }) {
    const [state, setState] = useState(null);
    const [referral, setReferral] = useState(null);
    const [busy, setBusy] = useState(null); // id of whatever action is in flight
    const [modal, setModal] = useState(null); // { title, message }
    const [copied, setCopied] = useState(false);
    const { requestPermission } = usePushPermission();

    const referralLink = `${window.location.origin}/register?ref=${encodeURIComponent(referralCode)}`;

    useEffect(() => {
        loadState();
        fetch('/api/referrals/stats', { credentials: 'same-origin' })
            .then((res) => (res.ok ? res.json() : null))
            .then(setReferral)
            .catch(() => setReferral(null));
    }, []);

    function loadState() {
        fetch('/api/rewards/state', { credentials: 'same-origin' })
            .then((res) => (res.ok ? res.json() : null))
            .then(setState)
            .catch(() => setState(null));
    }

    async function post(url) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));

        if (! response.ok) {
            throw new Error(data.message || 'Something went wrong. Please try again.');
        }

        return data;
    }

    async function claimDaily() {
        setBusy('daily');
        try {
            const result = await post('/api/rewards/daily-claim');
            setModal({ title: 'Streak Claimed!', message: `You earned ${result.points_added} points. Day ${result.new_streak} streak.` });
            loadState();
        } catch (err) {
            setModal({ title: 'Already Claimed', message: err.message });
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
                        title: 'Notifications not enabled',
                        message: 'Please allow notifications in your browser to claim this reward.',
                    });
                    return;
                }
            }

            const result = await post(`/api/rewards/tasks/${taskId}/claim`);
            setModal({ title: 'Task Complete!', message: `You earned ${result.points_added} points.` });
            loadState();
        } catch (err) {
            setModal({ title: 'Could not claim this task', message: err.message });
        } finally {
            setBusy(null);
        }
    }

    async function spin() {
        setBusy('spin');
        try {
            const result = await post('/api/rewards/spin');
            setModal({
                title: result.payout > 0 ? `You won ${result.payout} points!` : 'No win this time',
                message: `Outcome: ${result.outcome}. New balance: ${result.new_balance} points.`,
            });
            loadState();
        } catch (err) {
            setModal({ title: 'Could not spin', message: err.message });
        } finally {
            setBusy(null);
        }
    }

    async function redeem() {
        setBusy('redeem');
        try {
            const result = await post('/api/rewards/redeem');
            setModal({
                title: 'Redeemed!',
                message: `${result.redeemed_points} points converted to ${formatNaira(result.wallet_added)} in your wallet.`,
            });
            loadState();
        } catch (err) {
            setModal({ title: 'Could not redeem', message: err.message });
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

    const points = state?.points ?? 0;
    const streak = state?.streak ?? 0;
    const claimedToday = state?.is_claimed_today ?? false;
    const schedule = state?.daily_schedule ?? [];
    const tasks = state?.tasks ?? [];
    const spinOdds = state?.spin?.odds ?? [];
    const spinCost = state?.spin?.cost ?? 50;

    return (
        <>
            <Head title="Rewards" />
            <div className="relative min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="relative overflow-hidden bg-blue-900 px-5 pb-16 pt-4 dark:bg-blue-950">
                    <div className="pointer-events-none absolute right-0 top-0 h-64 w-64 -translate-y-1/2 translate-x-1/2 rounded-full bg-white/5 blur-3xl" />

                    <div className="relative z-10 mb-6 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <button onClick={() => window.history.back()} className="-ml-1 p-1 text-white/70 hover:text-white">
                                <ArrowLeft className="h-6 w-6" />
                            </button>
                            <h2 className="text-xl font-bold text-white">Rewards</h2>
                        </div>

                        <div className="flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1.5 backdrop-blur-md">
                            <Coins className="h-4 w-4 fill-current text-yellow-400" />
                            <span className="text-sm font-bold text-white">{points} Pts</span>
                        </div>
                    </div>

                    <div className="relative z-10 flex justify-between gap-2">
                        {schedule.map((reward, i) => {
                            const day = i + 1;
                            const isDone = day < streak || (day === streak && claimedToday);
                            const isToday = day === streak && ! claimedToday;

                            return (
                                <button
                                    key={day}
                                    onClick={isToday ? claimDaily : undefined}
                                    disabled={! isToday || busy === 'daily'}
                                    className={`flex-1 rounded-xl border py-2 text-center transition-transform ${
                                        isToday
                                            ? 'scale-105 border-yellow-400 bg-yellow-400/20 active:scale-95'
                                            : isDone
                                              ? 'border-white/20 bg-white/10'
                                              : 'border-white/10 bg-white/5 opacity-60'
                                    }`}
                                >
                                    <p className="text-[9px] font-bold uppercase text-blue-200">Day {day}</p>
                                    <p className="text-xs font-bold text-white">{reward}</p>
                                    {isDone && <CheckCircle2 className="mx-auto mt-0.5 h-3 w-3 text-green-400" />}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div className="relative z-20 -mt-6 space-y-5 px-5">
                    {/* Redeem card */}
                    <div className="flex items-center justify-between rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                        <div>
                            <p className="text-[10px] font-bold uppercase text-gray-400 dark:text-gray-500">Wallet Value</p>
                            <h3 className="text-xl font-bold text-gray-900 dark:text-white">{formatNaira(points / 10)}</h3>
                            <p className="text-[10px] text-green-600 dark:text-green-400">Rate: 10 Pts = ₦1</p>
                        </div>
                        <button
                            onClick={redeem}
                            disabled={busy === 'redeem' || points < 100}
                            className="flex items-center gap-2 rounded-xl bg-green-600 px-5 py-2.5 text-xs font-bold text-white shadow-md shadow-green-200 transition-transform active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-green-700 dark:shadow-none"
                        >
                            {busy === 'redeem' ? 'Redeeming…' : 'Redeem Now'} <ArrowRight className="h-3 w-3" />
                        </button>
                    </div>
                    {points < 100 && (
                        <p className="-mt-3 text-[10px] text-gray-400 dark:text-gray-500">Minimum redemption is 100 points.</p>
                    )}

                    {/* Spin & Win — real, working feature */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-r from-purple-600 to-indigo-600 p-5 text-white shadow-lg shadow-purple-500/20 dark:from-purple-800 dark:to-indigo-900">
                        <div className="pointer-events-none absolute right-0 top-0 h-32 w-32 -translate-y-1/2 translate-x-1/2 rounded-full bg-white/10 blur-2xl" />

                        <div className="relative z-10 flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-white/20 shadow-md backdrop-blur-md">
                                    <span className="text-2xl">🎰</span>
                                </div>
                                <div>
                                    <h3 className="text-lg font-bold text-white">Spin & Win</h3>
                                    <p className="text-xs text-purple-100">Costs {spinCost} points a spin</p>
                                </div>
                            </div>

                            <button
                                onClick={spin}
                                disabled={busy === 'spin' || points < spinCost}
                                className="flex items-center gap-1.5 rounded-full border border-white/20 bg-black/30 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-white backdrop-blur-md transition-transform active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <Sparkles className="h-3 w-3" /> {busy === 'spin' ? 'Spinning…' : 'Spin'}
                            </button>
                        </div>

                        {spinOdds.length > 0 && (
                            <div className="relative z-10 mt-4 grid grid-cols-4 gap-2 border-t border-white/10 pt-3">
                                {spinOdds.map((o) => (
                                    <div key={o.outcome} className="text-center">
                                        <p className="text-xs font-bold text-white">{o.payout}pt</p>
                                        <p className="text-[9px] text-purple-200">{Math.round(o.probability * 100)}%</p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

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

                    {/* Quick Tasks */}
                    <div>
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
                            <Zap className="h-4 w-4 text-app-primary" /> Quick Tasks
                        </h3>
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

                                        {task.completed ? (
                                            <span className="flex items-center gap-1 text-xs font-bold text-green-600 dark:text-green-400">
                                                <CheckCircle2 className="h-4 w-4" /> {task.repeatable ? 'Done today' : 'Done'}
                                            </span>
                                        ) : (
                                            <button
                                                onClick={() => claimTask(task.task_id)}
                                                disabled={busy === task.task_id}
                                                className="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-bold text-white active:scale-95 dark:bg-white dark:text-gray-900"
                                            >
                                                {busy === task.task_id ? 'Claiming…' : 'Claim'}
                                            </button>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>

            {modal && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-5 backdrop-blur-sm">
                    <div className="w-full max-w-sm rounded-3xl border border-gray-100 bg-white p-6 text-center dark:border-gray-800 dark:bg-dark-card">
                        <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                            <CheckCircle2 className="h-8 w-8 text-green-600 dark:text-green-400" />
                        </div>
                        <h2 className="mb-1 text-xl font-bold text-gray-900 dark:text-white">{modal.title}</h2>
                        <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">{modal.message}</p>
                        <button
                            onClick={() => setModal(null)}
                            className="w-full rounded-xl bg-gray-900 py-3 text-sm font-bold text-white dark:bg-white dark:text-gray-900"
                        >
                            Awesome
                        </button>
                    </div>
                </div>
            )}

            <BottomNav />
        </>
    );
}
