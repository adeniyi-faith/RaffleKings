<?php

namespace App\Http\Middleware;

use App\Services\Analytics\Analytics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a named event whenever a signed-in customer successfully does one
 * of the things listed below (spin the wheel, claim a reward, answer a
 * prediction…). One list, one place: to track another action, add a line.
 *
 * Only successful requests count. Money moving (purchases, top-ups paid,
 * withdrawals, wins, referral commissions) is recorded closer to the money,
 * in the services themselves — see the App\Services classes that call
 * Analytics::capture().
 */
class TrackApiActions
{
    /**
     * Route pattern → event name.
     *
     * @var array<string, string>
     */
    public const EVENTS = [
        'api/deposits' => 'topup_started',
        'api/wallet/transfer' => 'winnings_moved_to_wallet',
        'api/golden-box/{offer}/claim' => 'golden_box_claimed',
        'api/rewards/daily-claim' => 'daily_reward_claimed',
        'api/rewards/tasks/{task}/start' => 'task_started',
        'api/rewards/tasks/{task}/claim' => 'task_claimed',
        'api/rewards/spin' => 'spin_played',
        'api/rewards/redeem' => 'points_cashed_in',
        'api/season/claim' => 'season_reward_claimed',
        'api/predictions/{prediction}/answer' => 'prediction_answered',
        'api/raffles/{raffle}/unlock' => 'unlock_link_created',
        'api/unlock/{code}/tap' => 'unlock_link_tapped',
        'api/raffles/{raffle}/team' => 'team_created',
        'api/teams/{code}/join' => 'team_joined',
        'api/raffles/{raffle}/live-draw/envelopes' => 'red_envelope_sent',
        'api/envelopes/{envelope}/claim' => 'red_envelope_claimed',
        'api/raffles/{raffle}/live-draw/comments' => 'live_draw_comment_posted',
        'api/stories' => 'winner_story_posted',
        'api/bank-accounts' => 'bank_account_added',
        'api/support/tickets' => 'support_ticket_opened',
        'api/play-limits' => 'play_limit_changed',
        'api/play-limits/break' => 'break_started',
    ];

    /** Route parameters that are safe to send along (never codes or tokens). */
    private const SAFE_PARAMETERS = ['raffle' => 'raffle_id', 'task' => 'task', 'prediction' => 'prediction_id'];

    public function __construct(private readonly Analytics $analytics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->track($request, $response);
        } catch (\Throwable) {
            // Tracking must never break the request.
        }

        return $response;
    }

    private function track(Request $request, Response $response): void
    {
        if (! $request->isMethod('POST') || ! $response->isSuccessful() || ! $this->analytics->enabled()) {
            return;
        }

        $route = $request->route();
        $event = $route ? (self::EVENTS[$route->uri()] ?? null) : null;

        if ($event === null) {
            return;
        }

        $user = Auth::guard('wordpress')->user();

        if (! $user) {
            return;
        }

        $properties = [];

        foreach (self::SAFE_PARAMETERS as $parameter => $name) {
            $value = $route->originalParameter($parameter);

            if (is_scalar($value)) {
                $properties[$name] = $value;
            }
        }

        if ($event === 'topup_started' && is_numeric($request->input('amount'))) {
            $properties['amount'] = (float) $request->input('amount');
        }

        $this->analytics->capture($user->ID, $event, $properties);
    }
}
