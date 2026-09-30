// Number holds: talks to the server so a player's chosen numbers are kept for
// them for a few minutes (see wp/wp-content/mu-plugins/rk-core/holds-bridge.php).
// Used by select-numbers.php and checkout.php.
window.RKHolds = (function () {
    const STORE = 'rk_hold_key';
    const SAFE = /^[A-Za-z0-9_-]{16,64}$/;

    function randomKey() {
        const bytes = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    }

    // A private random label for this browser. A guest's held numbers belong to it
    // until they sign in, then the server hands them to their account.
    let memoryKey = '';
    function holderKey() {
        try {
            let k = localStorage.getItem(STORE);
            if (!k || !SAFE.test(k)) {
                k = randomKey();
                localStorage.setItem(STORE, k);
            }
            return k;
        } catch (e) {
            if (!memoryKey) memoryKey = randomKey();
            return memoryKey;
        }
    }

    // Ask the server to hold these numbers. Never throws; look at `.status`:
    //   'held'         -> ok, `.secondsLeft` says how long is left
    //   'unavailable'  -> `.unavailable` lists numbers sold or held by someone else
    //   'error'        -> could not reach the server (caller decides whether to carry on)
    async function hold(raffleId, numbers) {
        try {
            const res = await fetch('ajax-router.php?action=hold_numbers', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    raffle_id: raffleId,
                    numbers: Array.isArray(numbers) ? numbers.join(',') : String(numbers),
                    holder_key: holderKey()
                })
            });
            const data = await res.json();
            if (data && data.success) {
                return { status: 'held', secondsLeft: parseInt(data.seconds_left, 10) || 0 };
            }
            if (data && data.code === 'numbers_unavailable') {
                return { status: 'unavailable', unavailable: (data.unavailable || []).map(Number), message: data.message };
            }
            return { status: 'error', message: (data && data.message) || '' };
        } catch (e) {
            return { status: 'error', message: '' };
        }
    }

    // Which numbers are sold and which other people are holding right now.
    async function status(raffleId) {
        try {
            const res = await fetch('ajax-router.php?action=number_status&raffle_id=' + encodeURIComponent(raffleId) +
                '&holder_key=' + encodeURIComponent(holderKey()), { credentials: 'same-origin', cache: 'no-store' });
            if (!res.ok) return null;
            const data = await res.json();
            if (!data || !data.success) return null;
            return { sold: (data.sold || []).map(Number), heldByOthers: (data.held_by_others || []).map(Number) };
        } catch (e) {
            return null;
        }
    }

    return { holderKey, hold, status };
})();
