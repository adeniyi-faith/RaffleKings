<?php

namespace App\Services\Engagement;

use App\Models\Legacy\WpUser;
use App\Models\UserBadge;
use App\Models\UserEngagement;
use App\Notifications\EngagementAlert;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Badges (Phase 11): earned once each, shown on the customer's badges
 * page, and up to three pinned to their profile (the "showcase").
 */
class BadgeService
{
    /** @return array<string, array{key: string, name: string, description: string, emoji: string}> */
    public function catalog(): array
    {
        return collect((array) config('engagement.badges'))
            ->map(fn ($b, $key) => ['key' => $key, 'name' => $b[0], 'description' => $b[1], 'emoji' => $b[2]])
            ->all();
    }

    public function has(int $userId, string $badge): bool
    {
        return UserBadge::query()->where('user_id', $userId)->where('badge', $badge)->exists();
    }

    /**
     * Give a badge (once). Returns true only the first time, and tells the
     * customer about it.
     */
    public function award(int $userId, string $badge): bool
    {
        $info = $this->catalog()[$badge] ?? null;

        if (! $info || $this->has($userId, $badge)) {
            return false;
        }

        try {
            UserBadge::create(['user_id' => $userId, 'badge' => $badge, 'earned_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return false; // earned at the same moment elsewhere
        }

        WpUser::find($userId)?->notify(new EngagementAlert(
            "New badge: {$info['emoji']} {$info['name']}",
            'You earned a new badge. Open My Badges and tap it to pin it to your profile.',
            '/account/badges',
            'See my badges',
        ));

        return true;
    }

    /**
     * Every badge, earned or not, for the badges page.
     *
     * @return list<array{key: string, name: string, description: string, emoji: string, earned_at: ?string, pinned: bool}>
     */
    public function forUser(int $userId): array
    {
        $earned = UserBadge::query()->where('user_id', $userId)->pluck('earned_at', 'badge');
        $pinned = $this->showcaseKeys($userId);

        return collect($this->catalog())
            ->map(fn ($b) => $b + [
                'earned_at' => isset($earned[$b['key']]) ? Carbon::parse($earned[$b['key']])->toIso8601String() : null,
                'pinned' => in_array($b['key'], $pinned, true),
            ])
            ->sortBy(fn ($b) => $b['earned_at'] ? 0 : 1)
            ->values()
            ->all();
    }

    /**
     * The badges a customer shows on their profile: the ones they pinned,
     * or their newest ones if they haven't chosen.
     *
     * @return list<array{key: string, name: string, emoji: string}>
     */
    public function showcase(int $userId): array
    {
        $catalog = $this->catalog();
        $keys = $this->showcaseKeys($userId);

        if ($keys === []) {
            $keys = UserBadge::query()->where('user_id', $userId)->orderByDesc('earned_at')->limit($this->showcaseSize())->pluck('badge')->all();
        }

        return collect($keys)
            ->filter(fn ($k) => isset($catalog[$k]))
            ->map(fn ($k) => ['key' => $k, 'name' => $catalog[$k]['name'], 'emoji' => $catalog[$k]['emoji']])
            ->values()
            ->all();
    }

    /**
     * Pin up to three earned badges.
     *
     * @param  list<string>  $keys
     */
    public function setShowcase(int $userId, array $keys): array
    {
        $earned = UserBadge::query()->where('user_id', $userId)->pluck('badge')->all();
        $keys = array_values(array_slice(array_unique(array_intersect($keys, $earned)), 0, $this->showcaseSize()));

        UserEngagement::for($userId)->update(['showcase' => $keys]);

        return $keys;
    }

    public function showcaseSize(): int
    {
        return max(1, (int) config('engagement.showcase_size', 3));
    }

    /** @return list<string> */
    private function showcaseKeys(int $userId): array
    {
        return array_values((array) (UserEngagement::query()->where('user_id', $userId)->first()?->showcase ?? []));
    }
}
