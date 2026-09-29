import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Check, CheckCircle2, Copy, Gift, Share2, Ticket, UserPlus, Users } from 'lucide-react';
import PageTop from '../../Components/ui/PageTop';
import LoadError from '../../Components/ui/LoadError';
import { formatNaira } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { useSite } from '../../lib/site';
import { track } from '../../lib/analytics';
import BottomNav from '../../Components/layout/BottomNav';

// Phase 11: the full referral page — your link, the ladder of rewards for
// friends who join and play, your commission, and the friends you invited.
export default function ReferralsIndex({ ladder = [], commissionPercent = 0 }) {
    const { auth } = usePage().props;
    const site = useSite();
    const user = auth?.user;
    const { data, failed, reload } = useApi('/api/referrals/overview', { enabled: !! user });
    const [copied, setCopied] = useState(false);

    const rungs = data?.rungs ?? ladder;
    const playing = data?.friends_playing ?? 0;
    const top = rungs.length ? rungs[rungs.length - 1].friends : 1;
    const next = data?.next ?? null;
    const shareText = data ? `Join me on ${site.name || 'RaffleKings'} and let's win together! Sign up with my link: ${data.link}` : '';

    function copy() {
        track('referral_link_shared', { method: 'copy', from: 'referrals' });
        navigator.clipboard?.writeText(data.link).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    function share() {
        track('referral_link_shared', { method: navigator.share ? 'share_sheet' : 'whatsapp', from: 'referrals' });
        if (navigator.share) {
            navigator.share({ title: site.name, text: shareText }).catch(() => {});
        } else {
            window.open(`https://wa.me/?text=${encodeURIComponent(shareText)}`, '_blank');
        }
    }

    return (
        <>
            <Head title="Invite Friends" />
            <div className="min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <PageTop title="Invite Friends" subtitle="Earn together" back="/rewards" />

                <div className="relative overflow-hidden bg-gradient-to-br from-orange-500 via-red-500 to-pink-600 px-5 pb-16 pt-6 text-white">
                    <div className="pointer-events-none absolute -right-10 -top-10 h-48 w-48 rounded-full bg-white/10 blur-2xl" />
                    <p className="text-xs font-bold uppercase tracking-widest text-yellow-200">Refer and earn</p>
                    <h1 className="mt-1 text-2xl font-black leading-tight">Invite friends. Get rewards when they play.</h1>
                    <p className="mt-2 max-w-sm text-sm text-white/90">
                        A friend counts when they sign up with your link and buy at least one ticket. The more friends count, the bigger your reward. You also get {commissionPercent}% of each friend's first top-up, added to your winnings balance.
                    </p>

                    {user ? (
                        data && (
                            <div className="mt-4 space-y-2">
                                <div className="flex items-center gap-2 rounded-xl border border-white/20 bg-black/20 px-3 py-2">
                                    <p className="flex-1 truncate text-xs">{data.link}</p>
                                    <button onClick={copy} className="flex items-center gap-1 rounded-lg bg-white/20 px-2 py-1 text-[10px] font-bold uppercase">
                                        {copied ? <CheckCircle2 className="h-3 w-3" /> : <Copy className="h-3 w-3" />} {copied ? 'Copied' : 'Copy'}
                                    </button>
                                </div>
                                <div className="grid grid-cols-2 gap-2">
                                    <a
                                        href={`https://wa.me/?text=${encodeURIComponent(shareText)}`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="flex items-center justify-center gap-2 rounded-xl bg-green-500 py-3 text-sm font-bold shadow-lg active:scale-95"
                                    >
                                        Share on WhatsApp
                                    </a>
                                    <button onClick={share} className="flex items-center justify-center gap-2 rounded-xl bg-white py-3 text-sm font-bold text-red-600 shadow-lg active:scale-95">
                                        <Share2 className="h-4 w-4" /> More ways
                                    </button>
                                </div>
                                <p className="text-center text-[11px] text-white/80">Your code: <span className="font-mono font-bold">{data.code}</span></p>
                            </div>
                        )
                    ) : (
                        <Link href={`/login?redirect=${encodeURIComponent('/referrals')}`} className="mt-4 inline-block rounded-xl bg-white px-5 py-3 text-sm font-bold text-red-600 shadow-lg">
                            Log in to get your link
                        </Link>
                    )}
                </div>

                <div className="relative z-10 -mt-10 space-y-4 px-5">
                    {failed && <LoadError onRetry={reload} />}

                    {data && (
                        <div className="grid grid-cols-3 gap-2 rounded-2xl bg-white p-4 text-center shadow-sm dark:bg-dark-card">
                            <Stat icon={UserPlus} value={data.friends_joined} label="Joined" />
                            <Stat icon={Ticket} value={data.friends_playing} label="Playing" />
                            <Stat icon={Gift} value={formatNaira(data.commission_earned)} label="Commission" />
                        </div>
                    )}

                    {/* The ladder */}
                    <div className="rounded-2xl bg-white p-5 shadow-sm dark:bg-dark-card">
                        <h3 className="mb-1 text-sm font-bold text-gray-900 dark:text-white">Your rewards</h3>
                        <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">
                            {next
                                ? `${next.friends - playing} more ${next.friends - playing === 1 ? 'friend' : 'friends'} playing to reach the next reward.`
                                : user && data
                                  ? 'You have earned every reward. Well done!'
                                  : 'You get these rewards when friends sign up with your link and buy at least one ticket.'}
                        </p>
                        <div className="mb-5 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div className="h-full rounded-full bg-gradient-to-r from-orange-500 to-pink-500 transition-all" style={{ width: `${Math.min(100, (playing / top) * 100)}%` }} />
                        </div>
                        <ol className="space-y-3">
                            {rungs.map((r) => (
                                <li key={r.friends} className="flex items-center gap-3">
                                    <span
                                        className={`flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full text-sm font-black ${
                                            r.reached ? 'bg-green-500 text-white' : 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-300'
                                        }`}
                                    >
                                        {r.reached ? <Check className="h-5 w-5" /> : r.friends}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-bold text-gray-900 dark:text-white">
                                            {r.friends} {r.friends === 1 ? 'friend' : 'friends'} playing
                                        </p>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">{r.reward}</p>
                                    </div>
                                    {r.reached && <span className="text-[10px] font-bold uppercase text-green-600">Earned</span>}
                                </li>
                            ))}
                        </ol>
                    </div>

                    {/* How it works */}
                    <div className="rounded-2xl bg-white p-5 shadow-sm dark:bg-dark-card">
                        <h3 className="mb-3 text-sm font-bold text-gray-900 dark:text-white">How it works</h3>
                        <ol className="space-y-3 text-xs text-gray-600 dark:text-gray-300">
                            <Step n="1" text="Share your link with friends (WhatsApp, Telegram, anywhere)." />
                            <Step n="2" text="They sign up with it. When they add money for the first time, you get a share of it." />
                            <Step n="3" text="When they buy their first ticket, they count as playing. Rewards are added to your account automatically." />
                        </ol>
                        <p className="mt-3 text-[11px] text-gray-400">Friends must be 18 or older and use their own account. Rewards for fake or duplicate accounts are removed.</p>
                    </div>

                    {/* Friends */}
                    {data && (
                        <div className="rounded-2xl bg-white p-5 shadow-sm dark:bg-dark-card">
                            <h3 className="mb-3 flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
                                <Users className="h-4 w-4" /> Your friends
                            </h3>
                            {data.friends.length === 0 ? (
                                <p className="py-4 text-center text-xs text-gray-500 dark:text-gray-400">No friends yet. Share your link to get started!</p>
                            ) : (
                                <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                                    {data.friends.map((f, i) => (
                                        <li key={i} className="flex items-center justify-between py-2.5">
                                            <div>
                                                <p className="text-sm font-bold text-gray-900 dark:text-white">{f.name}</p>
                                                <p className="text-[11px] text-gray-400">Joined {f.joined ? new Date(f.joined).toLocaleDateString() : ''}</p>
                                            </div>
                                            <div className="text-right">
                                                <span
                                                    className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${
                                                        f.playing ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'
                                                    }`}
                                                >
                                                    {f.playing ? 'Playing' : 'Not played yet'}
                                                </span>
                                                {f.commission > 0 && <p className="mt-0.5 text-[10px] text-green-600">+{formatNaira(f.commission)}</p>}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </div>
            </div>
            <BottomNav />
        </>
    );
}

function Stat({ icon: Icon, value, label }) {
    return (
        <div>
            <Icon className="mx-auto mb-1 h-4 w-4 text-orange-500" />
            <p className="text-sm font-black text-gray-900 dark:text-white">{value}</p>
            <p className="text-[10px] text-gray-500 dark:text-gray-400">{label}</p>
        </div>
    );
}

function Step({ n, text }) {
    return (
        <li className="flex gap-3">
            <span className="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-orange-500 text-[11px] font-bold text-white">{n}</span>
            <span className="pt-0.5">{text}</span>
        </li>
    );
}
