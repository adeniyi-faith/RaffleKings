import { useEffect, useMemo, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, BookOpen, Play, RotateCw, Search, WifiOff, X } from 'lucide-react';
import HeartButton from '../../Components/support/HeartButton';
import { deviceId } from '../../lib/tutorialLikes';
import BottomNav from '../../Components/layout/BottomNav';

const PAGE_SIZE = 20;

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
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('All');
    const [visible, setVisible] = useState(PAGE_SIZE);

    useEffect(() => {
        fetchTutorials();
    }, []);

    // The categories, in the order the guides come in, each with how many guides it holds.
    const categories = useMemo(() => {
        const counts = new Map();
        [...(featured ? [featured] : []), ...articles].forEach((a) => counts.set(a.category, (counts.get(a.category) ?? 0) + 1));

        return [...counts.entries()];
    }, [articles, featured]);

    const needle = query.trim().toLowerCase();
    const filtering = needle !== '' || category !== 'All';
    const matches = useMemo(
        () => articles.filter((a) => (category === 'All' || a.category === category)
            && (needle === '' || `${a.title} ${a.excerpt}`.toLowerCase().includes(needle))),
        [articles, category, needle],
    );
    const shown = matches.slice(0, visible);

    function pick(next) {
        setCategory(next);
        setVisible(PAGE_SIZE);
    }

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
                        <div className="px-5 pt-4">
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                                <input
                                    type="search"
                                    value={query}
                                    onChange={(e) => {
                                        setQuery(e.target.value);
                                        setVisible(PAGE_SIZE);
                                    }}
                                    placeholder="Search guides, e.g. withdrawal"
                                    aria-label="Search guides"
                                    className="w-full rounded-xl border border-gray-200 bg-white py-3 pl-10 pr-10 text-sm text-gray-900 outline-none focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-dark-card dark:text-white"
                                />
                                {query && (
                                    <button onClick={() => setQuery('')} className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full p-1 text-gray-400" aria-label="Clear search">
                                        <X className="h-4 w-4" />
                                    </button>
                                )}
                            </div>
                            {categories.length > 1 && (
                                <div className="no-scrollbar -mx-5 mt-3 flex gap-2 overflow-x-auto px-5 pb-1">
                                    {[['All', articles.length + (featured ? 1 : 0)], ...categories].map(([name, count]) => (
                                        <button
                                            key={name}
                                            onClick={() => pick(name)}
                                            className={`flex-shrink-0 whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-bold transition-colors ${
                                                category === name
                                                    ? 'border-app-primary bg-app-primary text-white'
                                                    : 'border-gray-200 bg-white text-gray-600 dark:border-gray-700 dark:bg-dark-card dark:text-gray-300'
                                            }`}
                                        >
                                            {name} <span className="opacity-70">{count}</span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>

                        {featured && ! filtering && (
                            <section className="p-5 pb-2">
                                <Link
                                    href={featured.url}
                                    className="group relative mb-2 flex aspect-video w-full cursor-pointer items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 shadow-lg"
                                >
                                    <div className="absolute inset-0 z-10 bg-gradient-to-t from-black/80 via-black/10 to-transparent" />
                                    {featured.video_url && (
                                        <div className="relative z-20 flex flex-col items-center">
                                            <div className="flex h-14 w-14 items-center justify-center rounded-full border border-white/30 bg-white/20 shadow-xl backdrop-blur-md transition-transform group-hover:scale-110">
                                                <Play className="ml-1 h-6 w-6 fill-current text-white" />
                                            </div>
                                        </div>
                                    )}
                                    <div className="absolute bottom-4 left-4 right-4 z-20">
                                        <span className="mb-1 inline-block rounded bg-red-600 px-2 py-0.5 text-[9px] font-bold text-white">START HERE</span>
                                        <h3 className="text-lg font-bold leading-tight text-white">{featured.title}</h3>
                                        {featured.date_ago && <p className="text-xs text-gray-300">{featured.date_ago}</p>}
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
                                    {filtering ? `${matches.length} ${matches.length === 1 ? 'guide' : 'guides'} found` : 'All guides'}
                                </h3>
                                <div className="space-y-4">
                                    {shown.map((article) => (
                                        <Link
                                            key={article.id}
                                            href={article.url}
                                            className="flex cursor-pointer gap-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm transition-transform active:scale-[0.99] dark:border-gray-800 dark:bg-dark-card"
                                        >
                                            <div className="flex h-20 w-20 flex-shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-800">
                                                {article.image_url ? (
                                                    <img src={article.image_url} alt="" loading="lazy" className="h-full w-full object-cover object-top" />
                                                ) : (
                                                    <BookOpen className="h-8 w-8 text-gray-300 dark:text-gray-600" />
                                                )}
                                            </div>
                                            <div className="min-w-0 flex-1">
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

                                {matches.length === 0 && (
                                    <div className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                        <p className="font-bold text-gray-800 dark:text-gray-100">No guide matches that.</p>
                                        <p className="mt-1">Try a shorter word, or <Link href="/support?new=1" className="font-bold text-app-primary">ask support</Link>.</p>
                                    </div>
                                )}

                                {matches.length > shown.length && (
                                    <button
                                        onClick={() => setVisible((v) => v + PAGE_SIZE)}
                                        className="mt-5 w-full rounded-xl border border-gray-200 bg-white py-3 text-sm font-bold text-gray-700 shadow-sm active:scale-[0.99] dark:border-gray-700 dark:bg-dark-card dark:text-gray-200"
                                    >
                                        Show more guides ({matches.length - shown.length} left)
                                    </button>
                                )}
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
            <BottomNav />
        </>
    );
}
