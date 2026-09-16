<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Proves the WordPress session bridge (App\Auth\WordPressSessionGuard)
 * actually works end to end. Every future authenticated Laravel route
 * should follow this same shape: `Route::middleware('auth:wordpress')`,
 * then `$request->user()` (or Auth::user()) resolves to the real
 * App\Models\Legacy\WpUser the legacy cookie belongs to.
 */
class AuthBridgeController extends Controller
{
    public function me(): JsonResponse
    {
        /** @var WpUser $user */
        $user = Auth::guard('wordpress')->user();

        return response()->json([
            'id' => $user->ID,
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
            'display_name' => $user->display_name,
        ]);
    }
}
