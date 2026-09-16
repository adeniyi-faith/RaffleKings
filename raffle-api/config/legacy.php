<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WordPress Table Prefix
    |--------------------------------------------------------------------------
    |
    | The existing rk-core plugin created its tables with WordPress's own
    | table prefix (usually "wp_"). Every Eloquent model under
    | App\Models\Legacy points at one of those tables and builds its table
    | name from this value, so it stays correct if the prefix ever differs
    | between environments.
    |
    */

    'wp_prefix' => env('WP_TABLE_PREFIX', 'wp_'),

    /*
    |--------------------------------------------------------------------------
    | WordPress Session Cookie Bridge
    |--------------------------------------------------------------------------
    |
    | Until auth is fully cut over to Laravel (Sanctum), this app verifies
    | the SAME "logged in" cookie WordPress already issues via wp_signon(),
    | using WordPress's own cookie algorithm re-implemented in
    | App\Auth\WordPressAuthCookieValidator — no WordPress bootstrap
    | required. Copy these three values from wp-config.php on the real
    | server (LOGGED_IN_KEY, LOGGED_IN_SALT) and from the site's home URL
    | (COOKIEHASH = md5(home_url())) so a cookie issued by the live site
    | validates here too. Getting these wrong just means nobody can log
    | in through this bridge — it can never authenticate someone WordPress
    | itself would have rejected, because the HMAC has to match either way.
    |
    */

    'wp_logged_in_key' => env('WP_LOGGED_IN_KEY', ''),
    'wp_logged_in_salt' => env('WP_LOGGED_IN_SALT', ''),
    'wp_cookiehash' => env('WP_COOKIEHASH', ''),

];
