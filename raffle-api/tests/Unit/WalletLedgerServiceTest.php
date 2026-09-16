<?php

namespace Tests\Unit;

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
        $this->ledger->recordCredit(1, 'wallet', 1000, 'deposit');
        $this->ledger->recordDebit(1, 'wallet', 300, 'ticket_purchase');
        $this->ledger->recordCredit(1, 'wallet', 50, 'admin_adjustment');

        $this->assertEquals(750, $this->ledger->reconstructBalance(1, 'wallet'));
    }

    public function test_wallet_and_earnings_balances_are_tracked_independently(): void
    {
        $this->ledger->recordCredit(1, 'wallet', 100, 'deposit');
        $this->ledger->recordCredit(1, 'earnings', 500, 'referral_commission');

        $this->assertEquals(100, $this->ledger->reconstructBalance(1, 'wallet'));
        $this->assertEquals(500, $this->ledger->reconstructBalance(1, 'earnings'));
    }

    public function test_a_user_with_no_entries_reconstructs_to_zero(): void
    {
        $this->assertEquals(0, $this->ledger->reconstructBalance(999, 'wallet'));
    }

    public function test_it_rejects_an_unknown_balance_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->ledger->recordCredit(1, 'bonus_points', 100, 'deposit');
    }

    public function test_it_rejects_a_non_positive_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->ledger->recordDebit(1, 'wallet', 0, 'ticket_purchase');
    }

    public function test_entries_record_what_reference_they_belong_to(): void
    {
        $entry = $this->ledger->recordDebit(1, 'wallet', 100, 'ticket_purchase', 'raffle_transaction', 42);

        $this->assertSame('raffle_transaction', $entry->reference_type);
        $this->assertSame(42, $entry->reference_id);
    }
}
