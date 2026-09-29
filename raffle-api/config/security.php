<?php

return [
    /*
    | Other hostnames (besides this site's own) allowed to send change-making
    | requests to the API — e.g. the www. version of the domain if both are
    | used. Comma-separated in SECURITY_TRUSTED_ORIGINS. See VerifyApiOrigin.
    */
    'trusted_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECURITY_TRUSTED_ORIGINS', ''))))),
];
