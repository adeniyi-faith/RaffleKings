import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Button from '../../Components/ui/Button';
import { Card } from '../../Components/ui/Card';
import { TextInput, PasswordInput } from '../../Components/ui/TextInput';
import { apiPost } from '../../lib/api';

export default function Login() {
    const [form, setForm] = useState({ username: '', password: '' });
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    const update = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));

    async function handleSubmit(e) {
        e.preventDefault();
        setError(null);
        setSubmitting(true);

        try {
            await apiPost('/api/auth/login', form);
            router.visit('/');
        } catch (err) {
            setError(err.message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <>
            <Head title="Log in" />
            <div className="flex min-h-screen items-center justify-center bg-app-bg px-4 py-12 dark:bg-dark-bg">
                <Card className="w-full max-w-sm">
                    <h1 className="mb-6 text-xl font-bold">Welcome back</h1>

                    {error && (
                        <div className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                            {error}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <TextInput
                            label="Email or username"
                            value={form.username}
                            onChange={update('username')}
                            required
                        />
                        <PasswordInput
                            label="Password"
                            value={form.password}
                            onChange={update('password')}
                            required
                        />

                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Logging in…' : 'Login Now'}
                        </Button>
                    </form>

                    <div className="mt-4 flex items-center justify-between text-xs">
                        <a href="/forgot-password" className="text-gray-500 dark:text-gray-400">
                            Forgot password?
                        </a>
                        <a href="/register" className="font-semibold text-app-primary">
                            Create account
                        </a>
                    </div>
                </Card>
            </div>
        </>
    );
}
