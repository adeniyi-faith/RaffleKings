<?php

namespace Tests\Feature\Admin;

use App\Auth\StaffRoles;
use App\Filament\Pages\Auth\AdminLogin;
use App\Models\AdminAuditLog;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Services\Auth\WordPressPasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * The admin's own sign-in page (/admin/login): same accounts and password
 * as the site, staff only, and a sign-out that really signs you out.
 */
class AdminLoginTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function account(string $login, ?string $role): WpUser
    {
        $user = WpUser::create([
            'user_login' => $login,
            'user_email' => "{$login}@example.com",
            'user_pass' => app(WordPressPasswordHasher::class)->make('Right-Pass-1'),
            'display_name' => ucfirst($login),
        ]);

        if ($role) {
            WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => StaffRoles::META_KEY, 'meta_value' => $role]);
        }

        return $user;
    }

    private function sessionCount(WpUser $user): int
    {
        return count((array) @unserialize((string) WpUserMeta::where('user_id', $user->ID)->where('meta_key', 'session_tokens')->value('meta_value')));
    }

    public function test_guests_are_sent_to_the_admin_sign_in_page(): void
    {
        $this->get('/admin/withdrawals')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Staff sign in')->assertSee('Email or username');
    }

    public function test_staff_can_sign_in_with_their_site_password(): void
    {
        $staff = $this->account('grace', 'finance');

        Livewire::test(AdminLogin::class)
            ->fillForm(['login' => 'grace@example.com', 'password' => 'Right-Pass-1'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertSame(1, $this->sessionCount($staff));
        $this->assertTrue(AdminAuditLog::where('action', 'staff.signed_in')->where('admin_user_id', $staff->ID)->exists());
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->account('grace', 'finance');

        Livewire::test(AdminLogin::class)
            ->fillForm(['login' => 'grace', 'password' => 'nope'])
            ->call('authenticate')
            ->assertHasFormErrors(['login']);
    }

    public function test_a_customer_with_the_right_password_is_kept_out_and_not_left_signed_in(): void
    {
        $customer = $this->account('ada', null);

        Livewire::test(AdminLogin::class)
            ->fillForm(['login' => 'ada', 'password' => 'Right-Pass-1'])
            ->call('authenticate')
            ->assertHasFormErrors(['login'])
            ->assertSee('doesn\'t have access to the admin');

        $this->assertSame(0, $this->sessionCount($customer));
    }

    public function test_sign_in_asks_for_the_bot_check_when_it_is_switched_on(): void
    {
        config(['services.turnstile' => ['site_key' => 'site', 'secret_key' => 'secret', 'forms' => ['register' => true, 'login' => true, 'forgot_password' => false]]]);
        $this->account('grace', 'finance');

        Livewire::test(AdminLogin::class)
            ->fillForm(['login' => 'grace', 'password' => 'Right-Pass-1'])
            ->call('authenticate')
            ->assertHasFormErrors(['login']);
    }

    public function test_sign_out_ends_the_session_and_locks_the_admin_again(): void
    {
        $admin = $this->actingAsAdministrator();
        $this->assertSame(1, $this->sessionCount($admin));

        $this->post('/admin/logout')->assertRedirect('/admin/login');

        $this->assertSame(0, $this->sessionCount($admin));
    }
}
