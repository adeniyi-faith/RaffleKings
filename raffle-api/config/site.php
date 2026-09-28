<?php

/*
|--------------------------------------------------------------------------
| Site identity, links and on/off switches
|--------------------------------------------------------------------------
|
| All editable in the admin under Settings (App\Settings\SettingsRegistry);
| these are only the starting values.
|
*/

return [
    'support_email' => env('SITE_SUPPORT_EMAIL', 'help@rafflekings.com.ng'),
    'support_phone' => env('SITE_SUPPORT_PHONE'),

    'links' => [
        'telegram_support' => env('SITE_TELEGRAM_SUPPORT_URL', 'https://t.me/rafflekings_customersupport'),
        'community' => env('SITE_COMMUNITY_URL'),
        'whatsapp_channel' => env('SITE_WHATSAPP_CHANNEL_URL'),
        'instagram' => env('SITE_INSTAGRAM_URL'),
        'facebook' => env('SITE_FACEBOOK_URL'),
        'x' => env('SITE_X_URL'),
        'tiktok' => env('SITE_TIKTOK_URL'),
    ],

    // Pause a part of the site without a deploy (App\Http\Middleware\EnsureFeatureOn).
    'switches' => [
        'registrations' => true,
        'ticket_sales' => true,
        'deposits' => true,
        'withdrawals' => true,
        'live_chat' => true,
        'daily_claim' => true,
        'tasks' => true,
        'spin' => true,
        'point_redemption' => true,
    ],

    // Maintenance mode (App\Services\Maintenance): customers see a
    // "back soon" page; staff, the admin and payment confirmations keep
    // working. Times are stored in UTC.
    'maintenance' => [
        'enabled' => false,
        'starts_at' => null,
        'back_at' => null,
        'message' => 'We\'re making a few improvements to the site and will be back shortly. Thanks for your patience!',
        'warn_hours' => 12,
    ],

    'paused_message' => 'This is paused for a short while. Please try again soon.',
];
