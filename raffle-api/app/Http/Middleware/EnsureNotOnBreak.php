<?php

namespace App\Http\Middleware;

use App\Services\ResponsiblePlayService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Responsible play (Phase 10, item 38): refuses money and game actions
 * (buying, topping up, spinning, claiming offers, sending red envelopes)
 * while the signed-in customer is on a break they chose. Withdrawals,
 * support and everything else stay open.
 */
class EnsureNotOnBreak
{
    public function __construct(private readonly ResponsiblePlayService $play) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        if ($userId && ($until = $this->play->excludedUntil((int) $userId))) {
            return response()->json(['message' => $this->play->breakMessage($until), 'play_limit' => true], 403);
        }

        return $next($request);
    }
}
