<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\RegistrationService;
use App\Services\Auth\TurnstileVerifier;
use App\Services\Auth\WordPressCookieFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly TurnstileVerifier $turnstile,
        private readonly WordPressCookieFactory $cookies,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
            'referral_code' => ['nullable', 'string'],
            'turnstile_token' => [$this->turnstile->enabled() ? 'required' : 'nullable', 'string'],
        ], [
            'password.regex' => 'Password must contain at least one letter and one number.',
        ]);

        if (! $this->turnstile->verify($data['turnstile_token'] ?? null, $request->ip())) {
            throw ValidationException::withMessages(['turnstile_token' => 'Please complete the security check and try again.']);
        }

        // Falls back to the first-party rk_ref_code cookie (set by the
        // /register page itself, or by the legacy site's referral-tracking
        // module — same cookie name, same domain) when no code was
        // submitted, so a referral still counts even if the browser tab
        // that has the referral code isn't the one the form was submitted
        // from.
        if (empty($data['referral_code'])) {
            $data['referral_code'] = $request->cookie('rk_ref_code');
        }

        $result = $this->registration->register($data, $request->ip(), $request->userAgent());

        $cookieName = app('wordpress.auth_cookie_name');
        $cookie = $this->cookies->make($cookieName, $result['cookie']['value'], $result['cookie']['expiration']);

        // Phase 3 item 34 — same dual issuance as LoginController.
        $token = $result['user']->createToken('login', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $result['user']->ID,
                'user_login' => $result['user']->user_login,
                'user_email' => $result['user']->user_email,
                'display_name' => $result['user']->display_name,
            ],
            'token' => $token,
        ], 201)->withCookie($cookie);
    }
}
