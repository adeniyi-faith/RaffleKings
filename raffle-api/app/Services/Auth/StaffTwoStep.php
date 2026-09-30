<?php

namespace App\Services\Auth;

use App\Models\Legacy\WpUser;
use App\Notifications\StaffSignInCode;
use App\Services\AdminAuditLogService;
use App\Settings\ConnectionTester;
use App\Settings\SettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Two-step sign-in for staff (Settings → Security → Staff sign-in).
 *
 * When it is on, the right password only starts a sign-in: no login
 * cookie is given out until the 6-digit code emailed to the staff member
 * is typed in. Passing the code also gives the browser a second cookie
 * (COOKIE), and a matching row on the server (staff_verified_sessions,
 * stored hashed). The admin screens and the admin API both insist on that
 * pair, so a password alone, even one used on the customer site, can't
 * reach anything staff-only.
 *
 * The pending code lives in the server-side session, hashed, and is good
 * for CODE_MINUTES, MAX_TRIES wrong guesses, and MAX_SENDS emails.
 */
class StaffTwoStep
{
    public const COOKIE = 'rk_staff_2fa';

    public const CODE_MINUTES = 10;

    public const MAX_TRIES = 5;

    public const MAX_SENDS = 3;

    /** A verified sign-in lasts as long as the login cookie does (see LoginService). */
    public const VERIFIED_DAYS = 14;

    private const SESSION_KEY = 'staff_two_step';

    public static function enabled(): bool
    {
        return (bool) config('security.staff_two_step');
    }

    public function __construct(private readonly WordPressCookieFactory $cookies) {}

    /** Email a fresh code and remember it (hashed) for this browser's sign-in. */
    public function start(WpUser $user, ?string $ip, ?string $device): void
    {
        $this->send($user, $ip, $device, sends: 0);
    }

    /** Email a new code for the sign-in in progress. @return bool false if there's nothing to resend or the limit is used up */
    public function resend(?string $ip, ?string $device): bool
    {
        $pending = $this->pending();
        $user = $this->pendingUser();

        if (! $pending || ! $user || $pending['sends'] >= self::MAX_SENDS || now()->timestamp - $pending['sent_at'] < 30) {
            return false;
        }

        $this->send($user, $ip, $device, sends: $pending['sends']);

        return true;
    }

    private function send(WpUser $user, ?string $ip, ?string $device, int $sends): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Emailed straight away (not queued): the person is waiting on it.
        $user->notifyNow(new StaffSignInCode($code, self::CODE_MINUTES, $ip, $device));

        // Only remembered once the email really went, so a mail failure never leaves a pending sign-in behind.
        session()->put(self::SESSION_KEY, [
            'user_id' => $user->ID,
            'hash' => $this->hash($code),
            'expires' => now()->addMinutes(self::CODE_MINUTES)->timestamp,
            'tries' => 0,
            'sends' => $sends + 1,
            'sent_at' => now()->timestamp,
        ]);
    }

    /** @return array{user_id: int, hash: string, expires: int, tries: int, sends: int, sent_at: int}|null */
    public function pending(): ?array
    {
        $pending = session()->get(self::SESSION_KEY);

        return is_array($pending) && isset($pending['user_id'], $pending['hash']) ? $pending : null;
    }

    public function pendingUser(): ?WpUser
    {
        $pending = $this->pending();

        return $pending ? WpUser::query()->find($pending['user_id']) : null;
    }

    /** "a***@gmail.com" — enough to recognise the inbox without showing the whole address. */
    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    /** @return 'ok'|'wrong'|'expired'|'locked'|'none' */
    public function check(string $code): string
    {
        $pending = $this->pending();

        if (! $pending) {
            return 'none';
        }

        if (now()->timestamp > $pending['expires']) {
            $this->cancel();

            return 'expired';
        }

        if (hash_equals($pending['hash'], $this->hash($code))) {
            return 'ok';
        }

        $pending['tries']++;

        if ($pending['tries'] >= self::MAX_TRIES) {
            $this->cancel();

            return 'locked';
        }

        session()->put(self::SESSION_KEY, $pending);

        return 'wrong';
    }

    public function triesLeft(): int
    {
        return max(0, self::MAX_TRIES - (int) ($this->pending()['tries'] ?? 0));
    }

    /** Forget the sign-in in progress (a right code, too many wrong ones, or "start again"). */
    public function cancel(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * The code was right: remember this browser as verified.
     *
     * @return Cookie the cookie to send (queue it)
     */
    public function markVerified(WpUser $user): Cookie
    {
        $token = bin2hex(random_bytes(20));
        $expires = now()->addDays(self::VERIFIED_DAYS);

        DB::table('staff_verified_sessions')->insert([
            'user_id' => $user->ID,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expires,
            'created_at' => now(),
        ]);

        $this->cancel();

        return $this->cookies->make(self::COOKIE, $token, $expires->timestamp);
    }

    /** Did this browser pass the code for this staff member? */
    public static function isVerified(Request $request, WpUser $user): bool
    {
        $token = $request->cookies->get(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return false;
        }

        return DB::table('staff_verified_sessions')
            ->where('user_id', $user->ID)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->exists();
    }

    /** Whether this person must still pass the code to use the admin. */
    public static function required(Request $request, WpUser $user): bool
    {
        return self::enabled() && $user->staffRole() !== null && ! self::isVerified($request, $user);
    }

    /** Sign-out: this browser's verified mark goes too. */
    public function forgetVerified(Request $request, WpUser $user): ?Cookie
    {
        $token = $request->cookies->get(self::COOKIE);

        if (is_string($token) && $token !== '') {
            DB::table('staff_verified_sessions')->where('user_id', $user->ID)->where('token_hash', hash('sha256', $token))->delete();
        }

        return $this->cookies->forget(self::COOKIE);
    }

    public const SETTING = 'security.staff_two_step';

    /**
     * Switch it on, but only once email is proven to work (a test email goes to the owner
     * turning it on), so nobody can lock the whole team out.
     *
     * @return string|null why it wasn't switched on, or null when it is on
     */
    public function turnOn(WpUser $admin): ?string
    {
        if (self::enabled()) {
            return null;
        }

        [$works, $message] = app(ConnectionTester::class)->email($admin->user_email);

        if (! $works) {
            return "Not switched on, because email isn't working yet, so no one could receive a code. {$message}";
        }

        SettingsStore::save([self::SETTING => true], $admin);
        app(AdminAuditLogService::class)->record($admin, 'staff.two_step_on', WpUser::class, $admin->ID);

        return null;
    }

    public function turnOff(WpUser $admin): void
    {
        if (! self::enabled()) {
            return;
        }

        SettingsStore::save([self::SETTING => false], $admin);
        app(AdminAuditLogService::class)->record($admin, 'staff.two_step_off', WpUser::class, $admin->ID);
    }

    /** Housekeeping: verified marks that have run out. */
    public function prune(): int
    {
        return DB::table('staff_verified_sessions')->where('expires_at', '<', now())->delete();
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
