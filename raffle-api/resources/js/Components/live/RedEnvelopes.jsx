import { useState } from 'react';
import { X } from 'lucide-react';
import { apiPost } from '../../lib/api';
import { refreshBalances } from '../../lib/balances';
import { isOn, useSite } from '../../lib/site';

// Phase 11: red envelopes in the live-draw chat. Anyone signed in can drop
// points for the room; the first people to tap split them at random.
export function EnvelopeStrip({ envelopes, setEnvelopes, signedIn }) {
    const [toast, setToast] = useState(null);
    const open = envelopes.filter((e) => e.claimed_count < e.slots && new Date(e.expires_at) > new Date());

    async function grab(e) {
        if (! signedIn) {
            window.location.href = `/login?redirect=${encodeURIComponent(window.location.pathname)}`;
            return;
        }
        if (e.your_points != null || e.is_yours) return;
        try {
            const result = await apiPost(`/api/envelopes/${e.id}/claim`, {});
            setEnvelopes((list) => list.map((x) => (x.id === e.id ? { ...x, your_points: result.points } : x)));
            setToast({ good: true, text: `🧧 +${result.points} points from ${e.sender}!` });
            refreshBalances(true);
        } catch (err) {
            setToast({ good: false, text: err.message });
        }
        setTimeout(() => setToast(null), 3000);
    }

    if (open.length === 0 && ! toast) return null;

    return (
        <div className="border-b border-white/5 px-3 py-2">
            {toast && (
                <p className={`mb-2 rounded-lg px-3 py-1.5 text-center text-xs font-bold ${toast.good ? 'bg-yellow-400 text-red-800' : 'bg-white/10 text-white/80'}`}>{toast.text}</p>
            )}
            <div className="no-scrollbar flex gap-2 overflow-x-auto">
                {open.map((e) => (
                    <button
                        key={e.id}
                        onClick={() => grab(e)}
                        className={`flex min-w-[150px] flex-shrink-0 items-center gap-2 rounded-xl border border-yellow-400/40 bg-gradient-to-br from-red-600 to-red-800 px-3 py-2 text-left shadow-lg ${
                            e.your_points == null && ! e.is_yours ? 'animate-soft-bounce' : 'opacity-70'
                        }`}
                    >
                        <span className="text-2xl">🧧</span>
                        <span className="min-w-0">
                            <span className="block truncate text-[11px] font-black text-yellow-200">{e.sender}</span>
                            <span className="block truncate text-[10px] text-white/80">
                                {e.your_points != null ? `You got ${e.your_points} pts` : e.is_yours ? 'Your envelope' : e.message || 'Tap to open!'}
                            </span>
                            <span className="block text-[9px] text-white/50">
                                {e.claimed_count}/{e.slots} opened
                            </span>
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}

export function SendEnvelope({ raffleId, onClose }) {
    const site = useSite();
    const [points, setPoints] = useState(100);
    const [slots, setSlots] = useState(5);
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    if (! isOn(site, 'red_envelopes')) return null;

    async function send(e) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await apiPost(`/api/raffles/${raffleId}/live-draw/envelopes`, { points, slots, message });
            refreshBalances(true);
            onClose();
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    }

    const chip = (active) => `rounded-full px-3 py-1.5 text-xs font-bold ${active ? 'bg-yellow-400 text-red-800' : 'bg-white/10 text-white'}`;

    return (
        <div className="fixed inset-0 z-[80] flex items-end justify-center bg-black/70 p-4 sm:items-center" onClick={onClose}>
            <form onSubmit={send} onClick={(e) => e.stopPropagation()} className="w-full max-w-sm rounded-3xl bg-gradient-to-b from-red-600 to-red-900 p-5 text-white shadow-2xl">
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="text-lg font-black">🧧 Send a red envelope</h3>
                    <button type="button" onClick={onClose} aria-label="Close">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <p className="mb-4 text-xs text-white/80">Share your points with the room. The first people to tap split them at random. Anything not opened in 10 minutes comes back to you.</p>
                <p className="mb-1 text-[11px] font-bold uppercase text-yellow-200">Points</p>
                <div className="mb-3 flex flex-wrap gap-2">
                    {[50, 100, 200, 500, 1000].map((p) => (
                        <button type="button" key={p} onClick={() => setPoints(p)} className={chip(points === p)}>
                            {p}
                        </button>
                    ))}
                </div>
                <p className="mb-1 text-[11px] font-bold uppercase text-yellow-200">How many people</p>
                <div className="mb-3 flex flex-wrap gap-2">
                    {[1, 3, 5, 10].map((n) => (
                        <button type="button" key={n} onClick={() => setSlots(n)} className={chip(slots === n)}>
                            {n}
                        </button>
                    ))}
                </div>
                <input
                    value={message}
                    onChange={(e) => setMessage(e.target.value)}
                    maxLength={80}
                    placeholder="Add a message (optional)"
                    className="mb-3 w-full rounded-xl border border-white/20 bg-white/10 px-3 py-2 text-sm placeholder:text-white/50 focus:outline-none"
                />
                {error && <p role="alert" className="mb-3 rounded-lg bg-black/25 px-3 py-2 text-xs">{error}</p>}
                <button disabled={busy} className="w-full rounded-2xl bg-gradient-to-b from-yellow-300 to-amber-500 py-3 text-sm font-black uppercase text-red-900 shadow-[0_4px_0_#b45309] disabled:opacity-60">
                    {busy ? 'Sending…' : `Send ${points} points`}
                </button>
            </form>
        </div>
    );
}
