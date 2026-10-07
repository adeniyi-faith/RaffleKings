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
    | starting value: off until a real mail service (MAIL_MAILER) is set up, so
    | staff are never asked for a code that cannot arrive. If email ever stops working and nobody can sign in,
    | run `php artisan staff:two-step off` on the server.
    */
    'staff_two_step' => (bool) filter_var(
        env('STAFF_TWO_STEP', ! in_array(strtolower((string) env('MAIL_MAILER', 'log')), ['log', 'array', ''], true)),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    | The staff address. "admin" means rafflekings.com.ng/admin. Set
    | ADMIN_PATH to something private (letters, numbers, - and _) and the
    | admin moves there; /admin then shows the ordinary "page not found"
    | to everyone, so guests and customers never see a staff sign-in page.
    | Write the new address down: it is the only way in.
    */
    'admin_path' => env('ADMIN_PATH', 'admin'),
];
