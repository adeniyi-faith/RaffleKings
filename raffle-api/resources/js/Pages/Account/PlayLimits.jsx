import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Coffee, HeartHandshake, ShieldCheck } from 'lucide-react';
import PageTop from '../../Components/ui/PageTop';
import BottomNav from '../../Components/layout/BottomNav';
import LoadError from '../../Components/ui/LoadError';
import { apiPost } from '../../lib/api';
import { useApi } from '../../lib/useApi';
import { formatNaira } from '../../lib/format';

// Responsible play (Phase 10, item 38): the customer's own spending limits
// and "take a break" (self-exclusion). The rules live on the server
// (ResponsiblePlayService); this page only explains and edits them.
const PERIODS = [
    { key: 'daily', label: 'Daily limit', when: 'today' },
    { key: 'weekly', label: 'Weekly limit', when: 'this week (Mon to Sun)' },
    { key: 'monthly', label: 'Monthly limit', when: 'this month' },
];

const BREAK_LABELS = { 1: '1 day', 7: '7 days', 30: '30 days', 180: '6 months', 365: '1 year' };

const dateTime = (iso) => new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });

export default function PlayLimits() {
    const { data, setData, failed, reload } = useApi('/api/play-limits');
    const [form, setForm] = useState({ daily: '', weekly: '', monthly: '' });
    const [notice, setNotice] = useState(null);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [breakDays, setBreakDays] = useState(null);

    useEffect(() => {
        if (data) {
            setForm(Object.fromEntries(PERIODS.map(({ key }) => [key, data.limits[key] ?? ''])));
        }
    }, [data]);

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setError(null);
        setNotice(null);
        try {
            const body = Object.fromEntries(PERIODS.map(({ key }) => [key, form[key] === '' ? null : Number(form[key])]));
            const result = await apiPost('/api/play-limits', body);
            setData(result.state);
            setNotice(result.message);
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    async function startBreak() {
        setSaving(true);
        setError(null);
        try {
            const result = await apiPost('/api/play-limits/break', { days: breakDays, confirm: true });
            setData(result.state);
            setBreakDays(null);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <>
            <Head title="Play Limits & Breaks" />
            <div className="min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <PageTop title="Play Limits & Breaks" subtitle="Stay in control of your play" back="/profile" />

                <div className="space-y-5 p-5">
                    {failed && <LoadError onRetry={reload} />}

                    {data?.excluded_until && (
                        <div className="rounded-2xl border border-teal-200 bg-teal-50 p-4 text-sm text-teal-900 dark:border-teal-800 dark:bg-teal-900/20 dark:text-teal-100">
                            <p className="flex items-center gap-2 font-bold">
                                <Coffee className="h-4 w-4" /> You are on a break until {dateTime(data.excluded_until)}
                            </p>
                            <p className="mt-1 text-xs">
                                Until then you can't buy tickets, top up, spin or claim offers. You can still{' '}
                                <Link href="/account/withdraw" className="font-bold underline">withdraw your money</Link> and{' '}
                                <Link href="/support" className="font-bold underline">contact support</Link>.
                            </p>
                        </div>
                    )}

                    {/* Spending limits */}
                    <form onSubmit={save} className="rounded-2xl bg-white p-5 shadow-sm dark:bg-dark-card">
                        <h3 className="flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
                            <HeartHandshake className="h-4 w-4 text-teal-600" /> Spending limits
                        </h3>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Choose the most you want to spend on tickets. Leave a box empty for no limit.
                        </p>
                        <ul className="mt-2 list-disc space-y-0.5 pl-4 text-[11px] text-gray-500 dark:text-gray-400">
                            <li>Lowering a limit works straight away.</li>
                            <li>Raising or removing a limit starts after 24 hours, so you have time to think it over.</li>
                        </ul>

                        <div className="mt-4 space-y-4">
                            {PERIODS.map(({ key, label, when }) => (
                                <div key={key}>
                                    <label htmlFor={`limit-${key}`} className="mb-1 block text-xs font-bold text-gray-700 dark:text-gray-300">
                                        {label}
                                    </label>
                                    <div className="relative">
                                        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">₦</span>
                                        <input
                                            id={`limit-${key}`}
                                            type="number"
                                            inputMode="numeric"
                                            min="100"
                                            step="100"
                                            placeholder="No limit"
                                            value={form[key]}
                                            onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))}
                                            className="w-full rounded-xl border border-gray-200 bg-gray-50 py-3 pl-8 pr-3 text-sm font-bold text-gray-900 outline-none focus:border-teal-500 dark:border-gray-700 dark:bg-dark-bg dark:text-white"
                                        />
                                    </div>
                                    {data && (
                                        <p className="mt-1 text-[11px] text-gray-400">
                                            Spent {when}: {formatNaira(data.spent[key])}
                                            {data.limits[key] != null && ` of ${formatNaira(data.limits[key])}`}
                                            {data.pending && key in data.pending && (
                                                <span className="font-bold text-amber-600">
                                                    {' '}· Changes to {data.pending[key] == null ? 'no limit' : formatNaira(data.pending[key])} on {dateTime(data.pending_from)}
                                                </span>
                                            )}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>

                        {notice && <p className="mt-4 rounded-xl bg-green-50 px-3 py-2 text-xs font-bold text-green-700 dark:bg-green-900/20 dark:text-green-300">{notice}</p>}
                        {error && ! breakDays && <p role="alert" className="mt-4 rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700 dark:bg-red-900/20 dark:text-red-300">{error}</p>}

                        <button disabled={saving || ! data} className="mt-4 w-full rounded-xl bg-teal-600 py-3 text-sm font-bold text-white shadow active:scale-[0.98] disabled:opacity-60">
                            {saving ? 'Saving…' : 'Save limits'}
                        </button>
                    </form>

                    {/* Take a break */}
                    <div className="rounded-2xl bg-white p-5 shadow-sm dark:bg-dark-card">
                        <h3 className="flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
                            <Coffee className="h-4 w-4 text-teal-600" /> Take a break
                        </h3>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Stop yourself from playing for a while. During a break you can't buy tickets, top up, spin or claim offers. You can still withdraw
                            your money and contact support.
                        </p>
                        <p className="mt-1 text-xs font-bold text-gray-700 dark:text-gray-300">A break can't be ended early, so choose carefully.</p>

                        <div className="mt-3 flex flex-wrap gap-2">
                            {(data?.break_days ?? [1, 7, 30, 180, 365]).map((d) => (
                                <button
                                    key={d}
                                    type="button"
                                    onClick={() => { setBreakDays(d); setError(null); }}
                                    className={`rounded-full px-4 py-2 text-xs font-bold ${breakDays === d ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'}`}
                                >
                                    {BREAK_LABELS[d] ?? `${d} days`}
                                </button>
                            ))}
                        </div>

                        {breakDays && (
                            <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-100">
                                <p className="font-bold">Start a {BREAK_LABELS[breakDays]} break now?</p>
                                <p className="mt-1">You won't be able to play again until it ends, even if you change your mind.</p>
                                {error && <p role="alert" className="mt-2 font-bold text-red-700 dark:text-red-300">{error}</p>}
                                <div className="mt-3 grid grid-cols-2 gap-2">
                                    <button type="button" onClick={() => setBreakDays(null)} className="rounded-lg bg-white py-2 font-bold text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                        Cancel
                                    </button>
                                    <button type="button" onClick={startBreak} disabled={saving} className="rounded-lg bg-teal-600 py-2 font-bold text-white disabled:opacity-60">
                                        {saving ? 'Starting…' : 'Yes, start my break'}
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="rounded-2xl bg-white p-5 text-xs text-gray-600 shadow-sm dark:bg-dark-card dark:text-gray-300">
                        <h3 className="mb-2 flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
                            <ShieldCheck className="h-4 w-4 text-teal-600" /> Play for fun
                        </h3>
                        <ul className="list-disc space-y-1 pl-4">
                            <li>Only spend money you can afford to lose. Raffles are a game of chance.</li>
                            <li>Each raffle page shows your chance of winning before you buy.</li>
                            <li>You must be 18 or older to play.</li>
                            <li>
                                Worried about your play? <Link href="/support?new=1" className="font-bold text-teal-700 underline dark:text-teal-300">Talk to our support team</Link>.
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <BottomNav />
        </>
    );
}
