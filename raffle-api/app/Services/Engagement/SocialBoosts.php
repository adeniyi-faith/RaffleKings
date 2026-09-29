<?php

namespace App\Services\Engagement;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\RaffleTeam;
use App\Models\RaffleTeamMember;
use App\Models\UnlockLink;
use App\Models\UnlockTap;
use App\Notifications\EngagementAlert;
use App\Services\PointsService;
use App\Services\RaffleReadService;
use App\Services\RaffleRulesService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The two "bring your friends" features (Phase 11), both inside one raffle:
 *
 *  - Help me unlock: a customer with a ticket shares a link; when enough
 *    different friends tap it, the customer gets a free bonus entry in
 *    that raffle, and every friend who tapped gets a few points.
 *  - Team Up: a captain with a ticket starts a team; friends who also
 *    hold a ticket in the raffle join within the time limit; a full team
 *    gets everyone a free bonus entry.
 *
 * Only free rewards; nothing is bought. Every customer needs their own
 * ticket first, so no one can farm free entries with empty accounts.
 */
class SocialBoosts
{
    public function __construct(
        private readonly RaffleReadService $raffles,
        private readonly RaffleRulesService $rules,
        private readonly PointsService $points,
        private readonly BadgeService $badges,
    ) {}

    // ---- shared -------------------------------------------------------------

    public function holdsTicket(int $userId, int $raffleId): bool
    {
        return RaffleEntry::query()->where('user_id', $userId)->where('raffle_id', $raffleId)->exists();
    }

    /** @return array raffle as RaffleReadService presents it */
    private function openRaffle(int $raffleId): array
    {
        $raffle = $this->raffles->find($raffleId);

        if (! $raffle || $raffle['is_closed']) {
            throw new InvalidArgumentException('This raffle has closed, so it can\'t be unlocked or joined any more.');
        }

        return $raffle;
    }

