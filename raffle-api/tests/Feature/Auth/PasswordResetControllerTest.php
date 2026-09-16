<?php

namespace Tests\Feature\Auth;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Notifications\PasswordResetOtp;
use App\Services\Auth\WordPressPasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'jane@example.com'): WpUser
    {
        return WpUser::create([
            'user_login' => 'jane',
            'user_pass' => (new WordPressPasswordHasher)->make('oldpassword1'),
            'user_email' => $email,
        ]);
    }

    public function test_a_full_forgot_verify_reset_cycle_works(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $this->postJson('/api/auth/forgot-password', ['email' => 'jane@example.com'])->assertOk();

        $otp = $user->metaValue('rk_reset_otp');
        $this->assertNotNull($otp);
        // Stored as a hash, not the plaintext code the user receives by email.
        $this->assertSame(64, strlen($otp));

        Notification::assertSentTo($user, PasswordResetOtp::class);

        // Pull the real code out of the notification instead of the hash.
        $sentCode = null;
        Notification::assertSentTo($user, PasswordResetOtp::class, function ($notification) use (&$sentCode) {
            $sentCode = (fn () => $this->code)->call($notification);

            return true;
        });

        $this->postJson('/api/auth/verify-reset-code', ['email' => 'jane@example.com', 'otp' => $sentCode])
            ->assertOk();

        $this->postJson('/api/auth/reset-password', [
            'email' => 'jane@example.com',
            'otp' => $sentCode,
            'password' => 'newpassword2',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue((new WordPressPasswordHasher)->check('newpassword2', $user->user_pass));
        $this->assertNull($user->metaValue('rk_reset_otp'));
    }

    public function test_requesting_a_code_for_an_unknown_email_still_returns_a_generic_success_message(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If that email has an account, a reset code has been sent.');
    }

    public function test_a_wrong_code_is_rejected(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/forgot-password', ['email' => 'jane@example.com'])->assertOk();

        $this->postJson('/api/auth/verify-reset-code', ['email' => 'jane@example.com', 'otp' => '000000'])
            ->assertStatus(422);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $this->postJson('/api/auth/forgot-password', ['email' => 'jane@example.com'])->assertOk();

        $sentCode = null;
        Notification::assertSentTo($user, PasswordResetOtp::class, function ($notification) use (&$sentCode) {
            $sentCode = (fn () => $this->code)->call($notification);

            return true;
        });

        WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'rk_reset_expiry')
            ->update(['meta_value' => (string) (time() - 10)]);

        $this->postJson('/api/auth/verify-reset-code', ['email' => 'jane@example.com', 'otp' => $sentCode])
            ->assertStatus(422);
    }

    public function test_resetting_the_password_revokes_every_existing_session(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        WpUserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => 'session_tokens',
            'meta_value' => serialize(['somehash' => ['expiration' => time() + 3600]]),
        ]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'jane@example.com'])->assertOk();

        $sentCode = null;
        Notification::assertSentTo($user, PasswordResetOtp::class, function ($notification) use (&$sentCode) {
            $sentCode = (fn () => $this->code)->call($notification);

            return true;
        });

        $this->postJson('/api/auth/reset-password', [
            'email' => 'jane@example.com',
            'otp' => $sentCode,
            'password' => 'newpassword2',
        ])->assertOk();

        $this->assertNull($user->metaValue('session_tokens'));
    }
}
