import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { goBack } from '../../lib/nav';

// A light top bar for pages that stand on their own (log in, sign up,
// password reset, Team Up and unlock invites), so nobody lands on one with
// no way into the rest of the site: back, the logo (home), and raffles.
// `onDark` is for pages drawn on a coloured background.
export default function SimpleTop({ onDark = false, back = '/' }) {
    const text = onDark ? 'text-white' : 'text-gray-900 dark:text-white';
    const muted = onDark ? 'text-white/80 hover:text-white' : 'text-gray-400 hover:text-gray-600 dark:hover:text-white';

    return (
        <div
            className={`flex w-full items-center gap-2 px-4 pb-2 ${onDark ? '' : 'bg-transparent'}`}
            style={{ paddingTop: 'calc(env(safe-area-inset-top) + 0.75rem)' }}
        >
            <button type="button" onClick={() => goBack(back)} className={`-ml-1 p-1 ${muted}`} aria-label="Back">
                <ArrowLeft className="h-5 w-5" />
            </button>
            <Link href="/" className="flex items-center gap-2 active:scale-95" aria-label="RaffleKings home">
                <img src="/images/icon-192.png" alt="" width="28" height="28" className="h-7 w-7 rounded-lg" />
                <span className={`text-base font-black tracking-tight ${text}`}>
                    Raffle<span className={onDark ? 'text-yellow-300' : 'text-app-primary'}>Kings</span>
                </span>
            </Link>
            <Link
                href="/raffles"
                className={`ml-auto rounded-full px-3 py-1.5 text-xs font-bold ${
                    onDark ? 'bg-white/15 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'
                }`}
            >
                Browse raffles
            </Link>
        </div>
    );
}
