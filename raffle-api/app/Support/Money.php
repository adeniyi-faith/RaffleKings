<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Naira amounts as whole kobo (₦1 = 100 kobo).
 *
 * Every sum that changes a balance is done on whole numbers, never on
 * floats: 0.1 + 0.2 is not 0.3 in floating point, but 10 + 20 kobo is
 * always 30. The database still stores naira as DECIMAL(…,2), which is
 * exact; these helpers convert at the edges.
 */
final class Money
{
    /** The largest single amount anything in the app may move (₦100,000,000). */
    public const MAX_KOBO = 10_000_000_000;

    /**
     * Naira (as a DECIMAL string from the database, an int, or a float from a
     * request) to whole kobo. Refuses anything finer than one kobo.
     */
    public static function kobo(int|float|string|null $naira): int
    {
        if ($naira === null || $naira === '') {
            return 0;
        }

        if (is_int($naira)) {
            return $naira * 100;
        }

        if (is_string($naira)) {
            $naira = trim($naira);

            if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2})\d*)?$/', $naira, $m)) {
                throw new InvalidArgumentException("Not an amount of money: {$naira}");
            }

            // DECIMAL(…,2) columns never carry more than 2 places; a longer
            // string is only ever trailing zeros from a cast, so ignore them
            // only if they are zeros.
            if (preg_match('/\.\d{2}(\d+)$/', $naira, $extra) && (int) $extra[1] !== 0) {
                throw new InvalidArgumentException("Amounts can't be smaller than one kobo: {$naira}");
            }

            $kobo = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

            return $m[1] === '-' ? -$kobo : $kobo;
        }

        $kobo = $naira * 100;
        $rounded = (int) round($kobo);

        // A float that is really "x.yz" lands within a hair of a whole number
        // of kobo; anything further off had a third decimal place.
        if (abs($kobo - $rounded) > 0.0001) {
            throw new InvalidArgumentException("Amounts can't be smaller than one kobo: {$naira}");
        }

        return $rounded;
    }

    /** Whole kobo to a naira DECIMAL string, e.g. 150050 → "1500.50". */
    public static function naira(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);

        return $sign.intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Whole kobo to a float, for JSON responses and display only. */
    public static function toFloat(int $kobo): float
    {
        return $kobo / 100;
    }
}
