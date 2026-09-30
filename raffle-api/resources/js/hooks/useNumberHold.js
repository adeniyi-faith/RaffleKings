import { useCallback, useEffect, useRef, useState } from 'react';
import { holdNumbers } from '../lib/numberHolds';

/**
 * Holds `numbers` for this player and counts the time left.
 *
 * phase:
 *   'holding'     asking the server
 *   'held'        numbers are ours; `secondsLeft` counts down
 *   'unavailable' some numbers were sold or are held by someone else (`unavailable` lists them)
 *   'expired'     the time ran out (`retry()` tries to get the numbers back)
 *   'error'       the server could not be reached; the page carries on without a timer
 *
 * `active` = false stops the clock (e.g. once the tickets are paid for).
 */
export function useNumberHold(raffleId, numbers, active = true) {
    const [phase, setPhase] = useState('holding');
    const [secondsLeft, setSecondsLeft] = useState(0);
    const [unavailable, setUnavailable] = useState([]);
    const [retrying, setRetrying] = useState(false);
    const deadline = useRef(0);
    const key = numbers.join(',');

    const request = useCallback(async () => {
        const result = await holdNumbers(raffleId, numbers);

        if (result.status === 'held') {
            deadline.current = Date.now() + result.secondsLeft * 1000;
            setSecondsLeft(result.secondsLeft);
            setUnavailable([]);
            setPhase('held');
        } else if (result.status === 'unavailable') {
            setUnavailable(result.unavailable);
            setPhase('unavailable');
        } else {
            setPhase('error');
        }

        return result.status;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [raffleId, key]);

    useEffect(() => {
        if (active) {
            request();
        }
    }, [request, active]);

    useEffect(() => {
        if (phase !== 'held' || ! active) {
            return undefined;
        }

        const tick = () => {
            const left = Math.max(0, Math.round((deadline.current - Date.now()) / 1000));
            setSecondsLeft(left);

            if (left <= 0) {
                setPhase('expired');
            }
        };

        tick();
        const timer = setInterval(tick, 1000);

        return () => clearInterval(timer);
    }, [phase, active]);

    const retry = useCallback(async () => {
        setRetrying(true);
        await request();
        setRetrying(false);
    }, [request]);

    return { phase, secondsLeft, unavailable, retry, retrying };
}
