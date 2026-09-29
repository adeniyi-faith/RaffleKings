import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BookOpen, Clock, LifeBuoy } from 'lucide-react';
import HeartButton from '../../Components/support/HeartButton';
import BottomNav from '../../Components/layout/BottomNav';

// One Learning Hub guide on its own page (/support/tutorials/12-title),
// so it can be shared, bookmarked and opened from a notification.
// Content comes from routes/web.php → TutorialReadService::find().
export default function Tutorial({ tutorial, more = [] }) {
    const embed = tutorial.video_url
        ? tutorial.video_url.replace('watch?v=', 'embed/').replace('youtu.be/', 'youtube.com/embed/').replace('vimeo.com/', 'player.vimeo.com/video/')
        : null;

    return (
        <>
            <Head title={tutorial.title}>
                <meta name="description" content={tutorial.excerpt} />
            </Head>
            <div className="relative min-h-screen bg-white pb-28 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur dark:border-dark-border dark:bg-dark-bg/95">
                    <Link href="/support/tutorials" className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white" aria-label="Back to the Learning Hub">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <p className="truncate text-sm font-bold text-gray-900 dark:text-white">Learning Hub</p>
                </div>

                <article className="mx-auto max-w-2xl px-5 pt-6">
                    <span className="inline-block rounded bg-blue-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-blue-600 dark:bg-blue-900/30 dark:text-blue-300">
                        {tutorial.category}
                    </span>
                    <h1 className="mt-3 text-2xl font-bold leading-tight text-gray-900 sm:text-3xl dark:text-white">{tutorial.title}</h1>
                    <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-gray-100 pb-5 text-xs text-gray-400 dark:border-gray-800">
                        <span>{tutorial.date_ago}</span>
                        <span className="inline-flex items-center gap-1">
                            <Clock className="h-3.5 w-3.5" /> {tutorial.read_time} read
                        </span>
                        <HeartButton tutorial={tutorial} />
                    </div>

                    {embed && (
                        <div className="mt-6 aspect-video overflow-hidden rounded-xl border border-gray-200 shadow-sm dark:border-gray-800">
                            <iframe
                                src={embed}
                                title={tutorial.title}
                                className="h-full w-full"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowFullScreen
                            />
                        </div>
                    )}

                    <div className="rk-article mt-6" dangerouslySetInnerHTML={{ __html: tutorial.content }} />

                    <div className="mt-10 flex flex-col items-center gap-3 rounded-2xl bg-gray-50 p-5 text-center dark:bg-dark-card">
                        <p className="text-sm font-bold text-gray-800 dark:text-gray-100">Was this guide helpful?</p>
                        <HeartButton tutorial={tutorial} size="lg" />
                        <Link href="/support" className="mt-1 inline-flex items-center gap-1.5 text-xs font-bold text-app-primary">
                            <LifeBuoy className="h-3.5 w-3.5" /> Still stuck? Ask support
                        </Link>
                    </div>
                </article>

                {more.length > 0 && (
                    <section className="mx-auto max-w-2xl px-5 pt-10">
                        <h2 className="mb-3 text-sm font-bold text-gray-900 dark:text-white">Read next</h2>
                        <div className="space-y-3">
                            {more.map((item) => (
                                <Link
                                    key={item.id}
                                    href={item.url}
                                    className="flex gap-3 rounded-2xl border border-gray-100 bg-white p-3 shadow-sm transition-transform active:scale-[0.99] dark:border-gray-800 dark:bg-dark-card"
                                >
                                    <div className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl bg-gray-100 dark:bg-gray-800">
                                        <BookOpen className="h-6 w-6 text-gray-300 dark:text-gray-600" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <h3 className="line-clamp-2 text-sm font-bold leading-tight text-gray-800 dark:text-gray-100">{item.title}</h3>
                                        <p className="mt-1 text-[11px] text-gray-400">
                                            {item.category} · {item.read_time}
                                        </p>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
            </div>
            <BottomNav />
        </>
    );
}
