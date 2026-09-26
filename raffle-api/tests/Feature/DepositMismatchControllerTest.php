<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the admin resolution path for
 * Deposit::status = 'amount_mismatch' that had no UI or endpoint on
 * either system before this pass (see DepositMismatchService's
 * docblock).
 */
class DepositMismatchControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_paystack']);
    }

    private function actingAsAdministrator(): WpUser
    {
        $admin = $this->actingAsWordPressUser();

        WpUserMeta::create([
            'user_id' => $admin->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => serialize(['administrator' => true]),
        ]);

        return $admin;
    }

    private function mismatchedDeposit(): Deposit
    {
        $target = WpUser::create(['user_login' => 'depositor', 'user_pass' => 'x', 'user_email' => 'depositor@example.com']);

        return Deposit::create([
            'user_id' => $target->ID,
            'reference' => 'ref-mismatch-1',
            'gateway' => 'paystack',
            'amount' => 5000,
            'currency' => 'NGN',
            'status' => 'amount_mismatch',
            'failure_reason' => 'Expected 5000.00, gateway confirmed 4800.00.',
        ]);
    }

    public function test_a_non_administrator_cannot_reach_the_endpoint(): void
    {
        $this->actingAsWordPressUser();

        $this->getJson('/api/admin/deposits-mismatched')->assertStatus(403);
    }

    public function test_an_admin_can_list_mismatched_deposits(): void
    {
        $this->actingAsAdministrator();
        $deposit = $this->mismatchedDeposit();

        $response = $this->getJson('/api/admin/deposits-mismatched');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $deposit->id]);
    }

    public function test_crediting_uses_the_freshly_verified_gateway_amount_not_the_original_expectation(): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 480000, 'currency' => 'NGN', 'id' => 555],
            ]),
        ]);
        $this->actingAsAdministrator();
        $deposit = $this->mismatchedDeposit();

        $response = $this->postJson("/api/admin/deposits-mismatched/{$deposit->id}/credit");

        $response->assertOk();
        $response->assertJson(['status' => 'successful', 'amount' => '4800.00']);
        $this->assertEquals(4800, Wallet::where('user_id', $deposit->user_id)->value('wallet_balance'));
    }

    public function test_rejecting_a_mismatch_leaves_the_wallet_untouched(): void
    {
        $this->actingAsAdministrator();
        $deposit = $this->mismatchedDeposit();

        $response = $this->postJson("/api/admin/deposits-mismatched/{$deposit->id}/reject", ['reason' => 'Unrelated payment']);

        $response->assertOk();
        $response->assertJson(['status' => 'failed']);
        $this->assertNull(Wallet::where('user_id', $deposit->user_id)->first());
    }

    public function test_crediting_a_deposit_that_isnt_mismatched_returns_409(): void
    {
        $this->actingAsAdministrator();
        $deposit = $this->mismatchedDeposit();
        $deposit->update(['status' => 'successful']);

        $this->postJson("/api/admin/deposits-mismatched/{$deposit->id}/credit")->assertStatus(409);
    }
}
