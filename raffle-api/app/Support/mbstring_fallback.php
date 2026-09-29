<?php

use App\Support\TextWidth;

/*
 * Some shared hosts run PHP without the "mbstring" extension. Symfony's
 * polyfill (already installed) fills in almost every mb_* function, but
 * not mb_strimwidth(), which Laravel's Str::limit() uses to shorten long
 * text. Without it any admin list or page that trims a long name or
 * description crashed with "Call to undefined function mb_strimwidth()".
 *
 * This defines it only when PHP doesn't have it, using the polyfilled
 * mb_strwidth()/mb_substr(). Loaded by Composer (composer.json
 * "autoload.files"). Turning mbstring on in cPanel (Select PHP Version →
 * Extensions) is still better: faster, and this file then does nothing.
 */

if (! function_exists('mb_strimwidth')) {
    function mb_strimwidth(string $string, int $start, int $width, string $trim_marker = '', ?string $encoding = null): string
    {
        return TextWidth::trim($string, $start, $width, $trim_marker, $encoding ?? 'UTF-8');
    }
}
