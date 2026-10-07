<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Notifications\BankAccountCode;
use App\Services\AccountRestrictions;
use App\Services\PayoutService;
use App\Services\Risk\FraudWatchService;
use App\Services\WalletLedgerService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\ConfirmsBankAccountCode;
use Tests\TestCase;

/**
 * Money-safety audit (H4, H10, H13, J2): a stolen password can't add a
 * thief's bank account, account numbers are encrypted, and a name that
 * isn't the customer's own is caught.
 */
class BankAccountSafetyTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, ConfirmsBankAccountCode, RefreshDatabase;

    private array $account = ['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Jane Doe'];

    public function test_an_account_cannot_be_added_without_the_emailed_code(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/bank-accounts', $this->account)
            ->assertStatus(422)
            ->assertJson(['code_required' => true]);

        $this->assertSame(0, BankAccount::count());
    }

    public function test_the_code_is_emailed_works_once_and_a_wrong_one_is_refused(): void
    {
        Notification::fake();
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/bank-accounts/code')->assertOk();
        Notification::assertSentTo($user, BankAccountCode::class);

        $this->postJson('/api/bank-accounts', $this->account + ['code' => '000000'])->assertStatus(422);

        // Use a known code, then try it twice.
        Cache::put("bank-account-code:{$user->ID}", ['hash' => Hash::make('654321'), 'wrong' => 0], now()->addMinutes(10));
        $this->postJson('/api/bank-accounts', $this->account + ['code' => '654321'])->assertCreated();
        $this->postJson('/api/bank-accounts', ['account_number' => '1111111111', 'bank_name' => 'Kuda', 'account_name' => 'Jane Doe', 'code' => '654321'])->assertStatus(422);
    }

    public function test_a_code_for_one_customer_does_not_work_for_another(): void
    {
        $thief = $this->actingAsWordPressUser();
        $victim = WpUser::create(['user_login' => 'victim', 'user_pass' => 'x', 'user_email' => 'victim@example.com']);
        Cache::put("bank-account-code:{$victim->ID}", ['hash' => Hash::make('123456'), 'wrong' => 0], now()->addMinutes(10));

        $this->postJson('/api/bank-accounts', $this->account + ['code' => '123456'])->assertStatus(422);
        $this->assertSame($thief->ID, $this->lastActingUserId);
    }

    public function test_five_wrong_codes_burn_the_code(): void
    {
        $user = $this->actingAsWordPressUser();
        Cache::put("bank-account-code:{$user->ID}", ['hash' => Hash::make('123456'), 'wrong' => 0], now()->addMinutes(10));

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/bank-accounts', $this->account + ['code' => '999999'])->assertStatus(422);
        }

        $this->postJson('/api/bank-accounts', $this->account + ['code' => '123456'])->assertStatus(422);
    }

    public function test_account_numbers_are_stored_encrypted_and_shared_accounts_are_still_found(): void
    {
        $this->actingAsWordPressUser();
        $this->addBankAccount($this->account)->assertCreated();

        $raw = DB::table('bank_accounts')->first();
        $this->assertStringNotContainsString('0123456789', (string) $raw->account_number);
        $this->assertSame(BankAccount::hashNumber('0123456789'), $raw->account_number_hash);
        $this->assertSame('0123456789', BankAccount::first()->account_number);
        $this->assertSame('••••••6789', BankAccount::first()->masked());

        // A second customer saving the same number is picked up without decrypting anything.
        $other = WpUser::create(['user_login' => 'other', 'user_pass' => 'x', 'user_email' => 'other@example.com']);
        BankAccount::create(['user_id' => $other->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Someone Else']);

        $shared = app(FraudWatchService::class)->sharedBankAccounts();
        $this->assertCount(1, $shared);
        $this->assertSame('••••••6789', $shared->first()['account_number']);
    }

    public function test_the_same_account_cannot_be_saved_twice_by_one_customer(): void
    {
        $this->actingAsWordPressUser();
        $this->addBankAccount($this->account)->assertCreated();
        $this->addBankAccount($this->account)->assertStatus(409);
    }

    public function test_a_bank_name_that_is_not_the_customers_is_flagged_and_blocks_automatic_payout(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_abc', 'features.auto_payouts' => true]);
        Http::fake([
            'api.paystack.co/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => 'CHUKWU EMEKA OKAFOR']]),
            'api.paystack.co/bank*' => Http::response(['status' => true, 'data' => [['name' => 'GTBank', 'code' => '058', 'active' => true]]]),
        ]);

        $user = $this->actingAsWordPressUser(['display_name' => 'Jane Doe']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'first_name', 'meta_value' => 'Jane']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'last_name', 'meta_value' => 'Doe']);

        $this->addBankAccount(['bank_code' => '058', 'account_number' => '0123456789'])->assertCreated();

        $account = BankAccount::first();
        $this->assertTrue($account->name_mismatch);

        $withdrawal = WithdrawalRequest::create(['user_id' => $user->ID, 'bank_account_id' => $account->id, 'requested_amount' => 5000, 'fee_amount' => 0, 'amount_to_send' => 5000, 'status' => 'pending']);
        $this->assertStringContainsString('does not match', app(PayoutService::class)->blocker($withdrawal->load('bankAccount')));
    }

    public function test_a_matching_name_is_not_flagged(): void
    {
        $service = app(\App\Services\BankAccountService::class);
        $user = WpUser::create(['user_login' => 'jd', 'user_pass' => 'x', 'user_email' => 'jd@example.com', 'display_name' => 'Jane Doe']);

        $this->assertTrue($service->nameLooksLikeCustomer($user, 'DOE JANE ADA'));
        $this->assertFalse($service->nameLooksLikeCustomer($user, 'CHUKWU EMEKA'));
    }

    public function test_a_new_bank_account_cannot_receive_a_withdrawal_for_24_hours(): void
    {
        $user = WpUser::create(['user_login' => 'w', 'user_pass' => 'x', 'user_email' => 'w@example.com']);
        $ledger = app(WalletLedgerService::class);
        $ledger->credit($user->ID, 'earnings', 20000, 'prize_payout', 'p1', 'prizes');
        $ledger->credit($user->ID, 'wallet', 20000, 'deposit', 'd1', 'gateway_clearing');
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'W W', 'is_primary' => true]);

        try {
            app(WithdrawalService::class)->request($user, 5000, $account->id, 'wait-test-0001');
            $this->fail('withdrawal to a brand-new account was accepted');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('added recently', $e->getMessage());
        }

        $this->travel(25)->hours();
        $this->assertSame('pending', app(WithdrawalService::class)->request($user, 5000, $account->id, 'wait-test-0002')->status);
    }

    public function test_one_customer_can_only_look_up_so_many_accounts_a_day(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_abc', 'features.bank_name_check' => true, 'withdrawals.bank_lookups_per_day' => 2]);
        Http::fake(['*' => Http::response(['status' => true, 'data' => [['name' => 'GTBank', 'code' => '058', 'active' => true]], 'account_name' => 'X'])]);
        $this->actingAsWordPressUser();

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/bank-accounts/look-up', ['bank_code' => '058', 'account_number' => '0123456789']);
        }

        $this->postJson('/api/bank-accounts/look-up', ['bank_code' => '058', 'account_number' => '0123456789'])->assertStatus(429);
    }

    public function test_an_old_style_ban_flag_set_outside_the_new_admin_is_still_enforced(): void
    {
        $user = WpUser::create(['user_login' => 'old', 'user_pass' => 'x', 'user_email' => 'old@example.com']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_ban_withdraw', 'meta_value' => '1']);

        $this->expectException(\App\Exceptions\UserRestrictedException::class);
        app(AccountRestrictions::class)->assertCanMoveMoney($user->ID, 'withdraw');
    }

    public function test_an_expired_old_style_ban_is_not_revived(): void
    {
        $user = WpUser::create(['user_login' => 'old2', 'user_pass' => 'x', 'user_email' => 'old2@example.com']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_is_banned', 'meta_value' => '1']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'rk_ban_expiry', 'meta_value' => now()->subDay()->toDateString()]);

        $this->assertFalse(app(AccountRestrictions::class)->isBanned($user->ID));
        $this->assertFalse($user->isBanned());
    }

    public function test_login_is_limited_per_account_even_from_many_addresses(): void
    {
        WpUser::create(['user_login' => 'target', 'user_pass' => password_hash('right-password-1', PASSWORD_BCRYPT), 'user_email' => 'target@example.com']);

        $statuses = [];

        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/auth/login', ['username' => 'target', 'password' => 'wrong-'.$i])->status();
        }

        $this->assertContains(429, $statuses);
    }
}
