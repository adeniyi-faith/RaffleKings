<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\ReferralCommission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 35b — legacy never recorded which
 * deposit triggered a referral commission, only that one was paid
 * (rk_referral_commission_paid usermeta on the referee). These tests
 * prove the backfill correctly reconstructs a referral_commissions row
 * from that flag + the referee's earliest verified deposit, is
 * idempotent, and safely skips what it can't reconstruct.
 */
class ReconcileReferralCommissionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $login): WpUser
    {
        return WpUser::create([
            'user_login' => $login,
            'user_pass' => 'irrelevant-for-this-test',
            'user_email' => "{$login}@example.com",
            'display_name' => ucfirst($login),
        ]);
    }

    public function test_a_legacy_paid_commission_is_reconstructed_from_the_referees_first_deposit(): void
    {
        $referrer = $this->makeUser('referrer');
        $referee = $this->makeUser('referee');

        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);
        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'rk_referral_commission_paid', 'meta_value' => '1']);

        RaffleTransaction::create([
            'user_id' => $referee->ID, 'claimed_amount' => 2000, 'status' => 'verified_final',
            'type' => 'wallet_deposit', 'created_at' => now()->subDays(5),
        ]);
        // A later, larger deposit — must NOT be the one used (earliest wins).
        RaffleTransaction::create([
            'user_id' => $referee->ID, 'claimed_amount' => 9000, 'status' => 'verified_final',
            'type' => 'wallet_deposit', 'created_at' => now()->subDay(),
        ]);

        Artisan::call('legacy:reconcile-referrals');

        $commission = ReferralCommission::where('referee_user_id', $referee->ID)->first();
        $this->assertNotNull($commission);
        $this->assertSame($referrer->ID, $commission->referrer_user_id);
        $this->assertEquals(2000, $commission->deposit_amount);
        $this->assertEquals(1000, $commission->commission_amount); // 50% of 2000
        $this->assertEquals(0.5, (float) $commission->commission_rate);
    }

    public function test_it_is_idempotent(): void
    {
        $referrer = $this->makeUser('referrer2');
        $referee = $this->makeUser('referee2');

        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);
        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'rk_referral_commission_paid', 'meta_value' => '1']);
        RaffleTransaction::create([
            'user_id' => $referee->ID, 'claimed_amount' => 1000, 'status' => 'verified_final',
            'type' => 'wallet_deposit', 'created_at' => now(),
        ]);

        Artisan::call('legacy:reconcile-referrals');
        Artisan::call('legacy:reconcile-referrals');

        $this->assertSame(1, ReferralCommission::where('referee_user_id', $referee->ID)->count());
    }

    public function test_a_referee_with_no_matching_deposit_is_skipped_not_guessed(): void
    {
        $referrer = $this->makeUser('referrer3');
        $referee = $this->makeUser('referee3');

        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);
        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'rk_referral_commission_paid', 'meta_value' => '1']);
        // No RaffleTransaction at all for this referee.

        Artisan::call('legacy:reconcile-referrals');

        $this->assertSame(0, ReferralCommission::where('referee_user_id', $referee->ID)->count());
    }

    public function test_a_referee_that_already_has_a_real_row_is_left_untouched(): void
    {
        $referrer = $this->makeUser('referrer4');
        $referee = $this->makeUser('referee4');

        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);
        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'rk_referral_commission_paid', 'meta_value' => '1']);
        RaffleTransaction::create([
            'user_id' => $referee->ID, 'claimed_amount' => 5000, 'status' => 'verified_final',
            'type' => 'wallet_deposit', 'created_at' => now(),
        ]);

        $existing = ReferralCommission::create([
            'referrer_user_id' => $referrer->ID,
            'referee_user_id' => $referee->ID,
            'deposit_amount' => 123.45,
            'commission_amount' => 61.73,
            'commission_rate' => 0.5,
        ]);

        Artisan::call('legacy:reconcile-referrals');

        $this->assertSame(1, ReferralCommission::where('referee_user_id', $referee->ID)->count());
        $this->assertEquals(123.45, $existing->fresh()->deposit_amount);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $referrer = $this->makeUser('referrer5');
        $referee = $this->makeUser('referee5');

        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'referred_by', 'meta_value' => (string) $referrer->ID]);
        WpUserMeta::create(['user_id' => $referee->ID, 'meta_key' => 'rk_referral_commission_paid', 'meta_value' => '1']);
        RaffleTransaction::create([
            'user_id' => $referee->ID, 'claimed_amount' => 1000, 'status' => 'verified_final',
            'type' => 'wallet_deposit', 'created_at' => now(),
        ]);

        Artisan::call('legacy:reconcile-referrals', ['--dry-run' => true]);

        $this->assertSame(0, ReferralCommission::count());
    }
}
