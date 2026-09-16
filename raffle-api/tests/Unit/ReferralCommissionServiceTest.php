<?php

namespace Tests\Unit;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\ReferralCommission;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\ReferralCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralCommissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReferralCommissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReferralCommissionService::class);
    }

    private function makeReferredUser(WpUser $referrer): WpUser
    {
        $referee = WpUser::create(['user_login' => 'referee'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);

        return $referee;
    }

    private function makeUser(string $login = 'referrer'): WpUser
    {
        return WpUser::create(['user_login' => $login.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
    }

    public function test_it_pays_50_percent_of_the_first_deposit_by_default(): void
    {
        $referrer = $this->makeUser();
        $referee = $this->makeReferredUser($referrer);

        $commission = $this->service->payCommissionForFirstDeposit($referee, 1000, depositTransactionId: 99);

        $this->assertNotNull($commission);
        $this->assertEquals(500, $commission->commission_amount);
        $this->assertEquals(0.5, $commission->commission_rate);
        $this->assertEquals(500, Wallet::where('user_id', $referrer->ID)->value('earnings_balance'));
    }

    public function test_the_commission_rate_is_configurable(): void
    {
        config(['referrals.commission_rate' => 0.25]);
        $referrer = $this->makeUser();
        $referee = $this->makeReferredUser($referrer);

        $commission = $this->service->payCommissionForFirstDeposit($referee, 1000);

        $this->assertEquals(250, $commission->commission_amount);
    }

    public function test_it_records_a_matching_ledger_entry(): void
    {
        $referrer = $this->makeUser();
        $referee = $this->makeReferredUser($referrer);

        $this->service->payCommissionForFirstDeposit($referee, 1000, depositTransactionId: 42);

        $entry = WalletLedgerEntry::where('user_id', $referrer->ID)->first();
        $this->assertSame('credit', $entry->direction);
        $this->assertSame('earnings', $entry->balance_type);
        $this->assertEquals(500, $entry->amount);
        $this->assertSame(42, $entry->reference_id);
    }

    public function test_a_user_with_no_referrer_earns_nothing(): void
    {
        $referee = $this->makeUser('lone');

        $result = $this->service->payCommissionForFirstDeposit($referee, 1000);

        $this->assertNull($result);
        $this->assertSame(0, ReferralCommission::count());
    }

    public function test_a_second_deposit_does_not_pay_a_second_commission(): void
    {
        $referrer = $this->makeUser();
        $referee = $this->makeReferredUser($referrer);

        $this->service->payCommissionForFirstDeposit($referee, 1000);
        $second = $this->service->payCommissionForFirstDeposit($referee, 2000);

        $this->assertNull($second);
        $this->assertSame(1, ReferralCommission::count());
        $this->assertEquals(500, Wallet::where('user_id', $referrer->ID)->value('earnings_balance'));
    }

    public function test_self_referral_is_refused(): void
    {
        $user = $this->makeUser();
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $user->ID]);

        $result = $this->service->payCommissionForFirstDeposit($user, 1000);

        $this->assertNull($result);
    }

    public function test_stats_correctly_separates_paid_from_pending_referrals(): void
    {
        $referrer = $this->makeUser();
        $paidReferee = $this->makeReferredUser($referrer);
        $pendingReferee = $this->makeReferredUser($referrer);

        $this->service->payCommissionForFirstDeposit($paidReferee, 1000);

        $stats = $this->service->stats($referrer);

        $this->assertSame(2, $stats['referral_count']);
        $this->assertSame(1, $stats['paid_count']);
        $this->assertSame(1, $stats['pending_count']);
        $this->assertEquals(500, $stats['total_earned']);
    }
}
