<?php

namespace Tests\Unit;

use App\Exceptions\DuplicatePostingException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\UserRestrictedException;
use App\Models\Legacy\WpUser;
use App\Models\LedgerJournal;
use App\Models\LedgerSystemEntry;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\AccountRestrictions;
use App\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WalletLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(WalletLedgerService::class);
    }

    public function test_credits_and_debits_reconstruct_to_the_correct_balance(): void
    {
        $this->ledger->credit(1, 'wallet', 1000, 'deposit', 'k1', 'gateway_clearing');
        $this->ledger->debit(1, 'wallet', 300, 'ticket_purchase', 'k2', 'ticket_sales');
        $this->ledger->credit(1, 'wallet', 50, 'admin_adjustment', 'k3', 'adjustments');

        $this->assertEquals(750, $this->ledger->reconstructBalance(1, 'wallet'));
        $this->assertEquals(750, Wallet::where('user_id', 1)->value('wallet_balance'));
    }

    public function test_wallet_and_earnings_balances_are_tracked_independently(): void
    {
        $this->ledger->credit(1, 'wallet', 100, 'deposit', 'k1', 'gateway_clearing');
        $this->ledger->credit(1, 'earnings', 500, 'referral_commission', 'k2', 'referral_expense');

        $this->assertEquals(100, $this->ledger->reconstructBalance(1, 'wallet'));
        $this->assertEquals(500, $this->ledger->reconstructBalance(1, 'earnings'));
    }

    public function test_a_user_with_no_entries_reconstructs_to_zero(): void
    {
        $this->assertEquals(0, $this->ledger->reconstructBalance(999, 'wallet'));
    }

    public function test_it_rejects_an_unknown_balance_type_and_unknown_business_account(): void
    {
        try {
            $this->ledger->credit(1, 'bonus_points', 100, 'deposit', 'k1', 'gateway_clearing');
            $this->fail('unknown balance type accepted');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->ledger->credit(1, 'wallet', 100, 'deposit', 'k2', 'made_up_account');
    }

    public function test_it_rejects_a_non_positive_amount_and_amounts_finer_than_a_kobo(): void
    {
        foreach ([0, -5, 10.005] as $i => $bad) {
            try {
                $this->ledger->debit(1, 'wallet', $bad, 'ticket_purchase', "bad{$i}", 'ticket_sales');
                $this->fail("amount {$bad} accepted");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, WalletLedgerEntry::count());
    }

    public function test_entries_record_what_reference_they_belong_to(): void
    {
        $this->ledger->credit(1, 'wallet', 500, 'deposit', 'k0', 'gateway_clearing');
        $entry = $this->ledger->debit(1, 'wallet', 100, 'ticket_purchase', 'k1', 'ticket_sales', 'raffle_transaction', 42);

        $this->assertSame('raffle_transaction', $entry->reference_type);
        $this->assertSame(42, $entry->reference_id);
    }

    public function test_every_movement_is_a_balanced_journal(): void
    {
        $this->ledger->credit(1, 'wallet', 1000.10, 'deposit', 'k1', 'gateway_clearing');
        $this->ledger->debit(1, 'wallet', 250.05, 'ticket_purchase', 'k2', 'ticket_sales');
        $this->ledger->credit(1, 'earnings', 80, 'prize_payout', 'k3', 'prizes');
        $this->ledger->move(1, 'earnings', 'wallet', 30, 'earnings_transfer', 'k4');

        $this->assertSame(4, LedgerJournal::count());

        foreach (LedgerJournal::all() as $journal) {
            $total = 0;

            foreach (WalletLedgerEntry::where('journal_id', $journal->id)->get() as $e) {
                $total += ($e->direction === 'credit' ? 1 : -1) * (int) round($e->amount * 100);
            }

            foreach (LedgerSystemEntry::where('journal_id', $journal->id)->get() as $e) {
                $total += ($e->direction === 'credit' ? 1 : -1) * (int) round($e->amount * 100);
            }

            $this->assertSame(0, $total, "journal {$journal->business_key} does not add up to zero");
        }

        $this->assertSame(1000_10 - 250_05 + 30_00, $this->ledger->reconstructKobo(1, 'wallet'));
        $this->assertSame(50_00, $this->ledger->reconstructKobo(1, 'earnings'));
    }

    public function test_the_same_business_key_can_only_be_posted_once(): void
    {
        $this->ledger->credit(1, 'wallet', 100, 'deposit', 'deposit:7', 'gateway_clearing');

        try {
            $this->ledger->credit(1, 'wallet', 100, 'deposit', 'deposit:7', 'gateway_clearing');
            $this->fail('the second posting went through');
        } catch (DuplicatePostingException) {
            $this->assertTrue(true);
        }

        $this->assertEquals(100, Wallet::where('user_id', 1)->value('wallet_balance'));
        $this->assertSame(1, WalletLedgerEntry::count());
        $this->assertSame(1, LedgerSystemEntry::count());
    }

    public function test_a_balance_can_never_go_below_zero(): void
    {
        $this->ledger->credit(1, 'wallet', 100, 'deposit', 'k1', 'gateway_clearing');

        try {
            $this->ledger->debit(1, 'wallet', 100.01, 'ticket_purchase', 'k2', 'ticket_sales');
            $this->fail('overdraft accepted');
        } catch (InsufficientBalanceException) {
            $this->assertTrue(true);
        }

        $this->assertEquals(100, Wallet::where('user_id', 1)->value('wallet_balance'));
        $this->assertFalse($this->ledger->journalExists('k2'));
    }

    public function test_a_move_changes_both_sides_or_neither(): void
    {
        $this->ledger->credit(1, 'earnings', 100, 'prize_payout', 'k1', 'prizes');
        $this->ledger->move(1, 'earnings', 'wallet', 60, 'earnings_transfer', 'k2');

        $wallet = Wallet::where('user_id', 1)->first();
        $this->assertEquals(40, $wallet->earnings_balance);
        $this->assertEquals(60, $wallet->wallet_balance);

        try {
            $this->ledger->move(1, 'earnings', 'wallet', 40.01, 'earnings_transfer', 'k3');
            $this->fail('moved more than there was');
        } catch (InsufficientBalanceException) {
            $this->assertTrue(true);
        }

        $wallet->refresh();
        $this->assertEquals(40, $wallet->earnings_balance);
        $this->assertEquals(60, $wallet->wallet_balance);
    }

    public function test_pennies_add_up_exactly(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->ledger->credit(1, 'wallet', 0.10, 'deposit', "p{$i}", 'gateway_clearing');
        }

        $this->assertSame('1.00', number_format((float) Wallet::where('user_id', 1)->value('wallet_balance'), 2, '.', ''));
        $this->assertSame(100, $this->ledger->reconstructKobo(1, 'wallet'));
    }

    public function test_a_banned_customer_cannot_start_a_money_action_but_staff_postings_still_work(): void
    {
        $admin = WpUser::create(['user_login' => 'boss', 'user_pass' => 'x', 'user_email' => 'boss@example.com']);
        $user = WpUser::create(['user_login' => 'cheat', 'user_pass' => 'x', 'user_email' => 'cheat@example.com']);
        $this->ledger->credit($user->ID, 'wallet', 500, 'deposit', 'k1', 'gateway_clearing');

        app(AccountRestrictions::class)->impose($admin, $user, 'full_ban', 'Chargeback fraud');

        try {
            $this->ledger->debit($user->ID, 'wallet', 100, 'ticket_purchase', 'k2', 'ticket_sales', customerAction: 'spend');
            $this->fail('a banned customer spent money');
        } catch (UserRestrictedException) {
            $this->assertTrue(true);
        }

        // A reversal started by staff is not blocked by the ban.
        $this->ledger->debit($user->ID, 'wallet', 100, 'transaction_revoked', 'k3', 'gateway_clearing');
        $this->assertEquals(400, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }
}
