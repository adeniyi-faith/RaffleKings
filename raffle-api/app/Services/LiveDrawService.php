<?php

namespace App\Services;

use App\Events\LiveDrawCommentPosted;
use App\Events\LiveDrawReactionPosted;
use App\Exceptions\DrawNotCommittedException;
use App\Http\Controllers\Api\HallOfFameController;
use App\Jobs\RunLiveDrawRevealJob;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\LiveDrawComment;
use App\Models\LiveDrawReaction;
use App\Models\LiveDrawReveal;
use App\Models\Raffle;
use App\Models\RaffleDraw;
use App\Services\Engagement\PlayerProfiles;
use App\Support\Live;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Item 27 — Live Draw. Everything a raffle's live-draw page needs
 * that isn't the reveal sequence itself (that's RunLiveDrawRevealJob,
 * the actual server-paced, synchronized-for-everyone timer). See that
 * job's docblock for why the reveal is a queued job and not something
 * this service does inline.
 */
class LiveDrawService
{
    /** The only reaction types the UI offers — mirrors LiveDrawReaction::TYPES. */
    public const REACTION_TYPES = LiveDrawReaction::TYPES;

    /**
     * Everything a freshly-loaded live-draw page needs in one call:
     * this raffle's live-draw settings, whatever has already aired (so
     * a viewer who joins mid-reveal — or reloads — sees the same
     * winners already-revealed viewers see, not an empty screen), the
     * recent comment history, and current reaction totals.
     */
    public function pageState(Raffle $raffle): array
    {
        $legacyRaffleId = $raffle->public_id; // the number every ticket and winner row uses (item 43)

        $draw = RaffleDraw::query()->where('raffle_id', $raffle->id)->first();

        $revealedSoFar = LiveDrawReveal::query()
            ->where('raffle_id', $raffle->id)
            ->orderBy('sequence')
            ->get()
            ->map(function (LiveDrawReveal $reveal) {
                $winner = RaffleWinner::find($reveal->raffle_winner_id);

                return [
                    'sequence' => $reveal->sequence,
                    'revealed_at' => $reveal->revealed_at->toIso8601String(),
                    'winner' => $winner ? $this->winnerPayload($winner) : null,
                ];
            })
            ->values();

        $totalWinners = $draw
            ? RaffleWinner::query()->where('raffle_id', $legacyRaffleId)->count()
            : 0;

        return [
            'raffle_id' => $raffle->id,
            'is_live_draw_enabled' => $raffle->is_live_draw_enabled,
            'live_draw_status' => $raffle->live_draw_status,
            'live_draw_pace_ms' => $raffle->live_draw_pace_ms,
            'live_draw_theme_color' => $raffle->live_draw_theme_color,
            'live_draw_scheduled_at' => $raffle->live_draw_scheduled_at,
            'draw_committed' => (bool) $draw,
            'draw_has_run' => (bool) $draw?->hasRun(),
            'total_winners' => $totalWinners,
            'revealed' => $revealedSoFar,
            'comments' => $this->recentComments($raffle),
            'reaction_counts' => $this->reactionCounts($raffle),
        ];
    }

    /**
     * Every raffle with a live draw, for the Live Draws page (item 48):
     * happening now, coming up, and past events people can replay.
     *
     * @return array{live: list<array>, upcoming: list<array>, past: list<array>}
     */
    public function events(): array
    {
        $raffles = Raffle::query()
            ->publiclyVisible()
            ->where('is_live_draw_enabled', true)
            ->orderByDesc('live_draw_started_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $revealCounts = LiveDrawReveal::query()
            ->whereIn('raffle_id', $raffles->pluck('id'))
            ->selectRaw('raffle_id, count(*) as total')
            ->groupBy('raffle_id')
            ->pluck('total', 'raffle_id');

        $topPrize = RaffleWinner::query()
            ->whereIn('raffle_id', $raffles->pluck('public_id'))
            ->where('prize_rank', 1)
            ->get(['raffle_id', 'user_id', 'prize_name'])
            ->keyBy('raffle_id');

        $present = function (Raffle $raffle) use ($revealCounts, $topPrize) {
            $top = $topPrize->get($raffle->public_id);
            $topUser = $top && $raffle->live_draw_status === 'completed' ? WpUser::find($top->user_id) : null;

            return [
                'id' => $raffle->id,
                'public_id' => $raffle->public_id,
                'title' => $raffle->title,
                'grand_prize' => $raffle->grand_prize,
                'status' => $raffle->live_draw_status,
                'scheduled_at' => $raffle->live_draw_scheduled_at?->toIso8601String(),
                'started_at' => $raffle->live_draw_started_at?->toIso8601String(),
                'winners' => (int) ($revealCounts[$raffle->id] ?? 0),
                'top_winner' => $topUser ? PlayerProfiles::nameOf($topUser) : null,
                'theme_color' => $raffle->live_draw_theme_color,
            ];
        };

        $grouped = $raffles->groupBy(fn (Raffle $r) => match ($r->live_draw_status) {
            'revealing' => 'live',
            'completed' => 'past',
            default => 'upcoming',
        });

        return [
            'live' => $grouped->get('live', collect())->map($present)->values()->all(),
            'upcoming' => $grouped->get('upcoming', collect())
                ->sortBy(fn (Raffle $r) => $r->live_draw_scheduled_at?->getTimestamp() ?? PHP_INT_MAX)
                ->map($present)->values()->all(),
            'past' => $grouped->get('past', collect())->map($present)->values()->all(),
        ];
    }

    public function recentComments(Raffle $raffle, int $limit = 50): array
    {
        return LiveDrawComment::query()
            ->where('raffle_id', $raffle->id)
            ->whereNull('hidden_at') // removed by a moderator (item 45)
            ->with('user')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (LiveDrawComment $c) => $c->toBroadcastArray())
            ->all();
    }

