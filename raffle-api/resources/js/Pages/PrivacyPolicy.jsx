import { Head } from '@inertiajs/react';
import { ArrowLeft, Check, Cog, Database, Mail, ShieldCheck } from 'lucide-react';

// Faithful rebuild of the legacy privacy-policy.php +
// components/pages/privacy-policy-content.php. That page is
// deliberately standalone (no header.php/footer.php, no wallet/avatar
// top bar, no bottom nav) -- just a back button and the content, kept
// the same way here.
export default function PrivacyPolicy() {
    return (
        <>
            <Head title="Privacy Policy" />
            <div className="flex min-h-screen w-full flex-col bg-gray-50 text-gray-900 transition-colors duration-200 dark:bg-dark-bg dark:text-white">
                <div className="sticky top-0 z-50 border-b border-gray-100 bg-white/90 px-5 pb-2 pt-4 backdrop-blur-md dark:border-dark-border dark:bg-dark-bg/95">
                    <div className="flex items-center justify-between">
                        <button
                            type="button"
                            onClick={() => window.history.back()}
                            className="-ml-2 rounded-full p-2 text-gray-600 transition-colors hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            <ArrowLeft className="h-6 w-6" />
                        </button>
                        <h1 className="text-lg font-bold tracking-tight">Privacy Policy</h1>
                        <div className="w-8" />
                    </div>
                </div>

                <main className="no-scrollbar flex-1 overflow-y-auto p-6 pb-20">
                    <div className="mx-auto max-w-2xl space-y-8">
                        <div className="mb-8 text-center">
                            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-green-50 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                                <ShieldCheck className="h-8 w-8" />
                            </div>
                            <h2 className="text-2xl font-black text-gray-900 dark:text-white">Your Data is Safe</h2>
                            <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                We value your trust. Here is exactly how we handle your information.
                            </p>
                        </div>

                        <div className="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                            <h3 className="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">
                                <Database className="h-4 w-4 text-app-primary" /> Data We Collect
                            </h3>
                            <div className="space-y-3">
                                <Bullet>
                                    <strong className="text-gray-900 dark:text-white">Identity Data:</strong> Name, username, and profile
                                    picture (if uploaded).
                                </Bullet>
                                <Bullet>
                                    <strong className="text-gray-900 dark:text-white">Contact Data:</strong> Email address and phone number
                                    for winner notifications.
                                </Bullet>
                                <Bullet>
                                    <strong className="text-gray-900 dark:text-white">Financial Data:</strong> Bank account numbers provided
                                    strictly for withdrawal purposes. We do NOT store card PINs.
                                </Bullet>
                            </div>
                        </div>

                        <div className="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                            <h3 className="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">
                                <Cog className="h-4 w-4 text-orange-500" /> How We Use It
                            </h3>
                            <p className="mb-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                                Your data is used solely to operate the RaffleKings service. Specifically:
                            </p>
                            <div className="grid gap-2">
                                <UseItem>To process ticket purchases and verify payments.</UseItem>
                                <UseItem>To contact you immediately if you win a prize.</UseItem>
                                <UseItem>To process withdrawals to your bank account.</UseItem>
                            </div>
                        </div>

                        <div>
                            <h3 className="mb-2 text-lg font-bold text-gray-900 dark:text-white">Third-Party Sharing</h3>
                            <p className="mb-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                                We do not sell your personal data. We may share limited data with trusted partners solely for operational
                                purposes:
                            </p>
                            <ul className="ml-2 list-inside list-disc space-y-1 text-sm text-gray-600 dark:text-gray-400">
                                <li>Payment Processors (to verify transfers).</li>
                                <li>Email/SMS Providers (to send OTPs and alerts).</li>
                                <li>Security Services (to detect fraud).</li>
                            </ul>
                        </div>

                        <div className="rounded-xl border border-red-100 bg-red-50 p-4 dark:border-red-900/30 dark:bg-red-900/10">
                            <h3 className="mb-2 text-sm font-bold text-red-600 dark:text-red-400">Data Deletion Rights</h3>
                            <p className="text-xs leading-relaxed text-red-800 dark:text-red-200">
                                You have the right to request the deletion of your account and associated data. To do so, please contact
                                our support team. Note that transaction logs may be retained for legal and auditing purposes.
                            </p>
                        </div>
                    </div>

                    <div className="mt-12 border-t border-gray-100 pt-6 text-center dark:border-gray-800">
                        <a
                            href="mailto:help@rafflekings.com.ng"
                            className="inline-flex items-center gap-2 rounded-full bg-blue-50 px-4 py-2 text-sm font-bold text-app-primary dark:bg-blue-900/30"
                        >
                            <Mail className="h-4 w-4" /> Contact Privacy Officer
                        </a>
                    </div>
                </main>
            </div>
        </>
    );
}

function Bullet({ children }) {
    return (
        <div className="flex gap-3">
            <div className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-gray-400" />
            <p className="text-sm text-gray-600 dark:text-gray-300">{children}</p>
        </div>
    );
}

function UseItem({ children }) {
    return (
        <div className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
            <Check className="h-4 w-4 text-green-500" /> {children}
        </div>
    );
}
