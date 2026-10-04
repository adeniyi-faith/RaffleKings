<?php

namespace App\Services;

use App\Exceptions\DuplicatePostingException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\LedgerJournal;
use App\Models\LedgerSystemEntry;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The ONE way money moves in this app (money-safety audit B1, B2, B5, C1–C4,
 * A2, J2). Every credit, debit and transfer goes through post(), which, in
 * one database transaction:
 *
 *  1. locks the customer's wallet row (creating it if needed), so two
 *     requests for the same wallet take turns;
 *  2. checks the customer's restrictions when the customer started the
 *     action (a ban stops every money action, not just withdrawals);
 *  3. checks the balance can't go below zero, using the locked value;
 *  4. changes the balance by a relative amount (balance = balance - x),
 *     worked out in whole kobo, never floating point;
 *  5. writes a journal with a unique business key (so the same payment can
 *     never be booked twice) plus balanced entries: the customer's side in
 *     wallet_ledger_entries and the business's side in
 *     ledger_system_entries, adding up to zero.
 *
 * Callers never touch wallets.*_balance themselves.
 */
class WalletLedgerService
{
    public const BALANCE_TYPES = ['wallet', 'earnings', 'held'];

    /**
     * The business's side of each movement (the chart of accounts, B9).
     * Customer wallets are money the business owes its customers.
     */
    public const ACCOUNTS = [
        'gateway_clearing' => 'Money received through Paystack/Flutterwave or bank transfer',
        'ticket_sales' => 'Ticket sales (and their refunds)',
        'prizes' => 'Raffle prizes and Daily Drops',
        'promotions' => 'Bonuses, offers, Lucky Meter, points cashed in',
        'referral_expense' => 'Referral commissions',
        'affiliate_expense' => 'Affiliate commissions',
        'payouts' => 'Withdrawals sent to customers\' banks',
        'adjustments' => 'Staff balance adjustments and corrections',
        'opening_balances' => 'Balances carried over from the old site',
    ];

    private const COLUMNS = ['wallet' => 'wallet_balance', 'earnings' => 'earnings_balance', 'held' => 'held_balance'];

    public function __construct(private readonly AccountRestrictions $restrictions) {}

    /**
     * Money comes into a customer's balance from one of the business
     * accounts. Returns the customer's ledger entry.
     *
     * @param  string|null  $customerAction  set when the customer started this
     *                                       (e.g. 'redeem'), so their restrictions are checked
     */
    public function credit(
        int $userId,
        string $balanceType,
        int|float|string $amount,
        string $reason,
        string $key,
        string $from,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?string $customerAction = null,
        ?int $createdBy = null,
    ): WalletLedgerEntry {
        $kobo = $this->positiveKobo($amount);

        return $this->post($key, $reason, [
            [$userId, $balanceType, 'credit', $kobo],
        ], [
            [$from, 'debit', $kobo],
        ], compact('referenceType', 'referenceId', 'description', 'customerAction', 'createdBy'))[0];
    }

    /**
     * Money leaves a customer's balance to one of the business accounts.
     *
     * @throws InsufficientBalanceException
     */
    public function debit(
        int $userId,
        string $balanceType,
        int|float|string $amount,
        string $reason,
        string $key,
        string $to,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?string $customerAction = null,
        ?int $createdBy = null,
    ): WalletLedgerEntry {
        $kobo = $this->positiveKobo($amount);

        return $this->post($key, $reason, [
            [$userId, $balanceType, 'debit', $kobo],
        ], [
            [$to, 'credit', $kobo],
        ], compact('referenceType', 'referenceId', 'description', 'customerAction', 'createdBy'))[0];
    }

    /**
     * Moves money between two of the same customer's balances (winnings to
     * wallet, winnings into a withdrawal hold and back). Returns the debit
     * entry.
     *
     * @throws InsufficientBalanceException
     */
    public function move(
        int $userId,
        string $fromBalance,
        string $toBalance,
        int|float|string $amount,
        string $reason,
        string $key,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?string $customerAction = null,
        ?int $createdBy = null,
    ): WalletLedgerEntry {
        if ($fromBalance === $toBalance) {
            throw new InvalidArgumentException('A move needs two different balances.');
        }

        $kobo = $this->positiveKobo($amount);

        return $this->post($key, $reason, [
            [$userId, $fromBalance, 'debit', $kobo],
            [$userId, $toBalance, 'credit', $kobo],
        ], [], compact('referenceType', 'referenceId', 'description', 'customerAction', 'createdBy'))[0];
    }

