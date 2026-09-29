<?php

/*
|--------------------------------------------------------------------------
| Error alerts (OVERHAUL_CHECKLIST.md item 42)
|--------------------------------------------------------------------------
|
| Server errors are sent to the admin Telegram chat(s) configured in
| services.telegram (TELEGRAM_BOT_TOKEN / TELEGRAM_ADMIN_CHAT_IDS), so a
| problem is noticed within minutes instead of from customer complaints.
| On by default in production only. See App\Services\Monitoring\ErrorAlerter.
|
*/

return [

    'telegram_error_alerts' => (bool) env('ERROR_ALERTS_TELEGRAM', env('APP_ENV') === 'production'),

    // The same error is sent at most once per this many minutes...
    'repeat_after_minutes' => (int) env('ERROR_ALERTS_REPEAT_AFTER_MINUTES', 10),

    // ...and never more than this many alerts in total per hour, so an
    // outage can't flood the admin chat.
    'max_per_hour' => (int) env('ERROR_ALERTS_MAX_PER_HOUR', 20),

    // Phase 10: errors in customers' browsers are always saved on
    // System → Health; Telegram alerts for them are off by default, since
    // phone browsers and add-ons produce a lot of harmless noise.
    'browser_error_alerts' => (bool) env('ERROR_ALERTS_BROWSER', false),

];
