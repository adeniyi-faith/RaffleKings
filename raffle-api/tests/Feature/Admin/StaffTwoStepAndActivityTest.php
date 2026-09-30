<?php

namespace Tests\Feature\Admin;

use App\Auth\StaffRoles;
use App\Console\Commands\StaffTwoStepCommand;
use App\Filament\Pages\Auth\AdminLogin;
use App\Filament\Pages\StaffActivity as StaffActivityPage;
use App\Models\Admin\LoginEvent;
use App\Models\AdminAuditLog;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Notifications\StaffSignInCode;
use App\Services\Admin\StaffActivity;
use App\Services\Auth\StaffTwoStep;
use App\Services\Auth\WordPressPasswordHasher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** Staff two-step sign-in (an emailed code after the password) and Users → Staff activity. */
class StaffTwoStepAndActivityTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function account(string $login, ?string $role = 'finance'): WpUser
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

    private function twoStepOn(): void
    {
        config(['security.staff_two_step' => true]);
    }

    /** The code that was emailed (it is private inside the notification). */
    private function emailedCode(WpUser $user): string
    {
        $code = null;
        Notification::assertSentTo($user, StaffSignInCode::class, function (StaffSignInCode $n) use (&$code) {
            $code = (fn () => $this->code)->call($n);

            return true;
        });

        return $code;
    }

    private function startSignIn(WpUser $user): \Livewire\Features\SupportTesting\Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(AdminLogin::class)
            ->fillForm(['login' => $user->user_email, 'password' => 'Right-Pass-1'])
            ->call('authenticate');
    }

    // -- Signing in ---------------------------------------------------------

    public function test_with_it_off_the_password_alone_still_signs_in(): void
    {
        $staff = $this->account('grace');

        $this->startSignIn($staff)->assertHasNoErrors()->assertRedirect('/admin');

        $this->assertSame(1, $this->sessionCount($staff));
    }

    public function test_with_it_on_the_password_only_starts_the_sign_in_and_a_code_is_emailed(): void
    {
        Notification::fake();
        $this->twoStepOn();
        $staff = $this->account('grace');

        $page = $this->startSignIn($staff)->assertHasNoErrors()->assertNoRedirect();

        $this->assertSame('code', $page->get('step'));
        $this->assertSame('g***@example.com', $page->get('codeSentTo'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->emailedCode($staff));
        $this->assertSame(0, $this->sessionCount($staff), 'no login is given out before the code');
        $this->assertNull(Cookie::queued(app('wordpress.auth_cookie_name')));
        $this->assertFalse(LoginEvent::where('user_id', $staff->ID)->where('success', true)->exists());
    }

    public function test_the_right_code_finishes_the_sign_in_and_marks_the_browser(): void
    {
        Notification::fake();
        $this->twoStepOn();
        $staff = $this->account('grace');

        $page = $this->startSignIn($staff);
        $page->fillForm(['code' => $this->emailedCode($staff)])->call('authenticate')->assertHasNoFormErrors()->assertRedirect('/admin');

        $this->assertSame(1, $this->sessionCount($staff));
        $this->assertNotNull(Cookie::queued(app('wordpress.auth_cookie_name')));
        $mark = Cookie::queued(StaffTwoStep::COOKIE);
        $this->assertNotNull($mark);
        $this->assertSame(1, DB::table('staff_verified_sessions')->where('user_id', $staff->ID)->where('token_hash', hash('sha256', $mark->getValue()))->count());
        $this->assertTrue(LoginEvent::where('user_id', $staff->ID)->where('success', true)->where('place', 'admin')->exists());
        $this->assertTrue((bool) AdminAuditLog::where('action', 'staff.signed_in')->where('admin_user_id', $staff->ID)->first()->context['two_step']);
    }

    public function test_a_wrong_code_is_refused_and_five_wrong_ones_end_the_attempt(): void
    {
        Notification::fake();
        $this->twoStepOn();
        $staff = $this->account('grace');
        $page = $this->startSignIn($staff);
        $right = $this->emailedCode($staff);
        $wrong = $right === '000000' ? '111111' : '000000';

        $page->fillForm(['code' => $wrong])->call('authenticate')->assertHasFormErrors(['code']);
        $this->assertSame('code', $page->get('step'));

        foreach (range(1, 4) as $_) {
            $this->travel(61)->seconds(); // stay under the "5 tries a minute" limit, which is a separate guard
            $page->fillForm(['code' => $wrong])->call('authenticate');
        }

        $this->assertSame('password', $page->get('step'), 'too many wrong codes send them back to the start');
        $this->assertSame(0, $this->sessionCount($staff));
        $this->assertSame(5, LoginEvent::where('user_id', $staff->ID)->where('reason', 'two_step_failed')->count());

        // The old code is dead too, even the right one.
        $page->set('step', 'code')->fillForm(['code' => $right])->call('authenticate');
        $this->assertSame(0, $this->sessionCount($staff));
    }

    public function test_a_code_runs_out_after_ten_minutes(): void
    {
        Notification::fake();
        $this->twoStepOn();
        $staff = $this->account('grace');
        $page = $this->startSignIn($staff);
        $code = $this->emailedCode($staff);

        $this->travel(11)->minutes();
        $page->fillForm(['code' => $code])->call('authenticate')->assertHasFormErrors();

        $this->assertSame(0, $this->sessionCount($staff));
        $this->assertSame('password', $page->get('step'));
    }

    public function test_a_new_code_can_be_asked_for_but_not_endlessly(): void
    {
        Notification::fake();
        $this->twoStepOn();
        $staff = $this->account('grace');
        $page = $this->startSignIn($staff);
        $page->call('resendCode');
        Notification::assertSentToTimes($staff, StaffSignInCode::class, 1); // too soon: 30 seconds apart

        $this->travel(31)->seconds();
        $page->call('resendCode');
        Notification::assertSentToTimes($staff, StaffSignInCode::class, 2);

        $this->travel(31)->seconds();
        $page->call('resendCode');
        $this->travel(31)->seconds();
        $page->call('resendCode');
        Notification::assertSentToTimes($staff, StaffSignInCode::class, 3);
    }

    public function test_a_customer_with_the_right_password_gets_no_code_and_no_login(): void
    {
        Notification::fake();
        $this->twoStepOn();
        $customer = $this->account('ada', null);

        $this->startSignIn($customer)->assertHasFormErrors(['login']);

        Notification::assertNothingSent();
        $this->assertSame(0, $this->sessionCount($customer));
    }

    public function test_if_the_email_cannot_be_sent_the_person_is_told_and_nothing_is_left_half_done(): void
    {
        $this->twoStepOn();
        $staff = $this->account('grace');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        app('mail.manager')->forgetMailers();

        $this->startSignIn($staff)->assertHasFormErrors(['login']);

        $this->assertSame(0, $this->sessionCount($staff));
        $this->assertNull(app(StaffTwoStep::class)->pending());
    }

    // -- Enforcement --------------------------------------------------------

    public function test_signed_in_staff_without_the_code_are_sent_back_to_sign_in_once_it_is_on(): void
    {
        $admin = $this->actingAsAdministrator();
        $this->get('/admin')->assertOk();

        $this->twoStepOn();
        $this->get('/admin')->assertRedirect('/admin/login');

        // Passing the code lets them straight in.
        $token = app(StaffTwoStep::class)->markVerified($admin)->getValue();
        $this->withUnencryptedCookie(StaffTwoStep::COOKIE, $token);
        $this->get('/admin')->assertOk();
    }

    public function test_a_used_up_or_someone_elses_mark_does_not_count(): void
    {
        $admin = $this->actingAsAdministrator();
        $other = $this->account('other');
        $this->twoStepOn();
        $service = app(StaffTwoStep::class);

        $theirs = $service->markVerified($other)->getValue();
        $this->withUnencryptedCookie(StaffTwoStep::COOKIE, $theirs);
        $this->get('/admin')->assertRedirect('/admin/login');

        $mine = $service->markVerified($admin)->getValue();
        $this->withUnencryptedCookie(StaffTwoStep::COOKIE, $mine);
        $this->get('/admin')->assertOk();

        $this->travel(15)->days();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_the_admin_sign_in_page_does_not_bounce_a_half_signed_in_person_in_circles(): void
    {
        $this->actingAsAdministrator();
        $this->twoStepOn();

        $this->get('/admin/login')->assertOk()->assertSee('Two-step sign-in is on');
    }

    public function test_the_admin_api_needs_the_code_too_so_a_password_alone_is_not_enough(): void
    {
        $admin = $this->actingAsAdministrator();
        $this->getJson('/api/admin/audit-logs')->assertOk();

        $this->twoStepOn();
        $this->getJson('/api/admin/audit-logs')->assertStatus(403)->assertJsonPath('message', 'Sign in to the admin with your emailed code first.');

        $this->withUnencryptedCookie(StaffTwoStep::COOKIE, app(StaffTwoStep::class)->markVerified($admin)->getValue());
        $this->getJson('/api/admin/audit-logs')->assertOk();
    }

    public function test_signing_out_takes_the_mark_away(): void
    {
        $admin = $this->actingAsAdministrator();
        $token = app(StaffTwoStep::class)->markVerified($admin)->getValue();
        $this->withUnencryptedCookie(StaffTwoStep::COOKIE, $token);

        $this->post('/admin/logout')->assertRedirect('/admin/login');

        $this->assertSame(0, DB::table('staff_verified_sessions')->where('user_id', $admin->ID)->count());
    }

    // -- Turning it on and off ----------------------------------------------

    public function test_it_only_switches_on_when_a_test_email_works(): void
    {
        $owner = $this->actingAsAdministrator();
        config(['mail.default' => 'log']);

        $problem = app(StaffTwoStep::class)->turnOn($owner);

        $this->assertStringContainsString('email isn\'t working', $problem);
        $this->assertFalse(StaffTwoStep::enabled());

        config(['mail.default' => 'array']);
        $this->assertNull(app(StaffTwoStep::class)->turnOn($owner));
        $this->assertTrue(StaffTwoStep::enabled());
        $this->assertTrue(AdminAuditLog::where('action', 'staff.two_step_on')->exists());

        app(StaffTwoStep::class)->turnOff($owner);
        $this->assertFalse(StaffTwoStep::enabled());
    }

    public function test_the_settings_switch_uses_the_same_safety_check(): void
    {
        $this->actingAsAdministrator();
        config(['mail.default' => 'log']);

        Livewire::test(\App\Filament\Pages\Settings::class)
            ->set('data.security__staff_two_step', true)
            ->call('save');

        $this->assertFalse(StaffTwoStep::enabled(), 'not switched on while email cannot work');
    }

    public function test_the_server_command_can_always_switch_it_off(): void
    {
        $owner = $this->actingAsAdministrator();
        app(StaffTwoStep::class)->turnOn($owner);
        $this->assertTrue(StaffTwoStep::enabled());

        $this->artisan('staff:two-step', ['state' => 'status'])->expectsOutput('Two-step sign-in for staff is ON.')->assertSuccessful();
        $this->artisan('staff:two-step', ['state' => 'off'])->assertSuccessful();

        $this->assertFalse(StaffTwoStep::enabled());
        $this->artisan('staff:two-step', ['state' => 'on'])->assertFailed();
    }

    // -- Staff activity -----------------------------------------------------

    public function test_the_people_list_counts_sign_ins_failures_and_changes(): void
    {
        $grace = $this->account('grace');
        $ade = $this->account('ade', 'support');
        LoginEvent::record($grace->ID, 'grace', true, 'admin');
        LoginEvent::record($grace->ID, 'grace', true, 'admin');
        LoginEvent::record($grace->ID, 'grace', false, 'admin', 'wrong_password');
        LoginEvent::record($grace->ID, 'grace', true, 'site'); // the customer site doesn't count
        AdminAuditLog::create(['admin_user_id' => $grace->ID, 'action' => 'withdrawal.paid', 'subject_type' => 'x', 'subject_id' => 1, 'created_at' => now()]);
        AdminAuditLog::create(['admin_user_id' => $grace->ID, 'action' => 'staff.signed_in', 'subject_type' => 'x', 'subject_id' => 1, 'created_at' => now()]);
        AdminAuditLog::create(['admin_user_id' => $grace->ID, 'action' => 'user.banned', 'subject_type' => 'x', 'subject_id' => 1, 'created_at' => now()->subDays(60)]);

        $row = app(StaffActivity::class)->people()->find($grace->ID);

        $this->assertSame([2, 1, 1], [(int) $row->sign_ins_30, (int) $row->failed_30, (int) $row->actions_30], 'signing in is not counted as a change; old changes are outside the 30 days');
        $this->assertNotNull($row->last_sign_in_at);
        $this->assertNotNull($row->last_action_at);

        $quiet = app(StaffActivity::class)->people()->find($ade->ID);
        $this->assertSame([0, 0, 0], [(int) $quiet->sign_ins_30, (int) $quiet->failed_30, (int) $quiet->actions_30]);
        $this->assertNull($quiet->last_sign_in_at);
    }

    public function test_guessing_and_new_places_are_flagged(): void
    {
        $grace = $this->account('grace');
        $home = fn () => request()->server->set('REMOTE_ADDR', '10.0.0.1');
        $away = fn () => request()->server->set('REMOTE_ADDR', '203.0.113.9');

        $home();
        $this->travelTo(now()->subDays(20));
        LoginEvent::record($grace->ID, 'grace', true, 'admin');
        $this->travelBack();
        $away();
        LoginEvent::record($grace->ID, 'grace', true, 'admin');
        foreach (range(1, 5) as $_) {
            LoginEvent::record(null, 'boss@example.com', false, 'admin', 'wrong_password');
        }

        $alerts = app(StaffActivity::class)->alerts();

        $this->assertSame(5, $alerts['failed']['count']);
        $this->assertSame(['boss@example.com'], $alerts['failed']['who']);
        $this->assertCount(1, $alerts['new_places']);
        $this->assertSame(['Grace', '203.0.113.9'], [$alerts['new_places'][0]['name'], $alerts['new_places'][0]['ip']]);

        $mine = app(StaffActivity::class)->signIns($grace);
        $this->assertTrue($mine[0]['new_place']);
        $this->assertFalse($mine[1]['new_place'], 'the very first sign-in is not "new"');
    }

    public function test_only_owners_can_open_staff_activity_and_it_lists_people(): void
    {
        $this->assertFalse(StaffRoles::canOpen('manager', StaffActivityPage::class));
        $this->assertFalse(StaffRoles::canOpen('finance', StaffActivityPage::class));
        $this->assertTrue(StaffRoles::canOpen('owner', StaffActivityPage::class));

        $owner = $this->actingAsAdministrator();
        $grace = $this->account('grace');
        LoginEvent::record($grace->ID, 'grace', true, 'admin');

        Livewire::test(StaffActivityPage::class)
            ->assertSuccessful()
            ->assertSee('Grace')
            ->assertCanSeeTableRecords(app(StaffActivity::class)->people()->get())
            ->assertActionVisible('turnOnTwoStep')
            ->assertActionHidden('turnOffTwoStep');
    }
}
