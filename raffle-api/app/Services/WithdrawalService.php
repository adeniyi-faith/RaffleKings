<?php

namespace App\Services;

use App\Exceptions\BankAccountNotFoundException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MinimumWithdrawalNotMetException;
use App\Exceptions\VerificationFeeRequiredException;
use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use App\Notifications\WithdrawalProcessed;
use App\Notifications\WithdrawalRequestSubmittedAdminAlert;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Withdrawal requests against the earnings balance — same rules as the
 * legacy rk_handle_withdrawal() (wp-core/api-financials.php): a ₦2,000
 * minimum, and a one-time ₦1,000 "account verification" fee for anyone
 * whose lifetime deposits are below ₦1,000 (deducted from the
 * withdrawal itself if the balance can't cover both, exactly like the
 * legacy "smart balance" logic).
 *
 * What's different from the legacy version: requirements() lets a
 * client find out BEFORE submitting whether the verification fee will
 * apply, so it can be shown on the withdraw page upfront — the audit's
 * UX findings flagged the legacy version springing this fee on users
 * only at the moment they try to cash out as the single worst-timed
 * friction point in the whole customer journey. The underlying fee
 * mechanic itself (paid once, and the fee amount is then credited back
 * into the user's own wallet so it counts toward their own future
 * "lifetime deposits") is kept as-is — that's a product/business
 * decision (see audit §15), not something to silently change while
 * migrating the code that enforces it.
 */
