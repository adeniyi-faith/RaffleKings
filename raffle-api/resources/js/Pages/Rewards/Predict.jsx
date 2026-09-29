import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, CheckCircle2, Clock, Coins, XCircle } from 'lucide-react';
import LoadError from '../../Components/ui/LoadError';
import PausedNotice from '../../Components/layout/PausedNotice';
import { apiPost } from '../../lib/api';
import { useApi } from '../../lib/useApi';

// Phase 11: daily predictions — free to answer; a right answer earns points
// and Season Pass XP once the question is settled.
function timeLeft(iso) {
    const ms = new Date(iso).getTime() - Date.now();
    if (ms <= 0) return 'Closed';
    const h = Math.floor(ms / 3600000);
    const m = Math.floor((ms % 3600000) / 60000);

    return h >= 24 ? `${Math.floor(h / 24)}d ${h % 24}h left` : `${h}h ${m}m left`;
}

export default function Predict({ preview }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const { data, setData, failed, reload } = useApi('/api/predictions', { enabled: !! user });
    const board = user ? data : preview;
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState(null);

    async function answer(q, option) {
        if (! user || q.your_answer !== null || busy) return;
        setBusy(q.id);
        setError(null);
        try {
            const updated = await apiPost(`/api/predictions/${q.id}/answer`, { option });
            setData((d) => ({ ...d, open: d.open.map((x) => (x.id === q.id ? updated : x)) }));
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(null);
        }
    }

    return (
        <>
            <Head title="Daily Predictions" />
            <div className="min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="relative overflow-hidden bg-gradient-to-br from-emerald-600 via-teal-600 to-cyan-700 px-5 pb-14 pt-4 text-white">
                    <div className="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl" />
                    <button onClick={() => window.history.back()} className="relative z-10 -ml-1 p-1 text-white/70 hover:text-white" aria-label="Back">
                        <ArrowLeft className="h-6 w-6" />
                    </button>
                    <p className="relative z-10 mt-2 text-xs font-bold uppercase tracking-[0.2em] text-emerald-100">Free to play</p>
                    <h1 className="relative z-10 text-2xl font-black">🔮 Daily Predictions</h1>
                    <p className="relative z-10 mt-1 max-w-xs text-sm text-white/90">Call it right, earn points. New questions every day.</p>
                    {user && board && (
                        <p className="relative z-10 mt-3 inline-flex items-center gap-1 rounded-full bg-black/20 px-3 py-1 text-xs font-bold">
                            <CheckCircle2 className="h-3.5 w-3.5" /> {board.correct_total} right so far
                        </p>
                    )}
                </div>

                <div className="relative z-10 -mt-8 space-y-4 px-5">
                    <PausedNotice feature="predictions" />
                    {failed && <LoadError onRetry={reload} />}
                    {error && <p role="alert" className="rounded-xl bg-red-50 px-4 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">{error}</p>}

                    {board && board.open.length === 0 && (
                        <div className="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-dark-card">
                            <p className="text-3xl">⏳</p>
                            <p className="mt-2 text-sm font-bold text-gray-900 dark:text-white">No questions open right now</p>
                            <p className="text-xs text-gray-500 dark:text-gray-400">Check back soon: new ones arrive every day.</p>
                        </div>
                    )}

                    {board?.open.map((q) => {
                        const answered = q.your_answer !== null;
                        return (
                            <div key={q.id} className="rounded-2xl bg-white p-4 shadow-sm dark:bg-dark-card">
                                <div className="mb-2 flex items-center justify-between text-[11px]">
                                    <span className="rounded-full bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{q.category_label}</span>
                                    <span className="flex items-center gap-1 text-gray-400">
                                        <Clock className="h-3 w-3" /> {timeLeft(q.closes_at)}
                                    </span>
                                </div>
                                <p className="mb-3 text-base font-bold text-gray-900 dark:text-white">{q.question}</p>
                                <div className="space-y-2">
                                    {q.options.map((opt, i) => {
                                        const mine = q.your_answer === i;
                                        const pct = q.crowd ? q.crowd[i] : null;
                                        return (
                                            <button
                                                key={i}
                                                onClick={() => answer(q, i)}
                                                disabled={answered || ! user || busy === q.id}
                                                className={`relative w-full overflow-hidden rounded-xl border px-4 py-3 text-left text-sm font-bold transition-transform ${
                                                    mine
                                                        ? 'border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200'
                                                        : 'border-gray-200 text-gray-800 dark:border-gray-700 dark:text-gray-200'
                                                } ${! answered && user ? 'active:scale-[0.98] hover:border-emerald-400' : ''}`}
                                            >
                                                {pct !== null && (
                                                    <span className="absolute inset-y-0 left-0 bg-emerald-500/10" style={{ width: `${pct}%` }} aria-hidden="true" />
                                                )}
                                                <span className="relative flex items-center justify-between gap-2">
                                                    <span className="flex items-center gap-2">
                                                        {mine && <Check className="h-4 w-4" />} {opt}
                                                    </span>
                                                    {pct !== null && <span className="text-xs text-gray-500 dark:text-gray-400">{pct}%</span>}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                                <p className="mt-3 flex items-center gap-1 text-[11px] text-gray-500 dark:text-gray-400">
                                    <Coins className="h-3 w-3 text-yellow-500" /> Right answer: +{q.points} points.{' '}
                                    {answered ? 'Locked in. Results after it closes.' : user ? 'One answer, no changes.' : ''}
                                </p>
                            </div>
                        );
                    })}

                    {! user && (
                        <Link href={`/login?redirect=${encodeURIComponent('/rewards/predict')}`} className="block rounded-2xl bg-emerald-600 py-3.5 text-center text-sm font-bold text-white shadow-lg">
                            Log in to play
                        </Link>
                    )}

                    {board?.history?.length > 0 && (
                        <div className="rounded-2xl bg-white p-4 shadow-sm dark:bg-dark-card">
                            <h3 className="mb-2 text-sm font-bold text-gray-900 dark:text-white">Your recent results</h3>
                            <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                                {board.history.map((h, i) => (
                                    <li key={i} className="flex items-start gap-3 py-2.5">
                                        {h.is_correct ? <CheckCircle2 className="mt-0.5 h-4 w-4 flex-shrink-0 text-green-500" /> : <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0 text-red-400" />}
                                        <div className="min-w-0 flex-1">
                                            <p className="text-xs font-bold text-gray-900 dark:text-white">{h.question}</p>
                                            <p className="text-[11px] text-gray-500 dark:text-gray-400">
                                                You said {h.your_answer}. {h.is_correct ? `+${h.points} points` : `Answer: ${h.right_answer}`}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
