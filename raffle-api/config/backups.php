<?php

/*
|--------------------------------------------------------------------------
| Database backups (App\Services\Monitoring\DatabaseBackup)
|--------------------------------------------------------------------------
|
| A full copy of the database every night (`php artisan backup:run`),
| then a practice restore into a SEPARATE, empty database to prove the
| copy really works (`php artisan backup:test-restore`). Editable in
| Settings → Backups & status. These do not need a feature switch: backups
| are always on.
|
*/

return [
    'enabled' => (bool) env('BACKUPS_ENABLED', true),

    // Hour of the night (business time zone) the backup runs.
    'hour' => (int) env('BACKUPS_HOUR', 3),

    // Backups older than this are deleted from the server.
    'keep_days' => (int) env('BACKUPS_KEEP_DAYS', 14),

    // Also send each backup file to the staff Telegram chat(s), so a copy
    // survives if the server itself is lost (files up to ~45 MB).
    'send_to_telegram' => (bool) env('BACKUPS_TELEGRAM', false),

    // The practice-restore database. It MUST be a different, empty
    // database (cPanel → MySQL Databases → create one, e.g. yourname_restoretest,
    // and give a user all privileges on it). Everything in it is wiped
    // before each practice restore. Empty database name = no practice restore.
    'restore' => [
        'host' => env('BACKUP_RESTORE_DB_HOST'),
        'database' => env('BACKUP_RESTORE_DB_DATABASE'),
        'username' => env('BACKUP_RESTORE_DB_USERNAME'),
        'password' => env('BACKUP_RESTORE_DB_PASSWORD'),
    ],
];
