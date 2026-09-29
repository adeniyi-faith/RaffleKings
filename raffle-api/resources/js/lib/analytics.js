import { router } from '@inertiajs/react';
import { consentStatus } from './consent';

// The one place the browser records what visitors do (PostHog and Google Analytics).
//
//   track('event_name', { any: 'details' })   record an action
//
// Page views, and linking a visit to a logged-in customer's numeric id, happen
// by themselves on every page change (see startAnalytics below). The keys come
// from the admin's Settings → Analytics and Consent & privacy pages, shared with
// every page as the `analytics` prop. Nothing loads without a key, and — while
// the consent banner is switched on — nothing loads until the visitor accepts.
//
// Money events (purchase paid, top-up paid, withdrawal, win) are recorded by the
// server instead, so ad-blockers and closed tabs can't hide them.

let config = null;
let phLoaded = false;
let phReady = false;
let gaLoaded = false;
let queue = [];
let currentUrl = null;
let currentUserId = null;
let sentUrl = null;
let sentUserId = null;

function safe(fn) {
    try {
        fn();
    } catch {
        /* tracking must never break the site */
    }
}

function allowed() {
    return Boolean(config) && (! config.consent?.required || consentStatus() === 'granted');
}

// Switched off by an admin under System → Tracked Events.
function off(name) {
    return Boolean(config?.disabled_events?.includes(name));
}

function ph(method, ...args) {
    if (! phLoaded) {
        return;
    }

    if (phReady && window.posthog) {
        safe(() => window.posthog[method](...args));
    } else {
        queue.push([method, args]);
    }
}

function loadPostHog() {
    if (phLoaded || ! config.key) {
        return;
    }

    phLoaded = true;

    const script = document.createElement('script');
    script.async = true;
    script.crossOrigin = 'anonymous';
    script.src = `${config.host.replace('.i.posthog.com', '-assets.i.posthog.com')}/static/array.js`;
    script.onload = () => safe(() => {
        window.posthog.init(config.key, {
            api_host: config.host,
            // We send page views ourselves: the site changes pages without a reload.
            capture_pageview: false,
            capture_pageleave: true,
            autocapture: ! off('$autocapture'),
            // A visitor profile is only created once we know who they are.
            person_profiles: 'identified_only',
            disable_session_recording: ! config.recordings,
            session_recording: {
                // Everything typed into a field is hidden, plus anything marked
                // data-rk-mask (balances) or data-rk-block (hide the whole area).
                maskAllInputs: true,
                maskTextSelector: '[data-rk-mask]',
                blockSelector: '[data-rk-block]',
            },
        });
        phReady = true;
        const waiting = queue;
        queue = [];
        waiting.forEach(([method, args]) => ph(method, ...args));
    });
    document.head.appendChild(script);
}

function loadGoogleAnalytics() {
    if (gaLoaded || ! config.ga_id) {
        return;
    }

    gaLoaded = true;
    window.dataLayer = window.dataLayer || [];
    // Google's own snippet: it must push `arguments`, not an array.
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    // Page views are sent by us on each page change, not automatically.
    window.gtag('config', config.ga_id, { send_page_view: false });

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(config.ga_id)}`;
    document.head.appendChild(script);
}

function gtag(...args) {
    if (gaLoaded && typeof window.gtag === 'function') {
        safe(() => window.gtag(...args));
    }
}

// Bring the tools up to date with where the visitor is and who they are.
function sync() {
    if (! allowed()) {
        return;
    }

    loadPostHog();
    loadGoogleAnalytics();

    // Tie actions to the customer's id (never their name or email).
    if (currentUserId && currentUserId !== sentUserId) {
        ph('identify', String(currentUserId));
        gtag('set', { user_id: String(currentUserId) });
    } else if (! currentUserId && sentUserId) {
        ph('reset'); // logged out: the next visitor on this device is a stranger
        gtag('set', { user_id: null });
    }

    sentUserId = currentUserId;

    // Reloading the same page in place (live updates, refreshing balances) is not a new visit.
    if (currentUrl !== null && currentUrl !== sentUrl) {
        sentUrl = currentUrl;

        if (! off('$pageview')) {
            ph('capture', '$pageview');
            gtag('event', 'page_view', {
                page_path: currentUrl,
                page_location: window.location.href,
                page_title: document.title,
            });
        }
    }
}

export function track(name, props = {}) {
    if (! allowed() || off(name)) {
        return;
    }

    ph('capture', name, props);
    gtag('event', name, props);
}

function onConsentChange() {
    if (! config) {
        return;
    }

    if (allowed()) {
        if (window.posthog?.has_opted_out_capturing?.()) {
            safe(() => window.posthog.opt_in_capturing());
        }
        if (config.ga_id) {
            window[`ga-disable-${config.ga_id}`] = false;
        }
        sync();
    } else {
        // Withdrawn: stop everything that is running.
        if (phReady && window.posthog) {
            safe(() => window.posthog.opt_out_capturing());
        }
        if (config.ga_id) {
            window[`ga-disable-${config.ga_id}`] = true;
        }
    }
}

export function startAnalytics() {
    if (typeof window === 'undefined') {
        return;
    }

    router.on('navigate', (event) => {
        const page = event.detail.page;

        if (page.props.analytics && ! config) {
            config = page.props.analytics;
        }

        currentUrl = page.url;
        currentUserId = page.props.auth?.user?.id ?? null;
        sync();
    });

    window.addEventListener('rk-consent-changed', onConsentChange);

    // App install: eligible, installed, and opened from the home screen.
    window.addEventListener('beforeinstallprompt', () => track('app_install_available'));
    window.addEventListener('appinstalled', () => track('app_installed'));

    if (window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true) {
        // Wait a moment: the first page's settings arrive with the first navigation.
        setTimeout(() => track('app_opened_from_home_screen'), 1500);
    }
}
