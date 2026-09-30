import { useEffect, useRef } from 'react';
import { X } from 'lucide-react';

/**
 * A panel that slides up from the bottom of the screen (a centred card on wide
 * screens). It closes on the X, on a tap outside, and on Escape; the page
 * behind stops scrolling while it is open; and focus goes back to the button
 * that opened it when it closes.
 */
export default function BottomSheet({ open, onClose, title, children, keepMounted = false }) {
    const closeButton = useRef(null);
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;

    useEffect(() => {
        if (! open) {
            return undefined;
        }

        const opener = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        const onKey = (e) => {
            if (e.key === 'Escape') {
                onCloseRef.current();
            }
        };

        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        closeButton.current?.focus();

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = previousOverflow;
            if (opener && typeof opener.focus === 'function') {
                opener.focus();
            }
        };
    }, [open]);

    if (! open && ! keepMounted) {
        return null;
    }

    return (
        <div className={`fixed inset-0 z-[70] flex items-end justify-center sm:items-center ${open ? '' : 'hidden'}`} role="dialog" aria-modal="true" aria-label={title}>
            <div className="rk-fade absolute inset-0 bg-gray-950/60" onClick={() => onCloseRef.current()} aria-hidden="true" />
            <div className="rk-sheet relative flex max-h-[90dvh] w-full max-w-lg flex-col rounded-t-3xl bg-white shadow-2xl dark:bg-dark-card sm:rounded-3xl">
                <div className="mx-auto mt-2 h-1 w-10 rounded-full bg-gray-200 dark:bg-gray-700 sm:hidden" aria-hidden="true" />
                <div className="flex items-center justify-between gap-3 px-5 pb-2 pt-3">
                    <h2 className="text-lg font-extrabold tracking-tight text-gray-900 dark:text-white">{title}</h2>
                    <button
                        ref={closeButton}
                        type="button"
                        onClick={() => onCloseRef.current()}
                        className="rounded-full p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-white"
                        aria-label="Close"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <div className="overflow-y-auto px-5 pb-[calc(1.25rem+env(safe-area-inset-bottom,0px))]">{children}</div>
            </div>
        </div>
    );
}
