import { Link, usePage } from '@inertiajs/react';
import { Bell, Eye, EyeOff, Wallet } from 'lucide-react';
import { formatNaira } from '../../lib/format';
import { resolveAvatar } from '../../lib/avatar';
import { useBalanceHidden, useBalances } from '../../lib/balances';

// Same top bar as the legacy header.php: logo, a wallet balance pill
// (sent with the page, like the old server-rendered one, so it never
// flashes ₦0; the eye's "hidden" choice is remembered across pages),
// and an avatar linking to the account area -- the user's own uploaded
// photo if they have one, same Dicebear fallback otherwise.
export default function Header() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const balances = useBalances();
    const balance = balances?.wallet ?? 0;
    const [hidden, toggleHidden] = useBalanceHidden();

    const avatar = resolveAvatar(user);

    return (
        <header
            className="sticky top-0 z-30 flex flex-shrink-0 items-center justify-between border-b border-transparent bg-white px-4 pb-3 shadow-sm backdrop-blur-md transition-colors duration-200 dark:border-gray-800 dark:bg-dark-bg/95 dark:shadow-none sm:px-5"
            style={{ paddingTop: 'calc(env(safe-area-inset-top) + 0.75rem)' }}
        >
            <Link href="/" className="flex shrink-0 items-center gap-2 transition-transform active:scale-95">
                <img src="/images/icon-192.png" alt="RaffleKings Logo" width="32" height="32" className="h-8 w-8 rounded-lg" />
                <h1 className="text-lg font-black leading-none tracking-tight text-gray-900 dark:text-white">
                    Raffle<span className="text-app-primary">Kings</span>
                </h1>
            </Link>

            <div className="ml-4 flex min-w-0 items-center gap-2.5">
                {user && (
                <button
                    type="button"
                    onClick={toggleHidden}
                    aria-label={hidden ? 'Show balance' : 'Hide balance'}
                    className="group flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-100 px-2.5 py-1.5 transition-transform active:scale-95 dark:border-gray-700 dark:bg-gray-800"
                >
                    <Wallet className="h-3 w-3 text-gray-500 transition-colors group-hover:text-app-primary dark:text-gray-400" />
                    <span className="whitespace-nowrap text-xs font-bold text-gray-700 dark:text-gray-200">
                        {hidden ? '••••••' : formatNaira(balance)}
                    </span>
                    {hidden ? (
                        <EyeOff className="h-3 w-3 text-gray-400 dark:text-gray-500" />
                    ) : (
                        <Eye className="h-3 w-3 text-gray-400 dark:text-gray-500" />
                    )}
                </button>
                )}

                {user && (
                    <Link href="/messages" aria-label={`Notifications${user.unread_messages ? `, ${user.unread_messages} unread` : ''}`} className="relative p-1 text-gray-500 transition-transform active:scale-90 dark:text-gray-300">
                        <Bell className="h-5 w-5" />
                        {user.unread_messages > 0 && (
                            <span className="absolute -right-1 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[9px] font-bold text-white ring-2 ring-white dark:ring-dark-bg">
                                {user.unread_messages > 9 ? '9+' : user.unread_messages}
                            </span>
                        )}
                    </Link>
                )}

                <Link href="/profile" className="relative block transition-transform active:scale-90">
                    <div className="h-9 w-9 overflow-hidden rounded-full border-2 border-yellow-500 bg-gray-200 shadow-sm dark:bg-gray-700">
                        <img src={avatar} decoding="async" className="h-full w-full object-cover" alt="Profile" />
                    </div>
                </Link>
            </div>
        </header>
    );
}
