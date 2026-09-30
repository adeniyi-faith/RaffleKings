<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Legacy\WpUserResource\Pages\ViewWpUser;
use App\Models\AdminAuditLog;
use App\Models\CustomerMessage;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserBadge;
use App\Models\UserEngagement;
use App\Notifications\EmailChangedBySupport;
use App\Notifications\PasswordChangedBySupport;
use App\Notifications\PasswordResetOtp;
use App\Services\Admin\CustomerSupportTools;
use App\Services\Auth\WordPressPasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * What staff can do for a customer from their profile: see badges, reset a
 * password, sign them out, fix contact details, message them. Staff accounts
 * themselves are off limits, and each role only gets the buttons it should.
 */
class CustomerSupportToolsTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function customer(string $name = 'Sammmk'): WpUser
    {
        return WpUser::create([
            'user_login' => strtolower($name).uniqid(),
            'user_pass' => app(WordPressPasswordHasher::class)->make('old-password-1'),
            'user_email' => uniqid().'@example.com',
            'display_name' => $name,
        ]);
    }

    private function tools(): CustomerSupportTools
    {
        return app(CustomerSupportTools::class);
    }

    private function audit(string $action): ?AdminAuditLog
    {
        return AdminAuditLog::query()->where('action', $action)->latest('id')->first();
    }

    private function signedIn(WpUser $user): void
    {
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'session_tokens', 'meta_value' => serialize(['abc' => ['expiration' => time() + 3600]])]);
    }

    private function actingAsSupport(): WpUser
    {
        $user = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'support']);
        \Livewire\Livewire::withCookies($this->unencryptedCookies);

        return $user;
    }

    // --- Password -----------------------------------------------------------

    public function test_a_temporary_password_replaces_the_old_one_and_signs_them_out_everywhere(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();
        $this->signedIn($customer);

        $password = $this->tools()->setTemporaryPassword($admin, $customer, 'Locked out, called support');

        $hasher = app(WordPressPasswordHasher::class);
        $this->assertTrue($hasher->check($password, $customer->fresh()->user_pass));
        $this->assertFalse($hasher->check('old-password-1', $customer->fresh()->user_pass));
        $this->assertFalse(WpUserMeta::where('user_id', $customer->ID)->where('meta_key', 'session_tokens')->exists());
        Notification::assertSentTo($customer, PasswordChangedBySupport::class);
    }

    public function test_a_temporary_password_is_easy_to_read_out_and_never_written_to_the_audit_log(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();

        $password = $this->tools()->setTemporaryPassword($admin, $customer, 'Called support');

        $this->assertMatchesRegularExpression('/^[a-hj-km-np-zA-HJ-KM-NP-Z2-9]{12}$/', $password);
        $this->assertMatchesRegularExpression('/[a-z]/', $password);
        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);

        $entry = $this->audit('customer.password_set_temporary');
        $this->assertSame('Called support', $entry->context['reason']);
        $this->assertStringNotContainsString($password, json_encode($entry->toArray()));
    }

    public function test_a_reset_code_can_be_emailed_to_the_customer_instead(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();

        $this->tools()->sendResetCode($admin, $customer, 'Forgot password');

        Notification::assertSentTo($customer, PasswordResetOtp::class);
        $this->assertNotNull($customer->metaValue('rk_reset_otp'));
        $this->assertNotNull($this->audit('customer.password_reset_code_sent'));
    }

    public function test_a_staff_account_cannot_be_reset_from_a_customer_profile(): void
    {
        $admin = $this->actingAsAdministrator();
        $otherStaff = $this->customer('Boss');
        WpUserMeta::create(['user_id' => $otherStaff->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'manager']);
        $anotherAdmin = $this->customer('Root');
        WpUserMeta::create(['user_id' => $anotherAdmin->ID, 'meta_key' => config('legacy.wp_prefix').'capabilities', 'meta_value' => serialize(['administrator' => true])]);

        foreach ([$otherStaff, $anotherAdmin] as $staff) {
            try {
                $this->tools()->setTemporaryPassword($admin, $staff, 'x');
                $this->fail('A staff password must not be reset from here.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('staff account', $e->getMessage());
            }
        }

        $this->assertTrue(app(WordPressPasswordHasher::class)->check('old-password-1', $otherStaff->fresh()->user_pass));
    }

    public function test_a_customer_can_only_have_a_few_staff_resets_a_day(): void
    {
        Notification::fake();
        RateLimiter::clear('admin-password-reset:1');
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();
        RateLimiter::clear('admin-password-reset:'.$customer->ID);

        for ($i = 0; $i < 5; $i++) {
            $this->tools()->sendResetCode($admin, $customer, 'again');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('5 password resets');
        $this->tools()->setTemporaryPassword($admin, $customer, 'again');
    }

    public function test_signing_a_customer_out_everywhere_keeps_their_password(): void
    {
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();
        $this->signedIn($customer);

        $this->tools()->signOutEverywhere($admin, $customer);

        $this->assertFalse(WpUserMeta::where('user_id', $customer->ID)->where('meta_key', 'session_tokens')->exists());
        $this->assertTrue(app(WordPressPasswordHasher::class)->check('old-password-1', $customer->fresh()->user_pass));
        $this->assertNotNull($this->audit('customer.signed_out_everywhere'));
    }

    // --- Contact details ------------------------------------------------------

    public function test_contact_details_can_be_changed_and_the_old_email_is_warned(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();
        $old = $customer->user_email;

        $changes = $this->tools()->updateDetails($admin, $customer, ['name' => 'Samuel K', 'email' => 'new@example.com', 'phone' => '08012345678']);

        $this->assertSame(['name', 'email', 'phone'], array_keys($changes));
        $fresh = $customer->fresh();
        $this->assertSame('Samuel K', $fresh->display_name);
        $this->assertSame('new@example.com', $fresh->user_email);
        $this->assertSame('08012345678', $fresh->metaValue('phone'));
        Notification::assertSentOnDemand(EmailChangedBySupport::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $old);
        $this->assertSame($old, $this->audit('customer.details_changed')->context['changes']['email']['from']);
    }

    public function test_an_email_another_account_uses_or_a_bad_one_is_refused(): void
    {
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();
        $other = $this->customer('Other');

        foreach ([$other->user_email, 'not-an-email'] as $bad) {
            try {
                $this->tools()->updateDetails($admin, $customer, ['name' => 'X', 'email' => $bad, 'phone' => '']);
                $this->fail('Should have been refused.');
            } catch (RuntimeException) {
                $this->assertNotSame($bad, $customer->fresh()->user_email);
            }
        }
    }

    public function test_saving_with_nothing_changed_does_nothing(): void
    {
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();

        $this->assertSame([], $this->tools()->updateDetails($admin, $customer, ['name' => $customer->display_name, 'email' => $customer->user_email, 'phone' => '']));
        $this->assertNull($this->audit('customer.details_changed'));
    }

    // --- Messages and badges ----------------------------------------------------

    public function test_a_support_message_lands_in_the_customers_inbox(): void
    {
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();

        $this->tools()->sendMessage($admin, $customer, 'About your ticket', 'We have fixed it.');

        $message = CustomerMessage::where('user_id', $customer->ID)->first();
        $this->assertSame('support', $message->kind);
        $this->assertSame('We have fixed it.', $message->body);
    }

    public function test_a_badge_can_be_given_once_and_taken_back(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();

        $this->tools()->awardBadge($admin, $customer, 'first_ticket');
        $this->assertTrue(UserBadge::where('user_id', $customer->ID)->where('badge', 'first_ticket')->exists());

        try {
            $this->tools()->awardBadge($admin, $customer, 'first_ticket');
            $this->fail('A second copy must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already have', $e->getMessage());
        }

        UserEngagement::for($customer->ID)->update(['showcase' => ['first_ticket']]);
        $this->tools()->removeBadge($admin, $customer, 'first_ticket');

        $this->assertFalse(UserBadge::where('user_id', $customer->ID)->exists());
        $this->assertSame([], UserEngagement::for($customer->ID)->showcase);
        $this->assertNotNull($this->audit('customer.badge_removed'));
    }

    public function test_an_unknown_badge_is_refused(): void
    {
        $admin = $this->actingAsAdministrator();

        $this->expectException(RuntimeException::class);
        $this->tools()->awardBadge($admin, $this->customer(), 'not_a_badge');
    }

    // --- The profile page ---------------------------------------------------------

    public function test_the_profile_shows_earned_badges_and_perks(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();
        $customer = $this->customer();
        UserBadge::create(['user_id' => $customer->ID, 'badge' => 'first_ticket', 'earned_at' => now()]);
        UserEngagement::for($customer->ID)->update(['free_spins' => 3]);

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->assertSuccessful()
            ->assertSee('Badges')
            ->assertSee('First Ticket')
            ->assertSee('1 of ')
            ->assertSee('Free spins')
            ->assertSee('Season Pass');
    }

    public function test_an_owner_can_reset_a_password_from_the_profile_button(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->callAction('resetPassword', ['method' => 'temp', 'reason' => 'Called support'])
            ->assertHasNoActionErrors();

        $this->assertFalse(app(WordPressPasswordHasher::class)->check('old-password-1', $customer->fresh()->user_pass));
    }

    public function test_support_staff_can_reset_passwords_and_message_but_not_change_balances_details_or_badges(): void
    {
        Notification::fake();
        $this->actingAsSupport();
        $customer = $this->customer();

        $page = Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])->assertSuccessful();

        $page->assertActionVisible('resetPassword')
            ->assertActionVisible('signOutEverywhere')
            ->assertActionVisible('messageCustomer')
            ->assertActionHidden('editDetails')
            ->assertActionHidden('awardBadge')
            ->assertActionHidden('removeBadge')
            ->assertActionHidden('adjustBalance');

        $page->callAction('resetPassword', ['method' => 'code', 'reason' => 'Forgot it']);
        Notification::assertSentTo($customer, PasswordResetOtp::class);
    }

    public function test_the_reset_button_is_not_offered_on_a_staff_account(): void
    {
        $this->actingAsSupport();
        $staff = $this->customer('Boss');
        WpUserMeta::create(['user_id' => $staff->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'manager']);

        Livewire::test(ViewWpUser::class, ['record' => $staff->getKey()])
            ->assertActionHidden('resetPassword')
            ->assertActionHidden('signOutEverywhere');
    }

    public function test_staff_without_the_support_ability_do_not_get_the_reset_button(): void
    {
        $user = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'content']);
        Livewire::withCookies($this->unencryptedCookies);
        $customer = $this->customer();

        // (Content staff can't open Customers at all.)
        $this->get(\App\Filament\Resources\Legacy\WpUserResource::getUrl('view', ['record' => $customer]))->assertForbidden();
    }

    // --- Free spins and bonus tokens -----------------------------------------------

    public function test_free_spins_and_tokens_can_be_given_and_the_customer_is_told(): void
    {
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();
        UserEngagement::for($customer->ID)->update(['free_spins' => 1, 'bonus_entry_tokens' => 0]);

        $result = $this->tools()->adjustPerks($admin, $customer, 3, 2, 'Apology for the delayed payout');

        $this->assertSame(['from' => 1, 'to' => 4], $result['free_spins']);
        $this->assertSame(['from' => 0, 'to' => 2], $result['bonus_entry_tokens']);
        $row = UserEngagement::for($customer->ID);
        $this->assertSame(4, $row->free_spins);
        $this->assertSame(2, $row->bonus_entry_tokens);
        $this->assertSame('Apology for the delayed payout', $this->audit('customer.perks_adjusted')->context['reason']);
        $message = CustomerMessage::where('user_id', $customer->ID)->first();
        $this->assertSame('reward', $message->kind);
        $this->assertStringContainsString('3 free spins and 2 free bonus entries', $message->body);
    }

    public function test_perks_can_be_taken_back_but_never_below_zero(): void
    {
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();
        UserEngagement::for($customer->ID)->update(['free_spins' => 2]);

        $this->tools()->adjustPerks($admin, $customer, -2, 0, 'Given by mistake');
        $this->assertSame(0, UserEngagement::for($customer->ID)->free_spins);
        $this->assertSame(0, CustomerMessage::where('user_id', $customer->ID)->count(), 'taking back sends no message');

        try {
            $this->tools()->adjustPerks($admin, $customer, -1, 0, 'Too many');
            $this->fail('Going below zero must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('only have 0 free spin', $e->getMessage());
        }
        $this->assertSame(0, UserEngagement::for($customer->ID)->free_spins);
    }

    public function test_perk_changes_need_a_number_a_reason_and_stay_small(): void
    {
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();

        foreach ([[0, 0, 'x'], [1, 0, '  '], [101, 0, 'x'], [0, -101, 'x']] as [$spins, $tokens, $reason]) {
            try {
                $this->tools()->adjustPerks($admin, $customer, $spins, $tokens, $reason);
                $this->fail('Should have been refused.');
            } catch (RuntimeException) {
                $this->assertNull($this->audit('customer.perks_adjusted'));
            }
        }
    }

    public function test_the_customer_is_not_messaged_when_staff_choose_not_to(): void
    {
        $admin = $this->actingAsAdministrator();
        $customer = $this->customer();

        $this->tools()->adjustPerks($admin, $customer, 1, 0, 'Quietly', false);

        $this->assertSame(1, UserEngagement::for($customer->ID)->free_spins);
        $this->assertSame(0, CustomerMessage::where('user_id', $customer->ID)->count());
    }

    public function test_the_perks_button_works_for_managers_and_is_hidden_from_support(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->assertActionVisible('editPerks')
            ->callAction('editPerks', ['free_spins' => 2, 'bonus_entry_tokens' => 1, 'reason' => 'Goodwill', 'tell' => false])
            ->assertHasNoActionErrors();
        $this->assertSame(2, UserEngagement::for($customer->ID)->free_spins);

        $this->actingAsSupport();
        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])->assertActionHidden('editPerks');
    }
}
