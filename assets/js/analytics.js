/*
 * RaffleKings tracking layer.
 *
 * One place that every page uses to record what visitors do.
 * Other code should call:
 *   rkTrack('event_name', { any: 'details' })   -> record an action
 *   rkTrackPageview()                            -> record a page view (the SPA router does this)
 *   rkIdentify(userId, { traits })               -> link actions to a logged-in user
 *   rkAnalyticsReset()                           -> forget the user (on logout)
 *
 * PostHog collects and shows the data. Google Analytics (if present) still gets
 * page views and events too, so nothing that works today is lost.
 */
(function () {
    'use strict';

    // Paste your PostHog project key here (PostHog > Project settings).
    // While it still says REPLACE_ME, PostHog stays off and nothing is sent to it.
    var POSTHOG_KEY = 'phc_REPLACE_ME';
    // Use 'https://eu.i.posthog.com' instead if your PostHog project is in the EU region.
    var POSTHOG_HOST = 'https://us.i.posthog.com';

    var enabled = POSTHOG_KEY.indexOf('REPLACE_ME') === -1;
    var queue = [];   // calls made before PostHog has finished loading
    var ready = false;

    function safe(fn) {
        try { fn(); } catch (e) { /* tracking must never break the site */ }
    }

    function callPostHog(method, args) {
        if (!enabled) return;
        if (ready && window.posthog) {
            safe(function () { window.posthog[method].apply(window.posthog, args); });
        } else {
            queue.push([method, args]);
        }
    }

    function loadPostHog() {
        if (!enabled) return;
        var s = document.createElement('script');
        s.async = true;
        s.crossOrigin = 'anonymous';
        s.src = POSTHOG_HOST.replace('.i.posthog.com', '-assets.i.posthog.com') + '/static/array.js';
        s.onload = function () {
            safe(function () {
                window.posthog.init(POSTHOG_KEY, {
                    api_host: POSTHOG_HOST,
                    // We send page views ourselves so page changes inside the SPA are counted.
                    capture_pageview: false,
                    capture_pageleave: true,
                    autocapture: true,
                    // Only create a visitor profile once we know who they are.
                    person_profiles: 'identified_only',
                    session_recording: {
                        // Hide what people type, and anything marked with these classes.
                        maskAllInputs: true,
                        maskTextSelector: '.rk-mask, [data-rk-mask]',
                        blockSelector: '.rk-block, [data-rk-block]'
                    }
                });
                ready = true;
                queue.forEach(function (q) { callPostHog(q[0], q[1]); });
                queue = [];
            });
        };
        document.head.appendChild(s);
    }

    var lastPageUrl = null;

    window.rkTrackPageview = function () {
        var url = window.location.pathname + window.location.search;
        if (url === lastPageUrl) return; // avoid counting the same page twice
        lastPageUrl = url;

        callPostHog('capture', ['$pageview']);
        // Google Analytics does not notice page changes inside the SPA on its own.
        if (typeof window.gtag === 'function') {
            safe(function () {
                window.gtag('event', 'page_view', {
                    page_path: url,
                    page_location: window.location.href,
                    page_title: document.title
                });
            });
        }
    };

    window.rkTrack = function (name, props) {
        props = props || {};
        callPostHog('capture', [name, props]);
        if (typeof window.gtag === 'function') {
            safe(function () { window.gtag('event', name, props); });
        }
    };

    window.rkIdentify = function (userId, traits) {
        if (userId === undefined || userId === null || userId === '') return;
        // Use the numeric user ID only. Never pass a name or email here.
        callPostHog('identify', [String(userId), traits || {}]);
        if (typeof window.gtag === 'function') {
            safe(function () { window.gtag('set', { user_id: String(userId) }); });
        }
        try { localStorage.setItem('rk_user_id', String(userId)); } catch (e) {}
    };

    window.rkAnalyticsReset = function () {
        callPostHog('reset', []);
        try { localStorage.removeItem('rk_user_id'); } catch (e) {}
    };

    loadPostHog();

    // Re-link the visitor to their account on every full page load.
    try {
        var savedId = localStorage.getItem('rk_user_id');
        if (savedId) window.rkIdentify(savedId);
    } catch (e) {}

    // First page view of a full page load. Google Analytics already counts this one itself,
    // so we only send it to PostHog here.
    lastPageUrl = window.location.pathname + window.location.search;
    callPostHog('capture', ['$pageview']);

    // App install tracking (previously in analytics-tracker.js, now also goes to PostHog).
    window.addEventListener('beforeinstallprompt', function () {
        callPostHog('capture', ['pwa_install_eligible']);
    });
    window.addEventListener('appinstalled', function () {
        callPostHog('capture', ['pwa_install_success']);
    });
    if ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
        window.navigator.standalone === true) {
        callPostHog('capture', ['pwa_launch']);
    }
})();
