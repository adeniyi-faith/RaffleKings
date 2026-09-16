import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Button from '../../Components/ui/Button';
import { Card } from '../../Components/ui/Card';
import { TextInput, PasswordInput } from '../../Components/ui/TextInput';
import { apiPost } from '../../lib/api';

export default function ResetPassword({ email }) {
    const [otp, setOtp] = useState('');
    const [password, setPassword] = useState('');
    const [status, setStatus] = useState(null);
    const [busy, setBusy] = useState(false);

    if (! email) {
        router.visit('/forgot-password');
        return null;
    }

    async function verifyCode() {
        setStatus(null);
        setBusy(true);

        try {
            await apiPost('/api/auth/verify-reset-code', { email, otp });
            setStatus({ type: 'success', message: 'Code verified — set your new password below.' });
        } catch (err) {
            setStatus({ type: err.message.includes('expired') ? 'warning' : 'error', message: err.message });
        } finally {
            setBusy(false);
        }
    }

    async function resendCode() {
        setStatus(null);
        setBusy(true);

        try {
            await apiPost('/api/auth/forgot-password', { email });
            setStatus({ type: 'success', message: 'A new code has been sent.' });
        } catch (err) {
            setStatus({ type: 'error', message: err.message });
        } finally {
            setBusy(false);
        }
    }

    async function handleSubmit(e) {
        e.preventDefault();
        setStatus(null);
        setBusy(true);

        try {
            await apiPost('/api/auth/reset-password', { email, otp, password });
            setStatus({ type: 'success', message: 'Password updated. Redirecting to login…' });
            setTimeout(() => router.visit('/login'), 1500);
        } catch (err) {
            setStatus({ type: err.message.includes('expired') ? 'warning' : 'error', message: err.message });
        } finally {
            setBusy(false);
        }
    }

    const statusClasses = {
        success: 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400',
        warning: 'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400',
        error: 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400',
    };

    return (
        <>
            <Head title="Reset password" />
            <div className="flex min-h-screen items-center justify-center bg-app-bg px-4 py-12 dark:bg-dark-bg">
                <Card className="w-full max-w-sm">
                    <h1 className="mb-1 text-xl font-bold">Enter your code</h1>
                    <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">Sent to {email}</p>

                    {status && (
                        <div className={`mb-4 rounded-lg px-3 py-2 text-sm ${statusClasses[status.type]}`}>
                            {status.message}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <TextInput
                            label="6-digit code"
                            value={otp}
                            onChange={(e) => setOtp(e.target.value)}
                            maxLength={6}
                            required
                        />
                        <Button type="button" variant="ghost" onClick={verifyCode} disabled={busy || otp.length !== 6}>
                            Verify Code
                        </Button>

                        <PasswordInput
                            label="New password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                            minLength={6}
                        />

                        <Button type="submit" disabled={busy}>
                            Update Password
                        </Button>
                        <Button type="button" variant="ghost" onClick={resendCode} disabled={busy}>
                            Resend code
                        </Button>
                    </form>
                </Card>
            </div>
        </>
    );
}
