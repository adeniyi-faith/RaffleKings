import { useEffect, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, BookOpen, Play, RotateCw, WifiOff } from 'lucide-react';
import HeartButton from '../../Components/support/HeartButton';
import { deviceId } from '../../lib/tutorialLikes';

// Rebuild of tutorials.php (item 29) against the new GET /api/tutorials
// (TutorialController/TutorialReadService), which reads the same real
// `tutorial` WordPress CPT the legacy page's REST call did — this is
// migrated real content, not placeholder copy. Preserved from the
// legacy page: the featured-video hero card and the guide list with a
// thumbnail/heart-count/category badge. Each guide opens on its own page
// (Support/Tutorial.jsx) instead of a pop-up sheet.
export default function Tutorials() {
    const { url } = usePage();
    const cameFromSupport = new URLSearchParams(url.split('?')[1] ?? '').get('from') === 'support';
    const [state, setState] = useState('loading'); // loading | error | ready
    const [featured, setFeatured] = useState(null);
    const [articles, setArticles] = useState([]);

    useEffect(() => {
        fetchTutorials();
    }, []);

    function fetchTutorials() {
        setState('loading');
        fetch(`/api/tutorials?device=${encodeURIComponent(deviceId() ?? '')}`)
            .then((res) => (res.ok ? res.json() : Promise.reject()))
            .then((data) => {
                setFeatured(data.featured ?? null);
                setArticles(data.list ?? []);
                setState('ready');
            })
            .catch(() => setState('error'));
    }

    return (
        <>
            <Head title="Learning Hub" />
            <div className="relative min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center justify-between border-b border-gray-100 bg-white px-5 pb-4 pt-4 dark:border-dark-border dark:bg-dark-bg">
                    <div className="flex items-center gap-3">
                        {/* Back goes to wherever the visitor came from: Help & Support only if they
                            opened the hub from there, otherwise their profile. Never a history "back",
                            which used to bounce between this page and Help & Support forever. */}
                        <Link href={cameFromSupport ? '/support' : '/profile'} className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white" aria-label="Back">
                            <ArrowLeft className="h-5 w-5" />
                        </Link>
                        <h2 className="text-xl font-bold text-gray-900 dark:text-white">Learning Hub</h2>
                    </div>
                    <button onClick={fetchTutorials} className="rounded-full bg-gray-100 p-2 text-gray-500 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400">
                        <RotateCw className="h-4 w-4" />
                    </button>
                </div>

                {state === 'loading' && (
                    <div className="flex h-64 flex-col items-center justify-center p-10 text-center text-sm text-gray-400">
                        <div className="mb-3 h-8 w-8 animate-spin rounded-full border-2 border-app-primary border-t-transparent" />
                        <p>Loading guides...</p>
                    </div>
                )}

                {state === 'error' && (
                    <div className="flex h-64 flex-col items-center justify-center p-10 text-center text-sm text-red-400">
                        <WifiOff className="mb-3 h-8 w-8" />
                        <p>Could not load tutorials.</p>
                        <button onClick={fetchTutorials} className="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-2 text-xs font-bold text-gray-700 shadow-sm dark:border-gray-700 dark:bg-dark-card dark:text-gray-200">
                            Try Again
                        </button>
                    </div>
                )}

                {state === 'ready' && (
                    <>
                        {featured && (
                            <section className="p-5 pb-2">
                                <Link
                                    href={featured.url}
                                    className="group relative mb-2 flex aspect-video w-full cursor-pointer items-center justify-center overflow-hidden rounded-2xl bg-gray-900 shadow-lg"
                                >
                                    <div className="absolute inset-0 z-10 bg-gradient-to-t from-black/80 via-transparent to-transparent" />
                                    <div className="relative z-20 flex flex-col items-center">
                                        <div className="flex h-14 w-14 items-center justify-center rounded-full border border-white/30 bg-white/20 shadow-xl backdrop-blur-md transition-transform group-hover:scale-110">
                                            <Play className="ml-1 h-6 w-6 fill-current text-white" />
                                        </div>
                                    </div>
                                    <div className="absolute bottom-4 left-4 z-20">
                                        <span className="mb-1 inline-block rounded bg-red-600 px-2 py-0.5 text-[9px] font-bold text-white">FEATURED</span>
                                        <h3 className="text-lg font-bold leading-tight text-white">{featured.title}</h3>
                                        <p className="text-xs text-gray-300">{featured.date_ago}</p>
                                    </div>
                                </Link>
                                <div className="flex items-center justify-end px-1">
                                    <HeartButton tutorial={featured} />
                                </div>
                            </section>
                        )}

                        {articles.length > 0 && (
                            <section className="px-5 py-4">
                                <h3 className="mb-3 flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
                                    Latest Guides
                                    <span className="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-600 dark:bg-blue-900/30 dark:text-blue-300">Updated</span>
                                </h3>
                                <div className="space-y-4">
                                    {articles.map((article) => (
                                        <Link
                                            key={article.id}
                                            href={article.url}
                                            className="flex cursor-pointer gap-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm transition-transform active:scale-[0.99] dark:border-gray-800 dark:bg-dark-card"
                                        >
                                            <div className="flex h-20 w-20 flex-shrink-0 items-center justify-center rounded-xl bg-gray-100 dark:bg-gray-800">
                                                <BookOpen className="h-8 w-8 text-gray-300 dark:text-gray-600" />
                                            </div>
                                            <div className="flex-1">
                                                <div className="flex items-start justify-between">
                                                    <h4 className="line-clamp-2 text-sm font-bold leading-tight text-gray-800 dark:text-gray-100">{article.title}</h4>
                                                    <span className="ml-2 whitespace-nowrap rounded bg-gray-50 px-1.5 py-0.5 text-[9px] text-gray-400 dark:bg-gray-800">
                                                        {article.read_time}
                                                    </span>
                                                </div>
                                                <p className="mt-1 line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{article.excerpt}</p>
                                                <div className="mt-3 flex items-center gap-4 border-t border-gray-50 pt-2 dark:border-gray-800">
                                                    <HeartButton tutorial={article} />
                                                    <span className="rounded-full bg-blue-50 px-2 text-[10px] text-blue-500 dark:bg-blue-900/30 dark:text-blue-300">
                                                        {article.category}
                                                    </span>
                                                </div>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            </section>
                        )}

                        {! featured && articles.length === 0 && (
                            <div className="flex h-[60vh] flex-col items-center justify-center px-6 text-center">
                                <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                                    <BookOpen className="h-8 w-8 text-gray-300 dark:text-gray-600" />
                                </div>
                                <h3 className="text-lg font-bold text-gray-900 dark:text-white">No guides yet</h3>
                                <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">We are crafting new tutorials for you. Check back soon!</p>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
