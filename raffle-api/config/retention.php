<?php

/*
|--------------------------------------------------------------------------
| Win-back engine (App\Services\Retention)
|--------------------------------------------------------------------------
|
| Member segments are worked out every night. Comeback offers (personal,
| time-limited ticket credit or points) are OFF until switched on in
| Settings → Reminders → Comeback offers, and can never spend more than
| the budgets below. Money offers are paid as ticket credit (wallet
| balance: spendable on tickets, never withdrawable).
|
*/

return [
    // Total spent on tickets that makes a customer "high value" (₦).
    'high_value_spend' => 50000,

    'offers' => [
        'enabled' => false,

        // Hour of the day (business time zone) the day's offers go out.
        'send_hour' => 11,

        // Which segments get offers. Consistent and repeat players are
        // left out on purpose: they come anyway, so an offer would be wasted.
        'segments' => ['new_signup', 'never_played', 'first_timer', 'one_and_done', 'drifting', 'at_risk', 'lapsed'],

        // How long a customer has to claim, and when the "last call" goes.
        'claim_hours' => 24,
        'last_call_hours' => 3,

        // Ticket credit: a share of what they usually spend per order,
        // kept between these two amounts (₦).
        'credit_min' => 200,
        'credit_max' => 1000,

        // "A ticket on us": only for raffles whose ticket costs no more than this (₦).
        'ticket_max_price' => 1000,

        // Bonus points for a gentle nudge.
        'points' => 500,

        // Most ticket credit one customer can be offered in 30 days (₦).
        'per_member_30_days' => 1000,

        // Most ticket credit offered across everyone in a calendar month (₦),
        // and most points. Offers that run out unclaimed don't count.
        'monthly_budget' => 50000,
        'monthly_points_budget' => 250000,

        // Most new offers in one day.
        'daily_max' => 200,

        // Days between two offers to the same customer.
        'cooldown_days' => 14,

        // Customers who let this many offers in a row run out get no more
        // for 90 days (they aren't interested, and it stops it feeling like spam).
        'give_up_after_ignored' => 3,

        // Let Gemini write the messages (Settings → AI). Built-in messages are used otherwise.
        'use_ai' => true,
    ],
];
