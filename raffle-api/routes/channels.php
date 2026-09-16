<?php

use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Item 27 (Live Draw). The reveal steps, comments, and reactions
 * themselves broadcast on a PUBLIC channel (`live-draw.{raffleId}`,
 * declared in LiveDrawBroadcastEvent — public channels need no entry
 * here) on purpose: Hall of Fame and the live-draw reveal are public
 * pages with no login wall in the legacy site (winners.php has none),
 * and the whole point of a "live" event is that anyone who opens the
 * page sees it, not just people who are already logged in. Posting a
 * comment or reaction still requires a real login — that's enforced by
 * `auth:wordpress` on the HTTP endpoints in LiveDrawController, not by
 * the channel.
 *
 * This PRESENCE channel is the one thing that does need real
 * authorization: it powers an honest "N people watching" viewer count
 * for the live-draw page — replacing the kind of fabricated "1,200+
 * people are playing" / "3 other people are viewing this" counters
 * Phase 0 item 7 already tore out elsewhere in this app, with a real
 * one instead of just not building the feature. Only a user resolved by
 * the same `wordpress` guard every other authenticated part of this app
 * uses can join it (see bootstrap/app.php's `withBroadcasting()` call,
 * which points Echo's `/broadcasting/auth` check at that guard instead
 * of Laravel's default `web` session guard). A guest can still watch
 * the reveal/comments/reactions live over the public channel above —
 * they just aren't counted in the presence roster, which is a
 * reasonable, disclosed limitation rather than a fake number.
 */
Broadcast::channel('live-draw-presence.{raffleId}', function (WpUser $user, int $raffleId) {
    if (! Raffle::query()->whereKey($raffleId)->exists()) {
        return false;
    }

    return ['id' => $user->getKey(), 'name' => $user->display_name ?: $user->user_login];
});

/**
 * Item 30's honest "N viewing" count for a raffle's own detail/checkout
 * pages — same reasoning and same limitation as live-draw-presence
 * above (only a logged-in viewer is counted; a guest still sees the
 * live ticket-count updates over the public `raffle.{id}` channel
 * either way). {raffleId} here is the legacy wp_posts id
 * RaffleReadService/RaffleController already key raffles by, not the
 * native App\Models\Raffle id live-draw-presence uses above.
 */
Broadcast::channel('raffle-presence.{raffleId}', function (WpUser $user, int $raffleId) {
    if (! WpPost::query()->raffles()->whereKey($raffleId)->exists()) {
        return false;
    }

    return ['id' => $user->getKey(), 'name' => $user->display_name ?: $user->user_login];
});
