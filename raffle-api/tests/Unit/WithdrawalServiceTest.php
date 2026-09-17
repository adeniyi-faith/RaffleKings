<?php

namespace Tests\Unit;

use App\Exceptions\BankAccountNotFoundException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MinimumWithdrawalNotMetException;
use App\Exceptions\VerificationFeeRequiredException;
use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Notifications\WithdrawalProcessed;
use App\Notifications\WithdrawalRequestSubmittedAdminAlert;
use App\Services\WalletLedgerService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WithdrawalServiceTest extends TestCase
{
    use RefreshDatabase;

    private WithdrawalService $withdrawals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withdrawals = app(WithdrawalService::class);
    }

    private function makeVerifiedUser(float $earnings = 10000): array
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => $earnings]);
        // A user with >= the threshold in lifetime deposits skips the verification fee.
        app(WalletLedgerService::class)->recordCredit($user->ID, 'wallet', 5000, 'deposit');
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Test User', 'is_primary' => true]);

        return [$user, $account];
    }

    public function test_requirements_reports_no_fee_for_a_verified_user(): void
    {
        [$user] = $this->makeVerifiedUser();

        $requirements = $this->withdrawals->requirements($user);

        $this->assertFalse($requirements['requires_verification_fee']);
        $this->assertEquals(0.0, $requirements['verification_fee']);
    }

    public function test_requirements_reports_the_fee_for_a_new_user(): void
    {
        $user = WpUser::create(['user_login' => 'new'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);

        $requirements = $this->withdrawals->requirements($user);

        $this->assertTrue($requirements['requires_verification_fee']);
        $this->assertEquals(1000, $requirements['verification_fee']);
    }

    public function test_a_verified_user_can_withdraw_without_a_fee(): void
    {
        [$user, $account] = $this->makeVerifiedUser(earnings: 10000);

        Notification::fake();

        $withdrawal = $this->withdrawals->request($user, 3000, $account->id);

        $this->assertEquals(0, $withdrawal->fee_amount);
        $this->assertEquals(3000, $withdrawal->amount_to_send);
        $this->assertEquals(7000, Wallet::where('user_id', $user->ID)->value('earnings_balance'));

        Notification::assertSentTo(new AnonymousNotifiable, WithdrawalRequestSubmittedAdminAlert::class);
    }

    public function test_below_the_minimum_is_refused(): void
    {
        [$user, $account] = $this->makeVerifiedUser();

        $this->expectException(MinimumWithdrawalNotMetException::class);
        $this->withdrawals->request($user, 500, $account->id);
    }

    public function test_an_unknown_or_foreign_bank_account_is_refused(): void
    {
        [$user] = $this->makeVerifiedUser();

        $this->expectException(BankAccountNotFoundException::class);
        $this->withdrawals->request($user, 3000, 999999);
    }

    public function test_a_new_user_is_refused_without_authorizing_the_fee(): void
    {
        $user = WpUser::create(['user_login' => 'new2'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 10000]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'New User', 'is_primary' => true]);

        try {
            $this->withdrawals->request($user, 3000, $account->id, authorizeVerificationFee: false);
            $this->fail('Expected VerificationFeeRequiredException was not thrown.');
        } catch (VerificationFeeRequiredException $e) {
            $this->assertEquals(1000, $e->feeAmount);
        }

        $this->assertSame(0, WithdrawalRequest::count());
    }

    public function test_a_new_user_who_authorizes_the_fee_has_it_deducted_and_credited_back_to_wallet(): void
    {
        $user = WpUser::create(['user_login' => 'new3'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 10000]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'New User', 'is_primary' => true]);

        $withdrawal = $this->withdrawals->request($user, 3000, $account->id, authorizeVerificationFee: true);

        $this->assertEquals(1000, $withdrawal->fee_amount);
        $this->assertEquals(3000, $withdrawal->amount_to_send); // balance covered both amount and fee
        $wallet = Wallet::where('user_id', $user->ID)->first();
        $this->assertEquals(6000, $wallet->earnings_balance); // 10000 - 3000 - 1000
        $this->assertEquals(1000, $wallet->wallet_balance); // fee credited back

        // The fee credit unlocks future fee-free withdrawals.
        $requirements = $this->withdrawals->requirements($user);
        $this->assertFalse($requirements['requires_verification_fee']);
    }

    public function test_when_balance_only_covers_the_amount_the_fee_is_deducted_from_the_withdrawal_itself(): void
    {
        $user = WpUser::create(['user_login' => 'new4'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 3000]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'New User', 'is_primary' => true]);

        $withdrawal = $this->withdrawals->request($user, 3000, $account->id, authorizeVerificationFee: true);

        $this->assertEquals(1000, $withdrawal->fee_amount);
        $this->assertEquals(2000, $withdrawal->amount_to_send); // 3000 - 1000 fee
        $this->assertEquals(0, Wallet::where('user_id', $user->ID)->value('earnings_balance'));
    }

    public function test_insufficient_earnings_is_refused_and_nothing_changes(): void
    {
        [$user, $account] = $this->makeVerifiedUser(earnings: 100);

        try {
            $this->withdrawals->request($user, 3000, $account->id);
            $this->fail('Expected InsufficientBalanceException was not thrown.');
        } catch (InsufficientBalanceException $e) {
            $this->assertEquals(2900, $e->shortfall);
        }

        $this->assertEquals(100, Wallet::where('user_id', $user->ID)->value('earnings_balance'));
        $this->assertSame(0, WithdrawalRequest::count());
    }

    public function test_an_admin_can_mark_a_pending_withdrawal_paid(): void
    {
        [$user, $account] = $this->makeVerifiedUser();
        $withdrawal = $this->withdrawals->request($user, 3000, $account->id);
        $admin = WpUser::create(['user_login' => 'admin'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);

        Notification::fake();

        $this->withdrawals->markPaid($admin, $withdrawal);

        $this->assertSame('paid', $withdrawal->fresh()->status);
        Notification::assertSentTo($user, WithdrawalProcessed::class);
    }

    public function test_an_already_paid_withdrawal_cannot_be_marked_paid_again(): void
    {
        [$user, $account] = $this->makeVerifiedUser();
        $withdrawal = $this->withdrawals->request($user, 3000, $account->id);
        $admin = WpUser::create(['user_login' => 'admin'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $this->withdrawals->markPaid($admin, $withdrawal);

        $this->expectException(\RuntimeException::class);
        $this->withdrawals->markPaid($admin, $withdrawal->fresh());
    }

    public function test_rejecting_a_withdrawal_refunds_the_full_deducted_amount(): void
    {
        [$user, $account] = $this->makeVerifiedUser(earnings: 10000);
        $withdrawal = $this->withdrawals->request($user, 3000, $account->id);
        $admin = WpUser::create(['user_login' => 'admin'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);

        Notification::fake();

        $this->withdrawals->reject($admin, $withdrawal, 'wrong bank details');

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertEquals(10000, Wallet::where('user_id', $user->ID)->value('earnings_balance'));
        Notification::assertSentTo($user, WithdrawalProcessed::class);
    }
}
