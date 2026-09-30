<?php

namespace Tests\Feature;

use App\Filament\Resources\RaffleResource\Pages\EditRaffle;
use App\Models\Legacy\RaffleEntry;
use App\Models\Raffle;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * Admins can limit how many tickets one order holds: for the whole site, or
 * for a single raffle. It is checked on the page AND when paying, so it
 * cannot be skipped by editing a link or calling the API directly.
 */
class OrderSizeLimitTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.max_tickets_per_order' => 0]);
    }

    private function raffle(array $extra = []): Raffle
    {
        $raffle = $this->createRaffle(['public_id' => 31, 'price' => '100', 'max' => '200']);
        if ($extra) {
            $raffle->update($extra);
        }

        return $raffle->fresh();
    }

    public function test_there_is_no_limit_until_an_admin_sets_one(): void
    {
        $this->assertNull($this->raffle()->orderLimit());
        $this->getJson('/api/raffles/31')->assertOk()->assertJsonPath('max_per_order', null);
    }

    public function test_the_site_wide_limit_applies_to_every_raffle(): void
    {
        config(['pricing.max_tickets_per_order' => 20]);

        $this->assertSame(20, $this->raffle()->orderLimit());
        $this->getJson('/api/raffles/31')->assertJsonPath('max_per_order', 20);
    }

    public function test_a_raffles_own_limit_beats_the_site_wide_one_either_way(): void
    {
        config(['pricing.max_tickets_per_order' => 20]);

        $raffle = $this->raffle(['max_per_order' => 5]);
        $this->assertSame(5, $raffle->orderLimit());

        $raffle->update(['max_per_order' => 50]);
        $this->assertSame(50, $raffle->fresh()->orderLimit());
    }

    public function test_the_price_quote_refuses_an_order_over_the_limit(): void
    {
        $this->raffle(['max_per_order' => 10]);

        $this->getJson('/api/raffles/31/price-quote?quantity=10')->assertOk();
        $this->getJson('/api/raffles/31/price-quote?quantity=11')->assertStatus(422)->assertJsonPath('max_per_order', 10);
    }

    public function test_the_number_picker_brings_an_oversized_link_down_to_the_limit(): void
    {
        $this->raffle(['max_per_order' => 10]);

        $this->get('/raffles/31/numbers?qty=50')->assertOk()->assertInertia(fn ($page) => $page->where('qty', 10));
        $this->get('/raffles/31/numbers?qty=7')->assertInertia(fn ($page) => $page->where('qty', 7));
    }

    public function test_checkout_refuses_an_order_over_the_limit_for_guests_and_customers(): void
    {
        $this->raffle(['max_per_order' => 3]);
        $numbers = '1,2,3,4';

        $this->get("/checkout?raffle_id=31&qty=4&numbers={$numbers}")->assertStatus(422);

        $this->actingAsWordPressUser();
        $this->get("/checkout?raffle_id=31&qty=4&numbers={$numbers}")->assertStatus(422);
        $this->get('/checkout?raffle_id=31&qty=3&numbers=1,2,3')->assertOk();
    }

    public function test_paying_directly_for_more_than_the_limit_is_refused_and_nothing_is_charged(): void
    {
        $this->raffle(['max_per_order' => 3]);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 100000, 'earnings_balance' => 0]);

        $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 31, 'ticket_numbers' => [1, 2, 3, 4], 'unit_price' => 100, 'submitted_amount' => 360,
            'funding_source' => 'wallet', 'idempotency_key' => 'key-'.uniqid(),
        ])->assertStatus(422)->assertJsonPath('message', 'You can buy at most 3 tickets in one order for this raffle. No money has been taken.');

        $this->assertEquals(100000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
        $this->assertSame(0, RaffleEntry::where('raffle_id', 31)->count());
    }

    public function test_an_order_at_the_limit_still_goes_through(): void
    {
        $this->raffle(['max_per_order' => 2]);
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 100000, 'earnings_balance' => 0]);

        $this->postJson('/api/tickets/purchase', [
            'raffle_id' => 31, 'ticket_numbers' => [1, 2], 'unit_price' => 100, 'submitted_amount' => 180,
            'funding_source' => 'wallet', 'idempotency_key' => 'key-'.uniqid(),
        ])->assertCreated();
    }

    public function test_with_no_limit_big_orders_work_as_before(): void
    {
        $this->raffle();

        $this->get('/raffles/31/numbers?qty=60')->assertOk()->assertInertia(fn ($page) => $page->where('qty', 60));
        $this->getJson('/api/raffles/31/price-quote?quantity=60')->assertOk();
    }

    public function test_the_admin_can_set_a_raffles_limit_and_the_site_limit_is_a_setting(): void
    {
        $this->actingAsAdministrator();
        $raffle = $this->raffle();

        Livewire::test(EditRaffle::class, ['record' => $raffle->getKey()])->assertFormFieldExists('max_per_order');

        $keys = collect(\App\Settings\SettingsRegistry::tabs())->flatMap(fn ($t) => collect($t['sections'])->flatMap(fn ($s) => collect($s['settings'])->map->key))->all();
        $this->assertContains('pricing.max_tickets_per_order', $keys);
    }
}
