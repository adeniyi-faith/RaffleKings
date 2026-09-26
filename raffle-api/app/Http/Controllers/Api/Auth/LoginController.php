<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\WordPressSessionGuard;
use App\Http\Controllers\Controller;
use App\Services\Auth\LoginService;
use App\Services\Auth\WordPressCookieFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly WordPressCookieFactory $cookies,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->login->login($data['username'], $data['password'], $request->ip(), $request->userAgent());

        $cookieName = app('wordpress.auth_cookie_name');
        $cookie = $this->cookies->make($cookieName, $result['cookie']['value'], $result['cookie']['expiration']);

        // Phase 3 item 34: issue a real, usable Sanctum token alongside
        // the existing WordPress cookie — not a replacement for it yet
        // (see WordPressOrSanctumGuard's docblock for why), but a fully
        // working second way to authenticate starting now.
        $token = $result['user']->createToken('login', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $result['user']->ID,
                'user_login' => $result['user']->user_login,
                'user_email' => $result['user']->user_email,
                'display_name' => $result['user']->display_name,
            ],
            'token' => $token,
        ])->withCookie($cookie)->withCookies($this->cookies->forgetLeftovers($cookieName, $request->getHost()));
    }

    public function destroy(Request $request): JsonResponse
    {
        $cookieName = app('wordpress.auth_cookie_name');
        $user = Auth::guard('wordpress')->user();

        // Every copy of the cookie the browser sent, not just the first —
        // see WordPressSessionGuard::cookieValues() for why there can be
        // more than one. Only this user's own sessions are ever revoked.
        if ($user) {
            foreach (WordPressSessionGuard::cookieValues($request, $cookieName) as $rawCookie) {
                $parts = explode('|', $rawCookie);

                if (($parts[0] ?? null) === $user->user_login && ! empty($parts[2])) {
                    $this->login->logout($user, $parts[2]);
                }
            }
        }

        // Revoke only the Sanctum token this specific request actually
        // presented (if any) — never every token the user has ever
        // issued, so logging out on one device doesn't sign them out
        // everywhere else that's using a different token.
        $user?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.'])
            ->withCookie($this->cookies->forget($cookieName))
            ->withCookies($this->cookies->forgetLeftovers($cookieName, $request->getHost()));
    }
}
