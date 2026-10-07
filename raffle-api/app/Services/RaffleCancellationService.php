<?php

namespace App\Services;

use App\Exceptions\DuplicatePostingException;
use App\Jobs\RefundCancelledRaffle;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\RaffleBonusEntry;
use App\Models\RaffleDraw;
use App\Models\Wallet;
use App\Notifications\RaffleCancelledRefund;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Cancel a raffle and give every ticket buyer their money back, in one
 * action (Raffles → a raffle → Cancel and refund).
 *
 *  1. cancel(): closes sales at once (the raffle can never be reopened or
 *     drawn), records who and why, and starts the refunds.
 *  2. refundBatch(): refunds customers a batch at a time, in the
 *     background. Each customer is refunded inside one database
 *     transaction: their money goes back where it came from (spending
 *     wallet or winnings; bank-transfer purchases to the spending wallet),
 *     their tickets and free bonus entries in this raffle are removed, and
 *     the purchase is marked "refunded" so it can never be refunded twice.
 *     Because refunded tickets are gone, an interrupted run simply carries
 *     on with whoever is left; nobody is skipped or paid twice.
 *  3. Each customer gets a message (site bell and email) saying how much
 *     came back and where. Staff see progress on the raffle list.
 *
 * Refused once the draw has run: winners exist, and taking tickets back
 * then would be unfair to them.
 */
class RaffleCancellationService
{
    private const PURCHASE_DESTINATION = [
        'ticket_purchase_wallet' => 'wallet',
        'ticket_purchase_earnings' => 'earnings',
    ];

    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly AdminAuditLogService $auditLog,
    ) {}

    /** Why this raffle can't be cancelled, or null. */
    public function blocker(Raffle $raffle): ?string
    {
        return match (true) {
            $raffle->cancelled_at !== null => 'Already cancelled.',
            RaffleWinner::query()->where('raffle_id', $raffle->public_id)->exists()
                || (bool) RaffleDraw::query()->where('raffle_id', $raffle->id)->first()?->hasRun() => 'The draw has already run, so there are winners. It can\'t be cancelled.',
            default => null,
        };
    }

    /**
     * @throws RuntimeException when it can't be cancelled
     */
    public function cancel(WpUser $admin, Raffle $raffle, string $reason): Raffle
    {
        $raffle = DB::transaction(function () use ($admin, $raffle, $reason) {
            $locked = Raffle::query()->whereKey($raffle->id)->lockForUpdate()->firstOrFail();

            if ($why = $this->blocker($locked)) {
                throw new RuntimeException($why);
            }

            $locked->update([
                'status' => 'closed',
                'cancelled_at' => now(),
                'cancelled_by' => $admin->ID,
                'cancel_reason' => mb_substr(trim($reason), 0, 300),
                'refund_status' => 'refunding',
            ]);

            return $locked;
        });

        $this->auditLog->record($admin, 'raffle.cancelled', Raffle::class, $raffle->id, [
            'raffle' => "#{$raffle->public_id} {$raffle->title}",
            'reason' => $raffle->cancel_reason,
            'tickets' => RaffleEntry::query()->where('raffle_id', $raffle->public_id)->count(),
            'customers' => RaffleEntry::query()->where('raffle_id', $raffle->public_id)->distinct()->count('user_id'),
        ]);

        RefundCancelledRaffle::dispatch($raffle->id);

        return $raffle;
    }

    /**
     * Refunds up to $customers people. Returns how many were refunded; 0
     * means everyone has been (and the raffle is marked "refunded").
     */
    public function refundBatch(Raffle $raffle, int $customers = 100): int
    {
        if (! $raffle->cancelled_at) {
            return 0;
        }

        $userIds = RaffleEntry::query()->where('raffle_id', $raffle->public_id)->distinct()->orderBy('user_id')->limit($customers)->pluck('user_id');
        $done = 0;

        foreach ($userIds as $userId) {
            try {
                $amounts = $this->refundCustomer($raffle, (int) $userId);
            } catch (Throwable $e) {
                report($e);

                continue; // retried on the next run; the others still get their money
            }

            $done++;
            $total = array_sum($amounts);

            Raffle::query()->whereKey($raffle->id)->update([
                'refunded_customers' => DB::raw('refunded_customers + 1'),
                'refunded_total' => DB::raw('refunded_total + '.number_format($total, 2, '.', '')),
            ]);

            try {
                WpUser::query()->find($userId)?->notify(new RaffleCancelledRefund($raffle, $amounts));
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($userIds->isEmpty()) {
            RaffleBonusEntry::query()->where('raffle_id', $raffle->public_id)->delete();

            if ($raffle->refund_status !== 'refunded') {
                $raffle->update(['refund_status' => 'refunded']);
            }
        }

        return $done;
    }

    /**
     * One customer, all their tickets in this raffle, in one transaction.
     *
     * @return array{wallet: float, earnings: float}
     */
    private function refundCustomer(Raffle $raffle, int $userId): array
    {
        return DB::transaction(function () use ($raffle, $userId) {
            $this->ledger->lockWallet($userId);

            $entries = RaffleEntry::query()->where('raffle_id', $raffle->public_id)->where('user_id', $userId)->lockForUpdate()->get();
            $back = ['wallet' => 0.0, 'earnings' => 0.0];

            foreach ($entries->groupBy('txn_id') as $txnId => $tickets) {
                $transaction = $txnId ? RaffleTransaction::query()->whereKey($txnId)->lockForUpdate()->first() : null;

                if ($transaction && $transaction->status === 'verified_final') {
                    // Money back where it came from; a bank-transfer purchase to the spending wallet.
                    $to = self::PURCHASE_DESTINATION[$transaction->type] ?? 'wallet';
                    $amount = (float) $transaction->claimed_amount;
                    $transaction->update(['status' => 'refunded']);
                } elseif (! $transaction) {
                    // Tickets from the old site with no purchase record: the ticket price.
                    $to = 'wallet';
                    $amount = round((float) $raffle->price * $tickets->count(), 2);
                } else {
                    continue; // that purchase was already reversed or refunded
                }

                if ($amount <= 0) {
                    continue;
                }

                $key = $transaction
                    ? "ticket_refund:raffle_transaction:{$transaction->id}"
                    : "ticket_refund:raffle:{$raffle->public_id}:user:{$userId}";

                try {
                    $this->ledger->credit(
                        userId: $userId,
                        balanceType: $to,
                        amount: $amount,
                        reason: 'ticket_purchase_refunded',
                        key: $key,
                        from: 'ticket_sales',
                        referenceType: RaffleTransaction::class,
                        referenceId: $transaction?->id,
                        description: "Raffle #{$raffle->public_id} ({$raffle->title}) was cancelled",
                    );
                } catch (DuplicatePostingException) {
                    continue; // already refunded once; never twice
                }

                $back[$to] += $amount;
            }

            RaffleEntry::query()->where('raffle_id', $raffle->public_id)->where('user_id', $userId)->delete();
            RaffleBonusEntry::query()->where('raffle_id', $raffle->public_id)->where('user_id', $userId)->delete();

            return $back;
        });
    }
}
