<?php

namespace App\Services\Auth;

use App\Auth\WordPressAuthCookieIssuer;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registration, rebuilt on Laravel (item 23) but still creating a real
 * wp_users row — WordPress remains the source of truth for accounts during
 * the migration (see LEGACY_MIGRATION.md), so a user registered here must
 * end up in a state consistent with what the legacy
 * rk_handle_new_registration() would have left them in: same referral
 * bookkeeping, same auto-login — just reached through the new API instead
 * of ajax-router.php?action=register.
 *
 * Three deliberate differences from the legacy handler:
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
 *   - The ₦300 welcome bonus is credited to the NEW `wallets` table (via
 *     WalletLedgerService), not the legacy `wallet_balance` usermeta
 *     rk_ensure_welcome_bonus() writes. This was the wrong call in an
 *     earlier pass — usermeta looked like the safe, compatible choice,
 *     but item 13's deposit gateway and item 25's checkout/settlement
 *     path both already read and write the NEW wallets table exclusively
 *     (see DepositService::confirm(), TicketPurchaseService). Crediting
 *     the bonus to usermeta instead would have made it invisible to both
 *     — a user could register, see "₦300" nowhere a real balance check
 *     looks, and have deposits and purchases silently operate on a
 *     different, disconnected number. A user created through this new
 *     flow now has ONE consistent balance across signup, deposits, and
 *     purchases; the tradeoff (accepted deliberately, same one
 *     DepositService already made) is that this balance isn't visible to
 *     the still-live legacy PHP pages, which only read usermeta. That's
 *     exactly what Phase 3's planned cutover (items 32-33) resolves for
 *     good — not a gap this item can close on its own.
 */
class RegistrationService
{
    public function __construct(
        private readonly WordPressPasswordHasher $hasher,
        private readonly WordPressAuthCookieIssuer $cookieIssuer,
        private readonly WalletLedgerService $ledger,
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
     * Same ₦300 amount as the legacy rk_ensure_welcome_bonus(), same
     * idempotency guard (a second call for the same user is a no-op) —
     * but settled against the new wallets/wallet_ledger_entries tables
     * via the same lock-then-credit shape DepositService::confirm() uses,
     * not legacy usermeta. See this class's docblock for why.
     */
    private function grantWelcomeBonus(WpUser $user): void
    {
        DB::transaction(function () use ($user) {
            $alreadyGranted = WalletLedgerEntry::query()
                ->where('user_id', $user->getKey())
                ->where('reason', 'signup_bonus')
                ->exists();

            if ($alreadyGranted) {
                return;
            }

            $wallet = Wallet::query()->where('user_id', $user->getKey())->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $user->getKey(), 'wallet_balance' => 0, 'earnings_balance' => 0]);

            $wallet->wallet_balance = (float) $wallet->wallet_balance + 300;
            $wallet->save();

            $this->ledger->recordCredit($user->getKey(), 'wallet', 300, 'signup_bonus');
        });
    }

    private function setMeta(WpUser $user, string $key, string $value): void
    {
        WpUserMeta::updateOrCreate(
            ['user_id' => $user->getKey(), 'meta_key' => $key],
            ['meta_value' => $value],
        );
    }
}
