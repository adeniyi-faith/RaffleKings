import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Clock, Users } from 'lucide-react';
import { apiPost } from '../../lib/api';
import { formatNaira } from '../../lib/format';
import { rememberTeam, shareLink } from '../../lib/share';

// Phase 11: the page a friend opens from a Team Up invite.
export default function TeamPage({ team: initial, referrer, hasTicket }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const [team, setTeam] = useState(initial);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const here = `/team/${team.code}`;
    const open = ! team.completed && ! team.expired;
    const ms = new Date(team.expires_at).getTime() - Date.now();
    const left = ms > 0 ? `${Math.floor(ms / 3600000)}h ${Math.floor((ms % 3600000) / 60000)}m left` : 'Time up';

    async function join() {
        setBusy(true);
        setError(null);
        try {
            setTeam(await apiPost(`/api/teams/${team.code}/join`, {}));
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }

    function getTicket() {
        rememberTeam(team.code, team.raffle.id);
        router.visit(`/raffles/${team.raffle.id}`);
    }

    return (
        <>
            <Head title={`Join ${team.captain}'s team`} />
            <div className="flex min-h-screen flex-col items-center bg-gradient-to-b from-sky-600 via-indigo-700 to-violet-900 px-5 pb-16 pt-10 text-white">
                <div className="flex h-20 w-20 items-center justify-center rounded-full bg-white/20 text-4xl shadow-xl">{team.completed ? '🏆' : '🤜'}</div>
                <h1 className="mt-4 text-center text-2xl font-black">
                    {team.completed ? 'This team is full!' : team.is_member ? 'Your team' : `Join ${team.captain}'s team`}
                </h1>
                {team.raffle && (
                    <p className="mt-1 text-center text-sm text-white/90">
                        in <b>{team.raffle.title}</b>
                        {team.raffle.grand_prize ? ` (win ${team.raffle.grand_prize})` : ''}
                    </p>
                )}
                {open && (
                    <p className="mt-2 flex items-center gap-1 rounded-full bg-black/25 px-3 py-1 text-xs font-bold">
                        <Clock className="h-3 w-3" /> {left}
                    </p>
                )}

                <div className="mt-6 grid w-full max-w-sm grid-cols-1 gap-2">
                    {Array.from({ length: team.size }).map((_, i) => {
                        const m = team.members[i];
                        return (
                            <div key={i} className={`flex items-center gap-3 rounded-2xl px-4 py-3 ${m ? 'bg-white text-indigo-800' : 'border border-dashed border-white/40 text-white/70'}`}>
                                <Users className="h-4 w-4" />
                                <span className="text-sm font-bold">{m ? `${m.is_captain ? '🧢 ' : ''}${m.is_you ? 'You' : m.name}${m.is_captain ? ' (captain)' : ''}` : 'Open slot'}</span>
                            </div>
                        );
                    })}
                </div>

                <p className="mt-4 max-w-sm text-center text-xs text-white/80">
                    When all {team.size} places fill, everyone gets {team.bonus_entries} free bonus {team.bonus_entries === 1 ? 'entry' : 'entries'} in the draw. Each person needs their own ticket
                    {team.raffle ? ` (from ${formatNaira(team.raffle.price)})` : ''}.
                </p>

                <div className="mt-6 w-full max-w-sm space-y-3">
                    {error && <p role="alert" className="rounded-xl bg-black/25 px-4 py-2 text-center text-sm">{error}</p>}

                    {team.is_member && open && (
                        <button onClick={() => shareLink('Join my team! If our team fills, we all get a free bonus entry:', window.location.href)} className="w-full rounded-2xl bg-white py-4 text-base font-black text-indigo-700 shadow-xl active:scale-95">
                            Invite friends
                        </button>
                    )}

                    {! team.is_member && open && (
                        ! user ? (
                            <>
                                <Link href={`/register?ref=${encodeURIComponent(referrer || '')}&redirect=${encodeURIComponent(here)}`} className="block w-full rounded-2xl bg-white py-4 text-center text-base font-black text-indigo-700 shadow-xl">
                                    Sign up free to join
                                </Link>
                                <Link href={`/login?redirect=${encodeURIComponent(here)}`} className="block text-center text-sm font-bold text-white/90 underline">
                                    I already have an account
                                </Link>
                            </>
                        ) : hasTicket ? (
                            <button onClick={join} disabled={busy} className="w-full rounded-2xl bg-white py-4 text-base font-black text-indigo-700 shadow-xl active:scale-95 disabled:opacity-60">
                                {busy ? 'Joining…' : 'Join the team'}
                            </button>
                        ) : (
                            <button onClick={getTicket} className="w-full rounded-2xl bg-white py-4 text-base font-black text-indigo-700 shadow-xl active:scale-95">
                                Get a ticket and join
                            </button>
                        )
                    )}

                    {! open && ! team.is_member && team.raffle && ! team.raffle.is_closed && (
                        <Link href={`/raffles/${team.raffle.id}`} className="block rounded-2xl border border-white/40 py-3 text-center text-sm font-bold">
                            Start your own team in this raffle
                        </Link>
                    )}
                </div>
            </div>
        </>
    );
}
