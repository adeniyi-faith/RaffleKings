<?php

namespace Tests\Feature;

use App\Models\Raffle;
use App\Services\TicketPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class TicketPriceQuoteControllerTest extends TestCase
{
    use CreatesRaffles, RefreshDatabase;

    private function makeRaffle(string $price): Raffle
    {
        return $this->createRaffle(['price' => $price]);
    }

    public function test_it_returns_the_server_computed_price_for_a_quantity(): void
    {
        $raffle = $this->makeRaffle('500');

        $response = $this->getJson("/api/raffles/{$raffle->public_id}/price-quote?quantity=3");

        $response->assertOk();
        $response->assertJson([
            'quantity' => 3,
            'unit_price' => 500.0,
            'original' => 1500.0,
            'discounted' => 975.0, // 500 * 3 * 0.65 (the 3-ticket tier)
            'savings' => 525.0,
        ]);
    }

    public function test_it_matches_ticket_pricing_service_exactly_so_display_and_charge_never_drift(): void
    {
        $raffle = $this->makeRaffle('1000');

        $response = $this->getJson("/api/raffles/{$raffle->public_id}/price-quote?quantity=10");

        $response->assertOk()->assertJson([
            'discounted' => app(TicketPricingService::class)->calculate(10, 1000.0),
        ]);
    }

    public function test_an_unknown_raffle_returns_404(): void
    {
        $this->getJson('/api/raffles/999999/price-quote?quantity=1')->assertNotFound();
    }

    public function test_quantity_is_required_and_must_be_a_positive_integer(): void
    {
        $raffle = $this->makeRaffle('500');

        $this->getJson("/api/raffles/{$raffle->public_id}/price-quote")->assertStatus(422);
        $this->getJson("/api/raffles/{$raffle->public_id}/price-quote?quantity=0")->assertStatus(422);
        $this->getJson("/api/raffles/{$raffle->public_id}/price-quote?quantity=-1")->assertStatus(422);
    }

    public function test_several_quantities_come_back_in_one_request_matching_the_single_quotes(): void
    {
        $raffle = $this->makeRaffle('500');

        $batch = $this->getJson("/api/raffles/{$raffle->public_id}/price-quotes?quantities[]=2&quantities[]=3&quantities[]=10")
            ->assertOk()->json('quotes');

        $this->assertSame([2, 3, 10], array_map('intval', array_keys($batch)));

        foreach ([2, 3, 10] as $qty) {
            $this->assertEquals(
                $this->getJson("/api/raffles/{$raffle->public_id}/price-quote?quantity={$qty}")->json(),
                $batch[$qty],
            );
        }
    }

    public function test_the_batch_needs_quantities_and_an_unknown_raffle_is_404(): void
    {
        $raffle = $this->makeRaffle('500');

        $this->getJson("/api/raffles/{$raffle->public_id}/price-quotes")->assertStatus(422);
        $this->getJson('/api/raffles/999999/price-quotes?quantities[]=1')->assertNotFound();
    }
}
