<?php

namespace Tests\Unit;

use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Services\AuditReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — proves the audit matching
 * engine behind Daily Audit's reconciliation flags exactly the verified
 * transactions a set of bank credits doesn't account for, using the
 * same strict amount-match/no-double-counting rule legacy's own
 * rk_render_audit_page() uses.
 */
class AuditReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditReconciliationService $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = app(AuditReconciliationService::class);
    }

    private function makeTxn(int $userId, float $amount, string $type = 'wallet_deposit', string $status = 'verified_final'): RaffleTransaction
    {
        return RaffleTransaction::create(['user_id' => $userId, 'claimed_amount' => $amount, 'status' => $status, 'type' => $type, 'created_at' => now()]);
    }

    public function test_a_transaction_with_a_matching_bank_credit_is_not_flagged(): void
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $this->makeTxn($user->ID, 5000);

        $flagged = $this->audit->reconcile([['amount' => 5000]], now()->toDateString(), now()->toDateString());

        $this->assertCount(0, $flagged);
    }

    public function test_a_verified_transaction_with_no_matching_credit_is_flagged(): void
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $txn = $this->makeTxn($user->ID, 5000);

        $flagged = $this->audit->reconcile([['amount' => 3000]], now()->toDateString(), now()->toDateString());

        $this->assertCount(1, $flagged);
        $this->assertSame($txn->id, $flagged->first()['transaction']->id);
    }

    public function test_a_bank_credit_can_only_clear_one_transaction_at_the_same_amount(): void
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $this->makeTxn($user->ID, 1000);
        $this->makeTxn($user->ID, 1000);

        // Only ONE bank credit of ₦1,000 — the second identical transaction should be flagged.
        $flagged = $this->audit->reconcile([['amount' => 1000]], now()->toDateString(), now()->toDateString());

        $this->assertCount(1, $flagged);
    }

    public function test_pending_and_rejected_transactions_are_never_flagged(): void
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $this->makeTxn($user->ID, 2000, 'wallet_deposit', 'pending');
        $this->makeTxn($user->ID, 3000, 'wallet_deposit', 'rejected');

        $flagged = $this->audit->reconcile([], now()->toDateString(), now()->toDateString());

        $this->assertCount(0, $flagged);
    }

    public function test_withdrawals_are_excluded_from_reconciliation(): void
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $this->makeTxn($user->ID, 2000, 'withdrawal');

        $flagged = $this->audit->reconcile([], now()->toDateString(), now()->toDateString());

        $this->assertCount(0, $flagged);
    }

    public function test_transactions_outside_the_date_range_are_ignored(): void
    {
        $user = WpUser::create(['user_login' => 'u'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com']);
        $old = $this->makeTxn($user->ID, 5000);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $flagged = $this->audit->reconcile([], now()->subDays(2)->toDateString(), now()->toDateString());

        $this->assertCount(0, $flagged);
    }
}
