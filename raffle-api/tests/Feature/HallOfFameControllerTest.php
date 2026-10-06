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

    public function test_the_list_is_kept_briefly_but_hiding_or_adding_a_winner_shows_at_once(): void
    {
        $user = WpUser::create(['user_login' => 'hofcache', 'user_pass' => 'x', 'user_email' => 'hofc@example.com']);
        $win = fn (int $ticket) => RaffleWinner::create([
            'raffle_id' => 901, 'user_id' => $user->ID, 'ticket_number' => $ticket,
            'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 1000,
            'is_credited' => false, 'is_visible' => true,
        ]);

        $first = $win(1);
        $this->getJson('/api/hall-of-fame')->assertJsonCount(1, 'recent');

        // A change that bypasses the model is not seen until the copy expires...
        \DB::table($first->getTable())->where('id', $first->id)->update(['is_visible' => false]);
        $this->getJson('/api/hall-of-fame')->assertJsonCount(1, 'recent');

        // ...but a new winner, or hiding one through the admin, shows straight away.
        $win(2);
        $this->getJson('/api/hall-of-fame')->assertJsonCount(1, 'recent')->assertJsonFragment(['ticket' => 2]);

        RaffleWinner::query()->where('ticket_number', 2)->first()->update(['is_visible' => false]);
        $this->getJson('/api/hall-of-fame')->assertJsonCount(0, 'recent');
    }
}
