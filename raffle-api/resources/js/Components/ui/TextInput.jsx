import { useId, useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';

// The light-theme text input reused verbatim for username/email/password
// across register-special.php (and the other auth pages).
//
// `icon` is the optional leading glyph login.php/register.php pair with
// every auth field (mail, user, lock icons) — omit it for the plain
// inputs (search, price filters) that never had one.
export function TextInput({ label, icon: Icon, className = '', id, ...props }) {
    const generatedId = useId();
    const inputId = id || generatedId;

    return (
        <div className={className}>
            {label && (
                <label htmlFor={inputId} className="mb-1.5 block text-xs font-bold text-gray-500 dark:text-gray-400">
                    {label}
                </label>
            )}
            <div className="relative">
                {Icon && (
                    <Icon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-300 dark:text-gray-600" />
                )}
                <input
                    id={inputId}
                    className={[
                        'w-full rounded-xl border border-gray-200 bg-gray-50 py-3.5 font-bold text-gray-800 outline-none placeholder-gray-400 transition-all focus:border-app-primary focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-dark-bg dark:text-white dark:placeholder-gray-600',
                        Icon ? 'pl-11 pr-4' : 'px-4',
                    ].join(' ')}
                    {...props}
                />
            </div>
        </div>
    );
}

// Same input, with the show/hide toggle every auth page in the legacy app
// needed — with a real aria-label on the icon-only button (the fix
// .Jules/palette.md's dev log noted was missing there).
export function PasswordInput({ label, icon: Icon, className = '', id, ...props }) {
    const generatedId = useId();
    const inputId = id || generatedId;
    const [visible, setVisible] = useState(false);

    return (
        <div className={className}>
            {label && (
                <label htmlFor={inputId} className="mb-1.5 block text-xs font-bold text-gray-500 dark:text-gray-400">
                    {label}
                </label>
            )}
            <div className="relative">
                {Icon && (
                    <Icon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-300 dark:text-gray-600" />
                )}
                <input
                    id={inputId}
                    type={visible ? 'text' : 'password'}
                    className={[
                        'w-full rounded-xl border border-gray-200 bg-gray-50 py-3.5 pr-10 font-medium text-gray-800 outline-none placeholder-gray-400 transition-all focus:border-app-primary focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-dark-bg dark:text-white dark:placeholder-gray-600',
                        Icon ? 'pl-11' : 'pl-4',
                    ].join(' ')}
                    {...props}
                />
                <button
                    type="button"
                    onClick={() => setVisible((v) => !v)}
                    aria-label={visible ? 'Hide password' : 'Show password'}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                >
                    {visible ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                </button>
            </div>
        </div>
    );
}
