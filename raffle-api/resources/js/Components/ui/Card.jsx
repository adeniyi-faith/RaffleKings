// The generic content-card wrapper repeated across all three legacy files
// (checkout.php's Order Summary card, raffle-details.php's Prize Tiers
// card, register-special.php's hook/form cards).
export function Card({ className = '', children, ...props }) {
    return (
        <div
            className={[
                'rounded-2xl border border-gray-100 bg-white p-5 text-gray-900 shadow-sm dark:border-gray-800 dark:bg-dark-card dark:text-gray-100',
                className,
            ]
                .filter(Boolean)
                .join(' ')}
            {...props}
        >
            {children}
        </div>
    );
}

// The selectable-option card used for both ticket bundles
// (raffle-details.php's opt-1/opt-2/... cards) and payment-method choices
// (checkout.php's wallet/earnings/bank cards) — same shape, just a
// different radio-indicator style, so it's unified here behind a
// `radioStyle` prop instead of two near-identical copies.
export function SelectableCard({
    selected = false,
    highlighted = false,
    radioStyle = 'plain',
    className = '',
    children,
    ...props
}) {
    return (
        <div
            role="button"
            tabIndex={0}
            aria-pressed={selected}
            className={[
                'relative cursor-pointer overflow-hidden rounded-xl p-4 text-gray-900 transition-all dark:text-gray-100',
                highlighted
                    ? 'border-2 border-yellow-400 bg-yellow-50/50 shadow-sm dark:bg-yellow-900/20'
                    : selected
                      ? 'border-2 border-app-primary bg-blue-50/50 dark:bg-blue-900/10'
                      : 'border border-gray-200 bg-white hover:border-blue-200 dark:border-gray-800 dark:bg-dark-card dark:hover:border-blue-700',
                className,
            ]
                .filter(Boolean)
                .join(' ')}
            {...props}
        >
            <div className="flex items-center gap-3">
                <div className="flex-1">{children}</div>
                <div
                    className={
                        radioStyle === 'plain'
                            ? 'ml-auto mt-1 h-5 w-5 rounded-full border border-gray-300 dark:border-gray-600'
                            : 'flex h-5 w-5 items-center justify-center rounded-full border-2 border-gray-300 transition-all dark:border-gray-600'
                    }
                >
                    {radioStyle !== 'plain' && selected && <div className="h-2.5 w-2.5 rounded-full bg-app-primary" />}
                </div>
            </div>
        </div>
    );
}
