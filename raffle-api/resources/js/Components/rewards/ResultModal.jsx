import { AlertCircle, CheckCircle2, Gift, Info } from 'lucide-react';
import Confetti from '../ui/Confetti';

const LOOKS = {
    success: { icon: CheckCircle2, ring: 'bg-green-100 dark:bg-green-900/30', color: 'text-green-600 dark:text-green-400' },
    win: { icon: Gift, ring: 'bg-yellow-50 ring-4 ring-yellow-100 dark:bg-yellow-900/20 dark:ring-yellow-900/40', color: 'text-yellow-600 dark:text-yellow-400' },
    info: { icon: Info, ring: 'bg-blue-50 dark:bg-blue-900/30', color: 'text-app-primary dark:text-blue-400' },
    // Item 47: errors used to show the same green tick as a success.
    error: { icon: AlertCircle, ring: 'bg-red-100 dark:bg-red-900/30', color: 'text-red-600 dark:text-red-400' },
};

// The Rewards page's one pop-up: { kind, title, message, balance?, confetti? }.
export default function ResultModal({ modal, onClose }) {
    if (! modal) {
        return null;
    }

    const look = LOOKS[modal.kind] ?? LOOKS.success;
    const Icon = look.icon;

    return (
        <>
            {modal.confetti && <Confetti />}
            <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-5 backdrop-blur-sm" onClick={onClose}>
                <div
                    role="dialog"
                    aria-modal="true"
                    onClick={(e) => e.stopPropagation()}
                    className="w-full max-w-sm animate-winner-flash rounded-3xl border border-gray-100 bg-white p-6 text-center dark:border-gray-800 dark:bg-dark-card"
                >
                    <div className={`mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full ${look.ring}`}>
                        <Icon className={`h-9 w-9 ${look.color}`} />
                    </div>
                    <h2 className={`mb-1 font-black text-gray-900 dark:text-white ${modal.kind === 'win' ? 'text-3xl italic tracking-tight' : 'text-xl'}`}>
                        {modal.title}
                    </h2>
                    <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">{modal.message}</p>

                    {modal.balance !== undefined && (
                        <div className="mb-5 flex items-center justify-between rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800/50">
                            <span className="text-xs font-bold uppercase tracking-wider text-gray-400">New balance</span>
                            <span className="font-mono text-lg font-bold text-app-primary">{modal.balance} pts</span>
                        </div>
                    )}

                    <button
                        onClick={onClose}
                        className="w-full rounded-xl bg-gray-900 py-3 text-sm font-bold text-white active:scale-95 dark:bg-white dark:text-gray-900"
                    >
                        {modal.kind === 'error' ? 'OK' : 'Awesome'}
                    </button>
                </div>
            </div>
        </>
    );
}
