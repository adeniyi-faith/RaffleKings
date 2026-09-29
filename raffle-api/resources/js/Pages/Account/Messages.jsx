import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, ArrowUpRight, BellOff, CheckCheck, Gift, LifeBuoy, Trophy, Users, Wallet } from 'lucide-react';
import { apiPost } from '../../lib/api';

// The customer's notifications (the bell): personal alerts (support
// replies, wins, withdrawals, top-ups, referral earnings, written by
// App\Notifications\Channels\InboxChannel) and news staff send from the
// admin's "Message customers" page. Opening one marks it read; the bell's
// number in the header counts what's still unread.
const KINDS = {
    support: { icon: LifeBuoy, colour: 'bg-violet-500' },
    win: { icon: Trophy, colour: 'bg-amber-500' },
    wallet: { icon: Wallet, colour: 'bg-emerald-500' },
    withdrawal: { icon: ArrowUpRight, colour: 'bg-sky-500' },
    referral: { icon: Users, colour: 'bg-pink-500' },
    news: { icon: Gift, colour: 'bg-app-primary' },
};

const FILTERS = [
    ['all', 'All'],
    ['alerts', 'For you'],
    ['news', 'News & offers'],
];

export default function Messages() {
    const [state, setState] = useState('loading');
    const [messages, setMessages] = useState([]);
    const [open, setOpen] = useState(null);
    const [filter, setFilter] = useState('all');

    useEffect(() => {
        fetch('/api/messages', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((res) => {
                if (res.status === 401) {
                    router.visit('/login?redirect=' + encodeURIComponent('/messages'));
                    return null;
                }
                if (! res.ok) throw new Error();
                return res.json();
            })
            .then((data) => {
                if (! data) return;
                setMessages(data.messages);
                setState('ready');
            })
            .catch(() => setState('error'));
    }, []);

    function markRead(message) {
        setOpen(open === message.id ? null : message.id);
        if (message.is_read) return;

        setMessages((list) => list.map((m) => (m.id === message.id ? { ...m, is_read: true } : m)));
        apiPost(`/api/messages/${message.id}/read`).then(() => router.reload({ only: ['auth'] })).catch(() => {});
    }

    function markAllRead() {
        setMessages((list) => list.map((m) => ({ ...m, is_read: true })));
        apiPost('/api/messages/read-all').then(() => router.reload({ only: ['auth'] })).catch(() => {});
    }

    const unread = messages.filter((m) => ! m.is_read).length;
    const shown = messages.filter((m) => filter === 'all' || (filter === 'news' ? m.kind === 'news' : m.kind !== 'news'));

    return (
        <>
            <Head title="Notifications" />
            <div className="relative min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white px-5 pb-4 pt-4 shadow-sm dark:border-dark-border dark:bg-dark-bg dark:shadow-none">
                    <button onClick={() => window.history.back()} className="-ml-1 p-1 text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-200">
                        <ArrowLeft className="h-5 w-5" />
                    </button>
                    <div className="flex-1">
                        <h2 className="text-xl font-bold text-gray-900 dark:text-white">Notifications</h2>
                        <p className="text-xs text-gray-500 dark:text-gray-400">{unread > 0 ? `${unread} unread` : 'Replies, wins, payouts and news'}</p>
                    </div>
                    {unread > 0 && (
                        <button onClick={markAllRead} className="flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1.5 text-[11px] font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            <CheckCheck className="h-3.5 w-3.5" /> Mark all read
                        </button>
                    )}
                </div>

                <div className="flex gap-2 px-5 pt-4">
                    {FILTERS.map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setFilter(key)}
                            className={`rounded-full px-3.5 py-1.5 text-xs font-bold transition-colors ${filter === key ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-white text-gray-600 shadow-sm dark:bg-dark-card dark:text-gray-300'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <section className="space-y-3 px-5 py-4">
                    {state === 'loading' &&
                        [0, 1, 2].map((i) => <div key={i} className="h-20 animate-pulse rounded-2xl bg-white dark:bg-dark-card" />)}

                    {state === 'error' && <p className="py-10 text-center text-sm text-gray-500">Couldn't load your messages. Pull down to try again.</p>}

                    {state === 'ready' && shown.length === 0 && (
                        <div className="flex flex-col items-center py-16 text-center">
                            <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                                <BellOff className="h-6 w-6 text-gray-400" />
                            </div>
                            <p className="font-bold text-gray-800 dark:text-gray-200">Nothing here yet</p>
                            <p className="mt-1 text-xs text-gray-500">Support replies, wins, payouts and news will show up here.</p>
                        </div>
                    )}

                    {shown.map((m) => {
                        const kind = KINDS[m.kind] ?? KINDS.news;
                        const Icon = kind.icon;

                        return (
                            <article
                                key={m.id}
                                onClick={() => markRead(m)}
                                className={[
                                    'cursor-pointer rounded-2xl border p-4 shadow-sm transition-all',
                                    m.is_read
                                        ? 'border-gray-100 bg-white dark:border-dark-border dark:bg-dark-card'
                                        : 'border-blue-200 bg-blue-50/60 dark:border-blue-900/50 dark:bg-blue-900/20',
                                ].join(' ')}
                            >
                                <div className="flex items-start gap-3">
                                    <div className={`mt-0.5 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full ${m.is_read ? 'bg-gray-100 text-gray-400 dark:bg-gray-800' : `${kind.colour} text-white`}`}>
                                        <Icon className="h-4 w-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <h3 className={`text-sm ${m.is_read ? 'font-semibold text-gray-700 dark:text-gray-300' : 'font-bold text-gray-900 dark:text-white'}`}>{m.title}</h3>
                                            <span className="flex-shrink-0 text-[10px] text-gray-400">{m.sent_ago}</span>
                                        </div>
                                        <p className={`mt-1 whitespace-pre-line break-words text-xs leading-relaxed text-gray-600 dark:text-gray-400 ${open === m.id ? '' : 'line-clamp-2'}`}>{m.body}</p>
                                        {m.link_url && open === m.id && (
                                            m.link_url.startsWith('/') ? (
                                                <Link href={m.link_url} onClick={(e) => e.stopPropagation()} className="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-app-primary px-4 py-2 text-xs font-bold text-white">
                                                    {m.link_label || 'Open'} <ArrowRight className="h-3.5 w-3.5" />
                                                </Link>
                                            ) : (
                                                <a href={m.link_url} target="_blank" rel="noopener noreferrer" onClick={(e) => e.stopPropagation()} className="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-app-primary px-4 py-2 text-xs font-bold text-white">
                                                    {m.link_label || 'Open'} <ArrowRight className="h-3.5 w-3.5" />
                                                </a>
                                            )
                                        )}
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </section>
            </div>
        </>
    );
}
