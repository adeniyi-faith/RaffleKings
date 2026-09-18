<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Notifications\DepositConfirmed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the one real gap
 * DepositService::confirm() left behind on purpose: a payment where the
 * gateway-confirmed amount doesn't match what the deposit expected is
 * flagged `amount_mismatch` rather than credited on a guess (see that
 * method's own comment), but nothing anywhere — old system or new —
 * ever gave an admin a way to actually resolve one of those. A user who
 * genuinely paid the "wrong" amount (sent ₦4,800 instead of ₦5,000
 * because of a bank fee, say) had no path back to a credited wallet
 * except a support ticket and a manual balance adjustment.
 *
 * This is a brand-new admin action, not a second copy of
 * DepositService's settlement logic living in wp_usermeta/legacy
 * tables — a gateway deposit only ever exists on the new side (there is
 * no legacy equivalent of a Paystack/Flutterwave deposit to unify
 * with), so unlike DepositApprovalService there's no
 * rk_wallets_unified_enabled flag to respect here; it always credits
 * the real `wallets` table, exactly like DepositService::confirm()
 * already does for a clean match.
 */
class DepositMismatchService
{
    public function __construct(
        private readonly DepositService $deposits,
        private readonly WalletLedgerService $ledger,
        private readonly AdminAuditLogService $auditLog,
    ) {}

    /** @return Collection<int, Deposit> */
    public function pending(): Collection
    {
        return Deposit::query()
            ->where('status', 'amount_mismatch')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Re-verifies directly with the gateway one more time (never trusts
     * the stale `failure_reason` text the mismatch was originally
     * flagged with) and, if it still reports a successful payment,
     * credits the wallet for exactly what the gateway actually
     * confirmed — never the originally-expected amount, which is what
     * made this a mismatch in the first place.
     *
     * @throws RuntimeException if the deposit isn't in amount_mismatch status
     * @throws PaymentGatewayException if the gateway can't be reached
     */
    public function creditConfirmedAmount(WpUser $admin, Deposit $deposit): Deposit
    {
        $this->guardMismatch($deposit);

        $gateway = $this->deposits->gatewayFor($deposit->gateway);
        $verification = $gateway->verify($deposit->reference);

        if (! $verification->successful) {
            throw new RuntimeException("Gateway no longer reports {$deposit->reference} as a successful payment — reject this deposit instead of crediting it.");
        }

        $confirmedAmount = round($verification->amount, 2);

        DB::transaction(function () use ($deposit, $confirmedAmount, $verification) {
            $wallet = Wallet::query()->where('user_id', $deposit->user_id)->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $deposit->user_id, 'wallet_balance' => 0, 'earnings_balance' => 0]);

            $wallet->wallet_balance = (float) $wallet->wallet_balance + $confirmedAmount;
            $wallet->save();

            $this->ledger->recordCredit(
                userId: $deposit->user_id,
                balanceType: 'wallet',
                amount: $confirmedAmount,
                reason: 'deposit',
                referenceType: Deposit::class,
                referenceId: $deposit->id,
                description: "Deposit via {$deposit->gateway} (admin-resolved amount mismatch — credited the gateway-confirmed amount, not the originally-expected one)",
            );

            $deposit->update([
                'status' => 'successful',
                'amount' => $confirmedAmount,
                'gateway_transaction_id' => $verification->gatewayTransactionId,
                'verified_at' => now(),
            ]);
        });

        $this->auditLog->record($admin, 'deposit.mismatch_resolved_credited', Deposit::class, $deposit->id, [
            'user_id' => $deposit->user_id,
            'expected_amount' => (float) $deposit->getOriginal('amount'),
            'credited_amount' => $confirmedAmount,
        ]);

        $deposit->refresh();
        $deposit->user->notify(new DepositConfirmed($deposit));

        return $deposit;
    }

    /**
     * Admin decides the mismatched payment should not be credited at
     * all (e.g. it looks like it belongs to a different, unrelated
     * transaction). Leaves the wallet untouched — there was never a
     * credit to reverse, since a mismatch is never auto-credited.
     *
     * @throws RuntimeException if the deposit isn't in amount_mismatch status
     */
    public function reject(WpUser $admin, Deposit $deposit, ?string $reason = null): Deposit
    {
        $this->guardMismatch($deposit);

        $deposit->update([
            'status' => 'failed',
            'failure_reason' => $reason ?? 'Amount mismatch rejected by admin.',
        ]);

        $this->auditLog->record($admin, 'deposit.mismatch_resolved_rejected', Deposit::class, $deposit->id, [
            'user_id' => $deposit->user_id,
            'reason' => $reason,
        ]);

        return $deposit->fresh();
    }

    private function guardMismatch(Deposit $deposit): void
    {
        if ($deposit->status !== 'amount_mismatch') {
            throw new RuntimeException("Deposit #{$deposit->id} is not flagged amount_mismatch (status: {$deposit->status}).");
        }
    }
}
