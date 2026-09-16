<?php

namespace App\Services;

use App\Events\LiveDrawCommentPosted;
use App\Events\LiveDrawReactionPosted;
use App\Exceptions\DrawNotCommittedException;
use App\Jobs\RunLiveDrawRevealJob;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\LiveDrawComment;
use App\Models\LiveDrawReaction;
use App\Models\LiveDrawReveal;
use App\Models\Raffle;
use App\Models\RaffleDraw;
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
        $legacyRaffleId = $raffle->legacy_post_id ?? $raffle->id;

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

    public function recentComments(Raffle $raffle, int $limit = 50): array
    {
        return LiveDrawComment::query()
            ->where('raffle_id', $raffle->id)
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

    public function postComment(WpUser $user, Raffle $raffle, string $body): LiveDrawComment
    {
        $comment = LiveDrawComment::create([
            'raffle_id' => $raffle->id,
            'user_id' => $user->getKey(),
            'body' => $body,
        ]);

        $comment->setRelation('user', $user);

        LiveDrawCommentPosted::dispatch($comment);

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

        LiveDrawReactionPosted::dispatch($raffle->id, $type, $counts);

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

        return [
            'id' => $winner->id,
            'name' => $user ? ($user->display_name ?: $user->user_login) : 'Lucky Winner',
            'ticket_number' => $winner->ticket_number,
            'prize_name' => $winner->prize_name,
            'prize_rank' => $winner->prize_rank,
            'prize_cash_value' => (float) $winner->prize_cash_value,
        ];
    }
}
