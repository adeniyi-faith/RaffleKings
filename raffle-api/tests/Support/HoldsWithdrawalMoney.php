<?php

namespace Tests\Support;

use App\Models\WithdrawalRequest;
use App\Services\WalletLedgerService;

/**
 * Withdrawal requests made directly in a test (not through
 * WithdrawalService::request) have no held money behind them, so paying or
 * rejecting them would find nothing to pay out. This puts the held amount in,
 * the way a real request does.
 */
trait HoldsWithdrawalMoney
{
    protected function holdMoneyFor(WithdrawalRequest $withdrawal): WithdrawalRequest
    {
        app(WalletLedgerService::class)->credit(
            userId: $withdrawal->user_id,
            balanceType: 'held',
            amount: (string) $withdrawal->amount_to_send,
            reason: 'withdrawal_hold',
            key: "withdrawal_hold:{$withdrawal->id}",
            from: 'opening_balances',
            referenceType: 'withdrawal_request',
            referenceId: $withdrawal->id,
        );

        return $withdrawal;
    }
}
