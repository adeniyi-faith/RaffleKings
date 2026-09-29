import { useCallback, useEffect, useState } from 'react';

/**
 * Loads JSON from one of the site's API addresses. Gives back the data,
 * whether it's still loading, whether it failed (for LoadError's "Try
 * again"), and reload() to fetch it again after a change.
 */
export function useApi(url, { enabled = true } = {}) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(enabled);
    const [failed, setFailed] = useState(false);

    const reload = useCallback(() => {
        if (! enabled || ! url) return Promise.resolve(null);
        setLoading(true);
        setFailed(false);

        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((res) => {
                if (! res.ok) throw new Error(String(res.status));
                return res.json();
            })
            .then((json) => {
                setData(json);
                return json;
            })
            .catch(() => {
                setFailed(true);
                return null;
            })
            .finally(() => setLoading(false));
    }, [url, enabled]);

    useEffect(() => {
        reload();
    }, [reload]);

    return { data, setData, loading, failed, reload };
}
