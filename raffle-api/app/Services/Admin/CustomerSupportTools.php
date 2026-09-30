<?php

namespace App\Services\Admin;

use App\Models\CustomerMessage;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserBadge;
use App\Models\UserEngagement;
use App\Notifications\EmailChangedBySupport;
use App\Notifications\PasswordChangedBySupport;
use App\Services\AdminAuditLogService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\WordPressPasswordHasher;
use App\Services\Engagement\BadgeService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * What staff do for a customer from their profile: reset their password,
 * sign them out, fix their contact details, message them, and give or take
 * back a badge. Every action is written to the audit log, and none of them
 * ever puts a password in it.
 *
 * Staff accounts are off limits here: if support could reset an admin's
 * password, anyone with a support login could take over the whole admin.
 */
class CustomerSupportTools
{
    /** Password resets one customer can have from staff in a day. */
    private const RESETS_PER_DAY = 5;

    public function __construct(
        private readonly AdminAuditLogService $audit,
        private readonly PasswordResetService $passwordReset,
        private readonly WordPressPasswordHasher $hasher,
        private readonly BadgeService $badges,
    ) {}

    /** True for an admin or staff account (these can't be changed from a customer's profile). */
    public function isStaffAccount(WpUser $target): bool
    {
        return $target->staffRole() !== null || $target->isAdministrator();
    }

    // ---- Password ---------------------------------------------------------

    /** Emails the customer a reset code; they pick their own new password. */
    public function sendResetCode(WpUser $admin, WpUser $target, string $reason): void
    {
        $this->assertCustomer($target);
        $this->assertNotOverused($target);

        $this->passwordReset->requestCode($target->user_email);
        $this->audit->record($admin, 'customer.password_reset_code_sent', WpUser::class, $target->ID, ['reason' => $reason]);
    }

    /**
     * Sets a temporary password and signs the customer out everywhere.
     * Returns the password so staff can pass it on; it is not stored or logged.
     */
    public function setTemporaryPassword(WpUser $admin, WpUser $target, string $reason): string
    {
        $this->assertCustomer($target);
        $this->assertNotOverused($target);

        $password = $this->makeTemporaryPassword();

        $target->forceFill(['user_pass' => $this->hasher->make($password)])->save();
        $this->clearResetCodes($target);
        $this->signOutEverywhereQuietly($target);

        $this->audit->record($admin, 'customer.password_set_temporary', WpUser::class, $target->ID, ['reason' => $reason]);
        $target->notify(new PasswordChangedBySupport('Our support team set a temporary password on your account at your request.'));

        return $password;
    }

    /** A password staff can read out or type: no look-alike characters, always mixed case and a digit. */
    public function makeTemporaryPassword(): string
    {
        $pick = fn (string $chars, int $n) => implode('', array_map(fn () => $chars[random_int(0, strlen($chars) - 1)], range(1, $n)));
        $chars = $pick('abcdefghjkmnpqrstuvwxyz', 5).$pick('ABCDEFGHJKMNPQRSTUVWXYZ', 3).$pick('23456789', 3);

        return str_shuffle($chars.$pick('abcdefghjkmnpqrstuvwxyz23456789', 1));
    }

    public function signOutEverywhere(WpUser $admin, WpUser $target): void
    {
        $this->assertCustomer($target);

        $this->signOutEverywhereQuietly($target);
        $this->audit->record($admin, 'customer.signed_out_everywhere', WpUser::class, $target->ID);
    }

    // ---- Contact details ---------------------------------------------------

    /**
     * @param  array{name?: ?string, email?: ?string, phone?: ?string}  $data
     * @return array<string, array{from: ?string, to: ?string}> what changed
     */
    public function updateDetails(WpUser $admin, WpUser $target, array $data): array
    {
        $this->assertCustomer($target);

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Enter a name.');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('That email address does not look right.');
        }

        if (WpUser::query()->where('user_email', $email)->where('ID', '!=', $target->ID)->exists()) {
            throw new RuntimeException('Another account already uses that email address.');
        }

        $before = ['name' => $target->display_name, 'email' => $target->user_email, 'phone' => $target->metaValue('phone')];
        $changes = [];

        if ($name !== (string) $before['name']) {
            $changes['name'] = ['from' => $before['name'], 'to' => $name];
        }

