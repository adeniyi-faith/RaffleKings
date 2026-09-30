<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\WithdrawalRequest;
use App\Notifications\PayoutProblemAdminAlert;
use App\Services\Payments\PaystackApi;
use App\Support\Features;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Automatic payouts (Settings → On / off → New features): staff approve a
 * withdrawal with "Send with Paystack" and Paystack moves the money.
 *
 * A withdrawal's life with this on:
 *
 *   pending ──Send──▶ pending + payout "sending" ──Paystack: success──▶ paid
 *                              │
 *                              └──Paystack: failed / reversed──▶ pending + payout "failed"
 *                                  (staff can send again, pay by hand, or reject)
 *
 * The withdrawal only becomes "paid" (and the customer is told) when
 * Paystack confirms, never when we merely asked. While money is on the
 * way, Mark paid and Reject are refused, so a payout can't be paid twice
 * or refunded while it is still travelling.
 *
 * Each attempt has its own reference. Paystack treats a repeated
 * reference as the same transfer, and checkStuck() asks Paystack about any
 * attempt whose answer never arrived (a timeout, a lost webhook), so a
 * network hiccup never becomes a double payment.
 */
class PayoutService
{
    /** Asked about again after this long without an answer. */
    public const CHECK_AFTER_MINUTES = 10;

    /** No trace at Paystack after this long = it never arrived; safe to send again. */
    public const GIVE_UP_AFTER_MINUTES = 30;

    public function __construct(
        private readonly PaystackApi $paystack,
        private readonly WithdrawalService $withdrawals,
        private readonly AdminAuditLogService $auditLog,
    ) {}

    /** Why this withdrawal can't be sent with Paystack, or null if it can. */
    public function blocker(WithdrawalRequest $withdrawal): ?string
    {
        $account = $withdrawal->bankAccount;
        $max = (float) config('withdrawals.auto_payout_max', 0);

        return match (true) {
            ! Features::on('auto_payouts') => 'Automatic payouts are switched off.',
            ! $this->paystack->configured() => 'Paystack is not set up.',
            $withdrawal->status !== 'pending' => 'This withdrawal is not waiting to be paid.',
            $withdrawal->payout_status === 'sending' => 'Paystack is already sending this.',
            ! $account => 'No bank account on file.',
            ! $account->isVerified() => 'The bank account was saved before the bank-name check, so pay by hand.',
            $max > 0 && (float) $withdrawal->amount_to_send > $max => 'Above the automatic limit of ₦'.number_format($max).' (Settings → Withdrawals), so pay by hand.',
            default => null,
        };
    }

    /**
     * Staff approved: ask Paystack to send the money.
     *
     * @return string what happened, in plain words for the staff member
     *
     * @throws RuntimeException when it can't be sent (the reason says why)
     */
    public function send(WpUser $admin, WithdrawalRequest $withdrawal): string
    {
        $withdrawal = DB::transaction(function () use ($withdrawal, $admin) {
            $locked = WithdrawalRequest::query()->with('bankAccount')->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($reason = $this->blocker($locked)) {
                throw new RuntimeException($reason);
            }

            $attempt = $locked->payout_attempts + 1;
            $locked->update([
                'payout_status' => 'sending',
                'payout_reference' => sprintf('rkwd-%d-%d-%s', $locked->id, $attempt, Str::lower(Str::random(6))),
                'payout_transfer_code' => null,
                'payout_error' => null,
                'payout_attempts' => $attempt,
                'payout_started_at' => now(),
                'payout_started_by' => $admin->ID,
            ]);

            return $locked;
        });

        $this->auditLog->record($admin, 'withdrawal.payout_sent', WithdrawalRequest::class, $withdrawal->id, [
            'amount' => (float) $withdrawal->amount_to_send,
            'reference' => $withdrawal->payout_reference,
            'user_id' => $withdrawal->user_id,
        ]);

        try {
            $recipient = $this->recipientFor($withdrawal->bankAccount);
        } catch (PaymentGatewayException $e) {
            // Nothing was sent yet, so it's safe to try again straight away.
            $this->fail($withdrawal, $e->getMessage(), alert: false);

            throw new RuntimeException('Not sent: '.$e->getMessage());
        }

        try {
            $result = $this->paystack->transfer(
                (float) $withdrawal->amount_to_send,
                $recipient,
                $withdrawal->payout_reference,
                config('app.name').' withdrawal #'.$withdrawal->id,
            );
        } catch (PaymentGatewayException $e) {
            if ($e->getPrevious() instanceof ConnectionException) {
                // We can't know whether Paystack got it. checkStuck() asks
                // Paystack in a few minutes; until then it stays "sending".
                return 'No answer from Paystack yet. The site will check with Paystack in a few minutes; don\'t pay this by hand meanwhile.';
            }

            $this->fail($withdrawal, $e->getMessage(), alert: false);

            throw new RuntimeException('Not sent: '.$e->getMessage());
        }

        $withdrawal->update(['payout_transfer_code' => $result['transfer_code']]);

        return $this->apply($withdrawal->refresh(), $result['status'], $result['message']) ?? 'Paystack is sending ₦'.number_format((float) $withdrawal->amount_to_send).'. It is marked paid, and the customer told, as soon as the bank confirms.';
    }

