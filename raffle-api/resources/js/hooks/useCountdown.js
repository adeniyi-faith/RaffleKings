import { useEffect, useState } from 'react';

/** A real countdown to the raffle's actual expiry date — replacing the
 *  legacy raffle-details.php's promo countdown, which counted down an
 *  arbitrary localStorage timestamp with no connection to the raffle
 *  itself. */
export function useCountdown(expiry) {
    const [label, setLabel] = useState('');

    useEffect(() => {
        if (! expiry) {
            setLabel('');
            return;
        }

        const target = new Date(expiry).getTime();

        function tick() {
            const diff = target - Date.now();

            if (diff <= 0) {
                setLabel('Closed');
                return;
            }

            const days = Math.floor(diff / 86400000);
            const hours = Math.floor((diff % 86400000) / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);

            if (days > 0) {
                setLabel(`${days}d ${hours}h left`);
            } else if (hours > 0) {
                setLabel(`${hours}h ${minutes}m left`);
            } else {
                setLabel(`${minutes}m left`);
            }
        }

        tick();
        const interval = setInterval(tick, 30000);

        return () => clearInterval(interval);
    }, [expiry]);

    return label;
}
