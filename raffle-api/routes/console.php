<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// OVERHAUL_CHECKLIST.md Phase 3 item 32 — keep the shadow-traffic backlog
// caught up so a mismatch is visible within a minute of a real purchase,
// not discovered hours later by someone manually running the command.
Schedule::command('shadow:process-purchases')->everyMinute();