class WithdrawalService
{
    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly AdminAuditLogService $auditLog,
    ) {}

    /**
     * What a withdrawal will cost this user right now — safe to call
     * before showing a withdraw form, so the fee (if any) is never a
     * surprise.
     */
    public function requirements(WpUser $user): array
    {
        $lifetimeDeposits = $this->lifetimeDeposits($user);
        $requiresFee = $lifetimeDeposits < config('withdrawals.verification_deposit_threshold');

        return [
            'minimum_amount' => config('withdrawals.minimum_amount'),
            'lifetime_deposits' => $lifetimeDeposits,
            'requires_verification_fee' => $requiresFee,
            'verification_fee' => $requiresFee ? config('withdrawals.verification_fee') : 0.0,
        ];
    }

    /**
     * @throws MinimumWithdrawalNotMetException
     * @throws BankAccountNotFoundException
     * @throws VerificationFeeRequiredException
     * @throws InsufficientBalanceException
     */
    public function request(WpUser $user, float $amount, int $bankAccountId, bool $authorizeVerificationFee = false): WithdrawalRequest
    {
        $minimum = (float) config('withdrawals.minimum_amount');

        if ($amount < $minimum) {
            throw new MinimumWithdrawalNotMetException($minimum);
        }

        if (! BankAccount::query()->where('user_id', $user->ID)->whereKey($bankAccountId)->exists()) {
            throw new BankAccountNotFoundException;
        }

        $requiresFee = $this->lifetimeDeposits($user) < config('withdrawals.verification_deposit_threshold');
        $fee = $requiresFee ? (float) config('withdrawals.verification_fee') : 0.0;

        if ($requiresFee && ! $authorizeVerificationFee) {
            throw new VerificationFeeRequiredException($fee);
        }

        $withdrawal = DB::transaction(function () use ($user, $amount, $bankAccountId, $fee, $requiresFee) {
            $wallet = Wallet::query()->where('user_id', $user->ID)->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $user->ID, 'wallet_balance' => 0, 'earnings_balance' => 0]);

            $earnings = (float) $wallet->earnings_balance;

            if ($earnings >= $amount + $fee) {
                $deduct = $amount + $fee;
                $send = $amount;
            } elseif ($requiresFee && $earnings >= $amount) {
                $deduct = $amount;
                $send = $amount - $fee;

                if ($send <= 0) {
                    throw new InsufficientBalanceException($fee - $earnings);
                }
            } else {
                throw new InsufficientBalanceException(($amount + $fee) - $earnings);
            }

            $wallet->earnings_balance = $earnings - $deduct;
            $wallet->save();

            $this->ledger->recordDebit(
                userId: $user->ID,
                balanceType: 'earnings',
                amount: $deduct,
                reason: 'withdrawal_request',
            );

            if ($fee > 0) {
                // Same mechanic as the legacy site: the fee is credited
                // into the user's own spending wallet, tagged 'deposit'
                // so it counts toward THEIR OWN future lifetimeDeposits()
                // check — this is what actually unlocks future
                // withdrawals without the fee. See the class docblock.
                $wallet->wallet_balance = (float) $wallet->wallet_balance + $fee;
                $wallet->save();

                $this->ledger->recordCredit(
                    userId: $user->ID,
                    balanceType: 'wallet',
                    amount: $fee,
                    reason: 'deposit',
                    description: 'Account verification fee (counts toward future lifetime deposits).',
                );
            }

            return WithdrawalRequest::create([
                'user_id' => $user->ID,
                'bank_account_id' => $bankAccountId,
                'requested_amount' => $amount,
                'fee_amount' => $fee,
                'amount_to_send' => $send,
                'status' => 'pending',
            ]);
        });

        // Fired AFTER the transaction commits — same "notify after
        // commit" discipline as every other service in this app.
        Notification::send(new AnonymousNotifiable, new WithdrawalRequestSubmittedAdminAlert($withdrawal));

        return $withdrawal;
    }

    /**
     * Admin confirms the bank transfer was actually sent. Logged to the
     * admin audit log — fixes audit TD-15 ("no admin action audit log
     * anywhere... approvals... are unlogged").
     *
     * @throws RuntimeException if the request isn't pending
     */
    public function markPaid(WpUser $admin, WithdrawalRequest $withdrawal): WithdrawalRequest
    {
        if ($withdrawal->status !== 'pending') {
            throw new RuntimeException("Withdrawal #{$withdrawal->id} is not pending (status: {$withdrawal->status}).");
        }

        $withdrawal->update(['status' => 'paid']);

        $this->auditLog->record($admin, 'withdrawal.paid', WithdrawalRequest::class, $withdrawal->id, [
            'amount_sent' => (float) $withdrawal->amount_to_send,
            'user_id' => $withdrawal->user_id,
        ]);

        $withdrawal->user->notify(new WithdrawalProcessed($withdrawal, 'paid'));

        return $withdrawal;
    }

    /**
     * Admin declines the request — refunds exactly what was taken for
     * THIS request (amount_to_send + fee_amount) back to earnings.
     * Deliberately does NOT reverse a verification-fee credit into the
     * user's wallet balance, if one happened: that credit represents
     * the account having been verified, a fact independent of whether
     * this particular request is later approved or rejected.
     *
     * @throws RuntimeException if the request isn't pending
     */
    public function reject(WpUser $admin, WithdrawalRequest $withdrawal, ?string $reason = null): WithdrawalRequest
    {
        if ($withdrawal->status !== 'pending') {
            throw new RuntimeException("Withdrawal #{$withdrawal->id} is not pending (status: {$withdrawal->status}).");
        }

        DB::transaction(function () use ($withdrawal) {
            $wallet = Wallet::query()->where('user_id', $withdrawal->user_id)->lockForUpdate()->first();
            $refund = (float) $withdrawal->amount_to_send + (float) $withdrawal->fee_amount;

            $wallet->earnings_balance = (float) $wallet->earnings_balance + $refund;
            $wallet->save();

            $this->ledger->recordCredit(
                userId: $withdrawal->user_id,
                balanceType: 'earnings',
                amount: $refund,
                reason: 'withdrawal_rejected',
                referenceType: 'withdrawal_request',
                referenceId: $withdrawal->id,
            );

            $withdrawal->update(['status' => 'rejected']);
        });

        $this->auditLog->record($admin, 'withdrawal.rejected', WithdrawalRequest::class, $withdrawal->id, [
            'reason' => $reason,
            'refunded' => (float) $withdrawal->amount_to_send + (float) $withdrawal->fee_amount,
            'user_id' => $withdrawal->user_id,
        ]);

        $withdrawal->user->notify(new WithdrawalProcessed($withdrawal, 'rejected', $reason));

        return $withdrawal;
    }

    /**
     * A real payment gateway's deposit webhook (item 13) should record
     * its credits with reason 'deposit' via WalletLedgerService — that's
     * the contract this reads.
     */
    private function lifetimeDeposits(WpUser $user): float
    {
        return (float) WalletLedgerEntry::query()
            ->where('user_id', $user->ID)
            ->where('direction', 'credit')
            ->where('reason', 'deposit')
            ->sum('amount');
    }
}
