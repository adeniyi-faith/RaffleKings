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

];
