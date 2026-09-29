import { useEffect, useState } from 'react';
import { Check, Clock, Copy, Lock, Share2, Unlock, Users } from 'lucide-react';
import { apiPost } from '../../lib/api';
import { useApi } from '../../lib/useApi';
import { forgetTeam, pendingTeam, shareLink, teamInviteText, unlockInviteText, whatsappUrl } from '../../lib/share';
import { isOn, useSite } from '../../lib/site';

// Phase 11: "Help me unlock" and Team Up for one raffle, shown on the raffle
// page and right after a purchase. Both give free bonus entries only.
export default function BoostPanel({ raffleId, raffleTitle, compact = false }) {
    const site = useSite();
    const { data, setData, reload } = useApi(`/api/raffles/${raffleId}/boosts`);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(null);

    // Joining a team the customer was invited to before buying their ticket.
    useEffect(() => {
        const code = pendingTeam(raffleId);
        if (! data || ! code || data.team || ! data.has_tickets) return;
        forgetTeam();
        apiPost(`/api/teams/${code}/join`, {})
            .then((team) => setData((d) => ({ ...d, team })))
            .catch((e) => setError(e.message));
    }, [data, raffleId, setData]);

    if (! data) return null;

    async function run(key, url, apply) {
        setBusy(key);
        setError(null);
        try {
            const result = await apiPost(url, {});
            setData((d) => ({ ...d, [apply]: result }));
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(null);
        }
    }

    const unlockOn = isOn(site, 'unlock_links');
    const teamOn = isOn(site, 'team_up');
    if (! unlockOn && ! teamOn) return null;

    return (
        <div className={`space-y-3 text-left ${compact ? '' : ''}`}>
            {error && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300">{error}</p>}
            {unlockOn && <UnlockCard data={data} raffleTitle={raffleTitle} busy={busy === 'unlock'} onCreate={() => run('unlock', `/api/raffles/${raffleId}/unlock`, 'unlock')} onRefresh={reload} />}
            {teamOn && <TeamCard data={data} raffleTitle={raffleTitle} busy={busy === 'team'} onCreate={() => run('team', `/api/raffles/${raffleId}/team`, 'team')} />}
        </div>
    );
}

function Dots({ done, total }) {
    return (
        <div className="flex gap-1.5">
            {Array.from({ length: total }).map((_, i) => (
                <span key={i} className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-black ${i < done ? 'bg-yellow-300 text-amber-900' : 'bg-white/20 text-white/70'}`}>
                    {i < done ? <Check className="h-4 w-4" /> : i + 1}
                </span>
            ))}
        </div>
    );
}

// `text` is the whole invite message, link included.
function ShareRow({ text }) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="mt-3 grid grid-cols-3 gap-2">
            <a href={whatsappUrl(text)} target="_blank" rel="noreferrer" className="col-span-1 rounded-xl bg-green-500 py-2.5 text-center text-xs font-bold text-white">
                WhatsApp
            </a>
            <button onClick={() => shareLink(text)} className="flex items-center justify-center gap-1 rounded-xl bg-white/20 py-2.5 text-xs font-bold text-white">
                <Share2 className="h-3.5 w-3.5" /> Share
            </button>
            <button
                onClick={() => navigator.clipboard?.writeText(text).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); })}
                className="flex items-center justify-center gap-1 rounded-xl bg-white/20 py-2.5 text-xs font-bold text-white"
            >
                {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />} {copied ? 'Copied' : 'Copy'}
            </button>
        </div>
    );
}

function UnlockCard({ data, raffleTitle, busy, onCreate }) {
    const link = data.unlock;
    const needed = link?.needed ?? data.unlock_needed;

    return (
        <div className="rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 p-4 text-white shadow-lg shadow-orange-500/20">
            <div className="flex items-center gap-2">
                {link?.completed ? <Unlock className="h-5 w-5" /> : <Lock className="h-5 w-5" />}
                <h3 className="text-sm font-black">{link?.completed ? 'Unlocked! Free bonus entry added' : 'Unlock a free bonus entry'}</h3>
            </div>
            {! link?.completed && (
                <p className="mt-1 text-xs text-white/90">
                    Share your link. When {needed} friends open it and tap Help, you get {data.bonus_entries} free extra {data.bonus_entries === 1 ? 'entry' : 'entries'} in this draw. Each friend who taps gets points too.
                </p>
            )}
            {link ? (
                <>
                    <div className="mt-3">
                        <Dots done={link.taps} total={link.needed} />
                    </div>
                    {! link.completed && <ShareRow text={unlockInviteText({ prize: link.raffle?.grand_prize, raffleTitle, url: link.url, points: link.tapper_points })} />}
                </>
            ) : data.has_tickets ? (
                <button onClick={onCreate} disabled={busy} className="mt-3 w-full rounded-xl bg-white py-2.5 text-sm font-black text-orange-600 shadow active:scale-95 disabled:opacity-60">
                    {busy ? 'Getting your link…' : 'Get my unlock link'}
                </button>
            ) : (
                <p className="mt-2 rounded-lg bg-black/15 px-3 py-2 text-[11px]">Buy at least one ticket in this raffle to get your link.</p>
            )}
        </div>
    );
}

function TeamCard({ data, raffleTitle, busy, onCreate }) {
    const team = data.team;
    const [now, setNow] = useState(Date.now());

    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 60000);
        return () => clearInterval(id);
    }, []);

    const msLeft = team ? new Date(team.expires_at).getTime() - now : 0;
    const left = msLeft > 0 ? `${Math.floor(msLeft / 3600000)}h ${Math.floor((msLeft % 3600000) / 60000)}m left` : 'Time up';

    return (
        <div className="rounded-2xl bg-gradient-to-br from-sky-600 to-indigo-700 p-4 text-white shadow-lg shadow-indigo-500/20">
            <div className="flex items-center gap-2">
                <Users className="h-5 w-5" />
                <h3 className="text-sm font-black">{team?.completed ? 'Team full! Bonus entries added' : 'Team Up'}</h3>
                {team && ! team.completed && ! team.expired && (
                    <span className="ml-auto flex items-center gap-1 rounded-full bg-black/20 px-2 py-0.5 text-[10px] font-bold">
                        <Clock className="h-3 w-3" /> {left}
                    </span>
                )}
            </div>
            {! team?.completed && (
                <p className="mt-1 text-xs text-white/90">
                    Start a team and invite friends. Each person needs their own ticket in this raffle. If {data.team_size} people (you included) join within {data.team_hours} hours, everyone gets {data.team_bonus_entries} free extra {data.team_bonus_entries === 1 ? 'entry' : 'entries'}.
                </p>
            )}
            {team ? (
                <>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {Array.from({ length: team.size }).map((_, i) => {
                            const m = team.members[i];
                            return (
                                <span key={i} className={`rounded-full px-3 py-1 text-[11px] font-bold ${m ? 'bg-white text-indigo-700' : 'border border-dashed border-white/40 text-white/60'}`}>
                                    {m ? `${m.is_captain ? '🧢 ' : ''}${m.is_you ? 'You' : m.name}` : 'Open slot'}
                                </span>
                            );
                        })}
                    </div>
                    {team.expired && <p className="mt-2 text-[11px] text-white/80">Time ran out before the team was full. Your tickets still count in the draw.</p>}
                    {! team.completed && ! team.expired && <ShareRow text={teamInviteText({ prize: team.raffle?.grand_prize, raffleTitle, url: team.url, bonusEntries: team.bonus_entries ?? data.team_bonus_entries })} />}
                </>
            ) : data.has_tickets ? (
                <button onClick={onCreate} disabled={busy} className="mt-3 w-full rounded-xl bg-white py-2.5 text-sm font-black text-indigo-700 shadow active:scale-95 disabled:opacity-60">
                    {busy ? 'Starting…' : 'Start a team'}
                </button>
            ) : (
                <p className="mt-2 rounded-lg bg-black/15 px-3 py-2 text-[11px]">Buy at least one ticket in this raffle to start or join a team.</p>
            )}
        </div>
    );
}
