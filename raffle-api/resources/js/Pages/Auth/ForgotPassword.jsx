import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Button from '../../Components/ui/Button';
import { Card } from '../../Components/ui/Card';
import { TextInput } from '../../Components/ui/TextInput';
import { apiPost } from '../../lib/api';
import { useSite } from '../../lib/site';
import Turnstile from '../../Components/Turnstile';
import SimpleTop from '../../Components/layout/SimpleTop';

export default function ForgotPassword() {
    const [email, setEmail] = useState('');
    const site = useSite();
    const turnstileKey = site.turnstile?.forms?.includes('forgot_password') ? site.turnstile.site_key : null;
    const [turnstileToken, setTurnstileToken] = useState('');
    const [status, setStatus] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(e) {
        e.preventDefault();
        setStatus(null);
        setSubmitting(true);

        try {
            const data = await apiPost('/api/auth/forgot-password', { email, turnstile_token: turnstileToken || null });
            setStatus({ type: 'success', message: data.message });
            setTimeout(() => router.visit(`/reset-password?email=${encodeURIComponent(email)}`), 1200);
        } catch (err) {
            setStatus({ type: 'error', message: err.message });
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <>
            <Head title="Forgot password" />
            <div className="flex min-h-[100dvh] flex-col bg-app-bg dark:bg-dark-bg">
                <SimpleTop back={'/login'} />
                <div className="flex flex-1 items-center justify-center px-4 pb-12 pt-4">
                <Card className="w-full max-w-sm">
                    <h1 className="mb-1 text-xl font-bold">Reset your password</h1>
                    <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        We'll send a 6-digit code to your email.
                    </p>

                    {status && (
                        <div
                            className={
                                status.type === 'success'
                                    ? 'mb-4 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-900/20 dark:text-green-400'
                                    : 'mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400'
                            }
                        >
                            {status.message}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <TextInput
                            label="Email address"
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                        />

                        {turnstileKey && (
                            <Turnstile siteKey={turnstileKey} onToken={setTurnstileToken} onExpire={() => setTurnstileToken('')} />
                        )}

                        <Button type="submit" disabled={submitting || (turnstileKey && ! turnstileToken)}>
                            {submitting ? 'Sending…' : 'Send Code'}
                        </Button>
                    </form>
                </Card>
            </div>
            </div>
        </>
    );
}
