import { X } from 'lucide-react';

// The overlay + panel shell shared (with only copy/handlers differing) by
// every modal across checkout.php, raffle-details.php, and
// register-special.php — the "Convert Funds"/"Top Up"/"Fund Check" modals
// were literally the same markup pasted three times.
export default function Modal({ open, onClose, title, children, size = 'sm' }) {
    if (! open) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-5 backdrop-blur-sm"
            onClick={onClose}
        >
            <div
                onClick={(e) => e.stopPropagation()}
                className={[
                    'w-full rounded-3xl border border-gray-100 bg-white p-6 shadow-2xl dark:border-gray-800 dark:bg-dark-card',
                    size === 'lg' ? 'max-w-md' : 'max-w-sm',
                ].join(' ')}
            >
                {(title || onClose) && (
                    <div className="mb-4 flex items-center justify-between">
                        {title && <h3 className="text-lg font-bold text-gray-900 dark:text-white">{title}</h3>}
                        {onClose && (
                            <button onClick={onClose} className="text-gray-400" aria-label="Close">
                                <X className="h-5 w-5" />
                            </button>
                        )}
                    </div>
                )}
                {children}
            </div>
        </div>
    );
}
