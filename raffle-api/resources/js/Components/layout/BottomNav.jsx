import { Link, usePage } from '@inertiajs/react';
import { Gift, Home as HomeIcon, Ticket, Trophy, User } from 'lucide-react';

// Same five destinations, same order, as the legacy footer.php's bottom
// nav (components/layout/bottom-nav.php): Home, Raffles, Winners, My
// Rewards, Profile.
const ITEMS = [
    { href: '/', icon: HomeIcon, label: 'Home' },
    { href: '/raffles', icon: Ticket, label: 'Raffles' },
    { href: '/hall-of-fame', icon: Trophy, label: 'Winners' },
    { href: '/rewards', icon: Gift, label: 'My Rewards' },
];

export default function BottomNav() {
    const { url, props } = usePage();
    const user = props.auth?.user;
    const items = [...ITEMS, { href: user ? '/account/wallet' : '/login', icon: User, label: 'Profile' }];

    return (
        <nav className="fixed bottom-0 left-0 z-50 flex h-[calc(4.5rem+env(safe-area-inset-bottom))] w-full items-start justify-around border-t border-gray-100 bg-white px-2 pb-2 pt-3 shadow-[0_-5px_20px_rgba(0,0,0,0.03)] backdrop-blur-md transition-colors duration-200 dark:border-dark-border dark:bg-dark-bg/95 dark:shadow-none">
            {items.map(({ href, icon: Icon, label }) => {
                const active = href === '/' ? url === '/' : url.startsWith(href);

                return (
                    <Link
                        key={label}
                        href={href}
                        className={[
                            'flex flex-col items-center gap-1 p-1 transition-colors',
                            active
                                ? 'text-app-primary'
                                : 'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300',
                        ].join(' ')}
                    >
                        <Icon className="h-6 w-6" />
                        <span className="text-center text-[10px] font-medium leading-tight">{label}</span>
                    </Link>
                );
            })}
        </nav>
    );
}
