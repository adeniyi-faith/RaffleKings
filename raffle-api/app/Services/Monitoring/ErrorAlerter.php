<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * OVERHAUL_CHECKLIST.md item 42 — tells admins about problems as they
 * happen, instead of learning about them from customer complaints.
 *
 * Sends straight to the Telegram Bot API (not via the queue): if the
 * queue itself is what's broken, a queued alert about it would never
 * arrive. Deliberately defensive — an alert is best-effort and must never
 * turn one error into two, so every failure in here is swallowed (and
 * logged), the HTTP call has a short timeout, and repeats are throttled
 * (see config/monitoring.php).
 */
class ErrorAlerter
{
    /** An unexpected server error (from the exception handler). */
    public function exception(Throwable $e): void
    {
        $where = Str::after($e->getFile(), base_path().DIRECTORY_SEPARATOR).':'.$e->getLine();

        $this->send(
            fingerprint: get_class($e).'@'.$where,
            title: 'Server error',
            lines: [
                class_basename($e).': '.Str::limit($e->getMessage(), 400),
                'at '.$where,
                ...$this->requestLines(),
            ],
        );
    }

    /**
     * A problem the app handled gracefully for the customer, but that an
     * admin needs to know about (e.g. no payment gateway could start a
     * deposit).
     *
     * @param  array<int, string>  $details
     */
    public function problem(string $title, array $details = []): void
    {
        $this->send(
            fingerprint: 'problem:'.$title,
            title: $title,
            lines: [...$details, ...$this->requestLines()],
        );
    }

    /** @param  array<int, string>  $lines */
    private function send(string $fingerprint, string $title, array $lines): void
    {
        try {
            $token = config('services.telegram.bot_token');
            $chatIds = config('services.telegram.admin_chat_ids', []);

            if (! config('monitoring.telegram_error_alerts') || ! $token || empty($chatIds)) {
                return;
            }

            // Same error again within the window: already told admins.
            if (! Cache::add('error-alert:'.sha1($fingerprint), 1, now()->addMinutes(config('monitoring.repeat_after_minutes', 10)))) {
                return;
            }

            if (! RateLimiter::attempt('error-alerts', (int) config('monitoring.max_per_hour', 20), fn () => true, 3600)) {
                return;
            }

            $text = Str::limit("🚨 RaffleKings — {$title}\n".implode("\n", $lines), 3500);

            foreach ($chatIds as $chatId) {
                Http::timeout(3)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
            }
        } catch (Throwable $alertFailure) {
            Log::warning('Could not send an admin error alert.', ['error' => $alertFailure->getMessage()]);
        }
    }

    /** @return array<int, string> */
    private function requestLines(): array
    {
        if (! app()->bound('request') || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return ['(background job or command)'];
        }

        $request = request();
        $userId = rescue(fn () => auth('wordpress')->id(), null, false);

        return array_values(array_filter([
            $request->method().' '.Str::limit($request->path(), 150),
            $userId ? "user #{$userId}" : null,
        ]));
    }
}
