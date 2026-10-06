<?php

return [
    /*
    | Other hostnames (besides this site's own) allowed to send change-making
    | requests to the API — e.g. the www. version of the domain if both are
    | used. Comma-separated in SECURITY_TRUSTED_ORIGINS. See VerifyApiOrigin.
    */
    'trusted_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECURITY_TRUSTED_ORIGINS', ''))))),

    /*
    | Two-step sign-in for staff: after the right password, the admin asks
    | for a 6-digit code emailed to the staff member. Switched on and off
    | in Settings → Security (or Users → Staff activity); this is only the
    | starting value. If email ever stops working and nobody can sign in,
    | run `php artisan staff:two-step off` on the server.
    */
    'staff_two_step' => (bool) env('STAFF_TWO_STEP', true),
];
