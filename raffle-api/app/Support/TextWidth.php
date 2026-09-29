<?php

namespace App\Support;

/**
 * The logic behind the mb_strimwidth() fallback (app/Support/mbstring_fallback.php):
 * cut a string to a display width, adding a marker such as "..." when it
 * was cut. Kept in a class so it can be tested against PHP's own version.
 */
final class TextWidth
{
    public static function trim(string $string, int $start, int $width, string $marker = '', string $encoding = 'UTF-8'): string
    {
        $string = mb_substr($string, $start, null, $encoding);

        if (mb_strwidth($string, $encoding) <= $width) {
            return $string;
        }

        $room = max(0, $width - mb_strwidth($marker, $encoding));
        $kept = '';
        $used = 0;

        foreach (mb_str_split($string, 1, $encoding) as $char) {
            $charWidth = mb_strwidth($char, $encoding);

            if ($used + $charWidth > $room) {
                break;
            }

            $kept .= $char;
            $used += $charWidth;
        }

        return $kept.$marker;
    }
}
