import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BookOpen, Heart, Play, RotateCw, WifiOff } from 'lucide-react';

// Rebuild of tutorials.php (item 29) against the new GET /api/tutorials
// (TutorialController/TutorialReadService), which reads the same real
// `tutorial` WordPress CPT the legacy page's REST call did — this is
// migrated real content, not placeholder copy. Preserved from the
// legacy page: the featured-video hero card, the guide list with a
// thumbnail/heart-count/category badge, and the full-article reading
// sheet with an embedded video when one is set.
export default function Tutorials() {
    const [state, setState] = useState('loading'); // loading | error | ready
    const [featured, setFeatured] = useState(null);
    const [articles, setArticles] = useState([]);
    const [active, setActive] = useState(null);

    useEffect(() => {
        fetchTutorials();
    }, []);

    function fetchTutorials() {
        setState('loading');
        fetch('/api/tutorials')
            .then((res) => (res.ok ? res.json() : Promise.reject()))
            .then((data) => {
                setFeatured(data.featured ?? null);
                setArticles(data.list ?? []);
                setState('ready');
            })
            .catch(() => setState('error'));
    }

    async function markHelpful(article, e) {
        e?.stopPropagation();

        const bump = (a) => (a ? { ...a, helpful_count: a.helpful_count + 1 } : a);
        setFeatured((f) => (f?.id === article.id ? bump(f) : f));
        setArticles((list) => list.map((a) => (a.id === article.id ? bump(a) : a)));
        if (active?.id === article.id) setActive(bump(active));

        try {
            await fetch(`/api/tutorials/${article.id}/helpful`, { method: 'POST', headers: { Accept: 'application/json' } });
        } catch {
            // Optimistic update only — a failed request just leaves the count as-is next reload.
        }
    }

    return (
        <>
            <Head title="Learning Hub" />
            <div className="relative min-h-screen bg-gray-50 pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center justify-between border-b border-gray-100 bg-white px-5 pb-4 pt-4 dark:border-dark-border dark:bg-dark-bg">
                    <div className="flex items-center gap-3">
                        <Link href="/support" className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white">
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
                                <div
                                    onClick={() => setActive(featured)}
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
                                </div>
                                <div className="flex items-center justify-end px-1">
                                    <button onClick={(e) => markHelpful(featured, e)} className="flex items-center gap-1 text-gray-400 hover:text-red-500">
                                        <Heart className="h-4 w-4" />
                                        <span className="text-xs">{featured.helpful_count}</span>
                                    </button>
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
                                        <div
                                            key={article.id}
                                            onClick={() => setActive(article)}
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
                                                    <button onClick={(e) => markHelpful(article, e)} className="flex items-center gap-1 text-gray-400 hover:text-red-500">
                                                        <Heart className="h-3 w-3" />
                                                        <span className="text-[10px]">{article.helpful_count}</span>
                                                    </button>
                                                    <span className="rounded-full bg-blue-50 px-2 text-[10px] text-blue-500 dark:bg-blue-900/30 dark:text-blue-300">
                                                        {article.category}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
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

            {active && (
                <>
                    <div className="fixed inset-0 z-[60] bg-black/60 backdrop-blur-sm" onClick={() => setActive(null)} />
                    <div className="fixed bottom-0 left-0 z-[70] flex h-[85vh] w-full flex-col rounded-t-3xl bg-white shadow-2xl sm:left-1/2 sm:max-w-md sm:-translate-x-1/2 dark:bg-dark-card">
                        <div className="flex flex-shrink-0 justify-center pt-3 pb-1" onClick={() => setActive(null)}>
                            <div className="h-1.5 w-12 rounded-full bg-gray-200 dark:bg-gray-700" />
                        </div>
                        <div className="flex-1 overflow-y-auto px-6 pb-10 pt-2">
                            <span className="mb-3 inline-block rounded bg-blue-50 px-2 py-1 text-[10px] font-bold text-blue-600 dark:bg-blue-900/30 dark:text-blue-300">
                                {active.category}
                            </span>
                            <h1 className="mb-4 text-2xl font-bold leading-tight text-gray-900 dark:text-white">{active.title}</h1>
                            <p className="mb-6 border-b border-gray-100 pb-6 text-xs text-gray-400 dark:border-gray-800">{active.date_ago}</p>

                            {active.video_url && (
                                <div className="mb-6 aspect-video overflow-hidden rounded-xl border border-gray-200 shadow-sm dark:border-gray-800">
                                    <iframe
                                        src={active.video_url.replace('watch?v=', 'embed/')}
                                        className="h-full w-full"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowFullScreen
                                    />
                                </div>
                            )}

                            <div className="prose prose-sm dark:prose-invert max-w-none" dangerouslySetInnerHTML={{ __html: active.content }} />

                            <button
                                onClick={(e) => markHelpful(active, e)}
                                className="mt-8 flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-bold text-gray-600 hover:border-red-200 hover:text-red-500 dark:border-gray-700 dark:text-gray-300"
                            >
                                <Heart className="h-4 w-4" /> Helpful ({active.helpful_count})
                            </button>
                        </div>
                    </div>
                </>
            )}
        </>
    );
}
