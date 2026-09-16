<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Referral Commission Rate
    |--------------------------------------------------------------------------
    |
    | What fraction of a new user's first verified deposit their referrer
    | earns, paid once per referred user. The legacy site hardcodes this
    | at 50% (wp-core/api-financials.php's rk_process_referral_commission())
    | — kept as the default here so behaviour doesn't change during the
    | migration, but now a real, deliberately-tunable business decision
    | instead of a number buried in a PHP function.
    |
    */

    'commission_rate' => (float) env('REFERRAL_COMMISSION_RATE', 0.5),

];
