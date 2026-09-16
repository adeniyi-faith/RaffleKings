<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DrawNotCommittedException;
use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Services\LiveDrawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Item 27 — Live Draw's HTTP surface. The actual real-time delivery
 * (draw steps, comments, reactions) happens over Reverb/Echo, broadcast
 * by LiveDrawService/RunLiveDrawRevealJob — everything here is either a
 * one-time page-load fetch (show — also the "reconnect and catch up"
 * path if a viewer's WebSocket drops) or a write that triggers one of
 * those broadcasts.
 *
 * Degrades sanely without a running Reverb server: every endpoint here
 * still works over plain HTTP either way (a comment/reaction still
 * saves; a draw still reveals server-side on schedule) — what's lost
 * without Reverb is only the OTHER browsers finding out immediately.
 * The frontend's Echo client (resources/js/lib/echo.js) is built to
 * fail silently (no thrown error, just no live updates) if it can't
 * reach the configured Reverb host, and the Live Draw page always
 * re-fetches this endpoint on mount so a page load is never blank.
 */
class LiveDrawController extends Controller
{
    public function __construct(private readonly LiveDrawService $liveDraw) {}

    public function show(Raffle $raffle): JsonResponse
    {
        return response()->json($this->liveDraw->pageState($raffle));
    }

    public function storeComment(Request $request, Raffle $raffle): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:280'],
        ]);

        /** @var WpUser $user */
        $user = $request->user();

        $comment = $this->liveDraw->postComment($user, $raffle, trim($data['body']));

        return response()->json($comment->toBroadcastArray(), 201);
    }

    public function storeReaction(Request $request, Raffle $raffle): JsonResponse
    {
        $data = $request->validate([
            'reaction_type' => ['required', 'string', Rule::in(LiveDrawService::REACTION_TYPES)],
        ]);

        /** @var WpUser $user */
        $user = $request->user();

        $counts = $this->liveDraw->postReaction($user, $raffle, $data['reaction_type']);

        return response()->json(['reaction_type' => $data['reaction_type'], 'counts' => $counts], 201);
    }

    /** Admin-only — starts the synchronized reveal for everyone currently watching. */
    public function startReveal(Raffle $raffle): JsonResponse
    {
        try {
            $this->liveDraw->startReveal($raffle);
        } catch (DrawNotCommittedException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Live reveal started.', 'live_draw_status' => 'revealing']);
    }
}
