<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\TicketUnavailableException;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Services\TicketPurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TicketPurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private TicketPurchaseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TicketPurchaseService::class);
    }

    private function makeUserWithWallet(float $walletBalance = 1000, float $earningsBalance = 0): WpUser
    {
        $user = WpUser::create([
            'user_login' => 'tester',
            'user_pass' => 'irrelevant-for-this-test',
            'user_email' => 'tester@example.com',
            'display_name' => 'Tester',
        ]);

        Wallet::create([
            'user_id' => $user->ID,
            'wallet_balance' => $walletBalance,
            'earnings_balance' => $earningsBalance,
        ]);

        return $user;
    }

    public function test_a_normal_wallet_purchase_debits_the_wallet_and_creates_the_tickets(): void
    {
        $user = $this->makeUserWithWallet(walletBalance: 1000);

        $transaction = $this->service->purchaseFromBalance(
            user: $user,
            raffleId: 5,
            ticketNumbers: [10, 11, 12],
            unitPrice: 100,
            isGoldenBox: false,
            submittedAmount: 270, // <=200/ticket tier: qty>=2 gets 10% off (3*100*0.9)
            fundingSource: 'wallet',
            idempotencyKey: 'idem-1',
        );

        $this->assertSame('verified_final', $transaction->status);
        $this->assertSame(3, RaffleEntry::where('txn_id', $transaction->id)->count());
        $this->assertEquals(730, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
    }

    public function test_it_rejects_a_submitted_amount_that_does_not_match_the_server_calculated_price(): void
    {
        $user = $this->makeUserWithWallet(walletBalance: 1000);

        $this->expectException(InvalidArgumentException::class);

        // Correct price for 2 tickets at 300 each is a discounted 450 (25% off),
        // not the naive 600 being submitted here.
        $this->service->purchaseFromBalance(
            user: $user,
            raffleId: 5,
            ticketNumbers: [1, 2],
            unitPrice: 300,
            isGoldenBox: false,
            submittedAmount: 600,
            fundingSource: 'wallet',
            idempotencyKey: 'idem-2',
        );
    }

    public function test_insufficient_balance_is_rejected_and_nothing_is_charged_or_allocated(): void
    {
        $user = $this->makeUserWithWallet(walletBalance: 50);

        try {
            $this->service->purchaseFromBalance(
                user: $user,
                raffleId: 5,
                ticketNumbers: [1],
                unitPrice: 100,
                isGoldenBox: false,
                submittedAmount: 100,
                fundingSource: 'wallet',
                idempotencyKey: 'idem-3',
            );
            $this->fail('Expected InsufficientBalanceException was not thrown.');
        } catch (InsufficientBalanceException $e) {
            $this->assertEquals(50, $e->shortfall);
        }

        $this->assertEquals(50, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
        $this->assertSame(0, RaffleEntry::count());
        $this->assertSame(0, RaffleTransaction::count());
    }

    /**
     * This is the direct regression test for TD-06: "wallet debited before
     * ticket insert, no rollback on a ticket-number collision." A second
     * user tries to buy a number the first user already holds — this must
     * fail loudly AND leave the second user's wallet untouched, instead of
     * silently debiting them for a ticket they never receive.
     */
    public function test_a_ticket_number_collision_rolls_back_the_debit_completely(): void
    {
        $firstUser = $this->makeUserWithWallet(walletBalance: 1000);
        $this->service->purchaseFromBalance(
            user: $firstUser,
            raffleId: 5,
            ticketNumbers: [42],
            unitPrice: 100,
            isGoldenBox: false,
            submittedAmount: 100,
            fundingSource: 'wallet',
            idempotencyKey: 'idem-first-user',
        );

        $secondUser = $this->makeUserWithWallet(walletBalance: 1000);

        try {
            $this->service->purchaseFromBalance(
                user: $secondUser,
                raffleId: 5,
                ticketNumbers: [42], // already taken by $firstUser
                unitPrice: 100,
                isGoldenBox: false,
                submittedAmount: 100,
                fundingSource: 'wallet',
                idempotencyKey: 'idem-second-user',
            );
            $this->fail('Expected TicketUnavailableException was not thrown.');
        } catch (TicketUnavailableException $e) {
            $this->assertSame([42], $e->unavailableNumbers);
        }

        // The critical assertion: the second user was NOT charged.
        $this->assertEquals(1000, Wallet::where('user_id', $secondUser->ID)->value('wallet_balance'));
        $this->assertSame(0, RaffleTransaction::where('user_id', $secondUser->ID)->count());
        $this->assertSame(1, RaffleEntry::where('ticket_number', 42)->count()); // still only the first user's
    }

    /**
     * Regression test for the missing double-submit protection (audit
     * §9: "no idempotency key found on the payment endpoint"). The same
     * request replayed with the same idempotency key must not charge
     * twice.
     */
    public function test_a_repeated_request_with_the_same_idempotency_key_is_not_charged_twice(): void
    {
        $user = $this->makeUserWithWallet(walletBalance: 1000);

        $args = [
            'user' => $user,
            'raffleId' => 5,
            'ticketNumbers' => [7],
            'unitPrice' => 100,
            'isGoldenBox' => false,
            'submittedAmount' => 100,
            'fundingSource' => 'wallet',
            'idempotencyKey' => 'same-key-both-times',
        ];

        $first = $this->service->purchaseFromBalance(...$args);
        $second = $this->service->purchaseFromBalance(...$args);

        $this->assertSame($first->id, $second->id);
        $this->assertEquals(900, Wallet::where('user_id', $user->ID)->value('wallet_balance'));
        $this->assertSame(1, RaffleEntry::count());
    }

    /**
     * Regression test for TD-05: "bank-transfer purchases may never
     * create ticket entries." This simulates a transaction that some
     * other process (AI-verified bank transfer, a future payment gateway
     * webhook) has already marked verified_final, and proves the shared
     * allocation function actually creates the tickets for it.
     */
    public function test_entries_are_recorded_for_a_transaction_verified_by_another_process(): void
    {
        $user = $this->makeUserWithWallet();

        $transaction = RaffleTransaction::create([
            'user_id' => $user->ID,
            'claimed_amount' => 200,
            'status' => 'verified_final',
            'type' => 'ticket_purchase', // e.g. the bank-transfer path
            'proof_url' => 'https://example.com/receipt.jpg',
        ]);

        $this->service->recordEntriesForVerifiedTransaction($transaction, raffleId: 9, ticketNumbers: [1, 2]);

        $this->assertSame(2, RaffleEntry::where('txn_id', $transaction->id)->count());
    }

    public function test_it_refuses_to_allocate_entries_for_a_transaction_that_is_not_yet_verified(): void
    {
        $user = $this->makeUserWithWallet();

        $transaction = RaffleTransaction::create([
            'user_id' => $user->ID,
            'claimed_amount' => 200,
            'status' => 'manual_review',
            'type' => 'ticket_purchase',
            'proof_url' => 'https://example.com/receipt.jpg',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->recordEntriesForVerifiedTransaction($transaction, raffleId: 9, ticketNumbers: [1]);
    }
}
