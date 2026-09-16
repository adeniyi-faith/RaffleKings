/** Only ever returns a same-origin relative path — a `?redirect=` query
 *  param is user-controlled input, so it must never be trusted as a full
 *  URL (that's an open-redirect vector). Falls back to `/` for anything
 *  else, including a protocol-relative `//evil.com` path. */
export function safeRedirect(target, fallback = '/') {
    if (typeof target !== 'string' || ! target.startsWith('/') || target.startsWith('//')) {
        return fallback;
    }

    return target;
}
