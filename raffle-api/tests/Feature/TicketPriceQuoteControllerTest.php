<?php

namespace Tests\Feature;

use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpPostMeta;
use App\Services\TicketPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketPriceQuoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeRaffle(string $price): WpPost
    {
        $post = WpPost::create([
            'post_title' => 'iPhone 15 Pro Max Giveaway',
            'post_type' => 'raffle',
            'post_status' => 'publish',
            'post_date' => now(),
        ]);

        WpPostMeta::create(['post_id' => $post->ID, 'meta_key' => 'price', 'meta_value' => $price]);

        return $post;
    }

    public function test_it_returns_the_server_computed_price_for_a_quantity(): void
    {
        $raffle = $this->makeRaffle('500');

        $response = $this->getJson("/api/raffles/{$raffle->ID}/price-quote?quantity=3");

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

        $response = $this->getJson("/api/raffles/{$raffle->ID}/price-quote?quantity=10");

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

        $this->getJson("/api/raffles/{$raffle->ID}/price-quote")->assertStatus(422);
        $this->getJson("/api/raffles/{$raffle->ID}/price-quote?quantity=0")->assertStatus(422);
        $this->getJson("/api/raffles/{$raffle->ID}/price-quote?quantity=-1")->assertStatus(422);
    }
}
