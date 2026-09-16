<?php

namespace Tests\Unit;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaffleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sold_and_remaining_tickets_are_derived_from_real_entries(): void
    {
        $raffle = Raffle::create([
            'legacy_post_id' => 42,
            'title' => 'Test raffle',
            'price' => 100,
            'max_tickets' => 5,
            'status' => 'published',
        ]);

        $buyer = WpUser::create(['user_login' => 'buyer', 'user_pass' => 'x', 'user_email' => 'b@example.com']);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => 42, 'ticket_number' => 1, 'txn_id' => 1]);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => 42, 'ticket_number' => 2, 'txn_id' => 1]);

        $this->assertSame(2, $raffle->soldTickets());
        $this->assertSame(3, $raffle->remainingTickets());
        $this->assertFalse($raffle->isClosed());
    }

    public function test_a_raffle_is_closed_once_every_ticket_is_sold(): void
    {
        $raffle = Raffle::create([
            'legacy_post_id' => 43, 'title' => 'Sold out raffle', 'price' => 100, 'max_tickets' => 1, 'status' => 'published',
        ]);

        $buyer = WpUser::create(['user_login' => 'buyer2', 'user_pass' => 'x', 'user_email' => 'b2@example.com']);
        RaffleEntry::create(['user_id' => $buyer->ID, 'raffle_id' => 43, 'ticket_number' => 1, 'txn_id' => 1]);

        $this->assertTrue($raffle->isClosed());
    }

    public function test_a_draft_raffle_is_closed_even_with_tickets_remaining(): void
    {
        $raffle = Raffle::create([
            'legacy_post_id' => 44, 'title' => 'Draft raffle', 'price' => 100, 'max_tickets' => 100, 'status' => 'draft',
        ]);

        $this->assertTrue($raffle->isClosed());
    }
}
