/**
 * SYSTEM PULSE WATCHDOG
 * "The Black Box" - Captures frontend crashes and network failures.
 * Updated: Removed X-WP-Nonce to prevent 403 Forbidden errors on public logging.
 */

(function() {
    'use strict';

    // 1. CONFIGURATION
    let ENDPOINT = 'ajax-router.php?action=system_log';

    if (typeof API_CONFIG !== 'undefined' && API_CONFIG.SYSTEM_LOG) {
        ENDPOINT = API_CONFIG.SYSTEM_LOG;
    }

    // 2. USER ID HELPER
    function getUserId() {
        try {
            let user = localStorage.getItem('rk_user_id');
            if (user) return parseInt(user);

            user = sessionStorage.getItem('rk_user_id');
            if (user) return parseInt(user);

            if (window.rk_vars && window.rk_vars.user_id) return parseInt(window.rk_vars.user_id);
        } catch (e) { return 0; }
        return 0;
    }

    // 3. THE REPORTER
    function reportIncident(type, message, file, line) {
        // Prevent infinite loops
        if (message && typeof message === 'string' && message.includes('system/log')) return;

        const payload = {
            user_id: getUserId(),
            type: type,
            message: message,
            file: file || window.location.href,
            line: line || 0,
            timestamp: new Date().toISOString()
        };

        // Use fetch with keepalive.
        // CRITICAL FIX: Do NOT send X-WP-Nonce. This allows unauthenticated logging.
        fetch(ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload),
            keepalive: true,
        })
        .then(response => {
            if (!response.ok) {
                // If it fails, we just log to console, don't retry to avoid loops
                console.warn(`⚠️ System Pulse Rejected: ${response.status}`);
            } else {
                console.log("✅ System Pulse: Incident logged.");
            }
        })
        .catch(err => {
            console.error("⚠️ System Pulse Network Error", err);
        });
    }

    // 4. LISTENERS

    // A. Javascript Crash Listener
    window.onerror = function(msg, url, lineNo, columnNo, error) {
        const stringMsg = msg ? msg.toString() : 'Unknown Error';

        if (stringMsg.indexOf('Script error') > -1) return;
        if (stringMsg.includes('ResizeObserver loop')) return;

        console.log("🚨 Crash Detected: Reporting to System Pulse...");
        reportIncident('JS Crash', stringMsg, url, lineNo);
        return false;
    };

    // B. Promise Rejection Listener
    window.onunhandledrejection = function(event) {
        let reason = 'Unknown Promise Error';
        if (event.reason) {
            if (event.reason.message) reason = event.reason.message;
            else reason = event.reason.toString();
        }

        if (reason.includes('AbortError')) return;

        console.log("🚨 API Fail Detected: Reporting to System Pulse...");
        reportIncident('API/Promise Fail', reason, 'N/A', 0);
    };

    // 5. MANUAL TEST TRIGGER
    window.testSystemPulse = function() {
        console.log("🧪 Sending Test Pulse...");
        reportIncident('Manual Test', 'This is a test from the browser console.', 'console', 1);
        alert("Test Pulse Sent! Check Admin Dashboard > System Pulse.");
    };

    console.log("🛡️ System Pulse Watchdog Active (v3 - No Auth)");

})();
