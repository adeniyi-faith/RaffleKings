<?php

namespace App\Services;

use App\Exceptions\BankAccountNotFoundException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MinimumWithdrawalNotMetException;
use App\Exceptions\DuplicatePostingException;
use App\Exceptions\UserRestrictedException;
use App\Exceptions\VerificationFeeRequiredException;
use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use App\Notifications\WithdrawalProcessed;
use App\Notifications\WithdrawalRequestSubmittedAdminAlert;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use App\Support\Money;
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
        private readonly AccountRestrictions $restrictions,
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
     * Takes the money out of winnings straight away and keeps it in a
     * separate "held" balance until staff pay or reject the request, so a
     * customer can't spend it twice and staff can see what is on its way.
     *
     * @param  string|null  $idempotencyKey  made once per tap by the app; the same
     *                                       key returns the first request instead of making a second
     *
     * @throws UserRestrictedException
     * @throws MinimumWithdrawalNotMetException
     * @throws BankAccountNotFoundException
     * @throws VerificationFeeRequiredException
     * @throws InsufficientBalanceException
     */
    public function request(WpUser $user, float $amount, int $bankAccountId, bool $authorizeVerificationFee = false, ?string $idempotencyKey = null): WithdrawalRequest
    {
        $this->restrictions->assertCanMoveMoney($user->ID, 'withdraw');

        if ($idempotencyKey !== null && ($existing = WithdrawalRequest::query()->where('user_id', $user->ID)->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        $amount = round($amount, 2);
        $minimum = (float) config('withdrawals.minimum_amount');

        if ($amount < $minimum) {
            throw new MinimumWithdrawalNotMetException($minimum);
        }

        $bank = BankAccount::query()->active()->where('user_id', $user->ID)->whereKey($bankAccountId)->first();

        if (! $bank) {
            throw new BankAccountNotFoundException;
        }

        // A bank account added a moment ago can't receive money yet: it gives
        // the real owner time to notice and stop a thief who added it.
        $waitHours = (int) config('withdrawals.new_account_wait_hours', 24);

        if ($waitHours > 0 && $bank->created_at && $bank->created_at->gt(now()->subHours($waitHours))) {
            throw new BankAccountNotFoundException('This bank account was added recently. For your safety it can receive withdrawals from '.$bank->created_at->copy()->addHours($waitHours)->format('j M, g:ia').'.');
        }

        $requiresFee = $this->lifetimeDeposits($user) < config('withdrawals.verification_deposit_threshold');
        $fee = $requiresFee ? (float) config('withdrawals.verification_fee') : 0.0;

        if ($requiresFee && ! $authorizeVerificationFee) {
            throw new VerificationFeeRequiredException($fee);
        }

        try {
            $withdrawal = DB::transaction(function () use ($user, $amount, $bankAccountId, $fee, $requiresFee, $idempotencyKey) {
                $this->ledger->lockWallet($user->ID);
                $earnings = $this->ledger->balances($user->ID)['earnings'];

                $amountKobo = Money::kobo($amount);
                $feeKobo = Money::kobo($fee);

                if ($earnings >= $amountKobo + $feeKobo) {
                    $deduct = $amountKobo + $feeKobo;
                    $send = $amountKobo;
                } elseif ($requiresFee && $earnings >= $amountKobo) {
                    $deduct = $amountKobo;
                    $send = $amountKobo - $feeKobo;

                    if ($send <= 0) {
                        throw new InsufficientBalanceException(Money::toFloat($feeKobo - $earnings));
                    }
                } else {
                    throw new InsufficientBalanceException(Money::toFloat(($amountKobo + $feeKobo) - $earnings));
                }

                $withdrawal = WithdrawalRequest::create([
                    'user_id' => $user->ID,
                    'bank_account_id' => $bankAccountId,
                    'requested_amount' => $amount,
                    'fee_amount' => $fee,
                    'amount_to_send' => Money::naira($send),
                    'status' => 'pending',
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Winnings → held, all of it; then the fee (if any) goes to the
                // spending wallet under its own label, leaving exactly what will
                // be sent in the hold.
                $this->ledger->move(
                    userId: $user->ID,
                    fromBalance: 'earnings',
                    toBalance: 'held',
                    amount: Money::naira($deduct),
                    reason: 'withdrawal_hold',
                    key: "withdrawal_hold:{$withdrawal->id}",
                    referenceType: 'withdrawal_request',
                    referenceId: $withdrawal->id,
                    description: 'Held for withdrawal request',
                    customerAction: 'withdraw',
                );

                if ($fee > 0) {
                    $this->ledger->move(
                        userId: $user->ID,
                        fromBalance: 'held',
                        toBalance: 'wallet',
                        amount: Money::naira($feeKobo),
                        reason: 'verification_fee',
                        key: "verification_fee:{$withdrawal->id}",
                        referenceType: 'withdrawal_request',
                        referenceId: $withdrawal->id,
                        description: 'Account verification fee (counts toward future lifetime deposits).',
                    );
                }

                return $withdrawal;
            });
        } catch (UniqueConstraintViolationException|DuplicatePostingException) {
            // The same tap arrived twice at the same moment: hand back the first.
            return WithdrawalRequest::query()->where('user_id', $user->ID)->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        // Fired AFTER the transaction commits — same "notify after
        // commit" discipline as every other service in this app.
        Notification::send(new AnonymousNotifiable, new WithdrawalRequestSubmittedAdminAlert($withdrawal));

        app(\App\Services\Analytics\Analytics::class)->capture($user->ID, 'withdrawal_requested', [
            'amount' => (float) $withdrawal->requested_amount,
            'fee' => (float) $withdrawal->fee_amount,
        ]);

        return $withdrawal;
    }

    /**
     * Admin confirms the bank transfer was actually sent. The held money
     * leaves the books to the payouts account. Logged to the admin audit log.
     * Staff can't pay their own withdrawal.
     *
     * @throws RuntimeException if the request isn't pending
     */
    public function markPaid(WpUser $admin, WithdrawalRequest $withdrawal, bool $viaPaystack = false): WithdrawalRequest
    {
        $this->guardPending($withdrawal, $viaPaystack);
        $this->guardNotOwn($admin, $withdrawal, $viaPaystack);

        DB::transaction(function () use ($withdrawal, $admin, $viaPaystack) {
            $locked = WithdrawalRequest::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->guardPending($locked, $viaPaystack);

            $this->ledger->debit(
                userId: $locked->user_id,
                balanceType: 'held',
                amount: (string) $locked->amount_to_send,
                reason: 'withdrawal_paid',
                key: "withdrawal_paid:{$locked->id}",
                to: 'payouts',
                referenceType: 'withdrawal_request',
                referenceId: $locked->id,
                description: 'Withdrawal paid',
                createdBy: $admin->ID,
            );

            $locked->update(['status' => 'paid']);
            $withdrawal->setRawAttributes($locked->getAttributes(), true);
        });

        $this->auditLog->record($admin, 'withdrawal.paid', WithdrawalRequest::class, $withdrawal->id, [
            'amount_sent' => (float) $withdrawal->amount_to_send,
            'user_id' => $withdrawal->user_id,
            'method' => $viaPaystack ? 'paystack' : 'by hand',
        ]);

        $withdrawal->user->notify(new WithdrawalProcessed($withdrawal, 'paid'));

        app(\App\Services\Analytics\Analytics::class)->capture($withdrawal->user_id, 'withdrawal_paid', ['amount' => (float) $withdrawal->amount_to_send]);

        return $withdrawal;
    }

    /**
     * Admin declines the request. The held money goes back to winnings, and
     * the verification fee, if one was taken, is taken back out of the
     * spending wallet too (as much of it as is still there), so a rejected
     * request can't be used to collect the fee as free money.
     *
     * @param  string|null  $reason  an internal note, kept in the audit log
     * @param  string|null  $customerMessage  what the customer is told (never the internal note)
     *
     * @throws RuntimeException if the request isn't pending
     */
    public function reject(WpUser $admin, WithdrawalRequest $withdrawal, ?string $reason = null, ?string $customerMessage = null): WithdrawalRequest
    {
        $this->guardPending($withdrawal);
        $this->guardNotOwn($admin, $withdrawal);

        $reclaimed = 0.0;

        DB::transaction(function () use ($withdrawal, $admin, &$reclaimed) {
            // Re-checked under a lock (item 44): two admins clicking Reject
            // at once used to refund twice, and a Reject racing a Mark Paid
            // could leave a withdrawal both paid AND refunded.
            $locked = WithdrawalRequest::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->guardPending($locked);

            $this->ledger->move(
                userId: $locked->user_id,
                fromBalance: 'held',
                toBalance: 'earnings',
                amount: (string) $locked->amount_to_send,
                reason: 'withdrawal_rejected',
                key: "withdrawal_rejected:{$locked->id}",
                referenceType: 'withdrawal_request',
                referenceId: $locked->id,
                description: 'Withdrawal rejected: held money returned to winnings',
                createdBy: $admin->ID,
            );

            $fee = Money::kobo((string) $locked->fee_amount);

            if ($fee > 0) {
                $take = min($fee, $this->ledger->balances($locked->user_id)['wallet']);

                if ($take > 0) {
                    $this->ledger->move(
                        userId: $locked->user_id,
                        fromBalance: 'wallet',
                        toBalance: 'earnings',
                        amount: Money::naira($take),
                        reason: 'verification_fee_reclaimed',
                        key: "verification_fee_reclaimed:{$locked->id}",
                        referenceType: 'withdrawal_request',
                        referenceId: $locked->id,
                        description: 'Verification fee returned to winnings because the withdrawal was rejected',
                        createdBy: $admin->ID,
                    );
                    $reclaimed = Money::toFloat($take);
                }
            }

            $locked->update(['status' => 'rejected']);
            $withdrawal->setRawAttributes($locked->getAttributes(), true);
        });

        $this->auditLog->record($admin, 'withdrawal.rejected', WithdrawalRequest::class, $withdrawal->id, [
            'reason' => $reason,
            'customer_message' => $customerMessage,
            'returned_to_winnings' => (float) $withdrawal->amount_to_send + $reclaimed,
            'fee_reclaimed' => $reclaimed,
            'user_id' => $withdrawal->user_id,
        ]);

        $withdrawal->user->notify(new WithdrawalProcessed($withdrawal, 'rejected', $customerMessage));

        app(\App\Services\Analytics\Analytics::class)->capture($withdrawal->user_id, 'withdrawal_rejected', ['amount' => (float) $withdrawal->requested_amount]);

        return $withdrawal;
    }

    /**
     * @param  bool  $viaPaystack  Paystack itself confirming its own payout (PayoutService)
     *
     * @throws RuntimeException if the request isn't pending, or Paystack is still sending it
     */
    private function guardPending(WithdrawalRequest $withdrawal, bool $viaPaystack = false): void
    {
        if ($withdrawal->status !== 'pending') {
            throw new RuntimeException("Withdrawal #{$withdrawal->id} is not pending (status: {$withdrawal->status}).");
        }

        // Automatic payouts: while Paystack is sending the money, paying it
        // by hand would pay twice and rejecting would refund money already
        // on its way.
        if (! $viaPaystack && in_array($withdrawal->payout_status, ['sending', 'checking'], true)) {
            throw new RuntimeException("Paystack is still sending withdrawal #{$withdrawal->id}. Wait for it to finish (or fail) first.");
        }
    }

    /** Staff never pay or refund their own withdrawal (money-safety audit I2). */
    private function guardNotOwn(WpUser $admin, WithdrawalRequest $withdrawal, bool $viaPaystack = false): void
    {
        if (! $viaPaystack && (int) $admin->ID === (int) $withdrawal->user_id) {
            throw new RuntimeException('You can\'t pay or reject your own withdrawal. Ask another staff member.');
        }
    }

    /**
     * Money the customer has put in with their own payments: top-ups, plus
     * the verification fee they already paid (which unlocks future
     * withdrawals), less any fee taken back when a request was rejected.
     */
    private function lifetimeDeposits(WpUser $user): float
    {
        $sum = fn (string $balance, string $direction, array $reasons) => Money::kobo((string) (WalletLedgerEntry::query()
            ->where('user_id', $user->ID)
            ->where('balance_type', $balance)
            ->where('direction', $direction)
            ->whereIn('reason', $reasons)
            ->sum('amount') ?: '0'));

        $kobo = $sum('wallet', 'credit', ['deposit', 'verification_fee'])
            - $sum('wallet', 'debit', ['verification_fee_reclaimed']);

        return Money::toFloat(max(0, $kobo));
    }
}
