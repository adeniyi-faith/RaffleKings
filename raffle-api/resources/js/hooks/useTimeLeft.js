import { useEffect, useState } from 'react';

/** A "mm:ss" countdown to a time (the Golden Box offer and discount
 *  windows, item 46), ticking every second. `done` turns true at zero. */
export function useTimeLeft(endsAt) {
    const [secondsLeft, setSecondsLeft] = useState(() => secondsUntil(endsAt));

    useEffect(() => {
        setSecondsLeft(secondsUntil(endsAt));

        if (! endsAt) {
            return undefined;
        }

        const interval = setInterval(() => setSecondsLeft(secondsUntil(endsAt)), 1000);

        return () => clearInterval(interval);
    }, [endsAt]);

    const minutes = String(Math.floor(secondsLeft / 60)).padStart(2, '0');
    const seconds = String(secondsLeft % 60).padStart(2, '0');

    return { label: `${minutes}:${seconds}`, done: Boolean(endsAt) && secondsLeft <= 0 };
}

function secondsUntil(endsAt) {
    return endsAt ? Math.max(0, Math.floor((new Date(endsAt).getTime() - Date.now()) / 1000)) : 0;
}