    /** @return array<string, int> */
    public function reactionCounts(Raffle $raffle): array
    {
        $rows = LiveDrawReaction::query()
            ->where('raffle_id', $raffle->id)
            ->selectRaw('reaction_type, count(*) as total')
            ->groupBy('reaction_type')
            ->pluck('total', 'reaction_type');

        return collect(self::REACTION_TYPES)
            ->mapWithKeys(fn ($type) => [$type => (int) ($rows[$type] ?? 0)])
            ->all();
    }

    /**
     * @throws RuntimeException if the customer is muted, or nothing is left after the chat filter
     */
    public function postComment(WpUser $user, Raffle $raffle, string $body): LiveDrawComment
    {
        $moderation = app(ChatModerationService::class);

        if ($moderation->isMuted($user->getKey())) {
            throw new RuntimeException('You can\'t post in the chat right now. If you think this is a mistake, contact support.');
        }

        // Links, phone numbers and blocked words are removed first (item 45).
        $body = $moderation->clean($body);

        if ($body === '' || trim(preg_replace('~\[(link|number) removed\]~', '', $body)) === '') {
            throw new RuntimeException('Links and phone numbers can\'t be shared in the chat.');
        }

        $comment = LiveDrawComment::create([
            'raffle_id' => $raffle->id,
            'user_id' => $user->getKey(),
            'body' => $body,
        ]);

        $comment->setRelation('user', $user);

        Live::send(new LiveDrawCommentPosted($comment));

        return $comment;
    }

    /** @return array<string, int> the fresh aggregate counts, broadcast alongside the event */
    public function postReaction(WpUser $user, Raffle $raffle, string $type): array
    {
        if (! in_array($type, self::REACTION_TYPES, true)) {
            throw new RuntimeException("Unknown reaction type: {$type}");
        }

        LiveDrawReaction::create([
            'raffle_id' => $raffle->id,
            'user_id' => $user->getKey(),
            'reaction_type' => $type,
        ]);

        $counts = $this->reactionCounts($raffle);

        Live::send(new LiveDrawReactionPosted($raffle->id, $type, $counts));

        return $counts;
    }

    /**
     * Admin-only. Starts the synchronized reveal — dispatches the
     * pacing job rather than doing the work here, so an HTTP request
     * doesn't have to stay open for the whole reveal.
     *
     * @throws DrawNotCommittedException
     * @throws RuntimeException
     */
    public function startReveal(Raffle $raffle): void
    {
        if (! $raffle->is_live_draw_enabled) {
            throw new RuntimeException('This raffle does not have a live-draw event enabled.');
        }

        $draw = RaffleDraw::query()->where('raffle_id', $raffle->id)->first();

        if (! $draw || ! $draw->hasRun()) {
            throw new DrawNotCommittedException($raffle->id);
        }

        if ($raffle->live_draw_status === 'revealing') {
            throw new RuntimeException('A live reveal is already in progress for this raffle.');
        }

        DB::transaction(function () use ($raffle) {
            // Re-checked under a lock (item 44) so two clicks can't start
            // two reveals of the same draw.
            $locked = Raffle::query()->whereKey($raffle->id)->lockForUpdate()->firstOrFail();

            if ($locked->live_draw_status === 'revealing') {
                throw new RuntimeException('A live reveal is already in progress for this raffle.');
            }

            $raffle->update([
                'live_draw_status' => 'revealing',
                'live_draw_started_at' => now(),
            ]);
        });

        RunLiveDrawRevealJob::dispatch($raffle->id);
    }

    /**
     * Shared with RunLiveDrawRevealJob so the payload a viewer sees
     * live and the payload a viewer sees on catch-up are identical.
     */
    public function winnerPayload(RaffleWinner $winner): array
    {
        $user = WpUser::find($winner->user_id);
        $name = PlayerProfiles::nameOf($user, 'Lucky Winner');

        return [
            'id' => $winner->id,
            'name' => $name,
            'profile' => app(PlayerProfiles::class)->pathFor($user),
            'avatar' => HallOfFameController::avatar($user?->metaValue('profile_pic_url'), $name),
            'ticket_number' => $winner->ticket_number,
            'prize_name' => $winner->prize_name,
            'prize_rank' => $winner->prize_rank,
            'prize_cash_value' => (float) $winner->prize_cash_value,
        ];
    }
}
