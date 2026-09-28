<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/**
 * Item 26's new read endpoints backing the account section's "My
 * Tickets" and "Transactions" pages — see AccountReadService's docblock
 * for exactly what each derives and why.
 */
class AccountControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, CreatesRaffles, RefreshDatabase;

    private function makeRaffle(array $meta = []): Raffle
    {
        return $this->createRaffle(array_merge(['price' => '500', 'max' => '20'], $meta));
    }

    public function test_an_unauthenticated_request_for_tickets_is_rejected(): void
    {
        $this->getJson('/api/account/tickets')->assertUnauthorized();
    }

    public function test_an_unauthenticated_request_for_transactions_is_rejected(): void
    {
        $this->getJson('/api/account/transactions')->assertUnauthorized();
    }

    public function test_tickets_are_grouped_by_raffle_with_a_derived_status(): void
    {
        $user = $this->actingAsWordPressUser();

        $active = $this->makeRaffle(['expiry' => now()->addDays(3)->toDateString()]);
        $soldOut = $this->makeRaffle(['max' => '1']);

        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $active->public_id, 'ticket_number' => 5, 'txn_id' => 1]);
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $active->public_id, 'ticket_number' => 6, 'txn_id' => 1]);
        RaffleEntry::create(['user_id' => $user->ID, 'raffle_id' => $soldOut->public_id, 'ticket_number' => 1, 'txn_id' => 2]);

        // A ticket bought by someone else must never leak into this user's list.
        $other = WpUser::create(['user_login' => 'other', 'user_pass' => 'x', 'user_email' => 'o@example.com']);
        RaffleEntry::create(['user_id' => $other->ID, 'raffle_id' => $active->public_id, 'ticket_number' => 7, 'txn_id' => 3]);

        $response = $this->getJson('/api/account/tickets');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);

        $activeGroup = collect($data)->firstWhere('raffle_id', $active->public_id);
        $this->assertSame('Active', $activeGroup['status']);
        $this->assertEqualsCanonicalizing(['005', '006'], $activeGroup['tickets']);

        $soldOutGroup = collect($data)->firstWhere('raffle_id', $soldOut->public_id);
        // Real fix over the legacy version: a raffle with zero tickets
        // remaining is "Concluded" from the actual entry count, not from
        // a manually-set flag nobody toggled.
        $this->assertSame('Concluded', $soldOutGroup['status']);
    }

    public function test_transactions_merge_legacy_and_ledger_history_newest_first(): void
    {
        $user = $this->actingAsWordPressUser();

        $legacyTxn = RaffleTransaction::create([
            'user_id' => $user->ID, 'type' => 'wallet_deposit', 'claimed_amount' => 1000,
            'status' => 'verified_final',
        ]);
        // RaffleTransaction has real Eloquent timestamps enabled (only
        // UPDATED_AT is disabled), so `create()` always stamps `created_at`
        // with "now" regardless of what's passed in — bypass the model to
        // backdate it for this test.
        RaffleTransaction::whereKey($legacyTxn->id)->update(['created_at' => now()->subDays(2)]);

        WalletLedgerEntry::create([
            'user_id' => $user->ID, 'balance_type' => 'wallet', 'direction' => 'debit',
            'amount' => 500, 'reason' => 'ticket_purchase', 'created_at' => now(),
        ]);

        $response = $this->getJson('/api/account/transactions');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertSame('ticket_purchase_wallet', $data[0]['type']);
        $this->assertSame('wallet_deposit', $data[1]['type']);
    }
}