        if (strcasecmp($email, (string) $before['email']) !== 0) {
            $changes['email'] = ['from' => $before['email'], 'to' => $email];
        }

        if ($phone !== (string) $before['phone']) {
            $changes['phone'] = ['from' => $before['phone'], 'to' => $phone !== '' ? $phone : null];
        }

        if ($changes === []) {
            return [];
        }

        if (isset($changes['name'])) {
            $target->forceFill(['display_name' => $name])->save();
        }

        if (isset($changes['email'])) {
            $target->forceFill(['user_email' => $email])->save();
            // The old address hears about it too, in case the change is not wanted.
            if (filter_var($before['email'], FILTER_VALIDATE_EMAIL)) {
                Notification::route('mail', $before['email'])->notify(new EmailChangedBySupport($email));
            }
        }

        if (isset($changes['phone'])) {
            WpUserMeta::updateOrCreate(['user_id' => $target->ID, 'meta_key' => 'phone'], ['meta_value' => $phone]);
        }

        $this->audit->record($admin, 'customer.details_changed', WpUser::class, $target->ID, ['changes' => $changes]);

        return $changes;
    }

    // ---- Messages ---------------------------------------------------------

    /** A message into the customer's inbox (the bell), from support. */
    public function sendMessage(WpUser $admin, WpUser $target, string $title, string $body): void
    {
        $title = trim($title);
        $body = trim($body);

        if ($title === '' || $body === '') {
            throw new RuntimeException('Write a title and a message.');
        }

        CustomerMessage::create([
            'user_id' => $target->ID,
            'kind' => 'support',
            'title' => mb_strimwidth($title, 0, 150, ''),
            'body' => $body,
            'created_at' => now(),
        ]);

        $this->audit->record($admin, 'customer.message_sent', WpUser::class, $target->ID, ['title' => mb_strimwidth($title, 0, 150, '…')]);
    }

    // ---- Badges -----------------------------------------------------------

    /** @throws RuntimeException if it is not a real badge or they already have it */
    public function awardBadge(WpUser $admin, WpUser $target, string $badge): void
    {
        if (! isset($this->badges->catalog()[$badge])) {
            throw new RuntimeException('That badge does not exist.');
        }

        if (! $this->badges->award($target->ID, $badge)) {
            throw new RuntimeException('They already have that badge.');
        }

        $this->audit->record($admin, 'customer.badge_awarded', WpUser::class, $target->ID, ['badge' => $badge]);
    }

    /** @throws RuntimeException if they do not have it */
    public function removeBadge(WpUser $admin, WpUser $target, string $badge): void
    {
        $removed = UserBadge::query()->where('user_id', $target->ID)->where('badge', $badge)->delete();

        if (! $removed) {
            throw new RuntimeException('They do not have that badge.');
        }

        // No longer pinned to their profile either.
        $engagement = UserEngagement::query()->where('user_id', $target->ID)->first();

        if ($engagement && in_array($badge, (array) $engagement->showcase, true)) {
            $engagement->update(['showcase' => array_values(array_diff((array) $engagement->showcase, [$badge]))]);
        }

        $this->audit->record($admin, 'customer.badge_removed', WpUser::class, $target->ID, ['badge' => $badge]);
    }

    // ---- Internals --------------------------------------------------------

    private function assertCustomer(WpUser $target): void
    {
        if ($this->isStaffAccount($target)) {
            throw new RuntimeException('This is a staff account, so it can\'t be changed from a customer\'s profile.');
        }
    }

    private function assertNotOverused(WpUser $target): void
    {
        $key = 'admin-password-reset:'.$target->ID;

        if (RateLimiter::tooManyAttempts($key, self::RESETS_PER_DAY)) {
            throw new RuntimeException('This customer has already had '.self::RESETS_PER_DAY.' password resets from staff today. Ask an owner to look into it.');
        }

        RateLimiter::hit($key, 86400);
    }

    private function signOutEverywhereQuietly(WpUser $target): void
    {
        WpUserMeta::query()->where('user_id', $target->ID)->where('meta_key', 'session_tokens')->delete();
    }

    private function clearResetCodes(WpUser $target): void
    {
        WpUserMeta::query()->where('user_id', $target->ID)->whereIn('meta_key', ['rk_reset_otp', 'rk_reset_expiry'])->delete();
    }
}
