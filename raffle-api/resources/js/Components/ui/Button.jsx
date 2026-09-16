const VARIANTS = {
    // The blue "app-primary" CTA used for the main purchase/continue action
    // (checkout.php:297, raffle-details.php:364).
    primary: 'bg-app-primary text-white shadow-lg shadow-blue-500/30 active:scale-[0.98]',
    // The inverted dark/white CTA used for auth forms and success-modal
    // actions (register-special.php:212, checkout.php:329).
    inverted:
        'bg-gray-900 dark:bg-white text-white dark:text-gray-900 shadow-lg shadow-gray-900/20 dark:shadow-none active:scale-[0.98]',
    // Disabled-looking placeholder state (checkout.php:259) — pass
    // `disabled` to get this automatically; this variant exists for a
    // deliberately-inert button.
    muted: 'bg-gray-200 dark:bg-gray-800 text-gray-400 dark:text-gray-500 shadow-none cursor-not-allowed',
    // The plain-text "cancel/skip" link-style button repeated verbatim
    // across all three legacy files.
    ghost: 'bg-transparent text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 py-2',
};

const SIZES = {
    md: 'py-3.5 text-sm',
    lg: 'py-4 text-base',
};

export default function Button({
    variant = 'primary',
    size = 'md',
    className = '',
    disabled = false,
    type = 'button',
    children,
    ...props
}) {
    const isGhost = variant === 'ghost';

    return (
        <button
            type={type}
            disabled={disabled}
            className={[
                isGhost ? 'w-full text-xs font-medium transition-colors' : 'w-full rounded-xl font-bold shadow-lg transition-transform',
                isGhost ? VARIANTS.ghost : VARIANTS[disabled ? 'muted' : variant],
                isGhost ? '' : SIZES[size],
                'flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed',
                className,
            ]
                .filter(Boolean)
                .join(' ')}
            {...props}
        >
            {children}
        </button>
    );
}
