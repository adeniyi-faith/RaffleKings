import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Camera, Trophy, X } from 'lucide-react';
import PageTop from '../../Components/ui/PageTop';
import PausedNotice from '../../Components/layout/PausedNotice';
import { apiPost } from '../../lib/api';
import { useApi } from '../../lib/useApi';
import { isOn, useSite } from '../../lib/site';
import { resolveAvatar } from '../../lib/avatar';
import BottomNav from '../../Components/layout/BottomNav';

// Phase 11: the winner stories wall — real winners, real prizes. Winners
// post a photo or video; staff check it first; everyone can react.
export default function Stories({ wall, page }) {
    const { auth } = usePage().props;
    const site = useSite();
    const user = auth?.user;
    const [stories, setStories] = useState(wall.stories);
    const [composing, setComposing] = useState(false);
    const [sent, setSent] = useState(false);
    const { data: mine } = useApi('/api/stories/my-wins', { enabled: !! user });
    const canPost = (mine?.wins?.length ?? 0) > 0 && isOn(site, 'winner_stories');

    async function react(story, emoji) {
        if (! user) {
            window.location.href = `/login?redirect=${encodeURIComponent('/winners/stories')}`;
            return;
        }
        try {
            const result = await apiPost(`/api/stories/${story.id}/react`, { emoji });
            setStories((list) => list.map((s) => (s.id === story.id ? { ...s, counts: result.counts, yours: result.yours } : s)));
        } catch {
            // a reaction is best-effort
        }
    }

    return (
        <>
            <Head title="Winner Stories" />
            <div className="min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <PageTop
                    back="/hall-of-fame"
                    title="Winner Stories 📸"
                    subtitle="Real winners, real prizes"
                    right={
                        <Link href="/hall-of-fame" className="rounded-full bg-yellow-100 px-3 py-1 text-xs font-bold text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300">
                            Hall of Fame
                        </Link>
                    }
                />

                <div className="space-y-4 p-5">
                    <PausedNotice feature="winner_stories" />
                    {sent && (
                        <p className="rounded-2xl bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-300">
                            Thanks! We'll check your story and put it on the wall shortly. You'll get your thank-you points when it goes live.
                        </p>
                    )}
                    {canPost && ! sent && (
                        <button
                            onClick={() => setComposing(true)}
                            className="flex w-full items-center gap-3 rounded-2xl bg-gradient-to-r from-yellow-400 to-amber-500 p-4 text-left text-amber-950 shadow-lg shadow-yellow-500/20 active:scale-[0.98]"
                        >
                            <Camera className="h-6 w-6" />
                            <span>
                                <span className="block text-sm font-black">You're a winner! Share your story</span>
                                <span className="block text-xs">Post a photo or video with your prize and get points.</span>
                            </span>
                        </button>
                    )}

                    {stories.length === 0 && (
                        <div className="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-dark-card">
                            <Trophy className="mx-auto h-10 w-10 text-yellow-500" />
                            <p className="mt-2 text-sm font-bold text-gray-900 dark:text-white">No stories yet</p>
                            <p className="text-xs text-gray-500 dark:text-gray-400">Winners' photos and videos will appear here.</p>
                        </div>
                    )}

                    {stories.map((s) => (
                        <article key={s.id} className="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-dark-card">
                            <div className="flex items-center gap-3 p-3">
                                <img src={resolveAvatar({ avatar: s.avatar, name: s.name })} alt="" className="h-10 w-10 rounded-full bg-gray-100 object-cover" loading="lazy" />
                                <div className="min-w-0 flex-1">
                                    <p className="flex items-center gap-1 text-sm font-bold text-gray-900 dark:text-white">
                                        {s.name} {s.showcase.map((b) => <span key={b.key} title={b.name}>{b.emoji}</span>)}
                                    </p>
                                    <p className="truncate text-[11px] text-gray-500 dark:text-gray-400">
                                        Won {s.prize}
                                        {s.raffle ? ` · ${s.raffle}` : ''} · {s.posted_ago}
                                    </p>
                                </div>
                            </div>
                            {s.media_type === 'video' ? (
                                <video src={s.media_url} controls playsInline preload="metadata" className="max-h-[70vh] w-full bg-black" />
                            ) : (
                                <img src={s.media_url} alt={`${s.name} with their prize`} loading="lazy" className="max-h-[70vh] w-full bg-gray-100 object-contain dark:bg-black" />
                            )}
                            {s.caption && <p className="px-4 pt-3 text-sm text-gray-800 dark:text-gray-200">{s.caption}</p>}
                            <div className="flex gap-2 p-3">
                                {wall.reactions.map((emoji) => (
                                    <button
                                        key={emoji}
                                        onClick={() => react(s, emoji)}
                                        className={`flex items-center gap-1 rounded-full border px-3 py-1 text-sm transition-transform active:scale-90 ${
                                            s.yours === emoji ? 'border-yellow-400 bg-yellow-50 dark:bg-yellow-900/20' : 'border-gray-200 dark:border-gray-700'
                                        }`}
                                    >
                                        {emoji} <span className="text-xs font-bold text-gray-500 dark:text-gray-400">{s.counts[emoji] || ''}</span>
                                    </button>
                                ))}
                            </div>
                        </article>
                    ))}

                    <div className="flex justify-between">
                        {page > 1 ? <Link href={`/winners/stories?page=${page - 1}`} className="text-sm font-bold text-app-primary">← Newer</Link> : <span />}
                        {wall.has_more && <Link href={`/winners/stories?page=${page + 1}`} className="text-sm font-bold text-app-primary">Older →</Link>}
                    </div>
                </div>
            </div>

            {composing && <Composer wins={mine.wins} onClose={() => setComposing(false)} onSent={() => { setComposing(false); setSent(true); }} />}
            <BottomNav />
        </>
    );
}

