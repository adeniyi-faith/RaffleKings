<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Push & Admin-Alert Notifications
    |--------------------------------------------------------------------------
    |
    | Same providers the legacy site already uses (OneSignal for push,
    | Telegram for admin alerts) — see App\Notifications\Channels for the
    | queued channel implementations. Unlike the legacy versions, these
    | go through Laravel's own HTTP client, which verifies TLS
    | certificates by default (the legacy OneSignal calls disable
    | verification entirely — audit TD-37).
    |
    */

    'onesignal' => [
        'app_id' => env('ONESIGNAL_APP_ID'),
        'api_key' => env('ONESIGNAL_API_KEY'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'admin_chat_ids' => array_filter(explode(',', (string) env('TELEGRAM_ADMIN_CHAT_IDS', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gateways (item 13)
    |--------------------------------------------------------------------------
    |
    | Paystack is the primary deposit gateway, Flutterwave the automatic
    | backup — see config/payments.php and App\Services\DepositService.
    | Flutterwave's `secret_hash` is a separate value YOU set in its
    | dashboard for webhook verification, not the API secret key.
    |
    */

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    ],

    'flutterwave' => [
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'),
    ],

];
