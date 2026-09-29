import { router } from '@inertiajs/react';

// The one place the browser records what visitors do (PostHog).
//
//   track('event_name', { any: 'details' })   record an action
//
// Page views, and linking a visit to a logged-in customer's numeric id, happen
// by themselves on every page change (see startAnalytics below). The project key
// comes from the admin's Settings → Analytics page, shared with every page as
// the `analytics` prop; with no key saved, nothing is loaded and nothing is sent.
//
// Money events (purchase paid, top-up paid, withdrawal, win) are recorded by the
// server instead, so ad-blockers and closed tabs can't hide them.

let config = null;
let loading = false;
let ready = false;
let queue = [];
let lastUrl = null;
let lastUserId = null;

function safe(fn) {
    try {
        fn();
    } catch {
        /* tracking must never break the site */
    }
}

function call(method, ...args) {
    if (! config) {
        return;
    }

    if (ready && window.posthog) {
        safe(() => window.posthog[method](...args));
    } else {
        queue.push([method, args]);
    }
}

function load(next) {
    config = next;

    if (loading) {
        return;
    }

    loading = true;

    const script = document.createElement('script');
    script.async = true;
    script.crossOrigin = 'anonymous';
    script.src = `${next.host.replace('.i.posthog.com', '-assets.i.posthog.com')}/static/array.js`;
    script.onload = () => safe(() => {
        window.posthog.init(next.key, {
            api_host: next.host,
            // We send page views ourselves: the site changes pages without a reload.
            capture_pageview: false,
            capture_pageleave: true,
            autocapture: true,
            // A visitor profile is only created once we know who they are.
            person_profiles: 'identified_only',
            disable_session_recording: ! next.recordings,
            session_recording: {
                // Everything typed into a field is hidden, plus anything marked
                // data-rk-mask (balances) or data-rk-block (hide the whole area).
                maskAllInputs: true,
                maskTextSelector: '[data-rk-mask]',
                blockSelector: '[data-rk-block]',
            },
        });
        ready = true;
        const waiting = queue;
        queue = [];
        waiting.forEach(([method, args]) => call(method, ...args));
    });
    document.head.appendChild(script);
}

export function track(name, props = {}) {
    call('capture', name, props);
}

function onPage(page) {
    if (page.props.analytics && ! config) {
        load(page.props.analytics);
    }

    // Tie actions to the customer's id (never their name or email).
    const userId = page.props.auth?.user?.id ?? null;

    if (userId && userId !== lastUserId) {
        call('identify', String(userId));
    } else if (! userId && lastUserId) {
        call('reset'); // logged out: the next visitor on this device is a stranger
    }

    lastUserId = userId;

    // Reloading the same page in place (live updates, refreshing balances) is not a new visit.
    if (page.url !== lastUrl) {
        lastUrl = page.url;
        call('capture', '$pageview');
    }
}

export function startAnalytics() {
    if (typeof window === 'undefined') {
        return;
    }

    router.on('navigate', (event) => onPage(event.detail.page));

    // App install: eligible, installed, and opened from the home screen.
    window.addEventListener('beforeinstallprompt', () => track('app_install_available'));
    window.addEventListener('appinstalled', () => track('app_installed'));

    if (window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true) {
        track('app_opened_from_home_screen');
    }
}
