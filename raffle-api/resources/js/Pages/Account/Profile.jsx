import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Award,
    Bell,
    Camera,
    BookOpen,
    ChevronRight,
    CreditCard,
    Download,
    FileText,
    Info,
    Radio,
    History,
    Landmark,
    LifeBuoy,
    LogOut,
    Mail,
    MessageCircle,
    Moon,
    PlusCircle,
    RefreshCw,
    ShieldCheck,
    Sun,
    Ticket,
    UserCog,
    Wallet,
} from 'lucide-react';
import Header from '../../Components/layout/Header';
import BottomNav from '../../Components/layout/BottomNav';
import { formatNaira } from '../../lib/format';
import { apiPost } from '../../lib/api';
import { resolveAvatar } from '../../lib/avatar';
import { useSite } from '../../lib/site';
import TransferWinningsModal from '../../Components/wallet/TransferWinningsModal';
import { setBalances, useBalanceHidden, useBalances } from '../../lib/balances';
import { useInstallApp } from '../../hooks/useInstallApp';

// Faithful rebuild of the legacy profile.php: the blue avatar header,
// the guest "join now" card (or the two wallet cards when logged in),
// and the grouped menu list below.
export default function Profile() {
    const { auth } = usePage().props;
    const site = useSite();
    const user = auth?.user;
    // Sent with the page (no ₦0 flash), shared with the top bar.
    const balances = useBalances();
    const wallet = balances?.wallet ?? 0;
    const earnings = balances?.earnings ?? 0;
    const [hidden] = useBalanceHidden();
    const [transferOpen, setTransferOpen] = useState(false);
    const [installHelp, setInstallHelp] = useState(false);
    const app = useInstallApp();

    async function installApp() {
        const outcome = await app.install();
        if (outcome === 'manual') setInstallHelp(true);
    }
    const [isDark, setIsDark] = useState(() => document.documentElement.classList.contains('dark'));

    function toggleTheme() {
        const next = !isDark;
        setIsDark(next);
        document.documentElement.classList.toggle('dark', next);
        localStorage.setItem('theme', next ? 'dark' : 'light');
    }

    async function handleLogout() {
        try {
            await apiPost('/api/auth/logout', {});
        } finally {
            window.location.href = '/';
        }
    }

    const avatar = resolveAvatar(user);

    return (
        <>
            <Head title="Profile" />
            <div className="flex min-h-screen w-full flex-col bg-gray-50 text-gray-900 transition-colors duration-200 dark:bg-dark-bg dark:text-white">
                <Header />

                <div className="no-scrollbar flex-1 overflow-y-auto pb-28">
                    <div className="relative overflow-hidden bg-blue-600 px-5 pb-20 pt-8 dark:bg-blue-900">
                        {/* The old profile page's faint grid over the blue. */}
                        <div aria-hidden="true" className="pointer-events-none absolute inset-0 opacity-10">
                            <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                                <defs>
                                    <pattern id="profile-grid" width="40" height="40" patternUnits="userSpaceOnUse">
                                        <path d="M 40 0 L 0 0 0 40" fill="none" stroke="white" strokeWidth="1" />
                                    </pattern>
                                </defs>
                                <rect width="100%" height="100%" fill="url(#profile-grid)" />
                            </svg>
                        </div>
                        <div className="relative z-10 flex flex-col items-center text-center">
                            <div className="relative mb-3 h-24 w-24 overflow-hidden rounded-full border-4 border-white/20 bg-blue-700 shadow-xl dark:bg-blue-800">
                                <img src={avatar} alt="Profile" width="96" height="96" className="h-full w-full object-cover" />
                                {user && (
                                    <Link
                                        href="/account/edit-profile"
                                        aria-label="Change profile picture"
                                        className="absolute bottom-0 left-0 right-0 flex h-1/3 items-center justify-center bg-black/50 text-white backdrop-blur-sm transition-colors hover:bg-black/70"
                                    >
                                        <Camera className="h-4 w-4" />
                                    </Link>
                                )}
                            </div>
                            <h2 className="text-xl font-black tracking-tight text-white">{user ? user.name : 'Guest'}</h2>
                            {user ? (
                                <span className="mt-1 inline-flex items-center gap-1 rounded-full border border-blue-400/30 bg-blue-500/50 px-2 py-0.5 text-[10px] font-bold text-blue-100">
                                    <ShieldCheck className="h-3 w-3" /> Verified
                                </span>
                            ) : (
                                <span className="mt-1 text-xs text-blue-200">Guest User</span>
                            )}
                        </div>
                    </div>

                    <div className="relative z-20 -mt-12 space-y-4 px-5">
                        {!user ? (
                            <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-600 to-purple-700 p-6 text-center text-white shadow-lg shadow-indigo-500/20 dark:from-indigo-900 dark:to-purple-900">
                                <h3 className="mb-2 text-xl font-bold">Join 12,000+ Winners</h3>
                                <p className="mb-4 text-xs leading-relaxed text-indigo-100">
                                    Start your journey today. Get access to exclusive raffles, daily rewards, and instant cashouts.
                                </p>
                                <Link
                                    href="/register"
                                    className="block w-full rounded-xl bg-white py-3 font-bold text-indigo-700 shadow-md transition-transform active:scale-95"
                                >
                                    Create Free Account
                                </Link>
                                <p className="mt-3 text-[10px] text-indigo-200">
                                    Already a member?{' '}
                                    <Link href="/login" className="font-bold text-white underline">
                                        Login
                                    </Link>
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="rounded-2xl border border-gray-100 bg-white p-5 shadow-lg dark:border-gray-800 dark:bg-dark-card">
                                    <div className="mb-2 flex items-start justify-between">
                                        <div>
                                            <p className="flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                                <Wallet className="h-3 w-3" /> Spending Wallet
                                            </p>
                                            <p className="mt-1 text-2xl font-black text-gray-900 dark:text-white">{hidden ? '₦ ••••' : formatNaira(wallet)}</p>
                                        </div>
                                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                            <CreditCard className="h-4 w-4" />
                                        </div>
                                    </div>
                                    <Link
                                        href="/account/wallet"
                                        className="mt-2 flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 py-3 text-sm font-bold text-white shadow-md transition-transform hover:bg-blue-700 active:scale-95"
                                    >
                                        <PlusCircle className="h-4 w-4" /> Fund Wallet
                                    </Link>
                                </div>

                                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-yellow-500 to-orange-600 p-5 text-white shadow-lg dark:from-yellow-600 dark:to-orange-700">
                                    <div className="relative z-10 mb-2 flex items-start justify-between">
                                        <div>
                                            <p className="flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-yellow-100">
                                                <Award className="h-3 w-3" /> Winnings &amp; Bonus
                                            </p>
                                            <p className="mt-1 text-2xl font-black text-white">{hidden ? '₦ ••••' : formatNaira(earnings)}</p>
                                        </div>
                                    </div>
                                    <div className="relative z-10 mt-4 flex gap-3">
                                        <button
                                            type="button"
                                            onClick={() => setTransferOpen(true)}
                                            className="flex flex-1 items-center justify-center gap-2 rounded-xl border border-white/30 bg-white/20 py-2.5 text-xs font-bold text-white backdrop-blur-md transition-transform hover:bg-white/30 active:scale-95"
                                        >
                                            <RefreshCw className="h-3 w-3" /> Transfer
                                        </button>
                                        <Link
                                            href="/account/withdraw"
                                            className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-white py-2.5 text-xs font-bold text-orange-600 shadow-sm transition-transform hover:bg-orange-50 active:scale-95"
                                        >
                                            Withdraw
                                        </Link>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>

                    <div className="mt-8 space-y-6 px-5 pb-6">
                        <MenuGroup title="Activity">
                            <MenuLink href="/account/tickets" icon={Ticket} iconClass="bg-green-50 text-green-600 dark:bg-green-900/30 dark:text-green-400" title="My Tickets" subtitle="View active & past tickets" />
                            <MenuLink href="/account/transactions" icon={History} iconClass="bg-purple-50 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400" title="Transaction History" last={!user} />
                            {user && (
                                <MenuLink href="/messages" icon={Bell} iconClass="bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400" title="Notifications" subtitle="Replies, wins, payouts and news" last />
                            )}
                        </MenuGroup>

                        {user && (
                            <MenuGroup title="Account">
                                <MenuLink href="/account/edit-profile" icon={UserCog} iconClass="bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400" title="Personal Details" subtitle="Name, email, phone & password" />
                                <MenuLink href="/account/bank-accounts" icon={Landmark} iconClass="bg-green-50 text-green-600 dark:bg-green-900/30 dark:text-green-400" title="Bank Details" subtitle="For withdrawals" last />
                            </MenuGroup>
                        )}

                        <MenuGroup title="System">
                            <button
                                type="button"
                                onClick={toggleTheme}
                                className="group flex w-full items-center justify-between border-b border-gray-50 p-4 text-left transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
                            >
                                <div className="flex items-center gap-3">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        {isDark ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
                                    </div>
                                    <div className="flex flex-col">
                                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Appearance</span>
                                        <span className="text-[10px] text-gray-400 dark:text-gray-500">{isDark ? 'Dark Mode' : 'Light Mode'}</span>
                                    </div>
                                </div>
                                <div className="relative h-5 w-10 rounded-full bg-gray-200 transition-colors dark:bg-gray-600">
                                    <div
                                        className={`absolute top-0.5 h-4 w-4 rounded-full bg-white shadow-sm transition-transform duration-200 ${isDark ? 'translate-x-5' : 'translate-x-0.5'}`}
                                    />
                                </div>
                            </button>

                            {! app.installed && (
                                <MenuButton onClick={installApp} icon={Download} iconClass="bg-indigo-50 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400" title="Install App" subtitle="Add RaffleKings to your home screen" />
                            )}
                            <MenuLink href="/support/tutorials" icon={BookOpen} iconClass="bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400" title="Guides & Tutorials" subtitle="Learn how to play and win" last={!user} />

                            {user && (
                                <button
                                    type="button"
                                    onClick={handleLogout}
                                    className="flex w-full items-center gap-3 p-4 text-left transition-colors hover:bg-gray-50 dark:hover:bg-gray-800"
                                >
                                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-red-50 text-red-500 dark:bg-red-900/30">
                                        <LogOut className="h-4 w-4" />
                                    </div>
                                    <span className="text-sm font-medium text-red-500">Log Out</span>
                                </button>
                            )}
                        </MenuGroup>

                        {user && (
                            <MenuGroup title="Help">
                                <MenuLink href="/support?new=1" icon={PlusCircle} iconClass="bg-violet-50 text-violet-600 dark:bg-violet-900/30 dark:text-violet-400" title="Submit a support ticket" subtitle="Tell us what's wrong; we reply here and by email" />
                                <MenuLink href="/support" icon={LifeBuoy} iconClass="bg-sky-50 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400" title="My support tickets" subtitle="See replies and continue a conversation" last />
                            </MenuGroup>
                        )}

                        <MenuGroup title="Community">
                            <MenuLink href="/live-draws" icon={Radio} iconClass="bg-red-50 text-red-500 dark:bg-red-900/30 dark:text-red-400" title="Live Draws" subtitle="Watch live, or replay past draws" last={! site.links?.whatsapp_channel} />
                            {site.links?.whatsapp_channel && (
                                <a
                                    href={site.links.whatsapp_channel}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="flex items-center justify-between p-4 transition-colors hover:bg-gray-50 dark:hover:bg-gray-800"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                                            <MessageCircle className="h-4 w-4" />
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="text-sm font-medium text-gray-700 dark:text-gray-200">WhatsApp Channel</span>
                                            <span className="text-[10px] text-gray-400 dark:text-gray-500">New raffles, winners and offers</span>
                                        </div>
                                    </div>
                                    <ChevronRight className="h-4 w-4 text-gray-300 dark:text-gray-600" />
                                </a>
                            )}
                        </MenuGroup>

                        <MenuGroup title="Legal & Support">
                            <MenuLink href="/about" icon={Info} iconClass="bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400" title="About RaffleKings" subtitle="How it works and how draws stay fair" />
                            <MenuLink href="/terms" icon={FileText} iconClass="bg-gray-50 text-gray-500 dark:bg-gray-700/50 dark:text-gray-400" title="Terms of Service" />
                            <MenuLink href="/privacy-policy" icon={ShieldCheck} iconClass="bg-gray-50 text-gray-500 dark:bg-gray-700/50 dark:text-gray-400" title="Privacy Policy" />
                            {site.links?.telegram_support && (
                            <a
                                href={site.links.telegram_support}
                                target="_blank"
                                rel="noreferrer"
                                className="flex items-center justify-between border-b border-gray-50 p-4 transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
                            >
                                <div className="flex items-center gap-3">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                                        <MessageCircle className="h-4 w-4" />
                                    </div>
                                    <div className="flex flex-col">
                                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Get Help</span>
                                        <span className="text-[10px] text-gray-400 dark:text-gray-500">Fastest response</span>
                                    </div>
                                </div>
                                <ChevronRight className="h-4 w-4 text-gray-300 dark:text-gray-600" />
                            </a>
                            )}
                            {site.support_email && (
                            <a href={`mailto:${site.support_email}`} className="flex items-center justify-between p-4 transition-colors hover:bg-gray-50 dark:hover:bg-gray-800">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                        <Mail className="h-4 w-4" />
                                    </div>
                                    <div className="flex flex-col">
                                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Email Us</span>
                                        <span className="text-[10px] text-gray-400 dark:text-gray-500">{site.support_email}</span>
                                    </div>
                                </div>
                                <ChevronRight className="h-4 w-4 text-gray-300 dark:text-gray-600" />
                            </a>
                            )}
                        </MenuGroup>

                        <p className="pb-2 pt-2 text-center text-[10px] text-gray-400 dark:text-gray-600">{site.name || 'RaffleKings'}</p>
                    </div>
                </div>

                <BottomNav />
            </div>

            {installHelp && (
                <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-4 sm:items-center" onClick={() => setInstallHelp(false)}>
                    <div role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()} className="w-full max-w-sm rounded-3xl bg-white p-6 dark:bg-dark-card">
                        <h3 className="mb-2 text-lg font-bold text-gray-900 dark:text-white">Install RaffleKings</h3>
                        <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">
                            Open your browser's menu (⋮ or the share button) and choose <strong>Install app</strong> or <strong>Add to Home screen</strong>.
                            RaffleKings then opens full screen, like any other app.
                        </p>
                        <button onClick={() => setInstallHelp(false)} className="w-full rounded-xl bg-gray-900 py-3 text-sm font-bold text-white dark:bg-white dark:text-gray-900">
                            Got it
                        </button>
                    </div>
                </div>
            )}

            <TransferWinningsModal
                open={transferOpen}
                onClose={() => setTransferOpen(false)}
                earnings={earnings}
                onDone={(data) => setBalances(data)}
            />
        </>
    );
}

function MenuGroup({ title, children }) {
    return (
        <div>
            <h3 className="mb-2 pl-1 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">{title}</h3>
            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-dark-card">{children}</div>
        </div>
    );
}

function MenuLink({ href, icon: Icon, iconClass, title, subtitle, last }) {
    return (
        <Link
            href={href}
            className={`flex items-center justify-between p-4 transition-colors hover:bg-gray-50 dark:hover:bg-gray-800 ${last ? '' : 'border-b border-gray-50 dark:border-gray-800'}`}
        >
            <div className="flex items-center gap-3">
                <div className={`flex h-8 w-8 items-center justify-center rounded-full ${iconClass}`}>
                    <Icon className="h-4 w-4" />
                </div>
                <div className="flex flex-col">
                    <span className="text-sm font-medium text-gray-700 dark:text-gray-200">{title}</span>
                    {subtitle && <span className="text-[10px] text-gray-400 dark:text-gray-500">{subtitle}</span>}
                </div>
            </div>
            <ChevronRight className="h-4 w-4 text-gray-300 dark:text-gray-600" />
        </Link>
    );
}

function MenuButton({ onClick, icon: Icon, iconClass, title, subtitle }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex w-full items-center justify-between border-b border-gray-50 p-4 text-left transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
        >
            <div className="flex items-center gap-3">
                <div className={`flex h-8 w-8 items-center justify-center rounded-full ${iconClass}`}>
                    <Icon className="h-4 w-4" />
                </div>
                <div className="flex flex-col">
                    <span className="text-sm font-medium text-gray-700 dark:text-gray-200">{title}</span>
                    {subtitle && <span className="text-[10px] text-gray-400 dark:text-gray-500">{subtitle}</span>}
                </div>
            </div>
            <ChevronRight className="h-4 w-4 text-gray-300 dark:text-gray-600" />
        </button>
    );
}
