import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Eye, EyeOff, Wallet } from 'lucide-react';
import { formatNaira } from '../../lib/format';

// Same top bar as the legacy header.php: logo, a wallet balance pill
// (server-rendered there via PHP; fetched here client-side once, since
// this is a shared Inertia component with no per-page SSR balance prop),
// and an avatar linking to the account area. Same Dicebear avatar
// service and seed logic (display name, or "Guest" for a visitor).
export default function Header() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const [balance, setBalance] = useState(0);
    const [hidden, setHidden] = useState(false);

    useEffect(() => {
        if (!user) {
            return;
        }

        fetch('/api/wallet', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => data && setBalance(data.wallet_balance))
            .catch(() => {});
    }, [user]);

    const seed = user ? user.name.replace(/\s+/g, '') : 'Guest';
    const avatar = `https://api.dicebear.com/9.x/adventurer/svg?seed=${encodeURIComponent(seed)}&backgroundColor=e5e7eb`;

    return (
        <header
            className="sticky top-0 z-30 flex flex-shrink-0 items-center justify-between border-b border-transparent bg-white px-4 pb-3 shadow-sm backdrop-blur-md transition-colors duration-200 dark:border-gray-800 dark:bg-dark-bg/95 dark:shadow-none sm:px-5"
            style={{ paddingTop: 'calc(env(safe-area-inset-top) + 0.75rem)' }}
        >
            <Link href="/" className="flex items-center gap-2 transition-transform active:scale-95">
                <img
                    src="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/App_Icon.png"
                    alt="RaffleKings Logo"
                    className="h-8 w-8 rounded-lg"
                />
                <h1 className="text-lg font-black leading-none tracking-tight text-gray-900 dark:text-white">
                    Raffle<span className="text-app-primary">Kings</span>
                </h1>
            </Link>

            <div className="flex items-center gap-3">
                <button
                    type="button"
                    onClick={() => setHidden((h) => !h)}
                    className="group flex items-center gap-2 rounded-full border border-gray-200 bg-gray-100 px-3 py-1.5 transition-transform active:scale-95 dark:border-gray-700 dark:bg-gray-800"
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

                <Link href="/profile" className="relative block transition-transform active:scale-90">
                    <div className="h-9 w-9 overflow-hidden rounded-full border-2 border-yellow-500 bg-gray-200 shadow-sm dark:bg-gray-700">
                        <img src={avatar} className="h-full w-full object-cover" alt="Profile" />
                    </div>
                </Link>
            </div>
        </header>
    );
}
