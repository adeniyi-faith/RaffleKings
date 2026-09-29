<?php

namespace Tests\Feature\Engagement;

use App\Models\Wallet;
use App\Services\RaffleReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class FlashRafflesTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    public function test_a_flash_raffle_stops_selling_at_its_exact_time(): void
    {
        $raffle = $this->createRaffle(['public_id' => 7, 'max' => '20']);
        $raffle->update(['is_flash' => true, 'sales_end_at' => now()->addHour()]);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        $found = app(RaffleReadService::class)->find(7);
        $this->assertTrue($found['is_flash']);
        $this->assertSame($raffle->fresh()->sales_end_at->toIso8601String(), $found['ends_at']);
        $this->assertFalse($found['is_closed']);

        $this->travel(61)->minutes();

        $this->assertSame('ended', app(RaffleReadService::class)->find(7)['closed_reason']);
        $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 7, 'ticket_numbers' => [1], 'unit_price' => 100, 'submitted_amount' => 100,
            'funding_source' => 'wallet', 'idempotency_key' => 'flash-late-1',
        ])->assertStatus(409);
    }

    public function test_open_flash_raffles_come_first_when_closing_soon(): void
    {
        $this->createRaffle(['public_id' => 1, 'expiry' => now()->addDay()->toDateString()]);
        $flash = $this->createRaffle(['public_id' => 2]);
        $flash->update(['is_flash' => true, 'sales_end_at' => now()->addMinutes(30)]);

        $list = app(RaffleReadService::class)->listActive(['sort' => 'closing_soon'])['raffles'];

        $this->assertSame(2, $list[0]['id']);
        $this->get('/')->assertOk();
    }
}
