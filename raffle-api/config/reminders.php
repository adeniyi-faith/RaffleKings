<?php

/*
|--------------------------------------------------------------------------
| Reminders (App\Services\Reminders\ReminderService)
|--------------------------------------------------------------------------
|
| Switched on in Settings → On / off → New features; everything here is
| editable in Settings → Reminders. Sent every few minutes by the
| scheduler (`php artisan reminders:send`).
|
*/

return [
    'raffle_ending' => [
        'enabled' => true,
        // Sent this many minutes before a raffle stops selling.
        'minutes_before' => 60,
        // entrants = people with tickets in it; + checkout = also people who
        // left it in checkout; recent_players = anyone who played in the last 30 days.
        'audience' => 'entrants_and_checkout',
    ],

    'abandoned_checkout' => [
        'enabled' => true,
        // Sent this long after someone opened checkout and didn't pay.
        'after_minutes' => 45,
    ],

    'channels' => [
        'push' => true,
        'email' => true,
        'whatsapp' => false,
    ],

    // No reminders between these hours (business time zone). A checkout
    // reminder waits until morning; an "ends soon" reminder is skipped.
    'quiet_from' => 22,
    'quiet_until' => 8,

    // Most reminders one person gets in a day, all kinds together.
    'max_per_day' => 2,

    // WhatsApp Business (Meta Cloud API). Messages to customers who haven't
    // written first must use templates Meta has approved.
    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'language' => 'en',
        'templates' => [
            'raffle_ending' => env('WHATSAPP_TEMPLATE_RAFFLE_ENDING', 'raffle_ending_soon'),
            'abandoned_checkout' => env('WHATSAPP_TEMPLATE_CHECKOUT', 'checkout_reminder'),
        ],
    ],
];
