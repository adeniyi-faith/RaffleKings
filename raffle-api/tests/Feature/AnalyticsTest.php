<?php

namespace Tests\Feature;

use App\Services\Analytics\Analytics;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * PostHog tracking: off without an admin-saved key; when on, the browser is
 * told the key and the server records actions and money events.
 */
class AnalyticsTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function switchOn(): void
    {
        config([
            'services.posthog.project_key' => 'phc_test',
            'services.posthog.host' => 'https://us.i.posthog.com',
            'services.paystack.secret_key' => 'sk_test_paystack',
        ]);
    }

    /** Sends the events that are waiting for "after the reply". */
    private function flush(): void
    {
        $this->app->terminate();
    }

    public function test_nothing_is_sent_and_no_key_is_shared_without_a_project_key(): void
    {
        Http::fake();
        config(['services.posthog.project_key' => null]);

        app(Analytics::class)->capture(1, 'anything');
        $this->flush();

        Http::assertNothingSent();
        $this->get('/')->assertInertia(fn ($page) => $page->where('analytics', null));
    }

    public function test_the_browser_is_given_the_key_saved_in_admin_settings(): void
    {
        $this->switchOn();

        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('analytics.key', 'phc_test')
            ->where('analytics.host', 'https://us.i.posthog.com')
            ->where('analytics.recordings', true));
    }

    public function test_an_event_is_sent_to_posthog_with_the_users_id(): void
    {
        $this->switchOn();
        Http::fake(['us.i.posthog.com/*' => Http::response([])]);

        app(Analytics::class)->capture(42, 'tickets_purchased', ['amount' => 500]);
        $this->flush();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://us.i.posthog.com/capture/'
            && $r['api_key'] === 'phc_test'
            && $r['event'] === 'tickets_purchased'
            && $r['distinct_id'] === '42'
            && $r['properties']['amount'] === 500
            && $r['properties']['source'] === 'server');
    }

    public function test_a_posthog_outage_never_breaks_the_request(): void
    {
        $this->switchOn();
        Http::fake(['us.i.posthog.com/*' => Http::response('down', 500)]);

        app(Analytics::class)->capture(42, 'anything');
        $this->flush();

        $this->assertTrue(true); // reaching here without an exception is the test
    }

    public function test_a_successful_reward_action_is_recorded(): void
    {
        $this->switchOn();
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']]), 'us.i.posthog.com/*' => Http::response([])]);
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/deposits', ['amount' => 5000])->assertCreated();
        $this->flush();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'posthog.com')
            && $r['event'] === 'topup_started'
            && $r['distinct_id'] === (string) $user->ID
            && $r['properties']['amount'] === 5000.0);
    }

    public function test_a_rejected_request_is_not_recorded(): void
    {
        $this->switchOn();
        Http::fake(['us.i.posthog.com/*' => Http::response([])]);
        $this->actingAsWordPressUser();

        $this->postJson('/api/deposits', ['amount' => 1])->assertStatus(422);
        $this->flush();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'posthog.com'));
    }

    public function test_a_confirmed_top_up_is_recorded_once(): void
    {
        $this->switchOn();
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay/abc']]),
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 500000, 'id' => 99]]),
            'us.i.posthog.com/*' => Http::response([]),
        ]);
        $user = $this->actingAsWordPressUser();
        $deposit = app(DepositService::class)->initialize($user, 5000, 'https://app.test/callback');

        app(DepositService::class)->confirm('paystack', $deposit->reference);
        app(DepositService::class)->confirm('paystack', $deposit->reference); // repeat: already settled
        $this->flush();

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'posthog.com') && $r['event'] === 'topup_completed');
        $this->assertCount(1, $sent);
    }
}
