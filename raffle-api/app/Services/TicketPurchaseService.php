<?php

namespace App\Services;

use App\Events\RaffleTicketsUpdated;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\RaffleNotOnSaleException;
use App\Exceptions\TicketUnavailableException;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Notifications\TicketPurchaseReceipt;
use App\Services\Engagement\Perks;
use App\Services\Engagement\Progress;
use App\Services\Growth\PromoCodeService;
use App\Support\Live;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single settlement path for "a user paid, now give them tickets."
 *
 * This exists to close two confirmed problems from the audit:
 *
 *  - TD-06: the old code (api-financials.php) debits the wallet BEFORE
 *    inserting ticket rows, with no rollback if a ticket number turns out
 *    to be taken. A user can pay and get nothing. Here, the debit and the
 *    ticket allocation happen inside ONE database transaction — if any
 *    ticket number is taken, EVERYTHING rolls back, including the debit,
 *    before the caller ever sees a response.
 *
 *  - TD-05: the old code has three separate payment branches (wallet,
 *    earnings, bank-transfer) and only two of them insert ticket rows —
 *    the bank-transfer branch appears to never call the equivalent of
 *    recordEntriesForVerifiedTransaction() below. Here there is exactly
 *    ONE function that creates ticket entries, called by every funding
 *    path, so it cannot be silently skipped for one of them again.
 *
 * IMPORTANT — this operates on the NEW `wallets` table (see
 * app/Models/Wallet.php), not the old wp_usermeta wallet_balance/
 * earnings_balance rows the live PHP site still reads and writes. Do not
 * call this from anything user-facing until a real, deliberate cutover has
 * happened (see raffle-api/LEGACY_MIGRATION.md) — otherwise the old site
 * and this one will each think they know the "real" balance and disagree.
 */
class TicketPurchaseService
{
    public function __construct(
        private readonly TicketPricingService $pricing,
        private readonly WalletLedgerService $ledger,
        private readonly RaffleReadService $raffles,
        private readonly GoldenBoxService $goldenBox,
        private readonly WinningsTransferService $winnings,
        private readonly RaffleRulesService $rules,
        private readonly ResponsiblePlayService $play,
        private readonly PromoCodeService $promos,
        private readonly NumberHoldService $holds,
    ) {}

