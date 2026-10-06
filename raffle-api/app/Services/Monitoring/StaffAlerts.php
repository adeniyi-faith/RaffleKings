<?php

namespace App\Services\Monitoring;

use App\Notifications\SystemProblemAdminAlert;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * One place for "tell staff something is wrong with money". Sends the
 * Telegram alert, never throws (an alert that fails must not break the
 * payment it is about), and can hold back repeats of the same problem so a
 * stuck item doesn't send a message every few minutes.
 */
class StaffAlerts
{
    /**
     * @param  string|null  $onceKey  when set, the same key alerts at most once per $minutes
     */
    public static function send(string $problem, ?string $onceKey = null, int $minutes = 1440): bool
    {
        if ($onceKey !== null && ! Cache::add('staff-alert:'.$onceKey, 1, now()->addMinutes($minutes))) {
            return false;
        }

        Log::warning('Staff alert: '.$problem);

        try {
            Notification::send(new AnonymousNotifiable, new SystemProblemAdminAlert($problem));
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }
}
