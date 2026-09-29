import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileText, Info } from 'lucide-react';
import { goBack } from '../lib/nav';
import BottomNav from '../Components/layout/BottomNav';

// Terms of Service and About (item 48): admin-edited pages (Site → Pages),
// laid out like the standalone Privacy Policy page: a back button, a
// heading card, then the text. The body is cleaned on the server.
export default function SitePage({ slug, title, summary, body, updated_at: updatedAt }) {
    const Icon = slug === 'terms' ? FileText : Info;

    return (
        <>
            <Head title={title} />
            <div className="flex min-h-screen w-full flex-col bg-gray-50 pb-24 text-gray-900 dark:bg-dark-bg dark:text-white">
                <div className="sticky top-0 z-50 border-b border-gray-100 bg-white/90 px-5 pb-2 pt-4 backdrop-blur-md dark:border-dark-border dark:bg-dark-bg/95">
                    <div className="flex items-center justify-between gap-3">
                        <button
                            type="button"
                            onClick={() => goBack('/')}
                            className="-ml-2 rounded-full p-2 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                            aria-label="Back"
                        >
                            <ArrowLeft className="h-6 w-6" />
                        </button>
                        <h1 className="truncate text-lg font-bold tracking-tight">{title}</h1>
                        <div className="w-8" />
                    </div>
                </div>

                <main className="flex-1 px-5 pb-20 pt-6">
                    <div className="mx-auto max-w-2xl">
                        <div className="mb-6 text-center">
                            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-app-primary dark:bg-blue-900/30 dark:text-blue-400">
                                <Icon className="h-8 w-8" />
                            </div>
                            <h2 className="text-2xl font-black">{title}</h2>
                            {summary && <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">{summary}</p>}
                            {updatedAt && (
                                <p className="mt-2 text-xs font-medium text-gray-400">
                                    Last updated {new Date(updatedAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}
                                </p>
                            )}
                        </div>

                        <article
                            className="rk-article rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card sm:p-7"
                            dangerouslySetInnerHTML={{ __html: body }}
                        />

                        <p className="mt-8 text-center text-xs text-gray-400">
                            <Link href="/terms" className="hover:underline">Terms of Service</Link>
                            {' · '}
                            <Link href="/privacy-policy" className="hover:underline">Privacy Policy</Link>
                            {' · '}
                            <Link href="/about" className="hover:underline">About</Link>
                        </p>
                    </div>
                </main>
            </div>
            <BottomNav />
        </>
    );
}
