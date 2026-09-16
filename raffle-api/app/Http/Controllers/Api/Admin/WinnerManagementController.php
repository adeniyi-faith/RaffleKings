<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Services\WinnerManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class WinnerManagementController extends Controller
{
    public function __construct(private readonly WinnerManagementService $winners) {}

    public function credit(Request $request, RaffleWinner $winner): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        try {
            $winner = $this->winners->credit($admin, $winner);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($winner);
    }

    public function setVisibility(Request $request, RaffleWinner $winner): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        $winner = $this->winners->setVisibility($admin, $winner, $request->boolean('is_visible'));

        return response()->json($winner);
    }
}
