<?php

/**
 * Number holds: how long a player's picked numbers are kept for them while
 * they sign in and pay (App\Services\NumberHoldService).
 */
return [
    // Minutes a hold lasts. Refreshing the page does not restart the clock.
    'minutes' => (int) env('NUMBER_HOLD_MINUTES', 10),

    // Most numbers one person (or one guest browser) can hold at once, across all raffles.
    'max_per_owner' => (int) env('NUMBER_HOLD_MAX_PER_OWNER', 100),
];
