<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustUserBalanceRequest;
use App\Http\Requests\UpdateUserRestrictionsRequest;
use App\Models\Legacy\WpUser;
use App\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the new admin console's own
 * version of legacy's User Manager page. See UserManagementService's
 * docblock, especially the note that ban_withdraw/ban_transfer aren't
 * actually enforced anywhere yet (a pre-existing legacy gap).
 */
class UserManagementController extends Controller
{
    public function __construct(private readonly UserManagementService $users) {}

    public function adjustBalance(AdjustUserBalanceRequest $request, WpUser $user): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        try {
            $this->users->adjustBalance($admin, $user, $request->string('type')->toString(), (float) $request->float('amount'), $request->string('direction')->toString());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Balance updated.']);
    }

    public function updateRestrictions(UpdateUserRestrictionsRequest $request, WpUser $user): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        $this->users->updateRestrictions(
            $admin,
            $user,
            $request->boolean('is_banned'),
            $request->boolean('ban_withdraw'),
            $request->boolean('ban_transfer'),
            $request->string('ban_expiry')->toString() ?: null,
        );

        return response()->json(['message' => 'Restrictions updated.']);
    }
}
