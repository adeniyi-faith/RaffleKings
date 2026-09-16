<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Item 31's push-permission flow: saves the OneSignal player id a user's
 * browser gets back after they GENUINELY grant notification permission,
 * into the SAME `rk_onesignal_id` usermeta key `WpUser::
 * routeNotificationForOneSignal()` already reads to actually deliver a
 * push (see OneSignalChannel) — that channel existed with nothing on the
 * new frontend ever calling this, so a user opting in through the new
 * Rewards page's "Enable Notifications" task had no way to end up
 * reachable. Unlike the legacy daily-claim flow, granting this is never
 * a precondition for anything else in this app — see
 * TaskClaimService::claim()'s docblock and Rewards/Index.jsx, where the
 * daily streak claim has no dependency on push permission at all.
 */
class PushDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        $request->validate(['player_id' => ['required', 'string', 'max:191']]);

        WpUserMeta::updateOrCreate(
            ['user_id' => $user->getKey(), 'meta_key' => 'rk_onesignal_id'],
            ['meta_value' => $request->string('player_id')->toString()],
        );

        return response()->json(['success' => true]);
    }
}
