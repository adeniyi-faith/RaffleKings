<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class StatusFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_paid_withdrawal_cannot_go_back_or_be_rejected(): void
    {
        $account = \App\Models\BankAccount::create(['user_id' => 1, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Test User', 'is_primary' => true]);
        $w = WithdrawalRequest::create(['user_id' => 1, 'bank_account_id' => $account->id, 'requested_amount' => 3000, 'fee_amount' => 0, 'amount_to_send' => 3000, 'status' => 'pending']);
        $w->update(['status' => 'paid']);

        foreach (['pending', 'rejected'] as $to) {
            try {
                $w->update(['status' => $to]);
                $this->fail("paid → {$to} should be refused");
            } catch (LogicException) {
                $this->assertSame('paid', $w->fresh()->status);
            }
        }
    }

    public function test_a_credited_top_up_is_final_but_a_failed_one_can_still_turn_out_paid(): void
    {
        $paid = Deposit::create(['user_id' => 1, 'reference' => 'dep_a', 'gateway' => 'paystack', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'successful']);
        $this->expectException(LogicException::class);

        try {
            Deposit::create(['user_id' => 1, 'reference' => 'dep_b', 'gateway' => 'paystack', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'failed'])->update(['status' => 'successful']);
            $this->assertTrue(true);
        } finally {
            $paid->update(['status' => 'failed']);
        }
    }
}
