<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deposit gateway order
    |--------------------------------------------------------------------------
    |
    | The order DepositService tries gateways in when a user initializes a
    | deposit. Paystack is primary; if it fails right at initialization
    | (account issue, network error, etc.) the service automatically
    | falls back to Flutterwave rather than failing the deposit outright.
    | Once a deposit is created against a gateway, that gateway is the
    | only one ever consulted again for it (see App\Models\Deposit).
    |
    */

    'default_gateway' => env('PAYMENTS_DEFAULT_GATEWAY', 'paystack'),

    'backup_gateway' => env('PAYMENTS_BACKUP_GATEWAY', 'flutterwave'),

    /*
    |--------------------------------------------------------------------------
    | Manual review fallback
    |--------------------------------------------------------------------------
    |
    | The legacy site's Gemini AI screenshot check stays available as a
    | manual-review path for deposits a gateway genuinely can't handle
    | (e.g. a bank transfer made outside either gateway's flow) — this
    | does not replace it, it's the "should work for almost everyone"
    | path the manual review used to be the ONLY option for.
    |
    */

    'minimum_deposit' => (float) env('PAYMENTS_MINIMUM_DEPOSIT', 100),

    /*
    |--------------------------------------------------------------------------
    | Legacy manual deposit approval bonus
    |--------------------------------------------------------------------------
    |
    | OVERHAUL_CHECKLIST.md Phase 3 item 36 — when an admin approves one
    | of legacy's own manual bank-transfer deposits (wp_raffle_transactions
    | type='wallet_deposit', status pending/manual_review — the AI
    | screenshot review queue, not the Paystack/Flutterwave path above),
    | legacy credits this percentage of the deposit into the user's
    | earnings balance as a cashback bonus. Same constant as legacy's own
    | RK_DEPOSIT_BONUS_PERCENT (wp-core/theme-config.php) — currently 0
    | (temporarily disabled) there, so this defaults to 0 too; keep both
    | in sync if it's ever turned back on.
    |
    */

    'legacy_deposit_bonus_percent' => (float) env('LEGACY_DEPOSIT_BONUS_PERCENT', 0.00),

];
