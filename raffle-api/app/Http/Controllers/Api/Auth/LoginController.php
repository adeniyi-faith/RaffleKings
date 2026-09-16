<?php

namespace App\Http\Controllers\Api\Auth;

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

        return response()->json([
            'user' => [
                'id' => $result['user']->ID,
                'user_login' => $result['user']->user_login,
                'user_email' => $result['user']->user_email,
                'display_name' => $result['user']->display_name,
            ],
        ])->withCookie($cookie);
    }

    public function destroy(Request $request): JsonResponse
    {
        $cookieName = app('wordpress.auth_cookie_name');
        $rawCookie = $request->cookie($cookieName);
        $user = Auth::guard('wordpress')->user();

        if ($user && $rawCookie) {
            $token = explode('|', $rawCookie)[2] ?? null;

            if ($token) {
                $this->login->logout($user, $token);
            }
        }

        return response()->json(['message' => 'Logged out.'])->withCookie($this->cookies->forget($cookieName));
    }
}
