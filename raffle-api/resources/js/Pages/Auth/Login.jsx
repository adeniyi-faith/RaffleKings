import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { AlertCircle, ArrowRight, Eye, EyeOff, Lock, Mail, Zap } from 'lucide-react';
import { apiPost } from '../../lib/api';
import { useSite } from '../../lib/site';
import Turnstile from '../../Components/Turnstile';
import { safeRedirect } from '../../lib/safeRedirect';
import SimpleTop from '../../Components/layout/SimpleTop';

// Matches the legacy login.php page's design exactly (same layout, spacing,
// colors and copy), just rebuilt as a React/Inertia page instead of a raw
// PHP+vanilla-JS form. Dark-mode variants are added on top for consistency
// with the rest of the rebuilt app — the legacy page never supported dark
// mode at all, but everything else here (spacing, borders, type, the
// rotated logo tile, the uppercase field labels) matches it on purpose.
export default function Login({ redirect }) {
    const [form, setForm] = useState({ username: '', password: '' });
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    // Bot check, when switched on for log-in in the admin's Settings → Security.
    const site = useSite();
    const turnstileKey = site.turnstile?.forms?.includes('login') ? site.turnstile.site_key : null;
    const [turnstileToken, setTurnstileToken] = useState('');

    const update = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

    async function handleSubmit(e) {
        e.preventDefault();
        setError(null);
        setSubmitting(true);

        try {
            await apiPost('/api/auth/login', { ...form, turnstile_token: turnstileToken || null });
            router.visit(safeRedirect(redirect));
        } catch (err) {
            setError(err.message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <>
            <Head title="Log in" />
            <div className="flex min-h-[100dvh] flex-col bg-gray-50 dark:bg-dark-bg">
                <SimpleTop back={'/'} />
                <div className="flex flex-1 items-center justify-center px-4 pb-12 pt-4">
                <div className="w-full max-w-sm">
                    <div className="mb-8 text-center">
                        <div className="mx-auto mb-6 flex h-16 w-16 rotate-3 items-center justify-center rounded-2xl bg-white shadow-lg dark:bg-dark-card">
                            <Zap className="h-8 w-8 fill-current text-app-primary" />
                        </div>
                        <h1 className="text-2xl font-extrabold text-gray-900 dark:text-white">Log in</h1>
                        <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Welcome back. Log in to play and collect rewards.</p>
                    </div>

                    <div className="rounded-3xl border border-white bg-white p-8 shadow-xl shadow-gray-200/50 dark:border-gray-800 dark:bg-dark-card dark:shadow-none">
                        {error && (
                            <div className="mb-4 flex items-center justify-center gap-2 rounded-lg border border-red-100 bg-red-50 p-3 text-center text-xs font-bold text-red-600 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-400">
                                <AlertCircle className="h-4 w-4" />
                                <span>{error}</span>
                            </div>
                        )}

                        <form onSubmit={handleSubmit} className="space-y-5">
                            <div>
                                <label
                                    htmlFor="login-username"
                                    className="mb-2 block text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500"
                                >
                                    Email Address
                                </label>
                                <div className="relative">
                                    <Mail className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-300 dark:text-gray-600" />
                                    <input
                                        id="login-username"
                                        type="email"
                                        placeholder="you@example.com"
                                        value={form.username}
                                        onChange={update('username')}
                                        required
                                        className="w-full rounded-xl border border-gray-100 bg-gray-50 py-3.5 pl-11 pr-4 font-medium text-gray-900 outline-none placeholder-gray-400 transition-all focus:border-app-primary/50 focus:bg-white focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-dark-bg dark:text-white dark:placeholder-gray-600"
                                    />
                                </div>
                            </div>

                            <div>
                                <label
                                    htmlFor="login-password"
                                    className="mb-2 block text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500"
                                >
                                    Password
                                </label>
                                <div className="relative">
                                    <Lock className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-300 dark:text-gray-600" />
                                    <input
                                        id="login-password"
                                        type={showPassword ? 'text' : 'password'}
                                        placeholder="••••••••"
                                        value={form.password}
                                        onChange={update('password')}
                                        required
                                        className="w-full rounded-xl border border-gray-100 bg-gray-50 py-3.5 pl-11 pr-12 font-medium text-gray-900 outline-none placeholder-gray-400 transition-all focus:border-app-primary/50 focus:bg-white focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-dark-bg dark:text-white dark:placeholder-gray-600"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword((v) => !v)}
                                        aria-label={showPassword ? 'Hide password' : 'Show password'}
                                        className="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                    >
                                        {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                                    </button>
                                </div>
                                <div className="mt-2 flex justify-end">
                                    <a href="/forgot-password" className="text-xs font-bold text-app-primary hover:text-app-secondary">
                                        Forgot Password?
                                    </a>
                                </div>
                            </div>

                            {turnstileKey && (
                                <Turnstile siteKey={turnstileKey} onToken={setTurnstileToken} onExpire={() => setTurnstileToken('')} />
                            )}
                            <button
                                type="submit"
                                disabled={submitting || (turnstileKey && ! turnstileToken)}
                                className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-gray-900 py-4 font-bold text-white shadow-lg shadow-gray-900/20 transition-transform active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-gray-900 dark:shadow-none"
                            >
                                {submitting ? (
                                    'Logging in…'
                                ) : (
                                    <>
                                        Log in <ArrowRight className="h-4 w-4" />
                                    </>
                                )}
                            </button>
                        </form>
                    </div>

                    <p className="mt-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        New here?{' '}
                        <a
                            href={redirect ? `/register?redirect=${encodeURIComponent(redirect)}` : '/register'}
                            className="font-bold text-app-primary hover:underline"
                        >
                            Create an account
                        </a>
                    </p>
                </div>
            </div>
            </div>
        </>
    );
}
