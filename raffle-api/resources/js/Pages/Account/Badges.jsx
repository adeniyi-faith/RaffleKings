import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Check, Lock, Pin } from 'lucide-react';
import PageTop from '../../Components/ui/PageTop';
import LoadError from '../../Components/ui/LoadError';
import { apiPost } from '../../lib/api';
import { useApi } from '../../lib/useApi';
import BottomNav from '../../Components/layout/BottomNav';

// Phase 11: every badge, earned or still to earn, and up to three pinned
// to the customer's profile.
export default function Badges() {
    const { data, loading, failed, reload } = useApi('/api/badges');
    const [pinned, setPinned] = useState([]);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [privacy, setPrivacy] = useState(null);

    useEffect(() => {
        if (data) {
            setPinned(data.badges.filter((b) => b.pinned).map((b) => b.key));
            setPrivacy(data.privacy);
        }
    }, [data]);

    const badges = data?.badges ?? [];
    const earned = badges.filter((b) => b.earned_at);
    const max = data?.showcase_size ?? 3;

    function toggle(key) {
        setSaved(false);
        setPinned((current) => (current.includes(key) ? current.filter((k) => k !== key) : current.length < max ? [...current, key] : current));
    }

    // Who can see my public profile, and whether my wins show on it. Saves as soon as it changes.
    async function changePrivacy(change) {
        const before = privacy;
        setPrivacy({ ...privacy, ...change });
        try {
            const result = await apiPost('/api/badges/privacy', change);
            setPrivacy(result.privacy);
        } catch {
            setPrivacy(before);
        }
    }

    async function save() {
        setSaving(true);
        try {
            await apiPost('/api/badges/showcase', { badges: pinned });
            setSaved(true);
        } finally {
            setSaving(false);
        }
    }

    return (
        <>
            <Head title="My Badges" />
            <div className="min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <PageTop title="My Badges" back="/profile" subtitle={data ? `${earned.length} of ${badges.length} collected` : null} />

                <div className="space-y-4 p-5">
                    {failed && <LoadError onRetry={reload} />}

                    {loading && ! data && (
                        <div className="grid grid-cols-3 gap-3">
                            {Array.from({ length: 9 }).map((_, i) => (
                                <div key={i} className="h-32 animate-pulse rounded-2xl bg-white dark:bg-dark-card" />
                            ))}
                        </div>
                    )}

                    {data && (
                        <>
                            <div className="rounded-2xl bg-gradient-to-r from-fuchsia-600 to-indigo-600 p-4 text-white shadow-lg">
                                <p className="text-xs font-bold uppercase tracking-wider text-white/80">Profile showcase</p>
                                <p className="mt-1 text-sm">
                                    {earned.length === 0
                                        ? `You haven't earned a badge yet. Each badge below says how to earn it. Once you have one, tap it to put it in a slot here.`
                                        : `Tap up to ${max} of your badges below to show them on your profile, then tap Save.`}
                                </p>
                                <div className="mt-3 flex items-center gap-2">
                                    {Array.from({ length: max }).map((_, i) => {
                                        const b = badges.find((x) => x.key === pinned[i]);
                                        return (
                                            <span
                                                key={i}
                                                aria-label={b ? `Slot ${i + 1}: ${b.name}` : `Slot ${i + 1}: empty`}
                                                className={`flex h-12 w-12 items-center justify-center rounded-full text-2xl ${
                                                    b ? 'bg-white/25' : 'border-2 border-dashed border-white/50 text-sm font-bold text-white/70'
                                                }`}
                                            >
                                                {b ? b.emoji : i + 1}
                                            </span>
                                        );
                                    })}
                                    <button
                                        onClick={save}
                                        disabled={saving || earned.length === 0}
                                        className="ml-auto flex items-center gap-1 rounded-full bg-white px-4 py-2 text-xs font-bold text-indigo-700 shadow active:scale-95 disabled:opacity-60"
                                    >
                                        {saved ? <Check className="h-4 w-4" /> : <Pin className="h-4 w-4" />}
                                        {saving ? 'Saving…' : saved ? 'Saved' : 'Save'}
                                    </button>
                                </div>
                            </div>

                            {privacy && (
                                <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                                    <p className="text-sm font-bold text-gray-900 dark:text-white">Your public profile</p>
                                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Other players can open a card with your username, picture and badges. It never shows money, your real name, phone or email.
                                    </p>
                                    <div className="mt-3 grid grid-cols-2 gap-2">
                                        {[['everyone', 'Everyone can see it'], ['private', 'Only me']].map(([value, label]) => (
                                            <button
                                                key={value}
                                                onClick={() => changePrivacy({ visibility: value })}
                                                aria-pressed={privacy.visibility === value}
                                                className={`rounded-xl border px-3 py-2 text-xs font-bold ${privacy.visibility === value ? 'border-fuchsia-500 bg-fuchsia-50 text-fuchsia-700 dark:bg-fuchsia-900/20 dark:text-fuchsia-300' : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300'}`}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                    <label className="mt-3 flex items-start gap-2 text-xs text-gray-700 dark:text-gray-300">
                                        <input type="checkbox" checked={privacy.show_wins} onChange={(e) => changePrivacy({ show_wins: e.target.checked })} className="mt-0.5" />
                                        <span>Show my wins on my profile (the win badges and how many times I've won). Off by default.</span>
                                    </label>
                                    {privacy.visibility === 'everyone' && (
                                        <Link href={privacy.path} className="mt-3 inline-block text-xs font-bold text-app-primary">
                                            See my public profile
                                        </Link>
                                    )}
                                </div>
                            )}

                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                Grey badges are locked. The line under each one tells you what to do to earn it.
                            </p>

                            <div className="grid grid-cols-3 gap-3">
                                {badges.map((b) => {
                                    const isEarned = !! b.earned_at;
                                    const isPinned = pinned.includes(b.key);
                                    return (
                                        <button
                                            key={b.key}
                                            onClick={() => isEarned && toggle(b.key)}
                                            disabled={! isEarned}
                                            className={`relative flex flex-col items-center rounded-2xl border p-3 text-center transition-transform ${
                                                isEarned
                                                    ? `bg-white shadow-sm active:scale-95 dark:bg-dark-card ${isPinned ? 'border-fuchsia-500 ring-2 ring-fuchsia-500/40' : 'border-gray-100 dark:border-gray-800'}`
                                                    : 'border-dashed border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-dark-bg'
                                            }`}
                                        >
                                            {isPinned && <Pin className="absolute right-2 top-2 h-3 w-3 text-fuchsia-500" />}
                                            <span className={`text-3xl ${isEarned ? '' : 'opacity-30 grayscale'}`}>{b.emoji}</span>
                                            <span className={`mt-1 text-[11px] font-bold leading-tight ${isEarned ? 'text-gray-900 dark:text-white' : 'text-gray-400'}`}>{b.name}</span>
                                            <span className="mt-0.5 text-[9px] leading-tight text-gray-400">
                                                {isEarned ? new Date(b.earned_at).toLocaleDateString() : (
                                                    <span className="inline-flex items-center gap-0.5"><Lock className="h-2.5 w-2.5" /> {b.description}</span>
                                                )}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </>
                    )}
                </div>
            </div>
            <BottomNav />
        </>
    );
}
