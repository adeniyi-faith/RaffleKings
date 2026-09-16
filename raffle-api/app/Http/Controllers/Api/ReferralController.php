<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Services\ReferralCommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(private readonly ReferralCommissionService $referrals) {}

    public function stats(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json($this->referrals->stats($user));
    }
}
