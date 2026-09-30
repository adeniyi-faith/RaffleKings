import { Head, Link } from '@inertiajs/react';
import { BellOff, BellRing } from 'lucide-react';

// The "stop reminders" link in reminder emails lands here. One tap has
// already done it; "Undo" turns them back on.
export default function RemindersUnsubscribed({ stopped, undoUrl, stopUrl }) {
    const Icon = stopped ? BellOff : BellRing;

    return (
        <>
            <Head title={stopped ? 'Reminders stopped' : 'Reminders on'} />
            <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-6 py-12 text-center dark:bg-dark-bg">
                <div className="mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                    <Icon className="h-8 w-8 text-app-primary" />
                </div>
                <h1 className="text-xl font-bold text-gray-900 dark:text-white">
                    {stopped ? 'Reminders stopped' : 'Reminders are back on'}
                </h1>
                <p className="mt-2 max-w-xs text-sm text-gray-500 dark:text-gray-400">
                    {stopped
                        ? 'We won\'t send you "ends soon" or "left in checkout" reminders any more. You\'ll still get receipts, wins and payout updates.'
                        : 'We\'ll remind you before raffles you played end, and if you leave tickets in checkout.'}
                </p>
                <div className="mt-6 flex flex-col gap-3">
                    <a href={stopped ? undoUrl : stopUrl} className="text-sm font-bold text-app-primary">
                        {stopped ? 'Undo, keep reminding me' : 'Stop reminders again'}
                    </a>
                    <Link href="/" className="rounded-xl bg-app-primary px-6 py-3 text-sm font-bold text-white">
                        Go to raffles
                    </Link>
                </div>
            </div>
        </>
    );
}
