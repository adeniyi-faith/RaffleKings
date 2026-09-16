import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Button from '../../Components/ui/Button';
import { Card } from '../../Components/ui/Card';
import { TextInput, PasswordInput } from '../../Components/ui/TextInput';
import Turnstile from '../../Components/Turnstile';
import { apiPost } from '../../lib/api';

export default function Register({ turnstileSiteKey, referralCode }) {
    const [form, setForm] = useState({ username: '', email: '', password: '' });
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
                turnstile_token: turnstileToken || null,
            });
            router.visit('/');
        } catch (err) {
            setError(err.message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <>
            <Head title="Create account" />
            <div className="flex min-h-screen items-center justify-center bg-app-bg px-4 py-12 dark:bg-dark-bg">
                <Card className="w-full max-w-sm">
                    <h1 className="mb-1 text-xl font-bold">Create your account</h1>
                    <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        Join RaffleKings and get a ₦300 welcome bonus.
                    </p>

                    {error && (
                        <div className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                            {error}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <TextInput
                            label="Username"
                            value={form.username}
                            onChange={update('username')}
                            required
                            minLength={3}
                        />
                        <TextInput
                            label="Email address"
                            type="email"
                            value={form.email}
                            onChange={update('email')}
                            required
                        />
                        <PasswordInput
                            label="Password"
                            value={form.password}
                            onChange={update('password')}
                            required
                            minLength={6}
                        />

                        {turnstileSiteKey && (
                            <Turnstile
                                siteKey={turnstileSiteKey}
                                onToken={setTurnstileToken}
                                onExpire={() => setTurnstileToken('')}
                            />
                        )}

                        <Button type="submit" disabled={submitting || (turnstileSiteKey && ! turnstileToken)}>
                            {submitting ? 'Creating account…' : 'Create account'}
                        </Button>
                    </form>

                    <p className="mt-4 text-center text-xs text-gray-500 dark:text-gray-400">
                        Already have an account?{' '}
                        <a href="/login" className="font-semibold text-app-primary">
                            Log in
                        </a>
                    </p>
                </Card>
            </div>
        </>
    );
}
