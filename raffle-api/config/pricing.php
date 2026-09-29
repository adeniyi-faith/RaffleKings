<?php

/*
|--------------------------------------------------------------------------
| Ticket pricing (App\Services\TicketPricingService)
|--------------------------------------------------------------------------
|
| The defaults are the legacy site's own numbers (rk_calculate_ticket_price),
| so prices don't change unless an admin changes them in Settings → Raffles
| & pricing. Percentages are "percent off" (25 = 25% off).
|
| Cheap tickets (price at or below cheap_ticket_max_price) get one flat bulk
| discount. Dearer tickets get a discount only at the exact bundle sizes
| listed (the raffle page offers these as buttons), or above_percent_off
| for any quantity bigger than above_quantity.
|
*/

return [
    'cheap_ticket_max_price' => 200,
    'cheap_bulk_min_quantity' => 2,
    'cheap_bulk_percent_off' => 10,

    'bundles' => [
        ['quantity' => 2, 'percent_off' => 25],
        ['quantity' => 3, 'percent_off' => 35],
        ['quantity' => 5, 'percent_off' => 40],
        ['quantity' => 10, 'percent_off' => 45],
    ],

    'above_quantity' => 10,
    'above_percent_off' => 50,

    'golden_box_percent_off' => 10,
];
