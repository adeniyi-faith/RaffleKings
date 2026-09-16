/**
 * Item 31's push-permission flow: a real, standalone opt-in — request
 * the browser's actual Notification permission via the OneSignal SDK
 * (loaded in app.blade.php, same provider and app id the legacy site
 * uses), then save the resulting player id to this app's own
 * POST /api/push/device so OneSignalChannel can actually reach this
 * user. This exists on its own, callable from wherever a genuine
 * opt-in makes sense — it never gates anything else. That's the fix
 * for the legacy "push-permission trap": rewards-js.php's
 * attemptDailyClaim() forced this same modal open before letting a
 * user claim their UNRELATED daily login-streak points if they hadn't
 * already opted in. The new Rewards page's daily claim (see
 * DailyClaimService/RewardsController) has no such dependency; this
 * hook is used ONLY by the "Enable Notifications" task itself, whose
 * own reward is honestly tied to actually granting permission (see
 * Rewards/Index.jsx), not smuggled onto something else.
 *
 * @returns {() => Promise<boolean>} requestPermission — resolves true
 *          only once permission was actually granted AND a player id
 *          was actually saved server-side; false otherwise. Never
 *          throws — a SDK/network failure just resolves false.
 */
export function usePushPermission() {
    async function requestPermission() {
        if (typeof window === 'undefined' || ! window.OneSignalDeferred) {
            return false;
        }

        if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
            return false;
        }

        return new Promise((resolve) => {
            window.OneSignalDeferred.push(async (OneSignal) => {
                try {
                    const accepted = await OneSignal.Notifications.requestPermission();

                    if (! accepted) {
                        resolve(false);
                        return;
                    }

                    const playerId = await waitForPlayerId(OneSignal);

                    if (! playerId) {
                        resolve(false);
                        return;
                    }

                    const res = await fetch('/api/push/device', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ player_id: playerId }),
                    });

                    resolve(res.ok);
                } catch {
                    resolve(false);
                }
            });
        });
    }

    return { requestPermission };
}

function waitForPlayerId(OneSignal, attempts = 20, delayMs = 500) {
    return new Promise((resolve) => {
        let tries = 0;

        const interval = setInterval(async () => {
            tries += 1;
            const id = await OneSignal.User.PushSubscription.id;

            if (id) {
                clearInterval(interval);
                resolve(id);
            } else if (tries >= attempts) {
                clearInterval(interval);
                resolve(null);
            }
        }, delayMs);
    });
}
