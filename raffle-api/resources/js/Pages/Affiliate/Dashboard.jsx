import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { ArrowLeft, Check, Copy, MousePointerClick, Share2, Ticket, UserPlus, Wallet } from 'lucide-react';
import { formatNaira } from '../../lib/format';
import { goBack } from '../../lib/nav';

// An affiliate's own page (Growth → Affiliates in the admin): their link,
// what it has done, and what they've earned. Separate from the ordinary
// referral page. Customers' names are partly hidden.
export default function AffiliateDashboard({ dashboard: d }) {
    const [copied, setCopied] = useState(false);

    function copyLink() {
        navigator.clipboard?.writeText(d.link).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    function share() {
        if (navigator.share) {
            navigator.share({ title: 'Join me on RaffleKings', url: d.link }).catch(() => {});
        } else {
            copyLink();
        }
    }

    const stats = [
        { icon: MousePointerClick, label: 'Clicks (30 days)', value: d.clicks_30_days.toLocaleString() },
        { icon: UserPlus, label: 'Sign-ups', value: d.signups.toLocaleString() },
        { icon: Ticket, label: 'Played', value: d.players.toLocaleString() },
        { icon: Wallet, label: 'Topped up', value: d.topped_up.toLocaleString() },
    ];

    return (
        <>
            <Head title="Affiliate dashboard" />
            <div className="min-h-screen bg-gray-50 pb-16 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white px-5 py-4 shadow-sm dark:border-dark-border dark:bg-dark-bg dark:shadow-none">
                    <button onClick={() => goBack('/profile')} className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="Back">
                        <ArrowLeft className="h-5 w-5" />
                    </button>
                    <div>
                        <h2 className="text-xl font-bold text-gray-900 dark:text-white">Affiliate dashboard</h2>
                        <p className="text-xs text-gray-500 dark:text-gray-400">{d.name}</p>
                    </div>
                </div>

                <div className="mx-auto max-w-lg space-y-5 p-5">
                    {! d.is_active && (
                        <div className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                            Your affiliate account is paused, so new sign-ups don't earn. Contact support to restart it.
                        </div>
                    )}

                    <div className="rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 p-5 text-white shadow-lg">
                        <p className="text-xs font-bold uppercase tracking-wider text-blue-100">Earned so far</p>
                        <p className="mt-1 text-3xl font-black">{formatNaira(d.earned.paid)}</p>
                        <p className="mt-1 text-sm text-blue-100">
                            {formatNaira(d.earned.waiting)} on its way (paid into your winnings {d.hold_days} day{d.hold_days === 1 ? '' : 's'} after each top-up)
                        </p>
                        <p className="mt-3 text-xs text-blue-100">
                            You earn {d.commission_percent}% of every top-up by people who join through you, for their first {d.commission_days} days.
                        </p>
                    </div>

                    <div className="rounded-2xl border border-gray-100 bg-white p-4 dark:border-gray-800 dark:bg-dark-card">
                        <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Your link</p>
                        <p className="break-all font-mono text-sm text-gray-900 dark:text-white">{d.link}</p>
                        <div className="mt-3 grid grid-cols-2 gap-2">
                            <button onClick={copyLink} className="flex items-center justify-center gap-2 rounded-xl border border-gray-200 py-2.5 text-sm font-bold text-gray-700 dark:border-gray-700 dark:text-gray-200">
                                {copied ? <Check className="h-4 w-4 text-green-500" /> : <Copy className="h-4 w-4" />}
                                {copied ? 'Copied' : 'Copy'}
                            </button>
                            <button onClick={share} className="flex items-center justify-center gap-2 rounded-xl bg-app-primary py-2.5 text-sm font-bold text-white">
                                <Share2 className="h-4 w-4" /> Share
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        {stats.map(({ icon: Icon, label, value }) => (
                            <div key={label} className="rounded-2xl border border-gray-100 bg-white p-4 dark:border-gray-800 dark:bg-dark-card">
                                <Icon className="h-4 w-4 text-app-primary" />
                                <p className="mt-2 text-2xl font-black text-gray-900 dark:text-white">{value}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{label}</p>
                            </div>
                        ))}
                    </div>

                    {d.codes.length > 0 && (
                        <div className="rounded-2xl border border-gray-100 bg-white p-4 dark:border-gray-800 dark:bg-dark-card">
                            <p className="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">Your promo codes</p>
                            <div className="space-y-3">
                                {d.codes.map((c) => (
                                    <div key={c.code} className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="font-mono font-black text-gray-900 dark:text-white">{c.code}</p>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">{c.summary}{c.is_live ? '' : ' · ended'}</p>
                                        </div>
                                        <p className="text-sm font-bold text-gray-700 dark:text-gray-300">{c.signups} sign-up{c.signups === 1 ? '' : 's'}</p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="rounded-2xl border border-gray-100 bg-white p-4 dark:border-gray-800 dark:bg-dark-card">
                        <p className="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">Recent earnings</p>
                        {d.recent.length === 0 ? (
                            <p className="text-sm text-gray-500 dark:text-gray-400">Nothing yet. Share your link to get started.</p>
                        ) : (
                            <div className="divide-y divide-gray-50 dark:divide-gray-800">
                                {d.recent.map((e, i) => (
                                    <div key={i} className="flex items-center justify-between py-2.5">
                                        <div>
                                            <p className="text-sm font-semibold text-gray-900 dark:text-white">{e.customer} topped up {formatNaira(e.top_up)}</p>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                                {new Date(e.date).toLocaleDateString()} ·{' '}
                                                {e.status === 'paid' ? 'Paid' : e.status === 'cancelled' ? 'Cancelled' : `On its way${e.available_at ? ` (${new Date(e.available_at).toLocaleDateString()})` : ''}`}
                                            </p>
                                        </div>
                                        <p className={`text-sm font-black ${e.status === 'cancelled' ? 'text-gray-400 line-through' : 'text-green-600 dark:text-green-400'}`}>
                                            +{formatNaira(e.commission)}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
