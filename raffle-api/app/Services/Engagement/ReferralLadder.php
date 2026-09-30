<?php

namespace App\Services\Engagement;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\ReferralCommission;
use App\Models\ReferralMilestone;
use App\Notifications\EngagementAlert;
use App\Services\PointsService;
use App\Services\Risk\AbuseDetector;
use App\Support\Features;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The referral ladder (Phase 11): rewards that grow with each friend who
 * joins through a customer's link AND plays (buys at least one ticket) —
 * 1, 5, 10 and 25 friends by default (config engagement.referral_ladder).
 * Each rung pays once, automatically, the moment it's reached. This sits
 * on top of the existing first-top-up commission, which is unchanged.
 */
class ReferralLadder
{
    public function __construct(
        private readonly PointsService $points,
        private readonly Perks $perks,
        private readonly BadgeService $badges,
    ) {}

    /** @return list<array{friends: int, points: int, free_spins: int, badge: ?string}> */
    public function rungs(): array
    {
        return collect((array) config('engagement.referral_ladder'))
            ->map(fn ($r) => [
                'friends' => max(1, (int) $r['friends']),
                'points' => max(0, (int) ($r['points'] ?? 0)),
                'free_spins' => max(0, (int) ($r['free_spins'] ?? 0)),
                'badge' => ($r['badge'] ?? null) ?: null,
            ])
            ->sortBy('friends')
            ->unique('friends')
            ->values()
            ->all();
    }

    /** The rungs with their rewards in words, for the public referral page. */
    public function publicRungs(): array
    {
        return collect($this->rungs())->map(fn ($r) => $r + ['reward' => $this->describeReward($r), 'reached' => false])->all();
    }

    /** @return Collection<int, int> everyone this customer referred */
    public function refereeIds(int $userId): Collection
    {
        return WpUserMeta::query()->where('meta_key', 'referred_by')->where('meta_value', (string) $userId)->pluck('user_id')->map(fn ($id) => (int) $id);
    }

    /** Friends who joined through this customer's link and bought at least one ticket. */
    public function qualifiedCount(int $userId): int
    {
        $ids = $this->refereeIds($userId);

        if ($ids->isEmpty()) {
            return 0;
        }

        $players = RaffleEntry::query()->whereIn('user_id', $ids)->distinct()->pluck('user_id');

        // Multi-account protection: a "friend" who looks like the customer
        // themselves doesn't lift them up the ladder.
        if (Features::on('abuse_detection')) {
            $detector = app(AbuseDetector::class);
            $players = $players->reject(fn ($id) => $detector->linkBetween($userId, (int) $id) !== null);
        }

        return $players->count();
    }

    /** Progress listener: a customer's very first tickets may lift their referrer up the ladder. */
    public function ticketsBought(int $userId, int $count, int $total): void
    {
        if ($total !== $count) {
            return; // not their first purchase
        }

        $referrerId = (int) WpUserMeta::query()->where('user_id', $userId)->where('meta_key', 'referred_by')->value('meta_value');

        if ($referrerId && $referrerId !== $userId) {
            $this->payReachedRungs($referrerId);
        }
    }

    /** Pays every rung this customer has reached and not yet been paid for. */
    public function payReachedRungs(int $userId): void
    {
        $qualified = $this->qualifiedCount($userId);
        $user = WpUser::find($userId);

        if (! $user) {
            return;
        }

        foreach ($this->rungs() as $rung) {
            if ($qualified < $rung['friends'] || ReferralMilestone::query()->where('user_id', $userId)->where('friends', $rung['friends'])->exists()) {
                continue;
            }

            try {
                ReferralMilestone::create(['user_id' => $userId, 'reached_at' => now()] + $rung);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            if ($rung['points'] > 0) {
                $this->points->credit($user, $rung['points'], 'referral_ladder', description: "Referral ladder: {$rung['friends']} friends playing");
            }

            $this->perks->addFreeSpins($userId, $rung['free_spins']);

            if ($rung['badge']) {
                $this->badges->award($userId, $rung['badge']);
            }

            $user->notify(new EngagementAlert(
                "Referral ladder: {$rung['friends']} ".($rung['friends'] === 1 ? 'friend' : 'friends').' playing! 🎉',
                'You earned '.$this->describeReward($rung).'. Keep inviting to climb higher.',
                '/referrals',
                'See your ladder',
            ));
        }
    }

    /** The whole referral page for one customer. */
    public function overview(WpUser $user): array
    {
        $ids = $this->refereeIds($user->ID);
        $friends = $ids->isEmpty() ? collect() : WpUser::query()->whereIn('ID', $ids)->orderByDesc('user_registered')->limit(100)->get(['ID', 'display_name', 'user_login', 'user_registered']);
        $played = $ids->isEmpty() ? collect() : RaffleEntry::query()->whereIn('user_id', $ids)->selectRaw('user_id, min(created_at) as first_played')->groupBy('user_id')->pluck('first_played', 'user_id');
        $commissions = ReferralCommission::query()->where('referrer_user_id', $user->ID)->where('status', 'paid')->get();
        $reached = ReferralMilestone::query()->where('user_id', $user->ID)->pluck('reached_at', 'friends');
        $qualified = $played->count();

        $rungs = collect($this->rungs())->map(fn ($r) => $r + [
            'reward' => $this->describeReward($r),
            'reached' => isset($reached[$r['friends']]),
        ]);

        return [
            'code' => $user->user_login,
            'link' => url('/register?ref='.urlencode($user->user_login)),
            'commission_percent' => round((float) config('referrals.commission_rate') * 100),
            'friends_joined' => $ids->count(),
            'friends_playing' => $qualified,
            'commission_earned' => (float) $commissions->sum('commission_amount'),
            'rungs' => $rungs->values()->all(),
            'next' => $rungs->first(fn ($r) => ! $r['reached'] && $qualified < $r['friends']),
            'friends' => $friends->map(fn (WpUser $f) => [
                'name' => $this->mask($f->display_name ?: $f->user_login),
                'joined' => $f->user_registered ? Carbon::parse($f->user_registered)->toIso8601String() : null,
                'playing' => isset($played[$f->ID]),
                'commission' => (float) $commissions->firstWhere('referee_user_id', $f->ID)?->commission_amount,
            ])->values()->all(),
        ];
    }

    public function describeReward(array $rung): string
    {
        $parts = [];
        if ($rung['points'] > 0) {
            $parts[] = number_format($rung['points']).' points';
        }
        if ($rung['free_spins'] > 0) {
            $parts[] = $rung['free_spins'].' free '.($rung['free_spins'] === 1 ? 'spin' : 'spins');
        }
        if ($rung['badge'] && ($badge = $this->badges->catalog()[$rung['badge']] ?? null)) {
            $parts[] = 'the '.$badge['emoji'].' '.$badge['name'].' badge';
        }

        return $parts ? implode(' + ', $parts) : 'a thank-you';
    }

    /** "Adebayo" → "Ade****" so friends' names stay private. */
    private function mask(string $name): string
    {
        $name = trim($name);

        return mb_substr($name, 0, 3).str_repeat('*', max(2, min(6, mb_strlen($name) - 3)));
    }
}
