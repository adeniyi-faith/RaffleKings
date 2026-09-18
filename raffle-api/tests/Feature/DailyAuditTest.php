<?php

namespace Tests\Feature;

use App\Filament\Pages\DailyAudit;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the Daily Audit page ties
 * together AuditReconciliationService (built in the previous item-36
 * pass) and TransactionMonitorService::revoke() into one workflow.
 * Extraction itself (StatementExtractionService, Gemini) is covered
 * separately in StatementExtractionControllerTest; revoke() itself is
 * covered directly against TransactionMonitorService in
 * tests/Unit/TransactionMonitorServiceTest.php. This covers the
 * reconcile step using manually-entered credits — exactly the fallback
 * path the page itself offers when a statement isn't uploaded or the AI
 * can't read it — and confirms the page exposes the flagged
 * transaction for a "Revoke" button rather than checking the action
 * itself: Livewire's test harness calls component methods through an
 * internal request broker that does not carry the outer test's cookies
 * (see FilamentAdminPanelTest's own docblock on the same limitation for
 * the ban/unban action), so it cannot exercise Auth::guard('wordpress')
 * the way a real browser click does.
 */
class DailyAuditTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

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

    public function test_a_transaction_with_no_matching_credit_is_flagged_and_can_be_revoked(): void
    {
        $this->actingAsAdministrator();
        $depositor = WpUser::create(['user_login' => 'auditee', 'user_pass' => 'x', 'user_email' => 'auditee@example.com']);
        Wallet::create(['user_id' => $depositor->ID, 'wallet_balance' => 5000, 'earnings_balance' => 0]);
        $transaction = RaffleTransaction::create([
            'user_id' => $depositor->ID,
            'claimed_amount' => 5000,
            'status' => 'verified_final',
            'type' => 'wallet_deposit',
            'proof_url' => 'proof.png',
            'created_at' => now(),
        ]);

        $component = Livewire::test(DailyAudit::class)
            ->fillForm([
                'start_date' => now()->toDateString(),
                'end_date' => now()->toDateString(),
                'credits' => [['amount' => 999, 'date' => null, 'desc' => 'Unrelated credit']],
            ])
            ->call('reconcile');

        $this->assertNotEmpty($component->get('flagged'));
        $this->assertSame($transaction->id, (int) $component->get('flagged')[0]['transaction']->id);
    }

    public function test_a_transaction_with_a_matching_credit_is_not_flagged(): void
    {
        $this->actingAsAdministrator();
        $depositor = WpUser::create(['user_login' => 'auditee2', 'user_pass' => 'x', 'user_email' => 'auditee2@example.com']);
        RaffleTransaction::create([
            'user_id' => $depositor->ID,
            'claimed_amount' => 5000,
            'status' => 'verified_final',
            'type' => 'wallet_deposit',
            'proof_url' => 'proof.png',
            'created_at' => now(),
        ]);

        $component = Livewire::test(DailyAudit::class)
            ->fillForm([
                'start_date' => now()->toDateString(),
                'end_date' => now()->toDateString(),
                'credits' => [['amount' => 5000, 'date' => null, 'desc' => 'Matching credit']],
            ])
            ->call('reconcile');

        $this->assertEmpty($component->get('flagged'));
    }
}
