<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\WithdrawalRequestResource\Pages\ListWithdrawalRequests;
use App\Models\AdminAuditLog;
use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** OVERHAUL_CHECKLIST.md item 44 — the withdrawals queue screen. */
class WithdrawalsScreenTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function pendingWithdrawal(): WithdrawalRequest
    {
        $user = WpUser::create(['user_login' => 'c'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Customer']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Obi', 'is_primary' => true]);

        return WithdrawalRequest::create(['user_id' => $user->ID, 'bank_account_id' => $account->id, 'requested_amount' => 3000, 'fee_amount' => 0, 'amount_to_send' => 3000, 'status' => 'pending']);
    }

    public function test_it_lists_pending_withdrawals_by_default(): void
    {
        $this->actingAsAdministrator();
        $pending = $this->pendingWithdrawal();
        $paid = $this->pendingWithdrawal();
        $paid->update(['status' => 'paid']);

        Livewire::test(ListWithdrawalRequests::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$paid]);
    }

    public function test_mark_paid_really_marks_it_paid_and_is_audit_logged(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();
        $withdrawal = $this->pendingWithdrawal();

        Livewire::test(ListWithdrawalRequests::class)->callTableAction('markPaid', $withdrawal);

        $this->assertSame('paid', $withdrawal->fresh()->status);
        $this->assertSame(1, AdminAuditLog::where('action', 'withdrawal.paid')->count());
    }

    public function test_reject_refunds_the_customer_and_needs_a_reason(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();
        $withdrawal = $this->pendingWithdrawal();

        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reject', $withdrawal, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reject', $withdrawal, data: ['reason' => 'Account name does not match']);

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertEquals(3000, (float) Wallet::where('user_id', $withdrawal->user_id)->value('earnings_balance'));
    }

    public function test_actions_are_hidden_once_handled(): void
    {
        $this->actingAsAdministrator();
        $withdrawal = $this->pendingWithdrawal();
        $withdrawal->update(['status' => 'paid']);

        Livewire::test(ListWithdrawalRequests::class)
            ->filterTable('status', 'paid')
            ->assertTableActionHidden('markPaid', $withdrawal)
            ->assertTableActionHidden('reject', $withdrawal);
    }
}
