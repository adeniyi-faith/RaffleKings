import { Link } from '@inertiajs/react';
import {
    Banknote, Car, Coins, Crown, Gift, GraduationCap, Heart, House, Plus, Smartphone, Sparkles, Star, Ticket, Trophy, Users, Zap,
} from 'lucide-react';

// Icons staff can pick in the admin (must match HomeItem::ICONS in PHP).
export const ICONS = {
    banknote: Banknote, coins: Coins, crown: Crown, car: Car, smartphone: Smartphone, 'graduation-cap': GraduationCap,
    plus: Plus, gift: Gift, trophy: Trophy, ticket: Ticket, star: Star, zap: Zap, home: House, heart: Heart, users: Users, sparkles: Sparkles,
};

// Full class names written out so Tailwind keeps them.
export const THEMES = {
    blue: {
        card: 'border-blue-500/30 bg-gradient-to-r from-blue-600 to-blue-700 shadow-blue-500/20 dark:from-blue-700 dark:to-blue-900',
        slide: 'bg-gradient-to-br from-blue-700 via-blue-600 to-indigo-800 shadow-blue-900/30',
        tile: 'bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400',
        badge: 'bg-yellow-400 text-blue-900',
        button: 'text-blue-800',
        watermark: 'text-blue-200',
    },
    green: {
        card: 'border-green-500/30 bg-gradient-to-r from-green-600 to-emerald-700 shadow-green-500/20',
        slide: 'bg-gradient-to-br from-green-700 via-green-600 to-emerald-800 shadow-green-900/30',
        tile: 'bg-green-50 text-green-600 dark:bg-green-900/20 dark:text-green-400',
        badge: 'bg-yellow-400 text-green-900',
        button: 'text-green-800',
        watermark: 'text-yellow-300',
    },
    red: {
        card: 'border-red-500/30 bg-gradient-to-r from-red-700 to-red-600 shadow-red-500/20',
        slide: 'bg-gradient-to-br from-red-800 to-red-600 shadow-red-900/30',
        tile: 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400',
        badge: 'bg-white/20 text-white border border-white/20',
        button: 'text-red-800',
        watermark: 'text-white',
    },
    purple: {
        card: 'border-purple-500/30 bg-gradient-to-r from-purple-600 to-purple-800 shadow-purple-500/20',
        slide: 'bg-gradient-to-br from-purple-800 via-purple-700 to-fuchsia-800 shadow-purple-900/30',
        tile: 'bg-purple-50 text-purple-600 dark:bg-purple-900/20 dark:text-purple-400',
        badge: 'bg-white/20 text-white border border-white/20',
        button: 'text-purple-800',
        watermark: 'text-purple-200',
    },
    orange: {
        card: 'border-orange-500/30 bg-gradient-to-r from-orange-500 to-orange-700 shadow-orange-500/20',
        slide: 'bg-gradient-to-br from-orange-600 to-amber-700 shadow-orange-900/30',
        tile: 'bg-orange-50 text-orange-600 dark:bg-orange-900/20 dark:text-orange-400',
        badge: 'bg-white/20 text-white border border-white/20',
        button: 'text-orange-800',
        watermark: 'text-orange-100',
    },
    gold: {
        card: 'border-yellow-500/30 bg-gradient-to-r from-gray-900 to-black shadow-black/40',
        slide: 'border border-yellow-500/30 bg-gradient-to-br from-gray-900 via-gray-800 to-black shadow-black/50',
        tile: 'bg-yellow-50 text-yellow-600 dark:bg-yellow-900/20 dark:text-yellow-400',
        badge: 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/50',
        button: 'text-gray-900',
        watermark: 'text-yellow-500',
    },
    gray: {
        card: 'border-gray-500/30 bg-gradient-to-r from-gray-700 to-gray-900 shadow-gray-500/20',
        slide: 'bg-gradient-to-br from-gray-700 to-gray-900 shadow-gray-900/30',
        tile: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
        badge: 'bg-white/20 text-white border border-white/20',
        button: 'text-gray-900',
        watermark: 'text-white',
    },
};

export function themeOf(name) {
    return THEMES[name] || THEMES.blue;
}

// Internal paths use the SPA router; full web addresses open normally.
export function SmartLink({ href, children, ...props }) {
    if (/^https?:\/\//i.test(href)) {
        return (
            <a href={href} target="_blank" rel="noopener noreferrer" {...props}>
                {children}
            </a>
        );
    }

    return (
        <Link href={href} {...props}>
            {children}
        </Link>
    );
}
