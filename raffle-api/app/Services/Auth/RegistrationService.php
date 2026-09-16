<?php

namespace App\Services\Auth;

use App\Auth\WordPressAuthCookieIssuer;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use Illuminate\Validation\ValidationException;

/**
 * Registration, rebuilt on Laravel (item 23) but still creating a real
 * wp_users row — WordPress remains the source of truth for accounts during
 * the migration (see LEGACY_MIGRATION.md), so a user registered here must
 * end up in exactly the state the legacy rk_handle_new_registration()
 * would have left them in: same welcome bonus, same referral bookkeeping,
 * same auto-login — just reached through the new API instead of
 * ajax-router.php?action=register.
 *
 * Two deliberate differences from the legacy handler:
 *   - Passwords are hashed with WordPressPasswordHasher (bcrypt), not
 *     wp_create_user()'s phpass — WordPress 6.8+ does the same by default.
 *   - The referrer's own instant "+50 points" bonus (a separate, never
 *     audited mechanic bolted onto registration) is intentionally NOT
 *     reproduced here, to avoid double-paying alongside the real,
 *     audited-and-fixed commission engine (item 15,
 *     ReferralCommissionService) that pays on the referee's first
 *     deposit. `referred_by` usermeta IS still set, since that engine
 *     reads it — the referral relationship is captured correctly, only
 *     the legacy points-at-signup side effect is dropped.
 */
class RegistrationService
{
    public function __construct(
        private readonly WordPressPasswordHasher $hasher,
        private readonly WordPressAuthCookieIssuer $cookieIssuer,
    ) {}

    /**
     * @param  array{username: string, email: string, password: string, referral_code: ?string}  $data
     * @return array{user: WpUser, cookie: array{value: string, expiration: int}}
     */
    public function register(array $data, ?string $ip, ?string $userAgent): array
    {
        $this->assertUsernameAndEmailAreFree($data['username'], $data['email']);

        $user = WpUser::create([
            'user_login' => $data['username'],
            'user_email' => $data['email'],
            'user_pass' => $this->hasher->make($data['password']),
            'display_name' => $data['username'],
        ]);

        $this->setMeta($user, 'rk_referral_code', $data['username']);

        if (filled($data['referral_code'] ?? null)) {
            $this->captureReferrer($user, $data['referral_code']);
        }

        $this->grantWelcomeBonus($user);

        $cookie = $this->cookieIssuer->issue($user, ttlSeconds: 14 * 24 * 60 * 60, ip: $ip, userAgent: $userAgent);

        return ['user' => $user, 'cookie' => $cookie];
    }

    private function assertUsernameAndEmailAreFree(string $username, string $email): void
    {
        if (WpUser::where('user_login', $username)->exists()) {
            throw ValidationException::withMessages(['username' => 'That username is already taken.']);
        }

        if (WpUser::where('user_email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with that email already exists.']);
        }
    }

    /**
     * Legacy's rk_find_referrer_by_code() also matches by email/user ID;
     * this covers the two forms an actual referral link ever contains —
     * another user's username, or their own rk_referral_code meta
     * (registration sets both to the same value, so in practice these
     * always agree, but a code could exist from before that changed).
     */
    private function captureReferrer(WpUser $user, string $code): void
    {
        $referrer = WpUser::where('user_login', $code)
            ->orWhereHas('meta', fn ($q) => $q->where('meta_key', 'rk_referral_code')->where('meta_value', $code))
            ->first();

        if ($referrer && $referrer->getKey() !== $user->getKey()) {
            $this->setMeta($user, 'referred_by', (string) $referrer->getKey());
        }
    }

    /**
     * Verbatim port of rk_ensure_welcome_bonus() (api-auth.php) — same
     * amounts, same idempotency guard, same transaction shape — since
     * this is reproducing an EXISTING legacy behaviour for a brand-new
     * user, not introducing a new write path against usermeta/
     * raffle_transactions the way a new feature would.
     */
    private function grantWelcomeBonus(WpUser $user): void
    {
        $this->setMeta($user, 'wallet_balance', '300');
        $this->setMeta($user, 'earnings_balance', '0');
        $this->setMeta($user, 'rk_welcome_bonus_given', '1');
        $this->setMeta($user, 'rk_has_seen_welcome', '0');

        $txnRef = 'WELCOME-'.$user->getKey();

        if (! RaffleTransaction::where('user_id', $user->getKey())->where('txn_ref', $txnRef)->exists()) {
            RaffleTransaction::create([
                'user_id' => $user->getKey(),
                'claimed_amount' => 300,
                'status' => 'verified_final',
                'type' => 'signup_bonus',
                'proof_url' => 'system_welcome',
                'txn_ref' => $txnRef,
            ]);
        }
    }

    private function setMeta(WpUser $user, string $key, string $value): void
    {
        WpUserMeta::updateOrCreate(
            ['user_id' => $user->getKey(), 'meta_key' => $key],
            ['meta_value' => $value],
        );
    }
}
