<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Withdrawal Rules
    |--------------------------------------------------------------------------
    |
    | Same numbers the legacy site hardcodes in rk_handle_withdrawal()
    | (wp-core/api-financials.php) — kept as defaults so behaviour
    | doesn't change during the migration, but now real, tunable
    | settings instead of numbers buried in a PHP function.
    |
    | A user whose lifetime deposits are below `verification_deposit_threshold`
    | must authorize a one-time `verification_fee` deduction (from their
    | own withdrawal) before their first withdrawal — same mechanic as
    | the legacy site. What changes here is DISCLOSURE: see
    | WithdrawalController::requirements(), which lets the frontend show
    | this upfront on the withdraw page instead of springing it on the
    | user only when they try to cash out (audit §15).
    |
    */

    'minimum_amount' => (float) env('WITHDRAWAL_MINIMUM_AMOUNT', 2000),
    'verification_deposit_threshold' => (float) env('WITHDRAWAL_VERIFICATION_THRESHOLD', 1000),
    'verification_fee' => (float) env('WITHDRAWAL_VERIFICATION_FEE', 1000),

    // Automatic payouts (Settings → On / off → New features): the biggest
    // single withdrawal "Send with Paystack" may send. Bigger ones are paid
    // by hand. 0 = no limit.
    'auto_payout_max' => (float) env('WITHDRAWAL_AUTO_PAYOUT_MAX', 200000),

];
