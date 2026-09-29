// Phase 10 monitoring: tells the server about JavaScript errors on this
// site's pages (saved on the admin's System → Health), so a page that
// breaks on a customer's phone doesn't go unnoticed.
//
// Kept quiet on purpose: errors from other websites' scripts and browser
// add-ons (which show only as "Script error.") are skipped, each error is
// sent once per page load, and at most 5 per page load.
const sent = new Set();
let count = 0;

function report(message, source) {
    if (! message || count >= 5) return;
    const text = String(message).slice(0, 1000);
    if (text === 'Script error.' || text.includes('ResizeObserver loop')) return;
    if (source && ! String(source).startsWith(window.location.origin)) return;

    const key = `${text}@${source ?? ''}`;
    if (sent.has(key)) return;
    sent.add(key);
    count += 1;

    try {
        fetch('/api/client-errors', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            credentials: 'same-origin',
            keepalive: true,
            body: JSON.stringify({ message: text, source: source ? String(source).slice(0, 500) : null, page: window.location.pathname.slice(0, 500) }),
        }).catch(() => {});
    } catch {
        // Reporting must never cause an error of its own.
    }
}

if (typeof window !== 'undefined') {
    window.addEventListener('error', (event) => {
        const where = event.filename ? `${event.filename}:${event.lineno}:${event.colno}` : null;
        report(event.message || event.error?.message, where);
    });

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;
        // A failed network request isn't a bug in the page.
        if (reason?.name === 'TypeError' && /fetch|network|load failed/i.test(reason.message ?? '')) return;
        report(`Unhandled: ${reason?.message ?? String(reason)}`, null);
    });
}
