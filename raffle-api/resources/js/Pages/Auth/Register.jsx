import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Gift, Lock, Mail, User, UserPlus } from 'lucide-react';
import Button from '../../Components/ui/Button';
import { Card } from '../../Components/ui/Card';
import { TextInput, PasswordInput } from '../../Components/ui/TextInput';
import Turnstile from '../../Components/Turnstile';
import { apiPost } from '../../lib/api';
import PausedNotice from '../../Components/layout/PausedNotice';
import { safeRedirect } from '../../lib/safeRedirect';

export default function Register({ turnstileSiteKey, referralCode, referrerName, redirect }) {
    const [form, setForm] = useState({ username: '', email: '', password: '' });
    const [accepted, setAccepted] = useState(false);
    const [turnstileToken, setTurnstileToken] = useState('');
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    const update = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

    async function handleSubmit(e) {
        e.preventDefault();
        setError(null);
        setSubmitting(true);

        try {
            await apiPost('/api/auth/register', {
                ...form,
                referral_code: referralCode || null,
                accept_terms: accepted,
                turnstile_token: turnstileToken || null,
            });
            router.visit(safeRedirect(redirect));
        } catch (err) {
            setError(err.message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <>
            <Head title="Create account" />
            <PausedNotice feature="registrations" className="mx-4 mt-3" />
            <div className="flex min-h-screen items-center justify-center bg-app-bg px-4 py-12 dark:bg-dark-bg">
                <Card className="w-full max-w-sm rounded-3xl p-8 shadow-xl shadow-gray-200/50 dark:shadow-none">
                    <div className="mb-6 text-center">
                        <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                            <UserPlus className="h-6 w-6" />
                        </div>
                        <h1 className="text-xl font-extrabold text-gray-900 dark:text-white">Create Identity</h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Join RaffleKings and get a ₦300 welcome bonus.
                        </p>
                    </div>

                    {referrerName && (
                        <div className="mb-4 flex items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/40 dark:bg-amber-900/20">
                            <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-amber-400 text-white">
                                <Gift className="h-4 w-4" />
                            </div>
                            <p className="text-sm text-amber-900 dark:text-amber-200">
                                Invited by <span className="font-bold">{referrerName}</span>. Sign up to start playing together.
                            </p>
                        </div>
                    )}

                    {error && (
                        <div className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                            {error}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <TextInput
                            label="Username"
                            icon={User}
                            placeholder="e.g. lucky_winner"
                            autoComplete="username"
                            maxLength={60}
                            value={form.username}
                            onChange={update('username')}
                            required
                            minLength={3}
                        />
                        <TextInput
                            label="Email address"
                            icon={Mail}
                            type="email"
                            placeholder="you@example.com"
                            autoComplete="email"
                            maxLength={100}
                            value={form.email}
                            onChange={update('email')}
                            required
                        />
                        <PasswordInput
                            label="Password"
                            icon={Lock}
                            placeholder="At least 8 characters, with a number"
                            autoComplete="new-password"
                            value={form.password}
                            onChange={update('password')}
                            required
                            minLength={8}
                        />

                        <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs leading-relaxed text-gray-600 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
                            <input
                                type="checkbox"
                                checked={accepted}
                                onChange={(e) => setAccepted(e.target.checked)}
                                className="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-gray-300 text-app-primary focus:ring-app-primary"
                                required
                            />
                            <span>
                                I am <strong className="text-gray-900 dark:text-white">18 or older</strong> and I accept the{' '}
                                <a href="/terms" target="_blank" rel="noopener" className="font-semibold text-app-primary underline">
                                    Terms of Service
                                </a>{' '}
                                and{' '}
                                <a href="/privacy-policy" target="_blank" rel="noopener" className="font-semibold text-app-primary underline">
                                    Privacy Policy
                                </a>
                                .
                            </span>
                        </label>

                        {turnstileSiteKey && (
                            <Turnstile
                                siteKey={turnstileSiteKey}
                                onToken={setTurnstileToken}
                                onExpire={() => setTurnstileToken('')}
                            />
                        )}

                        <Button type="submit" disabled={submitting || ! accepted || (turnstileSiteKey && ! turnstileToken)}>
                            {submitting ? 'Creating account…' : 'Create account'}
                        </Button>
                    </form>

                    <p className="mt-4 text-center text-xs text-gray-500 dark:text-gray-400">
                        Already have an account?{' '}
                        <a href={redirect ? `/login?redirect=${encodeURIComponent(redirect)}` : '/login'} className="font-semibold text-app-primary">
                            Log in
                        </a>
                    </p>
                </Card>
            </div>
        </>
    );
}
