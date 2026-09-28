<?php

namespace Tests\Feature;

use App\Settings\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * Maintenance mode (Settings → On / off) and Turnstile on log-in /
 * forgot password (Settings → Security).
 */
class MaintenanceModeTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    public function test_customers_see_the_maintenance_page_and_the_api_says_back_soon(): void
    {
        config(['site.maintenance.enabled' => true, 'site.maintenance.message' => 'Upgrading the draw engine.']);

        $this->get('/')->assertStatus(503)
            ->assertInertia(fn ($page) => $page->component('Maintenance')->where('message', 'Upgrading the draw engine.'))
            ->assertHeader('Retry-After');
        $this->getJson('/api/raffles')->assertStatus(503)->assertJson(['maintenance' => true]);
    }

    public function test_the_admin_payment_confirmations_and_log_in_keep_working(): void
    {
        config(['site.maintenance.enabled' => true]);

        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
        // A payment provider's confirmation still reaches the app (it answers on its own terms, not with the maintenance page).
        $this->postJson('/api/webhooks/paystack', [])->assertJsonMissing(['maintenance' => true]);
    }

    public function test_staff_can_use_the_site_during_maintenance_and_see_a_reminder(): void
    {
        config(['site.maintenance.enabled' => true]);
        $this->actingAsAdministrator();

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->where('site.maintenance.active', true)
            ->where('site.maintenance.is_staff', true));
        $this->get('/admin')->assertOk()->assertSee('Maintenance mode is ON');
    }

    public function test_scheduled_maintenance_starts_and_ends_by_itself_and_warns_first(): void
    {
        config(['site.maintenance' => [
            'enabled' => false,
            'starts_at' => now()->addHours(3)->toDateTimeString(),
            'back_at' => now()->addHours(4)->toDateTimeString(),
            'warn_hours' => 12,
            'message' => 'Planned work.',
        ]]);

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->whereNot('site.maintenance.upcoming', null));

        $this->travel(181)->minutes();
        $this->get('/')->assertStatus(503);

        $this->travel(2)->hours();
        $this->get('/')->assertOk();
    }

    public function test_log_in_asks_for_the_bot_check_when_switched_on(): void
    {
        config(['services.turnstile' => ['site_key' => 'site', 'secret_key' => 'secret', 'forms' => ['register' => true, 'login' => true, 'forgot_password' => true]]]);

        $this->postJson('/api/auth/login', ['username' => 'someone', 'password' => 'whatever'])
            ->assertStatus(422)->assertJsonValidationErrors('turnstile_token');
        $this->postJson('/api/auth/forgot-password', ['email' => 'a@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('turnstile_token');

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']])]);
        $this->postJson('/api/auth/forgot-password', ['email' => 'a@example.com', 'turnstile_token' => 'bot'])
            ->assertStatus(422);
    }

    public function test_the_turnstile_check_button_tells_a_bad_secret_from_a_good_one(): void
    {
        Http::fakeSequence('challenges.cloudflare.com/*')
            ->push(['success' => false, 'error-codes' => ['invalid-input-secret']])
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']]);
        $tester = app(ConnectionTester::class);

        $this->assertFalse($tester->turnstile('bad', 'site')[0]);
        $this->assertTrue($tester->turnstile('good', 'site')[0]);
        $this->assertFalse($tester->turnstile('good', null)[0]);
    }
}
