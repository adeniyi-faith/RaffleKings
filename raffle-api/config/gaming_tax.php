<?php

/**
 * Gaming tax (App\Services\GamingTaxService): a percentage of what a month's
 * ticket sales are left with after the prizes won that month. Every value
 * here can be changed in the admin under Settings → Payments → Gaming tax.
 */
return [
    // The tax rate, in percent.
    'rate' => (float) env('GAMING_TAX_RATE', 2.5),

    // What happens in a month when prizes were worth more than sales:
    //   zero          that month owes no tax, and the shortfall is forgotten
    //   carry_forward the shortfall is taken off the next month's taxable amount
    'shortfall' => env('GAMING_TAX_SHORTFALL', 'zero'),

    // The tax is due on this day of the month after the one it covers.
    'due_day' => (int) env('GAMING_TAX_DUE_DAY', 21),
];
