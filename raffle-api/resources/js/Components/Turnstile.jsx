import { useEffect, useRef } from 'react';

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
let scriptPromise = null;

function loadScript() {
    if (scriptPromise) {
        return scriptPromise;
    }

    scriptPromise = new Promise((resolve, reject) => {
        if (window.turnstile) {
            resolve(window.turnstile);
            return;
        }

        const script = document.createElement('script');
        script.src = SCRIPT_SRC;
        script.async = true;
        script.onload = () => resolve(window.turnstile);
        script.onerror = reject;
        document.head.appendChild(script);
    });

    return scriptPromise;
}

// The Cloudflare Turnstile bot-protection widget — fully wired up on the
// legacy site (site key, callback JS, a working server-side verify call)
// but never actually turned on: the widget div, the client token check,
// and the server verify call were all commented out in register.php.
// This is that widget, actually rendered and actually required.
export default function Turnstile({ siteKey, onToken, onExpire }) {
    const containerRef = useRef(null);
    const widgetIdRef = useRef(null);

    useEffect(() => {
        if (! siteKey) {
            return;
        }

        let cancelled = false;

        loadScript().then((turnstile) => {
            if (cancelled || ! containerRef.current || ! turnstile) {
                return;
            }

            widgetIdRef.current = turnstile.render(containerRef.current, {
                sitekey: siteKey,
                callback: (token) => onToken(token),
                'expired-callback': () => onExpire?.(),
                'error-callback': () => onExpire?.(),
            });
        });

        return () => {
            cancelled = true;

            if (widgetIdRef.current && window.turnstile) {
                window.turnstile.remove(widgetIdRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [siteKey]);

    if (! siteKey) {
        return null;
    }

    return <div ref={containerRef} className="flex justify-center" />;
}
