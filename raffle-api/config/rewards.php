<?php

/*
|--------------------------------------------------------------------------
| Reward points (daily claim, tasks, Spin & Win, redemption)
|--------------------------------------------------------------------------
|
| The legacy site's own numbers, now editable in Settings → Rewards.
|
*/

return [
    // Points for day 1..7 of a daily-claim streak (the streak then repeats).
    'daily_claim' => [50, 70, 100, 150, 200, 300, 1000],

    'tasks' => [
        'push_notification' => 1500,
        'join_community' => 1300,
        'whatsapp_follow' => 800,
        'whatsapp_share' => 500,
    ],

    'spin_cost' => 50,

    // Chances are "out of the total of all weights" (they need not add up
    // to 1000, but the defaults do: 60% / 30% / 8% / 2%).
    'spin_prizes' => [
        ['payout' => 15, 'weight' => 600, 'outcome' => 'loss'],
        ['payout' => 50, 'weight' => 300, 'outcome' => 'tie'],
        ['payout' => 150, 'weight' => 80, 'outcome' => 'win'],
        ['payout' => 500, 'weight' => 20, 'outcome' => 'jackpot'],
    ],

    // Redemption: this many points = ₦1, and the least a customer can cash in.
    'points_per_naira' => 10,
    'minimum_redeem_points' => 100,
];
