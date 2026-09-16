<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HallOfFameControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_visible_winners_are_returned(): void
    {
        $raffle = Raffle::create(['legacy_post_id' => 900, 'title' => 'HoF Raffle', 'price' => 100, 'max_tickets' => 10, 'status' => 'published']);
        $user = WpUser::create(['user_login' => 'hofwinner', 'user_pass' => 'x', 'user_email' => 'hof@example.com', 'display_name' => 'Hof Winner']);

        RaffleWinner::create([
            'raffle_id' => 900, 'user_id' => $user->ID, 'ticket_number' => 42,
            'prize_name' => 'Cash Prize', 'prize_rank' => 1, 'prize_cash_value' => 50000,
            'is_credited' => false, 'is_visible' => true,
        ]);
        RaffleWinner::create([
            'raffle_id' => 900, 'user_id' => $user->ID, 'ticket_number' => 43,
            'prize_name' => 'Hidden Prize', 'prize_rank' => 2, 'prize_cash_value' => 10,
            'is_credited' => false, 'is_visible' => false,
        ]);

        $response = $this->getJson('/api/hall-of-fame')->assertOk();

        $response->assertJsonCount(1, 'recent');
        $response->assertJsonFragment(['ticket' => 42, 'raffle_native_id' => $raffle->id]);
        $response->assertJsonMissing(['ticket' => 43]);
    }

    public function test_featured_winners_are_the_top_five_by_prize_amount(): void
    {
        $user = WpUser::create(['user_login' => 'hofwinner2', 'user_pass' => 'x', 'user_email' => 'hof2@example.com']);

        foreach (range(1, 7) as $i) {
            RaffleWinner::create([
                'raffle_id' => 900, 'user_id' => $user->ID, 'ticket_number' => $i,
                'prize_name' => 'Prize', 'prize_rank' => $i, 'prize_cash_value' => $i * 1000,
                'is_credited' => false, 'is_visible' => true,
            ]);
        }

        $response = $this->getJson('/api/hall-of-fame')->assertOk();
        $data = $response->json();

        $this->assertCount(5, $data['featured']);
        $this->assertEquals(7000, $data['featured'][0]['prize_amount']);
        $this->assertCount(7, $data['recent']);
    }
}
