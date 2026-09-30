<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\DepositMismatchService;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * With Paystack's "customer pays the fees" on, a ₦2,000 top-up is charged
 * ₦2,030.46. It used to be flagged as a wrong amount, and crediting it
 * gave the customer Paystack's fee too.
 */
class PaystackFeesTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private array $verify = ['status' => 'success', 'amount' => 203046, 'requested_amount' => 200000, 'currency' => 'NGN', 'id' => 1];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_x']);
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://paystack.test/pay']]),
            'api.paystack.co/transaction/verify/*' => fn () => Http::response(['status' => true, 'data' => $this->verify]),
        ]);
    }

    public function test_a_fee_paid_by_the_customer_is_not_a_mismatch_and_only_the_top_up_is_credited(): void
    {
        $user = $this->actingAsWordPressUser();
        $deposit = app(DepositService::class)->initialize($user, 2000, 'https://app.test/api/deposits/callback');

        app(DepositService::class)->confirm('paystack', $deposit->reference);

        $this->assertSame('successful', $deposit->fresh()->status);
        $this->assertEquals(2000, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_a_really_wrong_amount_is_still_flagged(): void
    {
        $this->verify = ['status' => 'success', 'amount' => 150000, 'requested_amount' => 150000, 'currency' => 'NGN', 'id' => 1];
        $user = $this->actingAsWordPressUser();
        $deposit = app(DepositService::class)->initialize($user, 2000, 'https://app.test/api/deposits/callback');

        app(DepositService::class)->confirm('paystack', $deposit->reference);

        $this->assertSame('amount_mismatch', $deposit->fresh()->status);
    }

    public function test_crediting_an_old_flagged_top_up_never_includes_the_fee(): void
    {
        $admin = $this->actingAsAdministrator();
        $customer = \App\Models\Legacy\WpUser::create(['user_login' => 'c'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'C']);
        $deposit = Deposit::create(['user_id' => $customer->ID, 'amount' => 2000, 'reference' => 'dep_old', 'gateway' => 'paystack', 'status' => 'amount_mismatch']);

        app(DepositMismatchService::class)->creditConfirmedAmount($admin, $deposit);

        $this->assertEquals(2000, Wallet::where('user_id', $customer->ID)->value('wallet_balance'));
    }
}
