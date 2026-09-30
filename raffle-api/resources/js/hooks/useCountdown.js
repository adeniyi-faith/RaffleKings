import { useEffect, useState } from 'react';

function labelFor(expiry) {
    if (! expiry) {
        return '';
    }

    const diff = new Date(expiry).getTime() - Date.now();

    if (diff <= 0) {
        return 'Closed';
    }

    const days = Math.floor(diff / 86400000);
    const hours = Math.floor((diff % 86400000) / 3600000);
    const minutes = Math.floor((diff % 3600000) / 60000);

    if (days > 0) {
        return `${days}d ${hours}h left`;
    }

    return hours > 0 ? `${hours}h ${minutes}m left` : `${minutes}m left`;
}

/** A real countdown to the raffle's actual expiry date — replacing the
 *  legacy raffle-details.php's promo countdown, which counted down an
 *  arbitrary localStorage timestamp with no connection to the raffle
 *  itself. The first label is worked out on the first draw, so the timer is
 *  there with the page instead of popping in a moment later. */
export function useCountdown(expiry) {
    const [label, setLabel] = useState(() => labelFor(expiry));

    useEffect(() => {
        setLabel(labelFor(expiry));

        if (! expiry) {
            return undefined;
        }

        const interval = setInterval(() => setLabel(labelFor(expiry)), 30000);

        return () => clearInterval(interval);
    }, [expiry]);

    return label;
}
