<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Services\AccountReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the account section rebuilt in item 26 ("My Tickets" and
 * "Transactions") — see AccountReadService's docblock for exactly how
 * each list is derived and what, if anything, was fixed over the legacy
 * `user_tickets`/`transactions` ajax-router actions.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AccountReadService $account) {}

    public function tickets(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json(['data' => $this->account->tickets($user)]);
    }

    public function transactions(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json(['data' => $this->account->transactions($user)]);
    }
}
