<?php

/*
|--------------------------------------------------------------------------
| Loyalty tiers (App\Services\LoyaltyService) — the Raffle Rules Engine
|--------------------------------------------------------------------------
|
| Tiers reward CONSISTENCY: a customer's tier comes from how many of the
| last `window_weeks` weeks (Monday to Sunday, business time zone) they
| bought at least one ticket, and how many tickets they bought in that
| time. Both must be met. Editable in Settings → Loyalty.
|
| `bonus_entries`: free extra entries a tier gets in every raffle it
| enters whose draw rules switch loyalty bonus entries on. They are real,
| visible entries (My Tickets shows them) and take part in the draw like
| tickets, so the fairness proof still covers everything.
|
*/

return [
    'window_weeks' => 8,

    'tiers' => [
        ['key' => 'bronze', 'name' => 'Bronze', 'min_active_weeks' => 0, 'min_tickets' => 0, 'bonus_entries' => 0],
        ['key' => 'silver', 'name' => 'Silver', 'min_active_weeks' => 3, 'min_tickets' => 10, 'bonus_entries' => 1],
        ['key' => 'gold', 'name' => 'Gold', 'min_active_weeks' => 5, 'min_tickets' => 30, 'bonus_entries' => 2],
        ['key' => 'diamond', 'name' => 'Diamond', 'min_active_weeks' => 7, 'min_tickets' => 80, 'bonus_entries' => 3],
    ],
];
