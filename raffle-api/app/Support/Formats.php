<?php

namespace App\Support;

/**
 * Number and money formatting that doesn't need PHP's "intl" extension.
 *
 * Laravel's Number::format()/currency() and Filament's ->money()/->numeric()
 * throw an error when intl is missing, which some shared hosts leave off.
 * Admin tables use ->naira() / ->wholeNumber() instead (macros registered in
 * AdminPanelProvider::boot), which go through these.
 */
final class Formats
{
    /** "₦4,000" / "₦1,250.50" */
    public static function naira(float|int|string|null $amount): string
    {
        $amount = (float) $amount;

        return ($amount < 0 ? '-' : '').'₦'.number_format(abs($amount), fmod($amount, 1.0) == 0.0 ? 0 : 2);
    }

    /** "12,345" */
    public static function wholeNumber(float|int|string|null $number): string
    {
        return number_format((float) $number);
    }
}
