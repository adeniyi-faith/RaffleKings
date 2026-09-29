import { ArrowLeft } from 'lucide-react';

/** The plain white sticky header with a back arrow used by the Phase 11 pages. */
export default function PageTop({ title, subtitle, right = null }) {
    return (
        <div className="sticky top-0 z-30 flex items-center gap-3 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur-md dark:border-dark-border dark:bg-dark-bg/95">
            <button onClick={() => window.history.back()} className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white" aria-label="Back">
                <ArrowLeft className="h-5 w-5" />
            </button>
            <div className="min-w-0 flex-1">
                <h2 className="truncate text-lg font-bold text-gray-900 dark:text-white">{title}</h2>
                {subtitle && <p className="truncate text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}
            </div>
            {right}
        </div>
    );
}