    private function newCode(string $model): string
    {
        do {
            $code = Str::lower(Str::random(8));
        } while ($model::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * The name other people see on a team or an unlock link: the customer's
     * username, in full. (It used to cut a display name at its first space,
     * so "Mr John" showed as "Mr".)
     */
    private function firstName(?WpUser $user): string
    {
        $name = trim((string) ($user?->user_login ?: $user?->display_name));

        return $name === '' ? 'A friend' : $name;
    }

    /** What the raffle page and the purchase success screen show a signed-in customer. */
    public function forRaffle(int $userId, int $raffleId): array
    {
        $unlock = UnlockLink::query()->where('user_id', $userId)->where('raffle_id', $raffleId)->first();
        $member = RaffleTeamMember::query()->where('user_id', $userId)->where('raffle_id', $raffleId)->first();
        $team = $member ? RaffleTeam::find($member->raffle_team_id) : null;

        return [
            'has_tickets' => $this->holdsTicket($userId, $raffleId),
            'unlock' => $unlock ? $this->presentUnlock($unlock) : null,
            'team' => $team ? $this->presentTeam($team, $userId) : null,
            'unlock_needed' => $this->tapsNeeded(),
            'team_size' => $this->teamSize(),
            'team_hours' => (int) config('engagement.teams.hours', 24),
            'bonus_entries' => (int) config('engagement.unlock.bonus_entries', 1),
            'team_bonus_entries' => (int) config('engagement.teams.bonus_entries', 1),
        ];
    }

    // ---- Help me unlock -------------------------------------------------------

    public function tapsNeeded(): int
    {
        return max(1, min(20, (int) config('engagement.unlock.taps_needed', 3)));
    }

    public function createUnlock(WpUser $user, int $raffleId, ?string $ip = null): UnlockLink
    {
        $this->openRaffle($raffleId);

        if (! $this->holdsTicket($user->ID, $raffleId)) {
            throw new InvalidArgumentException('Buy at least one ticket in this raffle first, then share your link.');
        }

        return UnlockLink::query()->firstOrCreate(
            ['user_id' => $user->ID, 'raffle_id' => $raffleId],
            ['code' => $this->newCode(UnlockLink::class), 'taps_needed' => $this->tapsNeeded(), 'owner_ip' => $ip],
        );
    }

    /** A friend taps the link. Returns the link's page data and the points the friend got. */
    public function tap(WpUser $friend, string $code, ?string $ip = null): array
    {
        $link = UnlockLink::query()->where('code', $code)->firstOrFail();

        if ($link->user_id === $friend->ID) {
            throw new InvalidArgumentException('This is your own link. Share it with friends so they can tap it.');
        }

        if ($link->completed_at) {
            throw new InvalidArgumentException('Already unlocked! Thanks for helping.');
        }

        $this->openRaffle($link->raffle_id);

        if ($ip && $link->owner_ip && $ip === $link->owner_ip) {
            throw new InvalidArgumentException('Taps must come from friends on their own phones.');
        }

        $todayTaps = UnlockTap::query()->where('user_id', $friend->ID)->where('created_at', '>=', now()->setTimezone(config('raffles.timezone'))->startOfDay()->utc())->count();

        if ($todayTaps >= max(1, (int) config('engagement.unlock.daily_taps_per_user', 5))) {
            throw new InvalidArgumentException('You\'ve helped lots of friends today. Come back tomorrow to help more.');
        }

        $points = max(0, (int) config('engagement.unlock.tapper_points', 20));

        try {
            UnlockTap::create(['unlock_link_id' => $link->id, 'user_id' => $friend->ID, 'ip' => $ip, 'points' => $points]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('You already helped with this one. Thank you!');
        }

        if ($points > 0) {
            $this->points->credit($friend, $points, 'unlock_tap', 'unlock_link', $link->id, 'Helped a friend unlock a bonus entry');
        }

        $this->maybeCompleteUnlock($link);

        return ['link' => $this->presentUnlock($link->fresh()), 'points' => $points];
    }

    private function maybeCompleteUnlock(UnlockLink $link): void
    {
        $done = DB::transaction(function () use ($link) {
            $locked = UnlockLink::query()->whereKey($link->id)->lockForUpdate()->first();

            if ($locked->completed_at || $locked->taps()->count() < $locked->taps_needed) {
                return false;
            }

            $locked->update(['completed_at' => now()]);
            $this->rules->addEarnedEntries($locked->user_id, $locked->raffle_id, max(1, (int) config('engagement.unlock.bonus_entries', 1)), 'unlock');

            return true;
        });

        if ($done) {
            $this->badges->award($link->user_id, 'unlocker');
            WpUser::find($link->user_id)?->notify(new EngagementAlert(
                'Unlocked! 🔓',
                'Your friends helped you unlock a free bonus entry. It\'s in the draw now.',
                "/raffles/{$link->raffle_id}",
                'See the raffle',
            ));
        }
    }

    public function presentUnlock(UnlockLink $link): array
    {
        $raffle = $this->raffles->find($link->raffle_id);

        return [
            'code' => $link->code,
            'url' => url("/u/{$link->code}"),
            'owner' => $this->firstName(WpUser::find($link->user_id)),
            'owner_id' => $link->user_id,
            'taps' => min($link->taps_needed, $link->taps()->count()),
            'needed' => $link->taps_needed,
            'completed' => (bool) $link->completed_at,
            'tapper_points' => (int) config('engagement.unlock.tapper_points', 20),
            'raffle' => $raffle ? ['id' => $raffle['id'], 'title' => $raffle['title'], 'grand_prize' => $raffle['grand_prize'], 'is_closed' => $raffle['is_closed']] : null,
        ];
    }

    // ---- Team Up ----------------------------------------------------------------

    public function teamSize(): int
    {
        return max(2, min(10, (int) config('engagement.teams.size', 3)));
    }

    public function createTeam(WpUser $captain, int $raffleId): RaffleTeam
    {
        $raffle = $this->openRaffle($raffleId);

        if (! $this->holdsTicket($captain->ID, $raffleId)) {
            throw new InvalidArgumentException('Buy at least one ticket in this raffle first, then start your team.');
        }

        if ($member = RaffleTeamMember::query()->where('user_id', $captain->ID)->where('raffle_id', $raffleId)->first()) {
            return RaffleTeam::findOrFail($member->raffle_team_id);
        }

        $expires = now()->addHours(max(1, (int) config('engagement.teams.hours', 24)));

        if ($raffle['ends_at'] && $expires->greaterThan($raffle['ends_at'])) {
            $expires = Carbon::parse($raffle['ends_at']);
        }

        return DB::transaction(function () use ($captain, $raffleId, $expires) {
            $team = RaffleTeam::create([
                'code' => $this->newCode(RaffleTeam::class),
                'raffle_id' => $raffleId,
                'captain_id' => $captain->ID,
                'size' => $this->teamSize(),
                'expires_at' => $expires,
            ]);

            RaffleTeamMember::create(['raffle_team_id' => $team->id, 'raffle_id' => $raffleId, 'user_id' => $captain->ID, 'joined_at' => now()]);

            return $team;
        });
    }

    public function join(WpUser $user, string $code): RaffleTeam
    {
        $team = RaffleTeam::query()->where('code', $code)->firstOrFail();

        if (! $team->isOpen()) {
            throw new InvalidArgumentException($team->completed_at ? 'This team is already full.' : 'This team ran out of time. Start your own team instead!');
        }

        $this->openRaffle($team->raffle_id);

        if (! $this->holdsTicket($user->ID, $team->raffle_id)) {
            throw new InvalidArgumentException('Buy at least one ticket in this raffle, then join the team.');
        }

        $completed = DB::transaction(function () use ($team, $user) {
            $locked = RaffleTeam::query()->whereKey($team->id)->lockForUpdate()->first();

            if (! $locked->isOpen()) {
                throw new InvalidArgumentException('This team is already full.');
            }

            if (RaffleTeamMember::query()->where('user_id', $user->ID)->where('raffle_id', $locked->raffle_id)->exists()) {
                throw new InvalidArgumentException('You\'re already in a team for this raffle.');
            }

            RaffleTeamMember::create(['raffle_team_id' => $locked->id, 'raffle_id' => $locked->raffle_id, 'user_id' => $user->ID, 'joined_at' => now()]);

            if ($locked->members()->count() < $locked->size) {
                return false;
            }

            $locked->update(['completed_at' => now()]);

            foreach ($locked->members()->pluck('user_id') as $memberId) {
                $this->rules->addEarnedEntries($memberId, $locked->raffle_id, max(1, (int) config('engagement.teams.bonus_entries', 1)), 'team');
            }

            return true;
        });

        if ($completed) {
            foreach ($team->members()->pluck('user_id') as $memberId) {
                $this->badges->award($memberId, $memberId === $team->captain_id ? 'team_captain' : 'team_player');
                WpUser::find($memberId)?->notify(new EngagementAlert(
                    'Your team is full! 🤜🤛',
                    'Everyone in your Team Up team got a free bonus entry in the draw.',
                    "/team/{$team->code}",
                    'See your team',
                ));
            }
        } else {
            WpUser::find($team->captain_id)?->notify(new EngagementAlert(
                $this->firstName($user).' joined your team',
                ($team->size - $team->members()->count()).' more to go before your team is full.',
                "/team/{$team->code}",
                'See your team',
            ));
        }

        return $team->fresh();
    }

    public function presentTeam(RaffleTeam $team, ?int $viewerId = null): array
    {
        $members = $team->members()->orderBy('joined_at')->get();
        $users = WpUser::query()->whereIn('ID', $members->pluck('user_id'))->get()->keyBy('ID');
        $raffle = $this->raffles->find($team->raffle_id);

        return [
            'code' => $team->code,
            'url' => url("/team/{$team->code}"),
            'size' => $team->size,
            'expires_at' => $team->expires_at->toIso8601String(),
            'completed' => (bool) $team->completed_at,
            'expired' => ! $team->completed_at && $team->expires_at->isPast(),
            'captain' => $this->firstName($users->get($team->captain_id)),
            'members' => $members->map(fn ($m) => [
                'name' => $this->firstName($users->get($m->user_id)),
                'is_captain' => $m->user_id === $team->captain_id,
                'is_you' => $viewerId !== null && $m->user_id === $viewerId,
            ])->values()->all(),
            'is_member' => $viewerId !== null && $members->contains('user_id', $viewerId),
            'bonus_entries' => (int) config('engagement.teams.bonus_entries', 1),
            'raffle' => $raffle ? ['id' => $raffle['id'], 'title' => $raffle['title'], 'grand_prize' => $raffle['grand_prize'], 'price' => $raffle['price'], 'is_closed' => $raffle['is_closed']] : null,
        ];
    }
}
