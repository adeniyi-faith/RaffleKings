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

];
