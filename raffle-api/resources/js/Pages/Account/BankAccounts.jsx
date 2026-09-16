import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import { ArrowLeft, CreditCard, Lock, PlusCircle, Trash2 } from 'lucide-react';

// Faithful rebuild of bank-details.php + components/user/bank-accounts-list.php
// and add-bank-sheet.php against BankAccountController (item 12) — same
// list layout, same "PRIMARY" ribbon, same bottom-sheet add-account form
// with the same validation copy.
//
// One small, deliberate addition over the legacy page: BankAccountService
// already supports promoting a different saved account to primary
// (`PATCH /api/bank-accounts/{id}/primary`), but the legacy UI never
// exposed any way to use it — the only way to change which account was
// primary was to delete and re-add one. A "Make Primary" action on the
// non-primary card is added here since the capability already exists
// server-side and costs nothing extra to expose; everything else about
// the page (list styling, delete confirmation, add-sheet) is unchanged.
export default function AccountBankAccounts() {
    const [accounts, setAccounts] = useState(null); // null = loading
    const [sheetOpen, setSheetOpen] = useState(false);
    const [form, setForm] = useState({ bank_name: '', account_number: '', account_name: '' });
    const [formError, setFormError] = useState('');
    const [saving, setSaving] = useState(false);

    function loadAccounts() {
        fetch('/api/bank-accounts')
            .then((res) => (res.ok ? res.json() : { accounts: [] }))
            .then((data) => setAccounts(data.accounts || []))
            .catch(() => setAccounts([]));
    }

    useEffect(loadAccounts, []);

    async function saveAccount() {
        const bankName = form.bank_name.trim();
        const accNum = form.account_number.trim();
        const accName = form.account_name.trim();

        if (! bankName || ! accNum || ! accName) {
            setFormError('Please fill in bank name, account number, and account name.');
            return;
        }
        if (! /^\d{10}$/.test(accNum)) {
            setFormError('Nigerian account numbers must be exactly 10 digits.');
            return;
        }
        if (! /^[A-Za-z .'-]{3,80}$/.test(accName)) {
            setFormError('Enter the account name as it appears at the bank.');
            return;
        }

        setFormError('');
        setSaving(true);

        try {
            const response = await fetch('/api/bank-accounts', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ bank_name: bankName, account_number: accNum, account_name: accName }),
            });

            const data = await response.json();

            if (! response.ok) {
                throw new Error(data.message || 'Failed to save.');
            }

            setSheetOpen(false);
            setForm({ bank_name: '', account_number: '', account_name: '' });
            loadAccounts();
        } catch (err) {
            setFormError(err.message);
        } finally {
            setSaving(false);
        }
    }

    async function deleteAccount(id) {
        if (! window.confirm('Are you sure you want to remove this account?')) return;

        try {
            const response = await fetch(`/api/bank-accounts/${id}`, { method: 'DELETE', credentials: 'same-origin' });
            if (! response.ok && response.status !== 204) throw new Error();
            loadAccounts();
        } catch {
            window.alert('Failed to delete.');
        }
    }

    async function makePrimary(id) {
        try {
            const response = await fetch(`/api/bank-accounts/${id}/primary`, {
                method: 'PATCH',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (! response.ok) throw new Error();
            loadAccounts();
        } catch {
            window.alert('Failed to update.');
        }
    }

    const canAddMore = accounts !== null && accounts.length < 2;

    return (
        <>
            <Head title="Bank Accounts" />
            <div className="relative min-h-screen bg-gray-50 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white px-5 pb-4 pt-4 shadow-sm dark:border-dark-border dark:bg-dark-bg dark:shadow-none">
                    <button
                        onClick={() => window.history.back()}
                        className="-ml-1 p-1 text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-200"
                    >
                        <ArrowLeft className="h-5 w-5" />
                    </button>
                    <div>
                        <h2 className="text-xl font-bold text-gray-900 dark:text-white">Bank Accounts</h2>
                        <p className="text-xs text-gray-500 dark:text-gray-400">Manage up to two Nigerian bank accounts for withdrawals.</p>
                    </div>
                </div>

                <section className="space-y-4 p-5">
                    {accounts === null && (
                        <div className="space-y-4">
                            {[0, 1].map((i) => (
                                <div key={i} className="h-20 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />
                            ))}
                        </div>
                    )}

                    {accounts !== null && accounts.length === 0 && (
                        <div className="flex flex-col items-center justify-center py-10 text-center">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                                <CreditCard className="h-8 w-8 text-gray-400 dark:text-gray-500" />
                            </div>
                            <h3 className="font-bold text-gray-900 dark:text-white">No Accounts Linked</h3>
                            <p className="mt-1 max-w-[200px] text-xs text-gray-500 dark:text-gray-400">
                                Link a bank account to withdraw your winnings.
                            </p>
                        </div>
                    )}

                    {accounts !== null &&
                        accounts.map((acc) => (
                            <div
                                key={acc.id}
                                className={[
                                    'relative flex items-center justify-between overflow-hidden rounded-xl border bg-white p-4 shadow-sm dark:bg-dark-card',
                                    acc.is_primary
                                        ? 'border-green-200 ring-1 ring-green-100 dark:border-green-900/50 dark:ring-green-900/30'
                                        : 'border-gray-200 dark:border-gray-700',
                                ].join(' ')}
                            >
                                {acc.is_primary && (
                                    <div className="absolute left-0 top-0 rounded-br-lg bg-green-500 px-2 py-0.5 text-[9px] font-bold text-white">
                                        PRIMARY
                                    </div>
                                )}

                                <div className={`flex items-center gap-4 ${acc.is_primary ? 'mt-2' : ''}`}>
                                    <div className="flex h-10 w-10 items-center justify-center rounded-full border border-gray-100 bg-gray-50 text-xs font-bold text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                        {acc.bank_name.slice(0, 2).toUpperCase()}
                                    </div>
                                    <div>
                                        <h4 className="max-w-[150px] truncate text-sm font-bold text-gray-900 dark:text-white">
                                            {acc.bank_name}
                                        </h4>
                                        <p className="font-mono text-xs text-gray-500 dark:text-gray-400">{acc.account_number}</p>
                                        <p className="mt-0.5 max-w-[150px] truncate text-[10px] font-medium text-gray-400 dark:text-gray-500">
                                            {acc.account_name}
                                        </p>
                                        {! acc.is_primary && (
                                            <button
                                                onClick={() => makePrimary(acc.id)}
                                                className="mt-1 text-[10px] font-bold text-app-primary hover:text-blue-400"
                                            >
                                                Make Primary
                                            </button>
                                        )}
                                    </div>
                                </div>
                                <button
                                    onClick={() => deleteAccount(acc.id)}
                                    className="p-2 text-gray-300 transition-colors hover:text-red-500 dark:text-gray-600 dark:hover:text-red-400"
                                >
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </div>
                        ))}
                </section>

                {canAddMore && (
                    <div className="px-5 pb-5">
                        <button
                            onClick={() => setSheetOpen(true)}
                            className="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 py-4 text-sm font-bold text-gray-400 transition-all hover:border-app-primary hover:bg-blue-50 hover:text-app-primary dark:border-gray-700 dark:text-gray-500 dark:hover:border-app-primary dark:hover:bg-blue-900/10 dark:hover:text-app-primary"
                        >
                            <PlusCircle className="h-5 w-5" />
                            Link New Account
                        </button>
                    </div>
                )}

                <div className="mt-2 px-8 pb-8 text-center">
                    <div className="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 dark:bg-gray-800">
                        <Lock className="h-3 w-3 text-gray-400 dark:text-gray-500" />
                        <span className="text-[10px] font-medium text-gray-500 dark:text-gray-400">Bank details are encrypted</span>
                    </div>
                </div>
            </div>

            {sheetOpen && (
                <>
                    <div
                        onClick={() => setSheetOpen(false)}
                        className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm transition-opacity"
                    />
                    <div className="safe-bottom fixed bottom-0 left-0 z-50 flex h-[70vh] w-full flex-col rounded-t-3xl border-t bg-white shadow-2xl transition-transform dark:border-dark-border dark:bg-dark-card sm:left-1/2 sm:max-w-md sm:-translate-x-1/2">
                        <div
                            className="flex w-full flex-shrink-0 justify-center pb-1 pt-3"
                            onClick={() => setSheetOpen(false)}
                        >
                            <div className="h-1.5 w-12 rounded-full bg-gray-200 dark:bg-gray-700" />
                        </div>

                        <div className="flex-1 overflow-y-auto p-6 pt-2">
                            <h3 className="mb-6 text-lg font-bold text-gray-900 dark:text-white">Link Bank Account</h3>

                            <div className="space-y-5">
                                <div>
                                    <label className="mb-2 block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Bank Name
                                    </label>
                                    <input
                                        type="text"
                                        value={form.bank_name}
                                        onChange={(e) => setForm((f) => ({ ...f, bank_name: e.target.value }))}
                                        placeholder="e.g. GTBank, OPay, Kuda"
                                        className="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3.5 text-sm font-medium text-gray-900 outline-none transition-all placeholder:text-gray-300 focus:border-app-primary focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:placeholder:text-gray-600"
                                    />
                                </div>

                                <div>
                                    <label className="mb-2 block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Account Number
                                    </label>
                                    <input
                                        type="tel"
                                        maxLength={10}
                                        value={form.account_number}
                                        onChange={(e) => setForm((f) => ({ ...f, account_number: e.target.value }))}
                                        placeholder="0123456789"
                                        className="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3.5 font-mono text-lg font-medium text-gray-900 outline-none transition-all placeholder:text-gray-300 focus:border-app-primary focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:placeholder:text-gray-600"
                                    />
                                </div>

                                <div>
                                    <label className="mb-2 block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Account Name
                                    </label>
                                    <input
                                        type="text"
                                        value={form.account_name}
                                        onChange={(e) => setForm((f) => ({ ...f, account_name: e.target.value.toUpperCase() }))}
                                        placeholder="Full Name on Account"
                                        className="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3.5 text-sm font-medium uppercase text-gray-900 outline-none transition-all placeholder:text-gray-300 focus:border-app-primary focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:placeholder:text-gray-600"
                                    />
                                </div>
                            </div>

                            <p className="mt-5 text-xs text-gray-500 dark:text-gray-400">
                                Account number must be exactly 10 digits. Names should match your bank record.
                            </p>

                            {formError && (
                                <div className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                                    {formError}
                                </div>
                            )}

                            <button
                                onClick={saveAccount}
                                disabled={saving}
                                className="mt-8 flex w-full items-center justify-center gap-2 rounded-xl bg-app-primary py-3.5 font-bold text-white shadow-lg shadow-blue-500/30 transition-all active:scale-[0.98] disabled:opacity-60"
                            >
                                {saving ? 'Saving…' : 'Save Account'}
                            </button>
                        </div>
                    </div>
                </>
            )}
        </>
    );
}