    /**
     * Pay for tickets out of the user's wallet or earnings balance and
     * allocate the chosen ticket numbers, atomically.
     *
     * @param  int[]  $ticketNumbers
     * @param  'wallet'|'earnings'  $fundingSource
     * @param  string  $idempotencyKey  A key the CLIENT generates once per purchase attempt
     *                                  (e.g. a UUID created when the "Pay" button is first
     *                                  pressed) and resends unchanged on any retry. Prevents
     *                                  a double-tap or a retried request from charging twice.
     * @param  bool  $coverShortfallFromWinnings  Wallet purchases only: if the wallet is short,
     *                                            move exactly the difference from winnings first
     *                                            (checkout's "Use winnings to cover it"), in the
     *                                            same transaction as the purchase.
     *
     * @param  string|null  $promoCode  A promo code typed at checkout (Settings → On / off → New
     *                                  features). Its discount is worked out here, never trusted
     *                                  from the request.
     *
     * @throws InsufficientBalanceException
     * @throws TicketUnavailableException
     * @throws RaffleNotOnSaleException if the raffle is unknown, a draft, closed, ended or sold out
     * @throws InvalidArgumentException if the price changed, the submitted amount is wrong, or a ticket number is invalid
     */
    public function purchaseFromBalance(
        WpUser $user,
        int $raffleId,
        array $ticketNumbers,
        float $unitPrice,
        bool $isGoldenBox,
        float $submittedAmount,
        string $fundingSource,
        string $idempotencyKey,
        bool $coverShortfallFromWinnings = false,
        ?string $promoCode = null,
    ): RaffleTransaction {
        if (! in_array($fundingSource, ['wallet', 'earnings'], true)) {
            throw new InvalidArgumentException("Unknown funding source: {$fundingSource}");
        }

        if ($ticketNumbers === []) {
            throw new InvalidArgumentException('At least one ticket number is required.');
        }

        // Idempotency check FIRST, outside any lock — a retried request
        // with the same key gets back the original result instead of
        // being processed twice.
        $existing = RaffleTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Everything below is checked against the raffle's REAL record,
        // never trusted from the request (OVERHAUL_CHECKLIST.md item 43):
        // the unit price and Golden Box flag used to come straight from
        // the customer's browser, so anyone could pay ₦0.01 a ticket or
        // award themselves the Golden Box discount; closed, ended and
        // unknown raffles weren't refused at all; and ticket numbers
        // weren't checked against the raffle's range.
        $raffle = $this->raffles->find($raffleId);

        if (! $raffle || $raffle['is_closed']) {
            throw new RaffleNotOnSaleException($raffle['closed_reason'] ?? null);
        }

        // Raffle Rules Engine: members-only / new-players-only raffles.
        if ($reason = $this->rules->whyNotEligible($user->ID, $raffleId)) {
            throw new InvalidArgumentException($reason.' No money has been taken.');
        }

        if (abs($unitPrice - $raffle['price']) > 0.001) {
            throw new InvalidArgumentException('The ticket price for this raffle has changed. Please go back and review your order before paying.');
        }

        $unitPrice = (float) $raffle['price'];

        // The Golden Box discount comes only from an offer the server gave
        // this customer for this exact raffle and ticket count (item 46),
        // never from the request's own is_golden_box flag.
        $goldenOffer = $this->goldenBox->discountFor($user->ID, $raffleId, count($ticketNumbers));
        $isGoldenBox = $goldenOffer !== null;

        if (count(array_unique($ticketNumbers)) !== count($ticketNumbers)) {
            throw new InvalidArgumentException('The same ticket number was picked twice. Please review your numbers.');
        }

        $outOfRange = array_values(array_filter($ticketNumbers, fn (int $n) => $n < 1 || $n > $raffle['max_tickets']));

        if ($outOfRange !== []) {
            throw new InvalidArgumentException('Ticket numbers must be between 1 and '.$raffle['max_tickets'].'.');
        }

        // Numbers another player is holding for their own checkout can't be
        // paid for by someone else (they are held for a few minutes only).
        $this->holds->assertNotHeldByOthers($raffleId, $ticketNumbers, $user->ID);

        // Promo code: its discount comes off the price after any bulk or
        // Golden Box discount. An invalid code is refused outright rather
        // than silently charging the full price.
        $promo = null;
        $promoDiscount = 0.0;

        if (PromoCodeService::normalise($promoCode) !== '') {
            ['promo' => $promo, 'discount' => $promoDiscount] = $this->promos->quote(
                $user,
                $promoCode,
                $this->pricing->calculate(count($ticketNumbers), $unitPrice, $isGoldenBox),
            );

            $expected = round($this->pricing->calculate(count($ticketNumbers), $unitPrice, $isGoldenBox) - $promoDiscount, 2);

            if (abs($submittedAmount - $expected) > 0.01) {
                throw new InvalidArgumentException(sprintf('With your promo code the price is ₦%s. Please review your order before paying.', number_format($expected, 2)));
            }
        } elseif (! $this->pricing->matchesExpectedPrice($submittedAmount, count($ticketNumbers), $unitPrice, $isGoldenBox)) {
            if (! $isGoldenBox && $this->pricing->matchesExpectedPrice($submittedAmount, count($ticketNumbers), $unitPrice, true)
                && ! $this->pricing->matchesExpectedPrice($submittedAmount, count($ticketNumbers), $unitPrice)) {
                throw new InvalidArgumentException(sprintf(
                    'Your Golden Box discount has ended. The price is now ₦%s. Please review your order before paying.',
                    number_format($this->pricing->calculate(count($ticketNumbers), $unitPrice), 2),
                ));
            }

            $expected = $this->pricing->calculate(count($ticketNumbers), $unitPrice, $isGoldenBox);

            throw new InvalidArgumentException(
                sprintf('Price mismatch: expected %.2f for %d ticket(s), received %.2f.', $expected, count($ticketNumbers), $submittedAmount)
            );
        }

        $balanceColumn = $fundingSource === 'wallet' ? 'wallet_balance' : 'earnings_balance';
        $transactionType = $fundingSource === 'wallet' ? 'ticket_purchase_wallet' : 'ticket_purchase_earnings';

        try {
            $transaction = DB::transaction(function () use (
                $user, $raffleId, $ticketNumbers, $submittedAmount,
                $balanceColumn, $transactionType, $idempotencyKey, $fundingSource,
                $coverShortfallFromWinnings, $goldenOffer, $promo, $promoDiscount
            ) {
                // Lock this user's wallet row for the duration of the
                // transaction — a concurrent purchase or transfer by the
                // same user has to wait, not read a stale balance.
                $wallet = Wallet::query()
                    ->where('user_id', $user->ID)
                    ->lockForUpdate()
                    ->first();

                // Responsible play (item 38): the customer's own break and
                // spending limits. Checked under the wallet lock, so two
                // purchases at once can't both slip past a limit.
                $this->play->assertCanSpend($user->ID, (float) $submittedAmount);

                $currentBalance = (float) ($wallet->{$balanceColumn} ?? 0);

                if ($currentBalance < $submittedAmount && $fundingSource === 'wallet' && $coverShortfallFromWinnings) {
                    $shortfall = round($submittedAmount - $currentBalance, 2);
                    $earnings = (float) ($wallet->earnings_balance ?? 0);

                    if ($earnings + 0.001 < $shortfall) {
                        throw new InsufficientBalanceException(round($shortfall - $earnings, 2));
                    }

                    $this->winnings->moveWithinLockedWallet($wallet, $shortfall, $user->ID);
                    $currentBalance = (float) $wallet->wallet_balance;
                }

                if ($currentBalance < $submittedAmount) {
                    throw new InsufficientBalanceException($submittedAmount - $currentBalance);
                }

                $wallet->{$balanceColumn} = $currentBalance - $submittedAmount;
                $wallet->save();

                $transaction = RaffleTransaction::create([
                    'user_id' => $user->ID,
                    'claimed_amount' => $submittedAmount,
                    'status' => 'verified_final',
                    'type' => $transactionType,
                    'proof_url' => $balanceColumn === 'wallet_balance' ? 'wallet_debit' : 'earnings_debit',
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Every balance mutation gets a permanent ledger entry,
                // in the same transaction as the mutation itself — see
                // WalletLedgerService.
                $this->ledger->recordDebit(
                    userId: $user->ID,
                    balanceType: $fundingSource,
                    amount: $submittedAmount,
                    reason: 'ticket_purchase',
                    referenceType: 'raffle_transaction',
                    referenceId: $transaction->id,
                );

                // Ticket allocation happens INSIDE the same transaction as
                // the debit above. If this throws, the debit and the
                // transaction row created a moment ago are rolled back too
                // — the fix for TD-06.
                $this->allocateEntries($raffleId, $user->ID, $ticketNumbers, $transaction->id);

                // Uses the discount up in this same transaction; if it ran
                // out (or another purchase used it) a moment ago, nothing
                // is charged and the customer sees the full price instead.
                if ($goldenOffer && ! $this->goldenBox->markUsed($goldenOffer, $transaction->id)) {
                    throw new InvalidArgumentException('Your Golden Box discount has ended. Please go back and review your order before paying.');
                }

                // Uses the promo code up; if its last use went a moment ago,
                // everything above rolls back and nothing is charged.
                if ($promo) {
                    $this->promos->redeemInPurchase($promo, $user, $promoDiscount, $transaction->id);
                }

                return $transaction;
            });

            $this->goldenBox->markCompleted($user->ID);

            // The numbers are sold now; the hold on them has done its job.
            $this->holds->release($raffleId, $ticketNumbers, $user->ID, null);

            // Both fire AFTER the transaction commits — never inside it,
            // so a receipt or a live update can't go out for a purchase
            // that then rolls back.
            $user->notify(new TicketPurchaseReceipt($transaction, count($ticketNumbers)));
            $this->broadcastTicketsUpdated($raffleId, $ticketNumbers);

            app(\App\Services\Analytics\Analytics::class)->capture($user->ID, 'tickets_purchased', [
                'raffle_id' => $raffleId,
                'ticket_count' => count($ticketNumbers),
                'amount' => round($submittedAmount, 2),
                'funding_source' => $fundingSource,
                'golden_box' => $isGoldenBox,
                'promo_code' => $promo?->code,
            ]);

            return $transaction;
        } catch (UniqueConstraintViolationException) {
            // The transaction above has already been rolled back by this
            // point — no balance was actually debited. Work out which of
            // the requested numbers are the problem so the caller can
            // show the user something useful (e.g. "pick again").
            throw new TicketUnavailableException($this->findUnavailable($raffleId, $ticketNumbers));
        }
    }

    /**
     * For a transaction that was verified by some OTHER means (a bank
     * transfer confirmed by admin or AI review, a future payment gateway
     * webhook) — this is the one function that must be called afterward
     * to actually create the tickets. Fixes TD-05: there is no longer a
     * payment path that can mark a transaction "verified" without also
     * being required to call this.
     *
     * @param  int[]  $ticketNumbers
     *
     * @throws TicketUnavailableException
     */
    public function recordEntriesForVerifiedTransaction(RaffleTransaction $transaction, int $raffleId, array $ticketNumbers): void
    {
        if ($transaction->status !== 'verified_final') {
            throw new InvalidArgumentException(
                "Transaction #{$transaction->id} is not verified_final (status: {$transaction->status}); refusing to allocate tickets for it."
            );
        }

        if ($ticketNumbers === []) {
            throw new InvalidArgumentException('At least one ticket number is required.');
        }

        try {
            DB::transaction(function () use ($raffleId, $transaction, $ticketNumbers) {
                $this->allocateEntries($raffleId, $transaction->user_id, $ticketNumbers, $transaction->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw new TicketUnavailableException($this->findUnavailable($raffleId, $ticketNumbers));
        }

        $this->broadcastTicketsUpdated($raffleId, $ticketNumbers);
    }

    /**
     * Re-reads the raffle's own real sold/remaining/closed state (the
     * SAME numbers RaffleReadService gives every other reader — this is
     * not a separately-maintained counter that could drift from it) and
     * broadcasts it. Silently does nothing if the raffle can't be found
     * (e.g. a test exercising this service against a raffle id with no
     * backing post) — a live update is a nice-to-have, never something
     * a purchase should fail over. The numbers just taken go out too, so
     * every open number grid greys them out at once (item 46).
     */
    private function broadcastTicketsUpdated(int $raffleId, array $ticketNumbers = []): void
    {
        $raffle = $this->raffles->find($raffleId);

        if (! $raffle) {
            return;
        }

        Live::send(new RaffleTicketsUpdated(
            raffleId: $raffleId,
            soldTickets: $raffle['sold_tickets'],
            remainingTickets: $raffle['remaining_tickets'],
            isClosed: $raffle['is_closed'],
            takenNumbers: array_values(array_map('intval', $ticketNumbers)),
        ));
    }

    /**
     * @param  int[]  $ticketNumbers
     */
    private function allocateEntries(int $raffleId, int $userId, array $ticketNumbers, int $transactionId): void
    {
        $now = now();

        RaffleEntry::query()->insert(array_map(
            fn (int $number) => [
                'user_id' => $userId,
                'raffle_id' => $raffleId,
                'ticket_number' => $number,
                'txn_id' => $transactionId,
                'created_at' => $now,
            ],
            $ticketNumbers
        ));

        // Loyalty bonus entries, when this raffle's published rules give them.
        $this->rules->grantBonusEntries($userId, $raffleId);

        // Phase 11: free bonus-entry tokens (e.g. from the Season Pass) go into this raffle.
        $this->rules->addEarnedEntries($userId, $raffleId, app(Perks::class)->takeBonusTokens($userId), 'token');

        // Phase 11: badges, Season Pass XP, milestones (after the purchase saves).
        app(Progress::class)->ticketsBought($userId, count($ticketNumbers), $raffleId);
    }

    /**
     * @param  int[]  $requestedNumbers
     * @return int[]
     */
    private function findUnavailable(int $raffleId, array $requestedNumbers): array
    {
        return RaffleEntry::query()
            ->where('raffle_id', $raffleId)
            ->whereIn('ticket_number', $requestedNumbers)
            ->pluck('ticket_number')
            ->map(fn ($n) => (int) $n)
            ->values()
            ->all();
    }
}
