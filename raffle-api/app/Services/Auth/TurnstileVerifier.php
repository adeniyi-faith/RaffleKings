<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile verification — the bot-protection widget the
 * legacy registration page has fully wired up (site key, callback JS,
 * and a working rk_verify_turnstile_token() in api-auth.php) but never
 * actually turned on: the widget markup, the client-side token check,
 * and this exact server-side verify call are all commented out there.
 * Item 23 is "actually wired on and working" — this is that call.
 *
 * Verification is a no-op (always passes) when no secret key is
 * configured, so local development and tests don't need a real
 * Cloudflare account. Production must set TURNSTILE_SECRET_KEY.
 */
class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return filled(config('services.turnstile.secret_key'));
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        $response = Http::asForm()->post(self::VERIFY_URL, array_filter([
            'secret' => config('services.turnstile.secret_key'),
            'response' => $token,
            'remoteip' => $ip,
        ]));

        return $response->successful() && ($response->json('success') === true);
    }
}
