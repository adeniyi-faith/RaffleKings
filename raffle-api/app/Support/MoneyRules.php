<?php

namespace App\Support;

/**
 * Validation for every amount of money that comes in from a request
 * (money-safety audit A4): a number, at most two decimal places (whole
 * kobo), above zero, and below a hard maximum.
 */
final class MoneyRules
{
    /** @return list<string> */
    public static function amount(float $min = 0.01, ?float $max = null): array
    {
        $max ??= Money::MAX_KOBO / 100;

        return ['required', 'numeric', 'decimal:0,2', 'min:'.$min, 'max:'.$max];
    }

    /** A one-off code the app makes once per tap and reuses on every retry. */
    public static function idempotencyKey(): array
    {
        return ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'];
    }
}
