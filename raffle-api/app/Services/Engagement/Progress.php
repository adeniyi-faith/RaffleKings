<?php

namespace App\Services\Engagement;

use App\Models\Legacy\RaffleEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place the rest of the site reports "a customer did something"
 * (bought tickets, claimed the daily reward, finished a task, won…) so
 * the Phase 11 features can react: badges, Season Pass XP, milestone free
 * spins and the referral ladder.
 *
 * Runs after the database transaction that caused it has saved, and
 * never throws: a badge or XP hiccup must never undo a purchase or claim.
 */
class Progress
{
    public function ticketsBought(int $userId, int $count, int $raffleId): void
    {
        $this->after(function () use ($userId, $count) {
            $total = RaffleEntry::query()->where('user_id', $userId)->count();
            $badges = app(BadgeService::class);

            $badges->award($userId, 'first_ticket');

            if ($total >= 50) {
                $badges->award($userId, 'tickets_50');
            }

            $this->hook('ticketsBought', $userId, $count, $total);
        });
    }

    public function dailyClaimed(int $userId, int $streak): void
    {
        $this->after(function () use ($userId, $streak) {
            if ($streak >= 7) {
                app(BadgeService::class)->award($userId, 'streak_7');
            }

            $this->hook('dailyClaimed', $userId, $streak);
        });
    }

    public function taskCompleted(int $userId, string $taskId): void
    {
        $this->after(fn () => $this->hook('taskCompleted', $userId, $taskId));
    }

    public function won(int $userId): void
    {
        $this->after(function () use ($userId) {
            app(BadgeService::class)->award($userId, 'first_win');
            $this->hook('won', $userId);
        });
    }

    /**
     * Other Phase 11 services listen here (Season Pass, free spins,
     * referral ladder); each is optional so this file never has to know
     * about all of them.
     */
    private function hook(string $event, mixed ...$args): void
    {
        foreach ((array) config('engagement.listeners', []) as $class) {
            $listener = app($class);

            if (method_exists($listener, $event)) {
                try {
                    $listener->{$event}(...$args);
                } catch (Throwable $e) {
                    Log::warning("Engagement listener {$class}::{$event} failed: ".$e->getMessage());
                }
            }
        }
    }

    private function after(callable $work): void
    {
        DB::afterCommit(function () use ($work) {
            try {
                $work();
            } catch (Throwable $e) {
                Log::warning('Engagement progress failed: '.$e->getMessage());
            }
        });
    }
}
