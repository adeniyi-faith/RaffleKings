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
use Tests\Support\HoldsWithdrawalMoney;
use Tests\TestCase;

/** OVERHAUL_CHECKLIST.md item 44 — the withdrawals queue screen. */
class WithdrawalsScreenTest extends TestCase
{
    use ActsAsAdministrator, HoldsWithdrawalMoney, RefreshDatabase;

    private function pendingWithdrawal(): WithdrawalRequest
    {
        $user = WpUser::create(['user_login' => 'c'.uniqid(), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Customer']);
        Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);
        $account = BankAccount::create(['user_id' => $user->ID, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Obi', 'is_primary' => true]);

        return $this->holdMoneyFor(WithdrawalRequest::create(['user_id' => $user->ID, 'bank_account_id' => $account->id, 'requested_amount' => 3000, 'fee_amount' => 0, 'amount_to_send' => 3000, 'status' => 'pending']));
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
            ->callTableAction('reject', $withdrawal, data: ['reason' => '', 'customer_message' => ''])
            ->assertHasTableActionErrors(['reason' => 'required', 'customer_message' => 'required']);

        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('reject', $withdrawal, data: ['reason' => 'Account name does not match', 'customer_message' => 'We could not match the account name to yours.']);

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertEquals(3000, (float) Wallet::where('user_id', $withdrawal->user_id)->value('earnings_balance'));
    }

    public function test_the_account_number_is_hidden_until_staff_give_a_reason_and_it_is_logged(): void
    {
        $this->actingAsAdministrator();
        $withdrawal = $this->pendingWithdrawal();

        Livewire::test(ListWithdrawalRequests::class)
            ->assertSee('••••••6789')
            ->assertDontSee('0123456789')
            ->callTableAction('revealAccount', $withdrawal, data: ['why' => ''])
            ->assertHasTableActionErrors(['why' => 'required']);

        Livewire::test(ListWithdrawalRequests::class)
            ->callTableAction('revealAccount', $withdrawal, data: ['why' => 'Paying by hand']);

        $log = AdminAuditLog::where('action', 'bank_account.revealed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Paying by hand', json_encode($log->details ?? $log->toArray()));
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
