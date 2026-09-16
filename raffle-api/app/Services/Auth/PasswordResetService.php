<?php

namespace App\Services\Auth;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Notifications\PasswordResetOtp;
use Illuminate\Validation\ValidationException;

/**
 * The forgot-password / OTP flow, rebuilt on Laravel (item 23) against
 * the same wp_users row (see RegistrationService's docblock for why).
 * Reuses the legacy site's own usermeta keys (rk_reset_otp/rk_reset_expiry)
 * for continuity, but with two deliberate security fixes over
 * rk_handle_forgot_password()/rk_handle_verify_reset_code() (api-auth.php):
 *
 *   - The OTP is stored as a SHA-256 hash, not plaintext, and compared
 *     with hash_equals(), not `!=`.
 *   - requestCode() never reveals whether an email has an account (the
 *     legacy version returns a 404 "not_found" for an unknown email — a
 *     user-enumeration leak). It always returns success; only an email
 *     that actually matches an account gets a code sent.
 *
 * Rate limiting (request + separate OTP-guess limits) is applied at the
 * controller/route layer via named rate limiters, not in here.
 */
class PasswordResetService
{
    private const OTP_TTL_SECONDS = 15 * 60;

    public function __construct(private readonly WordPressPasswordHasher $hasher) {}

    public function requestCode(string $email): void
    {
        $user = WpUser::where('user_email', $email)->first();

        if (! $user) {
            return;
        }

        $code = (string) random_int(100000, 999999);

        $this->setMeta($user, 'rk_reset_otp', hash('sha256', $code));
        $this->setMeta($user, 'rk_reset_expiry', (string) (time() + self::OTP_TTL_SECONDS));

        $user->notify(new PasswordResetOtp($code));
    }

    public function verifyCode(string $email, string $code): WpUser
    {
        $user = WpUser::where('user_email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages(['otp' => 'That code is invalid or has expired.']);
        }

        $storedHash = $user->metaValue('rk_reset_otp');
        $expiry = (int) $user->metaValue('rk_reset_expiry');

        if (! $storedHash || ! hash_equals($storedHash, hash('sha256', $code))) {
            throw ValidationException::withMessages(['otp' => 'That code is invalid.']);
        }

        if (time() > $expiry) {
            throw ValidationException::withMessages(['otp' => 'That code has expired. Request a new one.']);
        }

        return $user;
    }

    public function resetPassword(string $email, string $code, string $newPassword): void
    {
        $user = $this->verifyCode($email, $code);

        $user->forceFill(['user_pass' => $this->hasher->make($newPassword)])->save();

        WpUserMeta::where('user_id', $user->getKey())->whereIn('meta_key', ['rk_reset_otp', 'rk_reset_expiry'])->delete();

        // A real security improvement over the legacy flow, which leaves
        // every existing session alive after a password reset: force
        // every device to log in again with the new password.
        WpUserMeta::where('user_id', $user->getKey())->where('meta_key', 'session_tokens')->delete();
    }

    private function setMeta(WpUser $user, string $key, string $value): void
    {
        WpUserMeta::updateOrCreate(
            ['user_id' => $user->getKey(), 'meta_key' => $key],
            ['meta_value' => $value],
        );
    }
}
