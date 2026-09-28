import { useEffect, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Mail, MessageCircle, ShieldCheck, Wrench } from 'lucide-react';

function remaining(backAt) {
    const ms = new Date(backAt).getTime() - Date.now();
    if (ms <= 0) return null;
    const mins = Math.ceil(ms / 60000);
    const h = Math.floor(mins / 60);
    const m = mins % 60;

    return h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m} min`;
}

// Shown to customers while the admin has maintenance mode on (Settings →
// On / off). Counts down to the "back at" time and quietly checks every
// minute, so the site comes back on its own without anyone refreshing.
export default function Maintenance({ message, back_at: backAt }) {
    const { site } = usePage().props;
    const [left, setLeft] = useState(backAt ? remaining(backAt) : null);

    useEffect(() => {
        const tick = setInterval(() => setLeft(backAt ? remaining(backAt) : null), 1000 * 15);
        const check = setInterval(() => window.location.reload(), 60 * 1000);

        return () => {
            clearInterval(tick);
            clearInterval(check);
        };
    }, [backAt]);

    return (
        <>
            <Head title="Back soon" />
            <div className="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-gradient-to-br from-blue-700 via-indigo-700 to-purple-800 px-6 py-12 text-center text-white">
                <div className="pointer-events-none absolute -left-24 -top-24 h-72 w-72 rounded-full bg-white/10 blur-3xl" />
                <div className="pointer-events-none absolute -bottom-24 -right-24 h-72 w-72 rounded-full bg-yellow-400/20 blur-3xl" />

                <div className="relative mb-6 flex h-20 w-20 items-center justify-center rounded-3xl bg-white/15 shadow-2xl ring-1 ring-white/20 backdrop-blur">
                    <Wrench className="h-9 w-9 animate-[spin_6s_linear_infinite] text-yellow-300" />
                </div>

                <p className="mb-2 text-xs font-bold uppercase tracking-[0.2em] text-white/70">{site?.name || 'RaffleKings'}</p>
                <h1 className="max-w-sm text-3xl font-black leading-tight">We&rsquo;ll be right back</h1>
                <p className="mt-3 max-w-sm text-sm leading-relaxed text-white/85">{message}</p>

                {left && (
                    <div className="mt-6 rounded-2xl bg-white/10 px-6 py-3 ring-1 ring-white/15 backdrop-blur">
                        <p className="text-[10px] font-bold uppercase tracking-widest text-white/60">Back in about</p>
                        <p className="text-2xl font-black tabular-nums">{left}</p>
                    </div>
                )}

                <div className="mt-6 flex items-center gap-2 rounded-full bg-green-400/15 px-4 py-2 text-xs font-semibold text-green-100 ring-1 ring-green-300/30">
                    <ShieldCheck className="h-4 w-4" /> Your tickets, wallet and winnings are safe.
                </div>

                {(site?.links?.telegram_support || site?.support_email) && (
                    <div className="mt-8 flex flex-wrap justify-center gap-3">
                        {site.links?.telegram_support && (
                            <a href={site.links.telegram_support} target="_blank" rel="noreferrer" className="flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-xs font-bold text-indigo-700 shadow-lg">
                                <MessageCircle className="h-4 w-4" /> Chat with support
                            </a>
                        )}
                        {site.support_email && (
                            <a href={`mailto:${site.support_email}`} className="flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-xs font-bold text-white ring-1 ring-white/25">
                                <Mail className="h-4 w-4" /> Email us
                            </a>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
