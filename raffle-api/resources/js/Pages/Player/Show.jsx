import { useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Award, Calendar, Flame, Lock, Ticket, Trophy } from 'lucide-react';
import PageTop from '../../Components/ui/PageTop';
import BottomNav from '../../Components/layout/BottomNav';
import { resolveAvatar } from '../../lib/avatar';
import { track } from '../../lib/analytics';

// A player's public card: username, picture and badges. Never money or private details.
// A private profile and a username that doesn't exist look the same on purpose.
export default function PlayerShow({ profile, is_you: isYou = false }) {
    useEffect(() => {
        if (profile) track('player_profile_viewed', { is_own: isYou });
    }, [profile?.username]);

    if (! profile) {
        return (
            <>
                <Head title="Player profile">
                    <meta name="robots" content="noindex" />
                </Head>
                <div className="min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                    <PageTop title="Player profile" back="/" />
                    <div className="mx-auto mt-16 max-w-sm px-6 text-center">
                        <Lock className="mx-auto h-10 w-10 text-gray-300" />
                        <h1 className="mt-4 text-lg font-bold text-gray-900 dark:text-white">This profile is private or doesn't exist</h1>
                        <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Players choose who can see their profile.</p>
                        <Link href="/raffles" className="mt-6 inline-block rounded-full bg-app-primary px-5 py-2.5 text-sm font-bold text-white">Browse raffles</Link>
                    </div>
                </div>
                <BottomNav />
            </>
        );
    }

    return (
        <>
            <Head title={`${profile.username} on RaffleKings`}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <PageTop title="Player profile" back="/" />

                <div className="space-y-4 p-5">
                    <div className="rounded-3xl bg-gradient-to-br from-fuchsia-600 to-indigo-600 p-6 text-center text-white shadow-lg">
                        <img
                            src={resolveAvatar({ avatar: profile.avatar, name: profile.username })}
                            alt=""
                            className="mx-auto h-24 w-24 rounded-full border-4 border-white/40 bg-gray-100 object-cover"
                        />
                        <h1 className="mt-3 break-all text-xl font-black">{profile.username}</h1>
                        {profile.member_since && (
                            <p className="mt-1 flex items-center justify-center gap-1 text-xs text-white/80">
                                <Calendar className="h-3 w-3" /> Member since {profile.member_since}
                            </p>
                        )}
                        {profile.showcase.length > 0 && (
                            <div className="mt-4 flex items-center justify-center gap-3">
                                {profile.showcase.map((b) => (
                                    <span key={b.key} title={b.name} className="flex h-14 w-14 items-center justify-center rounded-full bg-white/25 text-3xl">{b.emoji}</span>
                                ))}
                            </div>
                        )}
                    </div>

                    {isYou && (
                        <p className="rounded-xl bg-blue-50 p-3 text-xs text-blue-800 dark:bg-blue-900/20 dark:text-blue-200">
                            This is how other players see you.{' '}
                            <Link href="/account/badges" className="font-bold underline">Change who can see it</Link>
                        </p>
                    )}

                    <div className={`grid gap-3 text-center ${profile.wins !== null ? 'grid-cols-2' : 'grid-cols-3'}`}>
                        <Stat icon={Ticket} value={profile.raffles_entered} label="Raffles played" />
                        <Stat icon={Award} value={`${profile.badges.length}/${profile.badge_total}`} label="Badges" />
                        <Stat icon={Flame} value={profile.streak} label="Day streak" />
                        {profile.wins !== null && <Stat icon={Trophy} value={profile.wins} label="Wins" />}
                    </div>

                    <h2 className="pt-2 text-sm font-bold text-gray-900 dark:text-white">Badges earned</h2>
                    {profile.badges.length === 0 ? (
                        <p className="text-sm text-gray-500 dark:text-gray-400">No badges yet.</p>
                    ) : (
                        <div className="grid grid-cols-3 gap-3">
                            {profile.badges.map((b) => (
                                <div key={b.key} className="flex flex-col items-center rounded-2xl border border-gray-100 bg-white p-3 text-center shadow-sm dark:border-gray-800 dark:bg-dark-card">
                                    <span className="text-3xl">{b.emoji}</span>
                                    <span className="mt-1 text-[11px] font-bold leading-tight text-gray-900 dark:text-white">{b.name}</span>
                                    <span className="mt-0.5 text-[9px] leading-tight text-gray-400">{b.description}</span>
                                </div>
                            ))}
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
        <div className="rounded-2xl bg-white p-3 shadow-sm dark:bg-dark-card">
            <Icon className="mx-auto h-4 w-4 text-fuchsia-500" />
            <p className="mt-1 text-lg font-black text-gray-900 dark:text-white">{value}</p>
            <p className="text-[10px] text-gray-500 dark:text-gray-400">{label}</p>
        </div>
    );
}
