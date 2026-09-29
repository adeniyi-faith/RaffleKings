<?php

namespace Tests\Feature;

use App\Models\Wallet;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 49 (Phase 9): live updates through Pusher,
 * and a live update that fails must never break what triggered it.
 */
class PolishAndSpeedTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    public function test_a_purchase_still_succeeds_when_the_live_update_cannot_be_sent(): void
    {
        // A broadcaster that always fails, like Pusher with a wrong key or no network.
        $this->app->make(BroadcastManager::class)->extend('broken', fn () => new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new \RuntimeException('Pusher unreachable');
            }
        });
        config(['broadcasting.default' => 'broken', 'broadcasting.connections.broken' => ['driver' => 'broken']]);

        $this->createRaffle(['public_id' => 5, 'price' => '100', 'max' => '100']);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 5, 'ticket_numbers' => [3], 'unit_price' => 100, 'submitted_amount' => 100,
            'funding_source' => 'wallet', 'idempotency_key' => 'live-down-1',
        ])->assertCreated();
    }

    public function test_the_page_gets_only_the_public_pusher_key_when_live_updates_are_on(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'public-key-123',
            'broadcasting.connections.pusher.secret' => 'very-secret',
            'broadcasting.connections.pusher.options.cluster' => 'eu',
        ]);

        $this->get('/raffles')
            ->assertSee('window.__rkLive = {"key":"public-key-123","cluster":"eu"}', false)
            ->assertDontSee('very-secret');

        config(['broadcasting.default' => 'log']);
        $this->get('/raffles')->assertDontSee('window.__rkLive', false);
    }

    public function test_the_health_check_flags_pusher_switched_on_without_its_keys(): void
    {
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.secret' => null]);

        Artisan::call('app:health-check');
        $output = Artisan::output();

        $this->assertStringContainsString('Live updates (Pusher)', $output);
        $this->assertStringContainsString('missing', $output);
    }
}
