import { router } from '@inertiajs/react';

// A "Back" that never strands anyone. The browser's own back only helps
// when the customer got here from another page of this site; someone who
// opened a shared link (WhatsApp, a push alert) has nothing to go back to,
// so history.back() would do nothing or leave the site. We keep the list
// of pages seen inside the app and, when there's no earlier one, go to a
// sensible page of the site instead.
const trail = typeof window !== 'undefined' ? [window.location.pathname + window.location.search] : [];

if (typeof window !== 'undefined') {
    router.on('navigate', (event) => {
        const url = event.detail.page.url;
        if (trail[trail.length - 1] === url) return; // the page we opened on
        if (trail[trail.length - 2] === url) trail.pop(); // went back
        else trail.push(url);
    });
}

function cameFromThisSite() {
    try {
        return document.referrer !== '' && new URL(document.referrer).origin === window.location.origin;
    } catch {
        return false;
    }
}

export function goBack(fallback = '/') {
    if (trail.length > 1 || (cameFromThisSite() && window.history.length > 1)) {
        window.history.back();
    } else {
        router.visit(fallback);
    }
}