    /**
     * Paystack's transfer.success / transfer.failed / transfer.reversed
     * webhook. The event is only a hint: Paystack is asked directly before
     * anything changes, like top-ups (DepositService::confirm()).
     */
    public function handleWebhook(string $reference): void
    {
        $withdrawal = WithdrawalRequest::query()->where('payout_reference', $reference)->first();

        if (! $withdrawal) {
            return;
        }

        $this->refreshFromPaystack($withdrawal);
    }

    /**
     * Every few minutes (routes/console.php): asks Paystack about payouts
     * still "sending" whose answer never came.
     *
     * @return int how many were looked at
     */
    public function checkStuck(): int
    {
        if (! $this->paystack->configured()) {
            return 0;
        }

        $stuck = WithdrawalRequest::query()
            ->where('payout_status', 'sending')
            ->where('payout_started_at', '<=', now()->subMinutes(self::CHECK_AFTER_MINUTES))
            ->limit(50)
            ->get();

        foreach ($stuck as $withdrawal) {
            try {
                $this->refreshFromPaystack($withdrawal);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $stuck->count();
    }

    public function refreshFromPaystack(WithdrawalRequest $withdrawal): ?string
    {
        if (! $withdrawal->payout_reference) {
            return null;
        }

        $found = $this->paystack->verifyTransfer($withdrawal->payout_reference);

        if ($found === null) {
            if ($withdrawal->payout_status === 'sending' && $withdrawal->payout_started_at?->lte(now()->subMinutes(self::GIVE_UP_AFTER_MINUTES))) {
                $this->fail($withdrawal, 'Paystack never received this payout, so no money left. It is safe to send again.');
            }

            return null;
        }

        return $this->apply($withdrawal, $found['status'], $found['reason']);
    }

    /** Acts on a Paystack status. Returns a plain message when it finished one way or the other. */
    private function apply(WithdrawalRequest $withdrawal, string $status, ?string $detail): ?string
    {
        $status = strtolower($status);

        if ($status === 'success') {
            return $this->complete($withdrawal);
        }

        if ($status === 'otp') {
            $this->fail($withdrawal, 'Paystack wants a one-time code for each transfer. In Paystack: Settings → Preferences → untick "Confirm transfers before sending", then send again.');

            return 'Not sent: Paystack asked for a one-time code. See the note on this withdrawal.';
        }

        if (in_array($status, ['failed', 'reversed', 'abandoned', 'rejected', 'blocked'], true)) {
            if ($withdrawal->status === 'paid') {
                // Paystack took back money we had already reported as paid.
                $withdrawal->update(['payout_status' => 'reversed', 'payout_error' => mb_substr('Paystack reversed this payout after it was paid'.($detail ? ": {$detail}" : '.'), 0, 300)]);
                $this->alert($withdrawal, 'Reversed AFTER being marked paid. The customer may not have their money. Check Paystack and the customer.');

                return null;
            }

            $this->fail($withdrawal, 'Paystack could not send it ('.$status.')'.($detail ? ": {$detail}" : '.'));

            return 'Not sent: Paystack says '.$status.'. It is back in the queue.';
        }

        return null; // pending, processing, received, queued: still on the way
    }

    private function complete(WithdrawalRequest $withdrawal): string
    {
        $done = DB::transaction(function () use ($withdrawal) {
            $locked = WithdrawalRequest::query()->whereKey($withdrawal->id)->lockForUpdate()->first();

            if (! $locked || $locked->payout_status === 'success') {
                return false; // the webhook and the check both arrived: once is enough
            }

            $locked->update(['payout_status' => 'success', 'payout_error' => null]);

            return true;
        });

        $withdrawal->refresh();

        if ($done && $withdrawal->status === 'pending') {
            $admin = WpUser::query()->find($withdrawal->payout_started_by) ?? $withdrawal->user;
            $this->withdrawals->markPaid($admin, $withdrawal, viaPaystack: true);
        }

        return 'Sent. Paystack confirmed ₦'.number_format((float) $withdrawal->amount_to_send).' reached the bank, and the customer has been told.';
    }

    private function fail(WithdrawalRequest $withdrawal, string $reason, bool $alert = true): void
    {
        $withdrawal->update(['payout_status' => 'failed', 'payout_error' => mb_substr($reason, 0, 300)]);

        if ($alert) {
            $this->alert($withdrawal, 'Not sent, back in the queue: '.$reason);
        }
    }

    private function alert(WithdrawalRequest $withdrawal, string $problem): void
    {
        try {
            Notification::send(new AnonymousNotifiable, new PayoutProblemAdminAlert($withdrawal, $problem));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Paystack's id for the bank account, made once and remembered. */
    private function recipientFor(BankAccount $account): string
    {
        if ($account->paystack_recipient_code) {
            return $account->paystack_recipient_code;
        }

        $code = $this->paystack->createRecipient($account->account_name, $account->account_number, (string) $account->bank_code);
        $account->forceFill(['paystack_recipient_code' => $code])->save();

        return $code;
    }
}