function Composer({ wins, onClose, onSent }) {
    const [winnerId, setWinnerId] = useState(wins[0]?.id ?? '');
    const [caption, setCaption] = useState('');
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    async function submit(e) {
        e.preventDefault();
        if (! file) {
            setError('Choose a photo or video first.');
            return;
        }
        setBusy(true);
        setError(null);
        const body = new FormData();
        body.append('winner_id', winnerId);
        body.append('caption', caption);
        body.append('media', file);
        try {
            const res = await fetch('/api/stories', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body });
            const data = await res.json().catch(() => ({}));
            if (! res.ok) throw new Error(data.message || (res.status === 413 ? 'That file is too big.' : 'Could not post your story. Please try again.'));
            onSent();
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="fixed inset-0 z-[80] flex items-end justify-center bg-black/70 p-4 sm:items-center" onClick={onClose}>
            <form onSubmit={submit} onClick={(e) => e.stopPropagation()} className="w-full max-w-sm space-y-3 rounded-3xl bg-white p-5 shadow-2xl dark:bg-dark-card">
                <div className="flex items-center justify-between">
                    <h3 className="text-lg font-black text-gray-900 dark:text-white">Share your win 📸</h3>
                    <button type="button" onClick={onClose} aria-label="Close" className="text-gray-400">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <select value={winnerId} onChange={(e) => setWinnerId(e.target.value)} className="w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-dark-bg dark:text-white">
                    {wins.map((w) => (
                        <option key={w.id} value={w.id}>
                            {w.label}
                        </option>
                    ))}
                </select>
                <label className="block cursor-pointer rounded-xl border-2 border-dashed border-gray-300 p-4 text-center text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                    {file ? file.name : 'Tap to choose a photo or short video'}
                    <input type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" className="hidden" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                </label>
                <textarea
                    value={caption}
                    onChange={(e) => setCaption(e.target.value)}
                    maxLength={500}
                    rows={3}
                    placeholder="Say something about your win (optional)"
                    className="w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-dark-bg dark:text-white"
                />
                <p className="text-[11px] text-gray-400">Photos up to 5 MB, videos up to 20 MB. Please don't show bank details or your home address. We check every story before it goes live.</p>
                {error && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300">{error}</p>}
                <button disabled={busy} className="w-full rounded-2xl bg-app-primary py-3 text-sm font-black text-white disabled:opacity-60">
                    {busy ? 'Posting…' : 'Post my story'}
                </button>
            </form>
        </div>
    );
}
