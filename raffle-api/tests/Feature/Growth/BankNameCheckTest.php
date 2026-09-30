<?php

namespace Tests\Feature\Growth;

use App\Models\BankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

class BankNameCheckTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private bool $paystackDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_abc', 'features.bank_name_check' => true]);

        Http::fake([
            '*' => fn () => $this->paystackDown ? Http::response(['status' => false, 'message' => 'Server error'], 500) : null,
            'api.paystack.co/bank/resolve*' => function ($request) {
                return str_contains($request->url(), 'account_number=0123456789')
                    ? Http::response(['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => 'JANE ADA DOE']])
                    : Http::response(['status' => false, 'message' => 'Could not resolve account name. Check parameters or try again.'], 422);
            },
            'api.paystack.co/bank*' => Http::response(['status' => true, 'data' => [
                ['name' => 'Guaranty Trust Bank', 'code' => '058', 'active' => true],
                ['name' => 'Access Bank', 'code' => '044', 'active' => true],
                ['name' => 'Closed Bank', 'code' => '999', 'active' => false],
            ]]),
        ]);
    }

    public function test_off_the_old_form_still_works_and_the_new_routes_are_hidden(): void
    {
        config(['features.bank_name_check' => false]);
        $this->actingAsWordPressUser();

        $this->getJson('/api/banks')->assertNotFound();
        $this->postJson('/api/bank-accounts/look-up', ['bank_code' => '058', 'account_number' => '0123456789'])->assertNotFound();
        $this->getJson('/api/bank-accounts')->assertJson(['name_check' => false]);

        $this->postJson('/api/bank-accounts', ['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Jane Doe'])
            ->assertCreated();
        $this->assertNull(BankAccount::first()->name_verified_at);
    }

    public function test_the_bank_list_is_sorted_and_leaves_out_closed_banks(): void
    {
        $this->actingAsWordPressUser();

        $this->getJson('/api/banks')->assertOk()->assertExactJson(['banks' => [
            ['code' => '044', 'name' => 'Access Bank'],
            ['code' => '058', 'name' => 'Guaranty Trust Bank'],
        ]]);
    }

    public function test_look_up_shows_the_banks_name_for_the_account(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/bank-accounts/look-up', ['bank_code' => '058', 'account_number' => '0123456789'])
            ->assertOk()
            ->assertJson(['account_name' => 'JANE ADA DOE', 'bank_name' => 'Guaranty Trust Bank']);

        $this->postJson('/api/bank-accounts/look-up', ['bank_code' => '058', 'account_number' => '1111111111'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Guaranty Trust Bank has no account 1111111111. Check the number and the bank.']);
    }

    public function test_saving_uses_the_banks_name_not_what_the_customer_typed(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/bank-accounts', ['bank_code' => '058', 'account_number' => '0123456789', 'account_name' => 'SOMEONE ELSE'])
            ->assertCreated()
            ->assertJson(['bank_name' => 'Guaranty Trust Bank', 'account_name' => 'JANE ADA DOE', 'bank_code' => '058'])
            ->assertJsonMissingPath('paystack_recipient_code');

        $this->assertTrue(BankAccount::first()->isVerified());
    }

    public function test_a_wrong_number_or_bank_is_not_saved(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/bank-accounts', ['bank_code' => '058', 'account_number' => '1111111111'])->assertStatus(422);
        $this->postJson('/api/bank-accounts', ['bank_code' => '000', 'account_number' => '0123456789'])
            ->assertStatus(422)->assertJson(['message' => 'Choose your bank from the list.']);
        $this->postJson('/api/bank-accounts', ['account_number' => '0123456789'])->assertStatus(422);

        $this->assertSame(0, BankAccount::count());
    }

    public function test_when_paystack_is_down_nothing_unchecked_is_saved(): void
    {
        $this->paystackDown = true;
        $this->actingAsWordPressUser();

        $this->postJson('/api/bank-accounts', ['bank_code' => '058', 'account_number' => '0123456789'])
            ->assertStatus(503)
            ->assertJson(['message' => 'We can\'t check bank accounts right now. Please try again in a few minutes.']);

        $this->assertSame(0, BankAccount::count());
    }

    public function test_the_same_account_cannot_be_saved_twice(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/bank-accounts', ['bank_code' => '058', 'account_number' => '0123456789'])->assertCreated();
        $this->postJson('/api/bank-accounts', ['bank_code' => '058', 'account_number' => '0123456789'])
            ->assertStatus(409)->assertJson(['message' => 'This account is already saved.']);
    }
}
