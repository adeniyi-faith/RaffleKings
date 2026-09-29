<?php

namespace Tests\Feature\Engagement;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\ReferralMilestone;
use App\Models\Wallet;
use App\Services\Engagement\BadgeService;
use App\Services\Engagement\Perks;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class ReferralLadderTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private function friendOf(WpUser $referrer, string $login): WpUser
    {
        $friend = WpUser::create(['user_login' => $login, 'user_pass' => 'x', 'user_email' => "{$login}@example.com", 'display_name' => ucfirst($login)]);
        WpUserMeta::create(['user_id' => $friend->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);

        return $friend;
    }

    public function test_a_friends_first_purchase_pays_the_first_rung_once(): void
    {
        config(['engagement.referral_ladder' => [
            ['friends' => 1, 'points' => 200, 'free_spins' => 1, 'badge' => 'referred_1'],
            ['friends' => 2, 'points' => 500, 'free_spins' => 0, 'badge' => null],
        ]]);
        $this->createRaffle(['public_id' => 5]);
        $referrer = WpUser::create(['user_login' => 'boss', 'user_pass' => 'x', 'user_email' => 'boss@example.com']);
        $this->friendOf($referrer, 'idle'); // joined, never played
        $friend = $this->actingAsWordPressUser(['user_login' => 'tunde']);
        WpUserMeta::create(['user_id' => $friend->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);
        Wallet::create(['user_id' => $friend->ID, 'wallet_balance' => 1000, 'earnings_balance' => 0]);

        foreach (['ladder-buy-01', 'ladder-buy-02'] as $i => $key) {
            $this->postJson('/api/tickets/purchase', [
                'raffle_id' => 5, 'ticket_numbers' => [$i + 1], 'unit_price' => 100, 'submitted_amount' => 100,
                'funding_source' => 'wallet', 'idempotency_key' => $key,
            ])->assertCreated();
        }

        $this->assertSame(200, app(PointsService::class)->balance($referrer));
        $this->assertSame(1, app(Perks::class)->freeSpins($referrer->ID));
        $this->assertTrue(app(BadgeService::class)->has($referrer->ID, 'referred_1'));
        $this->assertSame(1, ReferralMilestone::query()->count());
    }

    public function test_the_overview_masks_friends_and_shows_the_next_rung(): void
    {
        $referrer = $this->actingAsWordPressUser();
        $friend = $this->friendOf($referrer, 'adebayo');
        $txn = RaffleTransaction::create(['user_id' => $friend->ID, 'claimed_amount' => 100, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet']);
        RaffleEntry::create(['user_id' => $friend->ID, 'raffle_id' => 9, 'ticket_number' => 1, 'txn_id' => $txn->id]);

        $this->getJson('/api/referrals/overview')->assertOk()
            ->assertJsonPath('friends_joined', 1)
            ->assertJsonPath('friends_playing', 1)
            ->assertJsonPath('friends.0.name', 'Ade****')
            ->assertJsonPath('friends.0.playing', true)
            ->assertJsonPath('next.friends', 5);

        $this->get('/referrals')->assertOk();
        $this->get('/referrals.php')->assertRedirect('/referrals');
    }
}
