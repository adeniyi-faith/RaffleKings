<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\RegistrationService;
use App\Services\Auth\TurnstileVerifier;
use App\Services\Auth\WordPressCookieFactory;
use App\Services\Growth\AffiliateService;
use App\Services\Growth\PromoCodeService;
use App\Services\Risk\AbuseDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
            // Max lengths match the wp_users columns (email 100), so a too-long
            // value is a clear message instead of a database error.
            'email' => ['required', 'email', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
            'referral_code' => ['nullable', 'string', 'max:60'],
            // Promo codes (Settings → On / off → New features).
            'promo_code' => ['nullable', 'string', 'max:40'],
            // Item 48: customers must be 18+ and accept the Terms of Service.
            'accept_terms' => ['accepted'],
            'turnstile_token' => [$this->turnstile->enabled() ? 'required' : 'nullable', 'string'],
        ], [
            'password.regex' => 'Password must contain at least one letter and one number.',
            'accept_terms.accepted' => 'Please confirm you are 18 or older and accept the Terms of Service.',
        ]);

        if (! $this->turnstile->verify($data['turnstile_token'] ?? null, $request->ip())) {
            throw ValidationException::withMessages(['turnstile_token' => 'Please complete the security check and try again.']);
        }

        // A wrong promo code is said before the account is made, so the
        // customer can fix it instead of losing the bonus.
        try {
            $promo = app(PromoCodeService::class)->assertUsableAtSignup($data['promo_code'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['promo_code' => $e->getMessage()]);
        }

        $result = $this->registration->register($data, $request->ip(), $request->userAgent());

        // Where this customer came from: a promo code first, else an
        // affiliate link they clicked in the last 30 days.
        if ($promo) {
            app(PromoCodeService::class)->applyAtSignup($result['user'], $promo);
        }
        app(AffiliateService::class)->attributeSignup($result['user'], $request->cookie(AffiliateService::COOKIE));

        // Multi-account protection: which browser this account was made on.
        app(AbuseDetector::class)->recordDevice($result['user']->ID, $request);

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
        ], 201)->withCookie($cookie)->withCookies($this->cookies->forgetLeftovers($cookieName, $request->getHost()));
    }
}
