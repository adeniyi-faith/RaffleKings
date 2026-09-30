import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Check, Coins, Gift, Lock, Unlock as UnlockIcon } from 'lucide-react';
import { apiPost } from '../../lib/api';
import { shareLink, unlockInviteText } from '../../lib/share';
import PlayerName from '../../Components/ui/PlayerName';
import SimpleTop from '../../Components/layout/SimpleTop';

// Phase 11: the page a friend opens from a "help me unlock" link.
export default function UnlockPage({ link: initial, referrer, alreadyTapped }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const [link, setLink] = useState(initial);
    const [done, setDone] = useState(alreadyTapped);
    const [points, setPoints] = useState(0);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const isOwner = user && user.id === link.owner_id;
    const here = `/u/${link.code}`;

    async function tap() {
        setBusy(true);
        setError(null);
        try {
            const result = await apiPost(`/api/unlock/${link.code}/tap`, {});
            setLink(result.link);
            setPoints(result.points);
            setDone(true);
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={`Help ${link.owner} unlock a free entry`} />
            <div className="flex min-h-screen flex-col items-center bg-gradient-to-b from-amber-500 via-orange-600 to-red-700 px-5 pb-16 text-white">
                <div className="-mx-5 mb-6 w-[calc(100%+2.5rem)]">
                    <SimpleTop onDark back="/raffles" />
                </div>
                <div className="flex h-20 w-20 items-center justify-center rounded-full bg-white/20 text-4xl shadow-xl">{link.completed ? '🔓' : '🔒'}</div>
                <h1 className="mt-4 text-center text-2xl font-black">
                    {link.completed ? `${link.owner} unlocked it!` : isOwner ? 'Your unlock link' : `Help ${link.owner} unlock a free entry`}
                </h1>
                {! isOwner && link.owner_profile && (
                    <p className="mt-1 text-center text-xs text-white/80">
                        Link from <PlayerName name={link.owner} profile={link.owner_profile} className="font-bold" />
                    </p>
                )}
                {link.raffle && (
                    <p className="mt-1 text-center text-sm text-white/90">
                        in <b>{link.raffle.title}</b>
                        {link.raffle.grand_prize ? ` (win ${link.raffle.grand_prize})` : ''}
                    </p>
                )}

                <div className="mt-6 flex gap-2">
                    {Array.from({ length: link.needed }).map((_, i) => (
                        <span key={i} className={`flex h-12 w-12 items-center justify-center rounded-full text-lg font-black ${i < link.taps ? 'bg-yellow-300 text-amber-900' : 'bg-white/20'}`}>
                            {i < link.taps ? <Check className="h-6 w-6" /> : <Lock className="h-5 w-5 text-white/70" />}
                        </span>
                    ))}
                </div>
                <p className="mt-2 text-xs text-white/80">
                    {link.taps} of {link.needed} friends have tapped
                </p>
                {! link.completed && (
                    <p className="mt-3 max-w-sm text-center text-xs text-white/80">
                        {isOwner
                            ? `Share this page. When ${link.needed} friends tap Help, you get a free extra entry in this raffle. Each friend who taps gets ${link.tapper_points} points.`
                            : `Tapping is free. It gives ${link.owner} a step towards a free extra entry, and gives you ${link.tapper_points} points.`}
                    </p>
                )}

                <div className="mt-8 w-full max-w-sm space-y-3">
                    {error && <p role="alert" className="rounded-xl bg-black/25 px-4 py-2 text-center text-sm">{error}</p>}

                    {done && (
                        <div className="rounded-2xl bg-white p-4 text-center text-gray-900 shadow-xl">
                            <p className="text-lg font-black">Thanks for helping! 🙌</p>
                            {points > 0 && (
                                <p className="mt-1 flex items-center justify-center gap-1 text-sm text-gray-600">
                                    <Coins className="h-4 w-4 text-yellow-500" /> +{points} points for you
                                </p>
                            )}
                        </div>
                    )}

                    {isOwner ? (
                        ! link.completed && (
                            <button
                                onClick={() => shareLink(unlockInviteText({ prize: link.raffle?.grand_prize, raffleTitle: link.raffle?.title, url: `${window.location.origin}${here}`, points: link.tapper_points }))}
                                className="w-full rounded-2xl bg-white py-4 text-base font-black text-orange-600 shadow-xl active:scale-95"
                            >
                                Share with friends
                            </button>
                        )
                    ) : ! user ? (
                        <>
                            <Link
                                href={`/register?ref=${encodeURIComponent(referrer || '')}&redirect=${encodeURIComponent(here)}`}
                                className="block w-full rounded-2xl bg-white py-4 text-center text-base font-black text-orange-600 shadow-xl"
                            >
                                Sign up free to help (+{link.tapper_points} points)
                            </Link>
                            <Link href={`/login?redirect=${encodeURIComponent(here)}`} className="block text-center text-sm font-bold text-white/90 underline">
                                I already have an account
                            </Link>
                        </>
                    ) : (
                        ! done &&
                        ! link.completed && (
                            <button onClick={tap} disabled={busy} className="flex w-full items-center justify-center gap-2 rounded-2xl bg-white py-4 text-base font-black text-orange-600 shadow-xl active:scale-95 disabled:opacity-60">
                                <UnlockIcon className="h-5 w-5" /> {busy ? 'Tapping…' : `Tap to help (+${link.tapper_points} points)`}
                            </button>
                        )
                    )}

                    {link.raffle && ! link.raffle.is_closed && ! isOwner && (
                        <Link href={`/raffles/${link.raffle.id}`} className="flex items-center justify-center gap-2 rounded-2xl border border-white/40 py-3 text-sm font-bold">
                            <Gift className="h-4 w-4" /> Get your own free entry: join this raffle
                        </Link>
                    )}
                </div>
            </div>
        </>
    );
}
