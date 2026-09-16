<?php

namespace App\Jobs;

use App\Events\LiveDrawStateChanged;
use App\Events\LiveDrawWinnerRevealed;
use App\Models\Legacy\RaffleWinner;
use App\Models\LiveDrawReveal;
use App\Models\Raffle;
use App\Models\RaffleDraw;
use App\Services\LiveDrawService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The one thing that makes item 27's reveal a genuinely SYNCHRONIZED,
 * real-time experience instead of the legacy livedraw.php's fake,
 * per-browser Math.random() flashing loop: this job is the SINGLE
 * writer that steps through an already-computed draw's winners on a
 * real server-side timer, one at a time, broadcasting each step as it
 * happens.
 *
 * Deliberately NOT "broadcast one giant 'here are all the winners'
 * event and let each browser re-run its own local pacing" — that
 * reproduces the exact non-synchronized feel this item exists to fix
 * (two viewers who joined a few seconds apart, or whose devices are
 * running the animation at slightly different real speeds, would see
 * winners land at different times). Instead, every step is a real,
 * separately-timed broadcast, and LiveDrawReveal rows are the durable
 * record of "what's aired so far" a newly-joining viewer can catch up
 * on with a plain HTTP GET (LiveDrawController::show) before Reverb
 * delivers anything further.
 *
 * Runs on the `database` queue (this app's default — see
 * OVERHAUL_CHECKLIST.md item 17) via a real queue worker
 * (`php artisan queue:work`), NOT inline in the HTTP request that
 * started it — the whole reveal can run for minutes, and a worker
 * dying mid-reveal just leaves `live_draw_status = 'revealing'`
 * stuck, safely resumable by re-dispatching (the unique
 * (raffle_draw_id, sequence) index means it can't double-reveal a
 * winner that already aired).
 */
class RunLiveDrawRevealJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // a partial reveal is safe to resume by re-dispatching; auto-retrying blind is not

    public function __construct(public readonly int $raffleId) {}

    public function handle(LiveDrawService $liveDraw): void
    {
        $raffle = Raffle::find($this->raffleId);

        if (! $raffle) {
            return;
        }

        $legacyRaffleId = $raffle->legacy_post_id ?? $raffle->id;

        $draw = RaffleDraw::query()->where('raffle_id', $raffle->id)->first();

        if (! $draw || ! $draw->hasRun()) {
            return;
        }

        $winners = RaffleWinner::query()
            ->where('raffle_id', $legacyRaffleId)
            ->orderBy('prize_rank')
            ->get();

        $alreadyRevealed = LiveDrawReveal::query()->where('raffle_draw_id', $draw->id)->pluck('raffle_winner_id')->all();

        $paceMicroseconds = max(200, $raffle->live_draw_pace_ms) * 1000;
        $sequence = LiveDrawReveal::query()->where('raffle_draw_id', $draw->id)->max('sequence') ?? 0;

        foreach ($winners as $winner) {
            if (in_array($winner->id, $alreadyRevealed, true)) {
                continue; // already aired in an earlier, interrupted run of this job
            }

            usleep($paceMicroseconds);

            $sequence++;

            $reveal = LiveDrawReveal::create([
                'raffle_id' => $raffle->id,
                'raffle_draw_id' => $draw->id,
                'raffle_winner_id' => $winner->id,
                'sequence' => $sequence,
                'revealed_at' => now(),
            ]);

            // Airing a winner live IS this raffle's publish step — the
            // legacy draw already gated Hall of Fame visibility behind
            // a separate `is_visible` flag (an admin action, see
            // WinnerManagementService); here the live reveal itself is
            // that action, once per winner, in sync with what everyone
            // is watching. Crediting the prize is untouched and stays
            // a deliberate, separate admin action either way.
            $winner->update(['is_visible' => true]);

            LiveDrawWinnerRevealed::dispatch($reveal, $liveDraw->winnerPayload($winner->fresh()));
        }

        $raffle->update(['live_draw_status' => 'completed']);
        LiveDrawStateChanged::dispatch($raffle->id, 'completed');
    }
}
