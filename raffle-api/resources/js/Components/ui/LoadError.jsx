import { RefreshCw, WifiOff } from 'lucide-react';

// Shown when a page couldn't load its data (Phase 9), instead of a blank
// screen or a misleading "₦0" / "nothing here yet". `dark` for the dark
// pages (live draws).
export default function LoadError({ onRetry, message = "Couldn't load this. Check your connection and try again.", dark = false, className = '' }) {
    return (
        <div
            role="alert"
            className={`flex flex-col items-center gap-3 rounded-2xl border p-6 text-center ${
                dark ? 'border-white/10 bg-white/5 text-white' : 'border-gray-100 bg-white text-gray-900 dark:border-gray-800 dark:bg-dark-card dark:text-white'
            } ${className}`}
        >
            <div className={`flex h-12 w-12 items-center justify-center rounded-full ${dark ? 'bg-white/10' : 'bg-red-50 dark:bg-red-900/20'}`}>
                <WifiOff className={`h-6 w-6 ${dark ? 'text-white/60' : 'text-red-500'}`} />
            </div>
            <p className={`text-sm ${dark ? 'text-white/70' : 'text-gray-600 dark:text-gray-300'}`}>{message}</p>
            {onRetry && (
                <button
                    type="button"
                    onClick={onRetry}
                    className="flex items-center gap-2 rounded-xl bg-app-primary px-5 py-2.5 text-sm font-bold text-white shadow-md active:scale-95"
                >
                    <RefreshCw className="h-4 w-4" /> Try again
                </button>
            )}
        </div>
    );
}
