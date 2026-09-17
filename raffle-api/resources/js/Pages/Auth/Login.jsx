import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowRight, Lock, Mail, Zap } from 'lucide-react';
import Button from '../../Components/ui/Button';
import { Card } from '../../Components/ui/Card';
import { TextInput, PasswordInput } from '../../Components/ui/TextInput';
import { apiPost } from '../../lib/api';
import { safeRedirect } from '../../lib/safeRedirect';

export default function Login({ redirect }) {
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
            <div className="flex min-h-screen items-center justify-center bg-app-bg px-4 py-12 dark:bg-dark-bg">
                <div className="w-full max-w-sm">
                    <div className="mb-8 text-center">
                        <div className="mx-auto mb-6 flex h-16 w-16 rotate-3 items-center justify-center rounded-2xl bg-white shadow-lg dark:bg-dark-card">
                            <Zap className="h-8 w-8 fill-current text-app-primary" />
                        </div>
                        <h1 className="text-2xl font-extrabold text-gray-900 dark:text-white">Resume Mission</h1>
                        <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Welcome back, winner.</p>
                    </div>

                    <Card className="rounded-3xl p-8 shadow-xl shadow-gray-200/50 dark:shadow-none">
                        {error && (
                            <div className="mb-4 flex items-center justify-center gap-2 rounded-lg border border-red-100 bg-red-50 p-3 text-center text-xs font-bold text-red-600 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-400">
                                {error}
                            </div>
                        )}

                        <form onSubmit={handleSubmit} className="space-y-5">
                            <TextInput
                                label="Email or username"
                                icon={Mail}
                                value={form.username}
                                onChange={update('username')}
                                required
                            />
                            <PasswordInput
                                label="Password"
                                icon={Lock}
                                value={form.password}
                                onChange={update('password')}
                                required
                            />
                            <div className="-mt-3 flex justify-end">
                                <a href="/forgot-password" className="text-xs font-bold text-app-primary hover:text-app-secondary">
                                    Forgot password?
                                </a>
                            </div>

                            <Button type="submit" variant="inverted" disabled={submitting}>
                                {submitting ? 'Logging in…' : (
                                    <>
                                        Login Now <ArrowRight className="h-4 w-4" />
                                    </>
                                )}
                            </Button>
                        </form>
                    </Card>

                    <p className="mt-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        Don&apos;t have an identity yet?{' '}
                        <a
                            href={redirect ? `/register?redirect=${encodeURIComponent(redirect)}` : '/register'}
                            className="font-bold text-app-primary hover:underline"
                        >
                            Create One
                        </a>
                    </p>
                </div>
            </div>
        </>
    );
}
