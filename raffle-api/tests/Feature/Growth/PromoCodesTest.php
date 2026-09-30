<?php

namespace Tests\Feature\Growth;

use App\Filament\Resources\PromoCodeResource\Pages\ListPromoCodes;
use App\Models\Growth\CustomerSource;
use App\Models\Growth\PromoCode;
use App\Models\Growth\PromoRedemption;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\UserPoints;
use App\Models\Wallet;
use App\Services\Growth\PromoCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class PromoCodesTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.promo_codes' => true]);
        // ₦1,000 tickets: no bulk discount for 1 or 2 tickets.
        $this->createRaffle(['public_id' => 5, 'price' => '1000', 'max' => '100']);
    }

    private function code(array $attributes = []): PromoCode
    {
        return PromoCode::create($attributes + ['code' => 'save10', 'kind' => 'ticket_discount', 'percent_off' => 10, 'is_active' => true]);
    }

    private function buy(array $extra = [], int $ticket = 1)
    {
        return $this->postJson('/api/tickets/purchase', $extra + [
            'raffle_id' => 5,
            'ticket_numbers' => [$ticket],
            'unit_price' => 1000,
            'submitted_amount' => 900,
            'funding_source' => 'wallet',
            'idempotency_key' => 'promo-test-'.uniqid(),
            'promo_code' => 'save10',
        ]);
    }

    private function register(array $extra = [])
    {
        return $this->postJson('/api/auth/register', $extra + [
            'username' => 'newbie'.random_int(100, 999),
            'email' => uniqid().'@example.com',
            'password' => 'secret123',
            'accept_terms' => true,
        ]);
    }

    public function test_codes_are_stored_in_capitals_and_found_in_any_case(): void
    {
        $this->code();

        $this->assertSame('SAVE10', PromoCode::first()->code);
        $this->assertNotNull(app(PromoCodeService::class)->find(' Save10 '));
    }

    public function test_the_price_quote_shows_the_discount(): void
    {
        $this->code(['max_discount' => 50]);
        $this->actingAsWordPressUser();

        // 10% of ₦1,000 is ₦100, capped at ₦50.
        $this->getJson('/api/raffles/5/price-quote?quantity=1&promo_code=save10')
            ->assertOk()
            ->assertJson(['discounted' => 950, 'promo' => ['code' => 'SAVE10', 'savings' => 50]]);

        $this->getJson('/api/raffles/5/price-quote?quantity=1&promo_code=nope')
            ->assertJson(['discounted' => 1000, 'promo' => null, 'promo_error' => 'That promo code isn\'t valid or has ended.']);
    }

    public function test_checkout_charges_the_discounted_price_and_records_the_use(): void
    {
        $this->code();
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);

        $this->buy()->assertCreated();

        $this->assertEquals(4100, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
        $this->assertDatabaseHas('promo_redemptions', ['user_id' => $user->ID, 'context' => 'checkout', 'value' => 100]);

        // One use per customer by default.
        $this->buy(ticket: 2)->assertStatus(422)->assertJson(['message' => 'You have already used this promo code.']);
    }

    public function test_the_full_price_with_a_code_is_refused_so_nobody_is_overcharged(): void
    {
        $this->code();
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);

        $this->buy(['submitted_amount' => 1000])->assertStatus(422)
            ->assertJsonFragment(['message' => 'With your promo code the price is ₦900.00. Please review your order before paying.']);
        $this->assertSame(0, RaffleEntry::count());
    }

    public function test_limits_minimum_order_first_orders_and_end_dates_are_enforced(): void
    {
        $user = $this->actingAsWordPressUser();
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 50000, 'earnings_balance' => 0]);

        $this->code(['min_order' => 5000]);
        $this->buy()->assertStatus(422)->assertJson(['message' => 'This code needs an order of at least ₦5,000.']);

        PromoCode::query()->update(['min_order' => 0, 'ends_at' => now()->subMinute()]);
        $this->buy()->assertStatus(422)->assertJson(['message' => 'That promo code isn\'t valid or has ended.']);

        PromoCode::query()->update(['ends_at' => null, 'new_customers_only' => true]);
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => 5, 'ticket_number' => 50, 'txn_id' => 0]);
        $this->buy()->assertStatus(422)->assertJson(['message' => 'This code is for first orders only.']);

        PromoCode::query()->update(['new_customers_only' => false, 'max_uses' => 1]);
        PromoRedemption::create(['promo_code_id' => PromoCode::first()->id, 'user_id' => 999, 'context' => 'checkout']);
        $this->buy()->assertStatus(422)->assertJson(['message' => 'This promo code has been fully used.']);
    }

    public function test_switched_off_a_code_does_nothing(): void
    {
        config(['features.promo_codes' => false]);
        $this->code();
        $this->actingAsWordPressUser();

        $this->getJson('/api/raffles/5/price-quote?quantity=1&promo_code=save10')->assertJson(['discounted' => 1000, 'promo' => null]);
        $this->get('/register')->assertInertia(fn ($page) => $page->where('promoEnabled', false));
    }

    public function test_a_welcome_bonus_code_at_sign_up_credits_the_spending_wallet_and_tracks_the_customer(): void
    {
        $promo = $this->code(['code' => 'TOBI', 'kind' => 'welcome_bonus', 'bonus_amount' => 500, 'percent_off' => null]);

        $this->register(['promo_code' => 'tobi'])->assertCreated();

        $user = WpUser::query()->latest('ID')->first();
        // ₦300 normal welcome bonus + ₦500 from the code, all in the spending wallet.
        $this->assertEquals(800, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
        $this->assertEquals(0, Wallet::where('user_id', $user->ID)->value('earnings_balance'));
        $this->assertSame($promo->id, CustomerSource::find($user->ID)->promo_code_id);
        $this->assertSame(1, app(PromoCodeService::class)->stats($promo)['customers']);
    }

    public function test_welcome_points_and_a_wrong_code_at_sign_up(): void
    {
        $this->code(['code' => 'PTS', 'kind' => 'welcome_points', 'bonus_amount' => 250, 'percent_off' => null]);

        $this->register(['promo_code' => 'WRONG'])->assertStatus(422)->assertJsonValidationErrors('promo_code');
        $this->assertSame(0, WpUser::count());

        $this->register(['promo_code' => 'PTS'])->assertCreated();
        $this->assertSame(250, (int) UserPoints::query()->value('balance'));
    }

    public function test_a_discount_code_given_at_sign_up_is_filled_in_at_checkout(): void
    {
        $this->code();

        $this->register(['promo_code' => 'SAVE10'])->assertCreated();
        $user = WpUser::query()->latest('ID')->first();

        $this->assertSame('SAVE10', app(PromoCodeService::class)->savedCodeFor($user->ID));
    }

    public function test_staff_see_the_codes_with_their_results(): void
    {
        $this->actingAsAdministrator();
        $this->code(['campaign' => 'Christmas']);

        Livewire::test(ListPromoCodes::class)->assertOk()->assertSee('SAVE10')->assertSee('10% off tickets');

        config(['features.promo_codes' => false]);
        Livewire::test(ListPromoCodes::class)->assertSee('Switched OFF');
    }
}