    /**
     * Locks (and if needed creates) one customer's wallet row for the rest
     * of the current transaction.
     */
    public function lockWallet(int $userId): Wallet
    {
        $this->assertInTransaction();

        $wallet = Wallet::query()->where('user_id', $userId)->lockForUpdate()->first();

        if ($wallet) {
            return $wallet;
        }

        Wallet::query()->insertOrIgnore([
            'user_id' => $userId,
            'wallet_balance' => 0,
            'earnings_balance' => 0,
            'held_balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Wallet::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Locks several customers' wallets, always in ascending user id order, so
     * two jobs locking overlapping sets can never wait on each other (C4).
     *
     * @param  int[]  $userIds
     * @return array<int, Wallet> keyed by user id
     */
    public function lockWallets(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids);

        $wallets = [];

        foreach ($ids as $id) {
            $wallets[$id] = $this->lockWallet($id);
        }

        return $wallets;
    }

    /**
     * A customer's balances in whole kobo, read fresh (and locked when called
     * inside a transaction).
     *
     * @return array{wallet: int, earnings: int, held: int}
     */
    public function balances(int $userId): array
    {
        $wallet = DB::transactionLevel() > 0
            ? $this->lockWallet($userId)
            : Wallet::query()->where('user_id', $userId)->first();

        return [
            'wallet' => Money::kobo($wallet?->getRawOriginal('wallet_balance')),
            'earnings' => Money::kobo($wallet?->getRawOriginal('earnings_balance')),
            'held' => Money::kobo($wallet?->getRawOriginal('held_balance')),
        ];
    }

    public function journalExists(string $key): bool
    {
        return LedgerJournal::query()->where('business_key', $key)->exists();
    }

    /**
     * Sums every ledger entry for this user/balance type into what the
     * balance SHOULD be. The nightly book check (ledger:check) compares this
     * with the stored balance.
     */
    public function reconstructBalance(int $userId, string $balanceType): float
    {
        return Money::toFloat($this->reconstructKobo($userId, $balanceType));
    }

    public function reconstructKobo(int $userId, string $balanceType): int
    {
        $sum = fn (string $direction) => Money::kobo((string) (WalletLedgerEntry::query()
            ->where('user_id', $userId)
            ->where('balance_type', $balanceType)
            ->where('direction', $direction)
            ->sum('amount') ?: '0'));

        return $sum('credit') - $sum('debit');
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string, 3: int}>  $customerLegs  [userId, balanceType, direction, kobo]
     * @param  list<array{0: string, 1: string, 2: int}>  $systemLegs  [account, direction, kobo]
     * @return list<WalletLedgerEntry> the customer entries, in the order given
     *
     * @throws InsufficientBalanceException
     * @throws DuplicatePostingException
     */
    private function post(string $key, string $reason, array $customerLegs, array $systemLegs, array $meta): array
    {
        $key = trim($key);

        if ($key === '' || mb_strlen($key) > 150) {
            throw new InvalidArgumentException('Every money movement needs a business key (at most 150 characters).');
        }

        if (strlen($reason) > 60 || $reason === '') {
            throw new InvalidArgumentException('Unknown ledger reason.');
        }

        $credits = 0;
        $debits = 0;

        foreach ($customerLegs as [$userId, $type, $direction, $kobo]) {
            if (! isset(self::COLUMNS[$type])) {
                throw new InvalidArgumentException("Unknown balance type: {$type}");
            }

            $direction === 'credit' ? $credits += $kobo : $debits += $kobo;
        }

        foreach ($systemLegs as [$account, $direction, $kobo]) {
            if (! isset(self::ACCOUNTS[$account])) {
                throw new InvalidArgumentException("Unknown business account: {$account}");
            }

            $direction === 'credit' ? $credits += $kobo : $debits += $kobo;
        }

        if ($credits !== $debits) {
            throw new RuntimeException("Journal {$key} does not balance.");
        }

        $this->assertPeriodOpen();

        return DB::transaction(function () use ($key, $reason, $customerLegs, $systemLegs, $meta) {
            $userIds = array_unique(array_column($customerLegs, 0));

            if (($meta['customerAction'] ?? null) !== null) {
                foreach ($userIds as $userId) {
                    $this->restrictions->assertCanMoveMoney($userId, $meta['customerAction']);
                }
            }

            $wallets = $this->lockWallets($userIds);

            // Work out every new balance first, so nothing is written unless
            // the whole movement is allowed.
            $after = [];

            foreach ($customerLegs as [$userId, $type, $direction, $kobo]) {
                $column = self::COLUMNS[$type];
                $current = $after[$userId][$column] ?? Money::kobo($wallets[$userId]->getRawOriginal($column));
                $next = $direction === 'credit' ? $current + $kobo : $current - $kobo;

                if ($next < 0) {
                    throw new InsufficientBalanceException(Money::toFloat(-$next));
                }

                if ($next > Money::MAX_KOBO * 10) {
                    throw new InvalidArgumentException('That would take the balance past the largest amount allowed.');
                }

                $after[$userId][$column] = $next;
            }

            try {
                $journal = LedgerJournal::create([
                    'business_key' => $key,
                    'reason' => $reason,
                    'currency' => 'NGN',
                    'effective_at' => now(),
                    'created_by' => $meta['createdBy'] ?? null,
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DuplicatePostingException($key);
            }

            $entries = [];

            foreach ($customerLegs as [$userId, $type, $direction, $kobo]) {
                $column = self::COLUMNS[$type];
                $amount = Money::naira($kobo);
                $query = Wallet::query()->whereKey($wallets[$userId]->getKey());
                $direction === 'credit' ? $query->increment($column, $amount) : $query->decrement($column, $amount);

                $entries[] = WalletLedgerEntry::create([
                    'journal_id' => $journal->id,
                    'user_id' => $userId,
                    'balance_type' => $type,
                    'direction' => $direction,
                    'amount' => $amount,
                    'currency' => 'NGN',
                    'reason' => $reason,
                    'description' => isset($meta['description']) ? mb_substr((string) $meta['description'], 0, 255) : null,
                    'reference_type' => $meta['referenceType'] ?? null,
                    'reference_id' => $meta['referenceId'] ?? null,
                    'effective_at' => $journal->effective_at,
                    'created_at' => now(),
                ]);
            }

            foreach ($systemLegs as [$account, $direction, $kobo]) {
                LedgerSystemEntry::create([
                    'journal_id' => $journal->id,
                    'account' => $account,
                    'direction' => $direction,
                    'amount' => Money::naira($kobo),
                    'currency' => 'NGN',
                    'created_at' => now(),
                ]);
            }

            // Keep any wallet model a caller is holding in step with the row.
            foreach ($after as $userId => $columns) {
                foreach ($columns as $column => $kobo) {
                    $wallets[$userId]->setRawAttributes(array_merge($wallets[$userId]->getAttributes(), [$column => Money::naira($kobo)]), true);
                }
            }

            return $entries;
        });
    }

    private function positiveKobo(int|float|string $amount): int
    {
        $kobo = Money::kobo($amount);

        if ($kobo <= 0) {
            throw new InvalidArgumentException('Ledger amounts must be positive; the direction says which way the money moved.');
        }

        if ($kobo > Money::MAX_KOBO) {
            throw new InvalidArgumentException('That amount is larger than the most the app will move at once.');
        }

        return $kobo;
    }

    /** B10: nothing may be booked into a period the books are closed for. */
    private function assertPeriodOpen(): void
    {
        $closedUntil = config('ledger.books_closed_until');

        if ($closedUntil && now()->lte(\Illuminate\Support\Carbon::parse($closedUntil)->endOfDay())) {
            throw new RuntimeException("The books are closed up to {$closedUntil}; nothing can be posted into that period.");
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Wallets can only be locked inside a database transaction.');
        }
    }
}
