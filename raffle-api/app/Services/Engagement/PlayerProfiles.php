<?php

namespace App\Services\Engagement;

use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\UserBadge;
use App\Models\UserEngagement;
use App\Services\DailyClaimService;
use Illuminate\Support\Carbon;

/**
 * The public "player card" at /player/{username}: username, picture, badges
 * and a couple of friendly numbers. Nothing about money, and nothing private.
 *
 * - Visible to everyone unless the customer switched it to private.
 * - A customer's wins (the win badges and the win count) show only if they
 *   chose to show them.
 * - Staff and banned accounts never have a public card.
 * - An unknown username and a private profile look exactly the same, so the
 *   page can't be used to find out who is a customer.
 */
class PlayerProfiles
{
    public const VISIBILITIES = ['everyone', 'private'];

    /** Badges that reveal a win. */
    private const WIN_BADGES = ['first_win', 'storyteller'];

    public function __construct(
        private readonly BadgeService $badges,
        private readonly DailyClaimService $daily,
    ) {}

    public function find(string $username): ?WpUser
    {
        $user = WpUser::query()->where('user_login', $username)->first();

        return $user && $this->isPublic($user) ? $user : null;
    }

    public function isPublic(WpUser $user): bool
    {
        if ($user->staffRole() !== null || $user->isBanned()) {
            return false;
        }

        return $this->settings($user->ID)['visibility'] === 'everyone';
    }

    /** @return array{visibility: string, show_wins: bool} */
    public function settings(int $userId): array
    {
        $row = UserEngagement::query()->where('user_id', $userId)->first();

        return [
            'visibility' => in_array($row?->profile_visibility, self::VISIBILITIES, true) ? $row->profile_visibility : 'everyone',
            'show_wins' => (bool) ($row?->show_wins ?? false),
        ];
    }

    /**
     * @param  array{visibility?: string, show_wins?: bool}  $changes
     * @return array{visibility: string, show_wins: bool}
     */
    public function update(int $userId, array $changes): array
    {
        $values = [];

        if (isset($changes['visibility']) && in_array($changes['visibility'], self::VISIBILITIES, true)) {
            $values['visibility'] = $changes['visibility'];
        }

        $row = UserEngagement::for($userId);
        $row->update(array_filter([
            'profile_visibility' => $values['visibility'] ?? null,
            'show_wins' => array_key_exists('show_wins', $changes) ? (bool) $changes['show_wins'] : null,
        ], fn ($v) => $v !== null));

        return $this->settings($userId);
    }

    /** What anyone may see about this customer, or null if there is no public card. */
    public function card(string $username): ?array
    {
        $user = $this->find($username);

        if (! $user) {
            return null;
        }

        $showWins = $this->settings($user->ID)['show_wins'];
        $hidden = $showWins ? [] : self::WIN_BADGES;
        $earned = UserBadge::query()->where('user_id', $user->ID)->pluck('earned_at', 'badge');

        $badges = collect($this->badges->catalog())
            ->filter(fn ($b) => isset($earned[$b['key']]) && ! in_array($b['key'], $hidden, true))
            ->map(fn ($b) => ['key' => $b['key'], 'name' => $b['name'], 'description' => $b['description'], 'emoji' => $b['emoji']])
            ->values();

        $showcase = collect($this->badges->showcase($user->ID))
            ->reject(fn ($b) => in_array($b['key'], $hidden, true))
            ->values();

        return [
            'username' => $user->user_login,
            'avatar' => $user->metaValue('profile_pic_url') ?: null,
            'member_since' => $user->user_registered ? Carbon::parse($user->user_registered)->format('F Y') : null,
            'showcase' => $showcase->all(),
            'badges' => $badges->all(),
            'badge_total' => count($this->badges->catalog()),
            'streak' => $this->streak($user),
            'wins' => $showWins ? RaffleWinner::query()->where('user_id', $user->ID)->count() : null,
        ];
    }

    /**
     * Days in a row so far. state() reports the day claiming *right now* would
     * land on when today's claim isn't made yet, so one less is what is done.
     */
    private function streak(WpUser $user): int
    {
        $state = $this->daily->state($user);

        return $state['is_claimed_today'] ? $state['streak'] : max(0, $state['streak'] - 1);
    }
}
