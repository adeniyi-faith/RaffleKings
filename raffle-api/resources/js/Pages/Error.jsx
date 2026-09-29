import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, RotateCw, Ticket } from 'lucide-react';

// Branded error page (OVERHAUL_CHECKLIST.md item 42) — replaces Laravel's
// bare default error pages for real customers. Same card, pulsing ring
// and wiggling emoji as the legacy 404 page (components/pages/404-content.php),
// with wording that tells the customer what happened and what to do next.
// Rendered by bootstrap/app.php's exception handler only when debug mode
// is off, so developers still see the full error locally.
const CONTENT = {
    404: {
        emoji: '🚧',
        title: 'Page not found',
        message: "This page doesn't exist or has moved. Let's get you back to the action.",
    },
    403: {
        emoji: '🔒',
        title: 'Not allowed',
        message: "You don't have access to this page.",
    },
    419: {
        emoji: '⌛',
        title: 'Page expired',
        message: 'This page was open for a while. Refresh and try again.',
        retry: true,
    },
    429: {
        emoji: '✋',
        title: 'Slow down a little',
        message: 'Too many requests in a short time. Wait a moment, then try again.',
        retry: true,
    },
    503: {
        emoji: '🛠️',
        title: 'Quick maintenance',
        message: "We're making RaffleKings better. We'll be back in a few minutes. Your tickets and balance are safe.",
        retry: true,
    },
    500: {
        emoji: '😵',
        title: 'Something went wrong',
        message: "That's on us, not you. Our team has been alerted. Your tickets and balance are safe. Please try again shortly.",
        retry: true,
    },
};

export default function Error({ status, reference = null }) {
    const content = CONTENT[status] ?? CONTENT[500];

    return (
        <>
            <Head title={content.title} />
            <div className="flex min-h-screen items-center justify-center bg-gray-100 p-5 text-center dark:bg-dark-bg">
                <div className="w-full max-w-sm rounded-3xl border border-white bg-white p-8 shadow-xl shadow-blue-900/5 dark:border-dark-border dark:bg-dark-card">
                    <div className="relative mx-auto mb-6 flex h-24 w-24 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/20">
                        <div className="absolute inset-0 animate-ping rounded-full bg-blue-100 opacity-20 dark:bg-blue-800" />
                        <span className="animate-wiggle text-5xl" aria-hidden="true">{content.emoji}</span>
                    </div>

                    <h1 className="mb-1 text-6xl font-bold tracking-tighter text-gray-900 dark:text-white">{status}</h1>
                    <h2 className="mb-4 text-lg font-bold text-gray-800 dark:text-gray-100">{content.title}</h2>

                    <p className={`${reference ? 'mb-3' : 'mb-8'} text-sm leading-relaxed text-gray-500 dark:text-gray-400`}>{content.message}</p>
                    {reference && (
                        <p className="mb-8 text-xs text-gray-400 dark:text-gray-500">
                            If you contact support, give them this error code: <span className="select-all font-mono font-bold text-gray-600 dark:text-gray-300">{reference}</span>
                        </p>
                    )}

                    <div className="space-y-3">
                        {content.retry && (
                            <button
                                type="button"
                                onClick={() => window.location.reload()}
                                className="flex w-full items-center justify-center gap-2 rounded-xl bg-app-primary py-3.5 font-bold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-[0.98]"
                            >
                                <RotateCw className="h-4 w-4" /> Try again
                            </button>
                        )}
                        <a
                            href="/"
                            className={[
                                'group flex w-full items-center justify-center gap-2 rounded-xl py-3.5 font-bold transition-transform active:scale-[0.98]',
                                content.retry
                                    ? 'border border-gray-200 text-gray-700 dark:border-gray-700 dark:text-gray-200'
                                    : 'bg-app-primary text-white shadow-lg shadow-blue-500/30',
                            ].join(' ')}
                        >
                            <ArrowLeft className="h-4 w-4 transition-transform group-hover:-translate-x-1" /> Back to Home
                        </a>
                        {status === 404 && (
                            <Link href="/raffles" className="flex items-center justify-center gap-1.5 pt-1 text-xs font-bold text-app-primary">
                                <Ticket className="h-3.5 w-3.5" /> Browse live raffles
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
