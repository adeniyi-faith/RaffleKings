import { useEffect, useRef, useState } from 'react';
import { X } from 'lucide-react';

// Admin announcements shown on every page (OVERHAUL_CHECKLIST.md item 45)
// — the old site's header.php notice system, rebuilt. Same three
// placements (pop-up at the top, pop-up at the bottom, full-width
// banner), same colours per type, same auto-dismiss countdown bar, and
// the same "how often" rules stored under the SAME browser keys
// (rk_notice_{id}_seen), so an announcement someone already dismissed on
// the old site stays dismissed. New: a slide-in/out animation and an
// optional button link. Mounted once in app.jsx, so it survives page
// navigation instead of re-appearing on every click.
const TYPE_STYLES = {
    info: 'border-blue-500 bg-blue-50 text-blue-900 dark:bg-blue-900/80 dark:border-blue-400 dark:text-blue-50',
    success: 'border-green-500 bg-green-50 text-green-900 dark:bg-green-900/80 dark:border-green-400 dark:text-green-50',
    warning: 'border-orange-500 bg-orange-50 text-orange-900 dark:bg-orange-900/80 dark:border-orange-400 dark:text-orange-50',
    danger: 'border-red-500 bg-red-50 text-red-900 dark:bg-red-900/80 dark:border-red-400 dark:text-red-50',
    promo: 'border-purple-500 bg-purple-50 text-purple-900 dark:bg-purple-900/80 dark:border-purple-400 dark:text-purple-50',
};

const DAY_MS = 24 * 60 * 60 * 1000;

function storage(kind) {
    try {
        return kind === 'session' ? window.sessionStorage : window.localStorage;
    } catch {
        return null;
    }
}

function shouldShow(notice) {
    const key = `rk_notice_${notice.id}_seen`;

    try {
        switch (notice.frequency) {
            case 'once_forever':
                return ! storage('local')?.getItem(key);
            case 'once_day': {
                const lastSeen = Number(storage('local')?.getItem(key) || 0);
                return Date.now() - lastSeen > DAY_MS;
            }
            case 'once_session':
                return ! storage('session')?.getItem(key);
            default:
                return true;
        }
    } catch {
        return true;
    }
}

function rememberDismissed(notice) {
    const key = `rk_notice_${notice.id}_seen`;

    try {
        if (notice.frequency === 'once_session') {
            storage('session')?.setItem(key, String(Date.now()));
        } else if (notice.frequency === 'once_forever' || notice.frequency === 'once_day') {
            storage('local')?.setItem(key, String(Date.now()));
        }
    } catch {
        // Private mode / blocked storage: it simply shows again next time.
    }
}

export default function SiteNotices() {
    const [notices, setNotices] = useState([]);

    useEffect(() => {
        fetch('/api/site-notices', { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : { notices: [] }))
            .then((data) => setNotices((data.notices ?? []).filter(shouldShow)))
            .catch(() => {});
    }, []);

    function dismiss(notice) {
        rememberDismissed(notice);
        setNotices((current) => current.filter((n) => n.id !== notice.id));
    }

    const banners = notices.filter((n) => n.location === 'banner');
    const top = notices.filter((n) => n.location === 'toast_top');
    const bottom = notices.filter((n) => n.location === 'toast_bottom');

    return (
        <>
            {banners.map((notice) => (
                <Notice key={notice.id} notice={notice} onDismiss={dismiss} variant="banner" />
            ))}
            {top.length > 0 && (
                <div className="pointer-events-none fixed left-1/2 top-20 z-[9999] flex w-[92%] max-w-sm -translate-x-1/2 flex-col gap-2">
                    {top.map((notice) => (
                        <Notice key={notice.id} notice={notice} onDismiss={dismiss} />
                    ))}
                </div>
            )}
            {bottom.length > 0 && (
                <div className="pointer-events-none fixed bottom-24 left-1/2 z-[9999] flex w-[92%] max-w-sm -translate-x-1/2 flex-col gap-2">
                    {bottom.map((notice) => (
                        <Notice key={notice.id} notice={notice} onDismiss={dismiss} from="bottom" />
                    ))}
                </div>
            )}
        </>
    );
}

function Notice({ notice, onDismiss, variant = 'toast', from = 'top' }) {
    const [visible, setVisible] = useState(false);
    const [progress, setProgress] = useState(100);
    const closing = useRef(false);

    function close() {
        if (closing.current) {
            return;
        }
        closing.current = true;
        setVisible(false);
        setTimeout(() => onDismiss(notice), 250);
    }

    useEffect(() => {
        const enter = requestAnimationFrame(() => setVisible(true));

        if (notice.dismiss_sec <= 0) {
            return () => cancelAnimationFrame(enter);
        }

        const total = notice.dismiss_sec * 1000;
        const started = Date.now();
        const timer = setInterval(() => {
            const left = Math.max(0, total - (Date.now() - started));
            setProgress((left / total) * 100);
            if (left <= 0) {
                clearInterval(timer);
                close();
            }
        }, 100);

        return () => {
            cancelAnimationFrame(enter);
            clearInterval(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const style = TYPE_STYLES[notice.type] ?? TYPE_STYLES.info;
    const hiddenOffset = from === 'bottom' ? 'translate-y-5' : '-translate-y-5';

    const body = (
        <div className="flex items-start justify-between gap-3">
            <div className="flex-1">
                {notice.title && <h4 className="mb-1 text-sm font-bold">{notice.title}</h4>}
                <p className="whitespace-pre-line text-xs leading-relaxed opacity-95">{notice.message}</p>
                {notice.link_url && (
                    <a
                        href={notice.link_url}
                        onClick={() => rememberDismissed(notice)}
                        className="mt-2 inline-block rounded-lg bg-current/10 px-3 py-1.5 text-xs font-bold underline decoration-2 underline-offset-2"
                    >
                        {notice.link_label || 'Learn more'}
                    </a>
                )}
            </div>
            <button type="button" onClick={close} aria-label="Dismiss announcement" className="-m-1 p-1 opacity-60 transition-opacity hover:opacity-100">
                <X className="h-4 w-4" />
            </button>
        </div>
    );

    if (variant === 'banner') {
        return (
            <div
                role="status"
                className={`relative z-[9999] border-b-4 px-4 py-3 text-center transition-all duration-300 ${style} ${visible ? 'opacity-100' : '-translate-y-2 opacity-0'}`}
            >
                <div className="mx-auto max-w-md text-left">{body}</div>
            </div>
        );
    }

    return (
        <div
            role="status"
            className={`pointer-events-auto relative overflow-hidden rounded-lg border-l-4 p-4 shadow-xl backdrop-blur-sm transition-all duration-300 ${style} ${visible ? 'translate-y-0 opacity-100' : `${hiddenOffset} opacity-0`}`}
        >
            {body}
            {notice.dismiss_sec > 0 && (
                <div className="absolute bottom-0 left-0 h-1 bg-current opacity-30 transition-[width] duration-100 ease-linear" style={{ width: `${progress}%` }} />
            )}
        </div>
    );
}
