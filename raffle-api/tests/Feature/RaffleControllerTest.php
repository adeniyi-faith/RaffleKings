<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpPostMeta;
use App\Models\Legacy\WpUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaffleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeRaffle(array $meta = [], string $status = 'publish'): WpPost
    {
        $post = WpPost::create([
            'post_title' => 'iPhone 15 Pro Max Giveaway',
            'post_excerpt' => 'Win a brand new iPhone.',
            'post_type' => 'raffle',
            'post_status' => $status,
            'post_date' => now(),
        ]);

        $defaults = [
            'price' => '500',
            'max' => '10',
            'sold' => '0', // deliberately stale/wrong — the API must ignore this and derive it
            'grand_prize' => 'iPhone 15 Pro Max',
            'prize_list' => "2nd Prize: 50,000 Naira\n3rd Prize: 20,000 Naira",
            'expiry' => '2030-01-01',
            'is_sold_out' => '0',
        ];

        foreach (array_merge($defaults, $meta) as $key => $value) {
            WpPostMeta::create(['post_id' => $post->ID, 'meta_key' => $key, 'meta_value' => $value]);
        }

        return $post;
    }

    public function test_it_lists_published_raffles_with_derived_ticket_counts(): void
    {
        $raffle = $this->makeRaffle();

        $buyer = WpUser::create([
            'user_login' => 'buyer', 'user_pass' => 'x', 'user_email' => 'b@example.com', 'display_name' => 'Buyer',
        ]);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => $raffle->ID, 'ticket_number' => 1, 'txn_id' => 1]);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => $raffle->ID, 'ticket_number' => 2, 'txn_id' => 1]);

        $response = $this->getJson('/api/raffles');

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $raffle->ID,
            'title' => 'iPhone 15 Pro Max Giveaway',
            'price' => 500.0,
            'max_tickets' => 10,
            'sold_tickets' => 2, // derived from real entries, NOT the stale 'sold' meta of '0'
            'remaining_tickets' => 8,
            'grand_prize' => 'iPhone 15 Pro Max',
            'is_closed' => false,
        ]);
    }

    public function test_a_raffle_that_is_actually_sold_out_is_reported_closed_even_if_the_manual_flag_says_otherwise(): void
    {
        $raffle = $this->makeRaffle(['max' => '2', 'is_sold_out' => '0']);

        $buyer = WpUser::create([
            'user_login' => 'buyer2', 'user_pass' => 'x', 'user_email' => 'b2@example.com', 'display_name' => 'Buyer',
        ]);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => $raffle->ID, 'ticket_number' => 1, 'txn_id' => 1]);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => $raffle->ID, 'ticket_number' => 2, 'txn_id' => 1]);

        $response = $this->getJson("/api/raffles/{$raffle->ID}");

        $response->assertOk();
        $response->assertJson(['remaining_tickets' => 0, 'is_closed' => true]);
    }

    public function test_a_manually_closed_raffle_is_reported_closed_even_with_tickets_remaining(): void
    {
        $raffle = $this->makeRaffle(['max' => '100', 'is_sold_out' => '1']);

        $response = $this->getJson("/api/raffles/{$raffle->ID}");

        $response->assertOk();
        $response->assertJson(['remaining_tickets' => 100, 'is_closed' => true]);
    }

    public function test_a_draft_raffle_is_not_listed_or_directly_fetchable(): void
    {
        $raffle = $this->makeRaffle(status: 'draft');

        $this->getJson('/api/raffles')->assertJsonMissing(['id' => $raffle->ID]);
        $this->getJson("/api/raffles/{$raffle->ID}")->assertNotFound();
    }

    public function test_an_unknown_raffle_id_returns_404(): void
    {
        $this->getJson('/api/raffles/999999')->assertNotFound();
    }
}
