<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Legacy\WpUserResource;
use App\Filament\Resources\Legacy\WpUserResource\Pages\ViewWpUser;
use App\Models\AdminAuditLog;
use App\Models\GoldenBoxOffer;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Services\Admin\Impersonation;
use App\Services\Risk\AbuseDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * "View as customer": an owner sees the site as one customer sees it, view-only,
 * for a few minutes, with a reason, and everything is logged. These tests are
 * mostly about the safeguards: who can, who can't be viewed, what can't be done.
 */
class ViewAsCustomerTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function customer(string $name = 'Sammmk'): WpUser
    {
        return WpUser::create(['user_login' => strtolower($name).uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => $name]);
    }

    private function service(): Impersonation
    {
        return app(Impersonation::class);
    }

    /** Starts a view as the signed-in owner and hands the browser its cookie. */
    private function startViewing(WpUser $owner, WpUser $customer, string $reason = 'Ticket #12'): string
    {
        $cookie = $this->service()->start($owner, $customer, $reason, request());
        $this->withUnencryptedCookie(Impersonation::COOKIE, $cookie->getValue());

        return $cookie->getValue();
    }

    private function audit(string $action): ?AdminAuditLog
    {
        return AdminAuditLog::query()->where('action', $action)->latest('id')->first();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('impersonation-starts:1');
    }

    // --- Who may, and who may be viewed ------------------------------------------

    public function test_an_owner_can_start_a_view_and_it_is_logged_with_the_reason(): void
    {
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();

        $cookie = $this->service()->start($owner, $customer, 'Ticket #12: balance looks wrong', request());

        $this->assertSame(Impersonation::COOKIE, $cookie->getName());
        $this->assertTrue($cookie->isHttpOnly());
        $entry = $this->audit('customer.impersonation_started');
        $this->assertSame('Ticket #12: balance looks wrong', $entry->context['reason']);
        $this->assertSame($customer->ID, $entry->subject_id);
    }

    public function test_only_owners_can_view_as_a_customer(): void
    {
        $manager = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $manager->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'manager']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only owners');
        $this->service()->start($manager, $this->customer(), 'x', request());
    }

    public function test_staff_banned_and_own_accounts_cannot_be_viewed(): void
    {
        $owner = $this->actingAsAdministrator();
        $staff = $this->customer('Boss');
        WpUserMeta::create(['user_id' => $staff->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'support']);
        $banned = $this->customer('Banned');
        WpUserMeta::create(['user_id' => $banned->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);

        foreach ([$staff, $banned, $owner] as $target) {
            $this->assertNotNull($this->service()->whyNot($owner, $target), 'must refuse '.$target->display_name);
        }
        $this->assertNull($this->audit('customer.impersonation_started'));
    }

    public function test_a_reason_is_required(): void
    {
        $owner = $this->actingAsAdministrator();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Say why');
        $this->service()->start($owner, $this->customer(), '   ', request());
    }

    public function test_an_owner_cannot_start_endless_views(): void
    {
        $owner = $this->actingAsAdministrator();
        RateLimiter::clear('impersonation-starts:'.$owner->ID);
        $customer = $this->customer();

        for ($i = 0; $i < 10; $i++) {
            $this->service()->start($owner, $customer, 'again', request());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a lot of customer views');
        $this->service()->start($owner, $customer, 'again', request());
    }

    // --- Seeing the customer's account ------------------------------------------------

    public function test_while_viewing_the_site_shows_the_customers_account_not_the_owners(): void
    {
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        Wallet::create(['user_id' => $customer->ID, 'wallet_balance' => 4321, 'earnings_balance' => 0]);

        $this->getJson('/api/me')->assertJsonPath('id', $owner->ID);

        $this->startViewing($owner, $customer);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('id', $customer->ID);
        $this->getJson('/api/wallet')->assertOk()->assertJsonPath('wallet_balance', 4321);
    }

    public function test_the_banner_details_are_shared_and_analytics_is_switched_off(): void
    {
        config(['services.posthog.project_key' => 'phc_test']);
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer('Sammmk');
        $this->startViewing($owner, $customer);

        $this->get('/profile')->assertOk()->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $customer->ID)
            ->where('impersonating.name', 'Sammmk')
            ->where('analytics', null));
    }

    public function test_the_admin_pages_always_see_the_owner_themselves(): void
    {
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        $this->startViewing($owner, $customer);

        $this->get('/admin')->assertOk();
        $this->get(WpUserResource::getUrl('view', ['record' => $customer]))->assertOk();
    }

    // --- View-only ------------------------------------------------------------------------

    public function test_nothing_can_be_bought_paid_changed_or_sent_while_viewing(): void
    {
        $owner = $this->actingAsAdministrator();
        $this->startViewing($owner, $this->customer());

        foreach ([
            ['/api/tickets/purchase', []],
            ['/api/deposits', ['amount' => 500]],
            ['/api/withdrawals', []],
            ['/api/profile', ['first_name' => 'X']],
            ['/api/wallet/transfer', ['amount' => 5]],
            ['/api/messages/read-all', []],
            ['/api/auth/logout', []],
        ] as [$url, $data]) {
            $this->postJson($url, $data)->assertStatus(403)->assertJsonPath('view_only', true);
        }
    }

    public function test_pages_that_change_things_just_by_opening_them_are_refused(): void
    {
        $owner = $this->actingAsAdministrator();
        $this->startViewing($owner, $this->customer());

        // The rewards page can hand out a birthday gift; checkout records a visit; the banner feed starts the Golden Box timer.
        foreach (['/rewards', '/checkout?raffle_id=1&qty=1&numbers=1', '/api/rewards/state', '/api/golden-box'] as $url) {
            $this->get($url)->assertStatus(403);
        }
    }

    public function test_the_bottom_menu_pages_can_be_browsed_without_starting_the_golden_box(): void
    {
        config(['pricing.golden_box_enabled' => true, 'pricing.golden_box_percent_off' => 10]);
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        GoldenBoxOffer::create(['user_id' => $customer->ID, 'status' => 'open', 'raffle_id' => 1, 'quantity' => 1, 'ticket_numbers' => [1], 'order_total' => 100000]);
        $this->startViewing($owner, $customer);

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->where('goldenBox', null));
        $this->get('/raffles')->assertOk()->assertInertia(fn ($page) => $page->where('goldenBox', null));
        $this->get('/hall-of-fame')->assertOk();

        $this->assertNull(GoldenBoxOffer::query()->first()->offered_until);
    }

    public function test_the_account_pages_can_be_looked_at(): void
    {
        $owner = $this->actingAsAdministrator();
        $this->startViewing($owner, $this->customer());

        foreach (['/profile', '/account/tickets', '/account/transactions', '/account/wallet', '/messages'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_the_owners_browser_is_never_linked_to_the_customers_account_while_viewing(): void
    {
        config(['features.abuse_detection' => true]);
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        $this->startViewing($owner, $customer);
        $this->withUnencryptedCookie(AbuseDetector::DEVICE_COOKIE, '0123456789abcdef0123456789abcdef');

        $detector = \Mockery::mock(AbuseDetector::class)->makePartial();
        $detector->shouldNotReceive('recordDevice');
        $this->app->instance(AbuseDetector::class, $detector);

        $this->get('/profile')->assertOk();
    }

    // --- Ending, expiry and tampering -----------------------------------------------------------

    public function test_stopping_ends_the_view_logs_it_and_the_cookie_stops_working(): void
    {
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        $value = $this->startViewing($owner, $customer);

        $response = $this->postJson('/api/impersonation/stop')->assertOk();

        $this->assertSame(WpUserResource::getUrl('view', ['record' => $customer]), $response->json('redirect'));
        $this->assertNotNull($this->audit('customer.impersonation_ended'));
        // Even if the old cookie is sent again, it no longer means anything.
        $this->withUnencryptedCookie(Impersonation::COOKIE, $value);
        $this->getJson('/api/me')->assertJsonPath('id', $owner->ID);
    }

    public function test_a_view_ends_by_itself_after_fifteen_minutes(): void
    {
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        $this->startViewing($owner, $customer);
        $this->getJson('/api/me')->assertJsonPath('id', $customer->ID);

        Carbon::setTestNow(now()->addMinutes(Impersonation::MINUTES + 1));
        $this->travel(0);

        try {
            $this->getJson('/api/me')->assertJsonPath('id', $owner->ID);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_made_up_or_altered_cookie_does_nothing(): void
    {
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        $value = $this->startViewing($owner, $customer);
        [$token] = explode('.', $value);

        foreach ([$token.'.'.str_repeat('a', 64), 'deadbeef', str_repeat('a', 40).'.'.str_repeat('b', 64)] as $fake) {
            $this->withUnencryptedCookie(Impersonation::COOKIE, $fake);
            $this->getJson('/api/me')->assertJsonPath('id', $owner->ID);
        }
    }

    public function test_someone_elses_view_cookie_does_not_work_for_another_signed_in_person(): void
    {
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        $value = $this->startViewing($owner, $customer);

        // A different person signs in with that cookie in their browser.
        $other = $this->actingAsWordPressUser();
        $this->withUnencryptedCookie(Impersonation::COOKIE, $value);

        $this->getJson('/api/me')->assertJsonPath('id', $other->ID);
    }

    public function test_the_view_stops_working_if_the_owner_loses_the_right_to_do_it(): void
    {
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        $this->startViewing($owner, $customer);

        WpUserMeta::where('user_id', $owner->ID)->where('meta_key', 'rk_staff_role')->delete();
        WpUserMeta::create(['user_id' => $owner->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'support']);

        $this->getJson('/api/me')->assertJsonPath('id', $owner->ID);
    }

    public function test_the_view_stops_working_if_the_customer_is_banned_meanwhile(): void
    {
        $owner = $this->actingAsAdministrator();
        $customer = $this->customer();
        $this->startViewing($owner, $customer);

        WpUserMeta::create(['user_id' => $customer->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);

        $this->getJson('/api/me')->assertJsonPath('id', $owner->ID);
    }

    // --- The button on the profile ------------------------------------------------------------

    public function test_the_owner_sees_the_button_and_using_it_starts_a_logged_view(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->assertActionVisible('viewAsCustomer')
            ->callAction('viewAsCustomer', ['reason' => 'Ticket #9', 'understood' => true])
            ->assertHasNoActionErrors()
            ->assertRedirect('/profile');

        $this->assertContains(Impersonation::COOKIE, collect(Cookie::getQueuedCookies())->map->getName()->all());
        $this->assertSame('Ticket #9', $this->audit('customer.impersonation_started')->context['reason']);
    }

    public function test_the_button_needs_a_reason_and_the_tick_box(): void
    {
        $this->actingAsAdministrator();
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])
            ->callAction('viewAsCustomer', ['reason' => '', 'understood' => false])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertNull($this->audit('customer.impersonation_started'));
    }

    public function test_managers_and_support_never_see_the_button_and_it_is_not_offered_on_staff(): void
    {
        $user = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'manager']);
        Livewire::withCookies($this->unencryptedCookies);
        $customer = $this->customer();

        Livewire::test(ViewWpUser::class, ['record' => $customer->getKey()])->assertActionHidden('viewAsCustomer');
    }

    public function test_the_button_is_not_offered_on_a_staff_account(): void
    {
        $this->actingAsAdministrator();
        $staff = $this->customer('Boss');
        WpUserMeta::create(['user_id' => $staff->ID, 'meta_key' => 'rk_staff_role', 'meta_value' => 'manager']);

        Livewire::test(ViewWpUser::class, ['record' => $staff->getKey()])->assertActionHidden('viewAsCustomer');
    }
}
