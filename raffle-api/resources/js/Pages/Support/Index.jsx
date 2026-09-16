import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BookOpen, ChevronDown, Inbox, MessageSquarePlus, Plus, Send } from 'lucide-react';
import Modal from '../../Components/ui/Modal';

// Rebuild of support.php (item 29) against the real ticketing backend
// (SupportTicketController/SupportTicketService) — replacing the legacy
// page whose "Submit Ticket" handler was a documented no-op ("// Simulate
// submission", no network call at all, so a user's message was silently
// dropped). Preserved from the legacy page: the "How to Play & Win"
// banner into the Learning Hub, the ticket list with a status dot and
// a tap-to-expand conversation thread, the floating "new ticket" button,
// and the bottom-sheet new-ticket form.
const STATUS_DOT = {
    open: 'bg-yellow-500',
    pending: 'bg-blue-500',
    resolved: 'bg-gray-400',
    closed: 'bg-gray-400',
};

function timeAgo(dateStr) {
    const diffMs = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diffMs / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}

export default function SupportIndex() {
    const [tickets, setTickets] = useState(null); // null = loading
    const [openId, setOpenId] = useState(null);
    const [thread, setThread] = useState(null);
    const [replyText, setReplyText] = useState('');
    const [busy, setBusy] = useState(false);

    const [showNewTicket, setShowNewTicket] = useState(false);
    const [subject, setSubject] = useState('General Inquiry');
    const [message, setMessage] = useState('');
    const [formError, setFormError] = useState(null);

    useEffect(() => {
        loadTickets();
    }, []);

    function loadTickets() {
        fetch('/api/support/tickets', { credentials: 'same-origin' })
            .then((res) => (res.ok ? res.json() : { tickets: [] }))
            .then((data) => setTickets(data.tickets ?? []))
            .catch(() => setTickets([]));
    }

    async function toggleTicket(id) {
        if (openId === id) {
            setOpenId(null);
            return;
        }

        setOpenId(id);
        setThread(null);

        const res = await fetch(`/api/support/tickets/${id}`, { credentials: 'same-origin' });
        if (res.ok) {
            setThread(await res.json());
        }
    }

    async function sendReply(id) {
        if (! replyText.trim()) return;

        setBusy(true);
        try {
            const res = await fetch(`/api/support/tickets/${id}/reply`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ message: replyText }),
            });

            if (res.ok) {
                setReplyText('');
                const refreshed = await fetch(`/api/support/tickets/${id}`, { credentials: 'same-origin' });
                setThread(await refreshed.json());
                loadTickets();
            }
        } finally {
            setBusy(false);
        }
    }

    async function submitTicket(e) {
        e.preventDefault();
        setFormError(null);

        if (! message.trim()) {
            setFormError('Please describe your issue.');
            return;
        }

        setBusy(true);
        try {
            const res = await fetch('/api/support/tickets', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ subject, message }),
            });
            const data = await res.json();

            if (! res.ok) {
                throw new Error(data.message || 'Could not submit ticket. Please try again.');
            }

            setShowNewTicket(false);
            setMessage('');
            loadTickets();
        } catch (err) {
            setFormError(err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title="Help & Support" />
            <div className="relative min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 border-b border-gray-100 bg-white px-5 pb-4 pt-4 dark:border-dark-border dark:bg-dark-bg">
                    <div className="flex items-center gap-3">
                        <button onClick={() => window.history.back()} className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white">
                            <ArrowLeft className="h-5 w-5" />
                        </button>
                        <div>
                            <h2 className="text-xl font-bold text-gray-900 dark:text-white">Help & Support</h2>
                            <p className="text-xs text-gray-500 dark:text-gray-400">We are here to help you win.</p>
                        </div>
                    </div>
                </div>

                <section className="p-5 pb-2">
                    <Link
                        href="/support/tutorials"
                        className="group relative block overflow-hidden rounded-2xl bg-gradient-to-r from-blue-600 to-indigo-700 p-5 text-white shadow-lg shadow-blue-500/20 transition-transform active:scale-[0.98]"
                    >
                        <div className="pointer-events-none absolute bottom-0 right-0 h-24 w-24 translate-x-1/4 translate-y-1/4 rounded-full bg-white/10 blur-2xl" />
                        <div className="relative z-10 flex items-center justify-between">
                            <div>
                                <span className="mb-2 inline-block rounded bg-white/20 px-2 py-0.5 text-[9px] font-bold text-white">NEW USER?</span>
                                <h3 className="text-lg font-bold leading-tight">How to Play & Win</h3>
                                <p className="mt-1 text-xs text-blue-100">Read the Learning Hub guides</p>
                            </div>
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-white/20">
                                <BookOpen className="h-5 w-5 text-white" />
                            </div>
                        </div>
                    </Link>
                </section>

                <section className="px-5 pt-2">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-bold text-gray-900 dark:text-white">Your Conversations</h3>
                        <button
                            onClick={() => setShowNewTicket(true)}
                            className="flex items-center gap-1 rounded-full bg-blue-50 px-3 py-1.5 text-[10px] font-bold text-app-primary active:bg-blue-100 dark:bg-blue-900/30"
                        >
                            <Plus className="h-3 w-3" /> New Ticket
                        </button>
                    </div>

                    {tickets === null && (
                        <div className="space-y-3">
                            <div className="h-20 animate-pulse rounded-xl border border-gray-100 bg-white dark:border-gray-800 dark:bg-dark-card" />
                            <div className="h-20 animate-pulse rounded-xl border border-gray-100 bg-white dark:border-gray-800 dark:bg-dark-card" />
                        </div>
                    )}

                    {tickets?.length === 0 && (
                        <div className="py-10 text-center text-gray-400 dark:text-gray-600">
                            <Inbox className="mx-auto mb-2 h-10 w-10" />
                            <p className="text-xs">No conversations yet. Tap "New Ticket" if you need help.</p>
                        </div>
                    )}

                    <div className="space-y-3">
                        {tickets?.map((t) => (
                            <div
                                key={t.id}
                                onClick={() => toggleTicket(t.id)}
                                className="cursor-pointer rounded-xl border border-gray-100 bg-white p-4 shadow-sm transition-colors active:bg-gray-50 dark:border-gray-800 dark:bg-dark-card dark:active:bg-gray-800"
                            >
                                <div className="mb-1 flex items-start justify-between">
                                    <div className="flex items-center gap-2">
                                        <div className={`h-2 w-2 rounded-full ${STATUS_DOT[t.status] ?? 'bg-gray-400'}`} />
                                        <h4 className="text-sm font-bold text-gray-800 dark:text-gray-100">{t.subject}</h4>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-[10px] text-gray-400">{timeAgo(t.updated_at ?? t.created_at)}</span>
                                        <ChevronDown className={`h-3 w-3 text-gray-400 transition-transform ${openId === t.id ? 'rotate-180' : ''}`} />
                                    </div>
                                </div>
                                <p className="line-clamp-1 text-xs capitalize text-gray-500 dark:text-gray-400">{t.status}</p>

                                {openId === t.id && (
                                    <div className="mt-3 border-t border-gray-50 pt-3 dark:border-gray-800" onClick={(e) => e.stopPropagation()}>
                                        {! thread ? (
                                            <p className="text-xs text-gray-400">Loading conversation…</p>
                                        ) : (
                                            <>
                                                <div className="mb-2 space-y-2">
                                                    {thread.messages.map((m) => (
                                                        <div
                                                            key={m.id}
                                                            className={`rounded-lg p-3 ${m.is_from_admin ? 'bg-blue-50 dark:bg-blue-900/20' : 'bg-gray-50 dark:bg-gray-800'}`}
                                                        >
                                                            <p className={`mb-1 text-[10px] font-bold ${m.is_from_admin ? 'text-blue-800 dark:text-blue-300' : 'text-gray-500 dark:text-gray-400'}`}>
                                                                {m.is_from_admin ? 'Support Team' : 'You'}
                                                            </p>
                                                            <p className={`text-xs ${m.is_from_admin ? 'text-blue-700 dark:text-blue-200' : 'text-gray-700 dark:text-gray-200'}`}>
                                                                {m.message}
                                                            </p>
                                                        </div>
                                                    ))}
                                                </div>
                                                <div className="flex gap-2">
                                                    <input
                                                        type="text"
                                                        value={replyText}
                                                        onChange={(e) => setReplyText(e.target.value)}
                                                        placeholder="Type a reply..."
                                                        className="flex-1 rounded-full border border-gray-200 bg-gray-50 px-3 py-2 text-xs outline-none focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                                                    />
                                                    <button
                                                        onClick={() => sendReply(t.id)}
                                                        disabled={busy}
                                                        className="rounded-full bg-app-primary px-4 py-2 text-[10px] font-bold text-white disabled:opacity-50"
                                                    >
                                                        Send
                                                    </button>
                                                </div>
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            </div>

            <button
                onClick={() => setShowNewTicket(true)}
                className="fixed bottom-24 right-5 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-gray-900 text-white shadow-xl shadow-gray-900/30 transition-transform active:scale-90 dark:bg-white dark:text-gray-900"
            >
                <MessageSquarePlus className="h-6 w-6" />
            </button>

            <Modal open={showNewTicket} onClose={() => setShowNewTicket(false)} title="Open New Ticket">
                <form onSubmit={submitTicket}>
                    <div className="space-y-4">
                        {formError && (
                            <div className="rounded-lg bg-red-50 p-2 text-xs font-bold text-red-600 dark:bg-red-900/20 dark:text-red-400">
                                {formError}
                            </div>
                        )}
                        <div>
                            <label className="mb-2 block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Issue Type</label>
                            <select
                                value={subject}
                                onChange={(e) => setSubject(e.target.value)}
                                className="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                            >
                                <option>General Inquiry</option>
                                <option>Withdrawal / Deposit</option>
                                <option>Claiming a Prize</option>
                                <option>Report a Bug</option>
                            </select>
                        </div>
                        <div>
                            <label className="mb-2 block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Message</label>
                            <textarea
                                value={message}
                                onChange={(e) => setMessage(e.target.value)}
                                rows={4}
                                placeholder="Describe your issue in detail..."
                                className="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                            />
                        </div>
                    </div>
                    <button
                        type="submit"
                        disabled={busy}
                        className="mt-6 flex w-full items-center justify-center gap-2 rounded-xl bg-app-primary py-3.5 font-bold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-[0.98] disabled:opacity-60"
                    >
                        {busy ? 'Sending…' : 'Submit Ticket'} <Send className="h-4 w-4" />
                    </button>
                </form>
            </Modal>
        </>
    );
}
