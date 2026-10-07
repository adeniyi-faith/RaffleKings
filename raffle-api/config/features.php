<?php

/*
|--------------------------------------------------------------------------
| Growth and safety features (Settings → On / off → New features)
|--------------------------------------------------------------------------
|
| Each one starts OFF, so nothing changes for customers until an admin
| switches it on. How "off" looks (App\Support\Features):
|
|  - Customers: the feature is simply not there. No promo-code box, no
|    affiliate page, no reminders. A greyed-out "disabled" button only
|    raises questions and support tickets.
|  - Staff: the admin screens stay visible with an "Off" label and a link
|    to switch it on, so nobody forgets it exists or wonders why it's idle.
|
*/

return [
    // Paystack confirms the account name when a customer saves a bank account.
    // Starts ON (a safety measure); it only takes effect once a Paystack key is set.
    'bank_name_check' => (bool) env('FEATURE_BANK_NAME_CHECK', true),

    // "Send with Paystack" on the withdrawals queue.
    'auto_payouts' => (bool) env('FEATURE_AUTO_PAYOUTS', false),

    // "Raffle ends in 1 hour" and "you left tickets in checkout" reminders.
    'reminders' => (bool) env('FEATURE_REMINDERS', false),

    // Promo codes at sign-up and checkout.
    'promo_codes' => (bool) env('FEATURE_PROMO_CODES', false),

    // Influencer / affiliate links and their earnings dashboard.
    'affiliates' => (bool) env('FEATURE_AFFILIATES', false),

    // Hold referral and affiliate rewards when the two accounts look like one person.
    'abuse_detection' => (bool) env('FEATURE_ABUSE_DETECTION', false),

    // The public /status page and its message.
    'status_page' => (bool) env('FEATURE_STATUS_PAGE', false),
];
