<?php

/*
|--------------------------------------------------------------------------
| Community & engagement features (Phase 11)
|--------------------------------------------------------------------------
|
| Badges, the referral ladder, the Season Pass, daily predictions, free
| spins, share-to-unlock links, Team Up, red envelopes and winner stories.
| Everything a customer can win here is free (points, free spins, free
| bonus entries or badges) — nothing is bought with money. The numbers are
| editable in the admin under Settings → Engagement; these are only the
| starting values.
|
*/

return [
    // key => [name, what earns it, emoji]
    'badges' => [
        'first_ticket' => ['First Ticket', 'Bought your first ticket', '🎟️'],
        'tickets_50' => ['Dedicated Player', 'Bought 50 tickets', '🔥'],
        'first_win' => ['First Win', 'Won a prize in a draw', '🏆'],
        'streak_7' => ['7-Day Streak', 'Claimed the daily reward 7 days in a row', '📅'],
        'referred_1' => ['Recruiter', 'A friend you invited joined and played', '🤝'],
        'referred_10' => ['Referred 10 Friends', '10 friends you invited joined and played', '📣'],
        'referred_25' => ['Community Builder', '25 friends you invited joined and played', '🌟'],
        'team_captain' => ['Team Captain', 'Led a Team Up team that filled up', '🧢'],
        'team_player' => ['Team Player', 'Joined a Team Up team that filled up', '🤜'],
        'predictor' => ['Sharp Predictor', 'Got 5 daily predictions right', '🔮'],
        'season_10' => ['Season Level 10', 'Reached level 10 of a Season Pass', '🥉'],
        'season_30' => ['Season Champion', 'Reached level 30 of a Season Pass', '🏅'],
        'storyteller' => ['Storyteller', 'Shared your winner story', '📸'],
        'generous' => ['Generous', 'Sent a red envelope to other players', '🧧'],
        'birthday' => ['Birthday Spin', 'Spun the wheel on your birthday', '🎂'],
        'unlocker' => ['Unlocked!', 'Friends helped you unlock a free bonus entry', '🔓'],
    ],

    // How many badges a customer can pin to their profile.
    'showcase_size' => 3,

    // Referral ladder: rewards for friends who join AND buy at least one ticket.
    'referral_ladder' => [
        ['friends' => 1, 'points' => 200, 'free_spins' => 0, 'badge' => 'referred_1'],
        ['friends' => 5, 'points' => 1500, 'free_spins' => 2, 'badge' => null],
        ['friends' => 10, 'points' => 4000, 'free_spins' => 3, 'badge' => 'referred_10'],
        ['friends' => 25, 'points' => 12000, 'free_spins' => 5, 'badge' => 'referred_25'],
    ],

    // Season Pass: a free 4-week track with 30 levels.
    'season' => [
        'starts_on' => '2026-10-05', // a Monday; seasons follow back to back from here (Lagos time)
        'weeks' => 4,
        'levels' => 30,
        'xp_per_level' => 100,
        'xp' => [
            'ticket' => 10,
            'ticket_daily_cap' => 150, // most XP from tickets in one day, so buying more never races ahead
            'daily_claim' => 25,
            'task' => 15,
            'prediction' => 5,
            'prediction_correct' => 20,
        ],
        // Each level pays points; every 5th level also a free spin; levels 10, 20 and 30 a free bonus entry.
        'level_points' => 25,
        'level_points_step' => 5,
    ],

    // Daily prediction: points for a correct answer (each question can override).
    'predictions' => [
        'default_points' => 50,
    ],

    // Free spins of the Spin & Win wheel.
    'free_spins' => [
        'birthday' => 1,
        'milestones' => [
            'tickets_10' => 1,
            'tickets_50' => 2,
            'tickets_100' => 3,
            'anniversary' => 2,
        ],
    ],

    // "Help me unlock": friends tap a customer's link for a raffle.
    'unlock' => [
        'taps_needed' => 3,
        'tapper_points' => 20,
        'daily_taps_per_user' => 5,
        'bonus_entries' => 1,
    ],

    // Team Up: a captain and friends in the same raffle.
    'teams' => [
        'size' => 3, // captain included
        'hours' => 24,
        'bonus_entries' => 1,
    ],

    // Red envelopes: points gifts split between the first people to tap.
    'red_envelopes' => [
        'min_points' => 50,
        'max_points' => 5000,
        'max_slots' => 10,
        'minutes' => 10,
    ],

    // Winner stories.
    'stories' => [
        'points' => 100,
        'max_image_kb' => 5120,
        'max_video_kb' => 20480,
    ],
];
