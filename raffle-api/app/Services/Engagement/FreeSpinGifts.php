<?php

namespace App\Services\Engagement;

use App\Models\Legacy\WpUser;
use App\Models\UserEngagement;
use App\Notifications\EngagementAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Free spins of the lucky wheel for special days (Phase 11): the
 * customer's birthday (once a year), each account anniversary, and
 * ticket milestones (10th, 50th, 100th ticket). Each is given once and
 * announced in the inbox; the wheel uses free spins before points.
 */
class FreeSpinGifts
{
    public function __construct(private readonly Perks $perks, private readonly BadgeService $badges) {}

    /** Progress listener: ticket milestones. */
    public function ticketsBought(int $userId, int $count, int $total): void
    {
        foreach ([10, 50, 100] as $milestone) {
            if ($total >= $milestone && $total - $count < $milestone) {
                $this->give($userId, "tickets_{$milestone}", "your {$milestone}th ticket");
            }
        }
    }

    /**
     * Birthday and account-anniversary spins, checked when the customer
     * opens Rewards or the wheel (so nothing needs to run at midnight).
     */
    public function checkOccasions(WpUser $user): void
    {
        $tz = (string) config('raffles.timezone', 'Africa/Lagos');
        $today = now()->setTimezone($tz);
        $row = UserEngagement::query()->where('user_id', $user->ID)->first();

        if ($row?->birthday === $today->format('m-d') && (int) $row->birthday_spin_year !== $today->year) {
            $given = DB::transaction(function () use ($user, $today) {
                $locked = UserEngagement::lockFor($user->ID);

                if ((int) $locked->birthday_spin_year === $today->year) {
                    return false;
                }

                $locked->update(['birthday_spin_year' => $today->year]);
                $locked->increment('free_spins', max(0, (int) config('engagement.free_spins.birthday', 1)));

                return true;
            });

            if ($given) {
                $this->badges->award($user->ID, 'birthday');
                $user->notify(new EngagementAlert('Happy birthday! 🎂', 'Your free birthday spin of the lucky wheel is waiting.', '/rewards/spin', 'Spin now'));
            }
        }

        $joined = $user->user_registered ? Carbon::parse($user->user_registered, 'UTC') : null;

        if ($joined && $joined->year >= 2000) {
            $years = (int) $joined->setTimezone($tz)->diffInYears($today);

            if ($years >= 1 && $years <= 50) {
                $this->give($user->ID, "anniversary_{$years}", $years === 1 ? 'your first year with us' : "{$years} years with us", 'anniversary');
            }
        }
    }

    /** Gives a milestone's free spins once. */
    private function give(int $userId, string $key, string $why, ?string $configKey = null): void
    {
        $spins = max(0, (int) config('engagement.free_spins.milestones.'.($configKey ?? $key), 0));

        if ($spins < 1) {
            return;
        }

        $given = DB::transaction(function () use ($userId, $key, $spins) {
            $row = UserEngagement::lockFor($userId);
            $done = (array) ($row->milestones ?? []);

            if (in_array($key, $done, true)) {
                return false;
            }

            $row->milestones = [...$done, $key];
            $row->free_spins += $spins;
            $row->save();

            return true;
        });

        if ($given) {
            WpUser::find($userId)?->notify(new EngagementAlert(
                'Free spin'.($spins === 1 ? '' : 's').' unlocked! 🎡',
                "{$spins} free ".($spins === 1 ? 'spin' : 'spins')." of the lucky wheel for {$why}.",
                '/rewards/spin',
                'Spin now',
            ));
        }
    }
}
