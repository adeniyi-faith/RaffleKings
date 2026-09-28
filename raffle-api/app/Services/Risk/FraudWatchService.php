<?php

namespace App\Services\Risk;

use App\Models\BankAccount;
use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fraud watch (Users → Fraud watch, customer profile, withdrawals queue).
 *
 * Plain, explainable warning signs — never an automatic block. Each flag
 * says what was seen, so staff can decide:
 *
 *  - shared_bank:   the same bank account number saved on more than one
 *                   customer (multi-accounting to farm sign-up bonuses and
 *                   referral commission, or a mule account collecting
 *                   several people's winnings).
 *  - rapid_topups:  several successful top-ups within a short time (card
 *                   testing with stolen cards).
 *  - quick_cashout: a withdrawal asked for soon after a top-up with little
 *                   of it played (using the site to "wash" money), or from
 *                   a brand-new account.
 */
final class FraudWatchService
{
    public const RAPID_TOPUPS = 3;

    public const RAPID_WINDOW_MINUTES = 30;

    public const CASHOUT_WINDOW_HOURS = 24;

    public const NEW_ACCOUNT_HOURS = 72;

    /**
     * Every bank account number used by more than one customer.
     *
     * @return Collection<int, array{account_number: string, bank_name: string, user_ids: list<int>}>
     */
    public function sharedBankAccounts(?int $forUserId = null): Collection
    {
        $query = BankAccount::query()
            ->select('account_number')
            ->groupBy('account_number')
            ->havingRaw('COUNT(DISTINCT user_id) > 1');

        if ($forUserId) {
            $query->whereIn('account_number', BankAccount::query()->where('user_id', $forUserId)->select('account_number'));
        }

        $numbers = $query->pluck('account_number');

        if ($numbers->isEmpty()) {
            return collect();
        }

        return BankAccount::query()
            ->whereIn('account_number', $numbers)
            ->get()
            ->groupBy('account_number')
            ->map(fn (Collection $rows, string $number) => [
                'account_number' => $number,
                'bank_name' => (string) $rows->first()->bank_name,
                'user_ids' => $rows->pluck('user_id')->unique()->values()->all(),
            ])
            ->values();
    }

    /**
     * Customers with RAPID_TOPUPS or more successful top-ups inside any
     * RAPID_WINDOW_MINUTES window, within the last $days days.
     *
     * @return Collection<int, array{user_id: int, count: int, first_at: Carbon, total: float}>
     */
    public function rapidTopUps(int $days = 30, ?int $forUserId = null): Collection
    {
        $deposits = Deposit::query()
            ->where('status', 'successful')
            ->where('created_at', '>=', now()->subDays($days))
            ->when($forUserId, fn ($q) => $q->where('user_id', $forUserId))
            ->orderBy('created_at')
            ->get(['user_id', 'amount', 'created_at']);

        return $deposits->groupBy('user_id')->map(function (Collection $rows, $userId) {
            $best = null;
            $times = $rows->values();

            foreach ($times as $i => $start) {
                $window = $times->slice($i)->filter(fn ($d) => $d->created_at->lte($start->created_at->copy()->addMinutes(self::RAPID_WINDOW_MINUTES)));

                if ($window->count() >= self::RAPID_TOPUPS && (! $best || $window->count() > $best['count'])) {
                    $best = ['user_id' => (int) $userId, 'count' => $window->count(), 'first_at' => $start->created_at, 'total' => (float) $window->sum('amount')];
                }
            }

            return $best;
        })->filter()->values();
    }

    /**
     * Withdrawals asked for within CASHOUT_WINDOW_HOURS of a top-up while
     * under half of that top-up went on tickets — or from an account
     * younger than NEW_ACCOUNT_HOURS.
     *
     * @return Collection<int, array{user_id: int, withdrawal_id: int, reason: string}>
     */
    public function quickCashOuts(int $days = 30, ?int $forUserId = null, bool $pendingOnly = false): Collection
    {
        $withdrawals = WithdrawalRequest::query()
            ->with('user')
            ->where('created_at', '>=', now()->subDays($days))
            ->when($forUserId, fn ($q) => $q->where('user_id', $forUserId))
            ->when($pendingOnly, fn ($q) => $q->where('status', 'pending'))
            ->get();

        return $withdrawals->map(function (WithdrawalRequest $w) {
            $registered = $w->user?->user_registered ? Carbon::parse($w->user->user_registered) : null;

            if ($registered && $registered->diffInHours($w->created_at) < self::NEW_ACCOUNT_HOURS) {
                return ['user_id' => (int) $w->user_id, 'withdrawal_id' => $w->id, 'reason' => 'Withdrawal from an account opened '.$registered->diffForHumans($w->created_at, true).' earlier.'];
            }

            $topUp = WalletLedgerEntry::query()
                ->where('user_id', $w->user_id)
                ->where('reason', 'deposit')
                ->where('direction', 'credit')
                ->whereBetween('created_at', [$w->created_at->copy()->subHours(self::CASHOUT_WINDOW_HOURS), $w->created_at])
                ->orderByDesc('created_at')
                ->first();

            if (! $topUp) {
                return null;
            }

            $played = (float) WalletLedgerEntry::query()
                ->where('user_id', $w->user_id)
                ->where('reason', 'ticket_purchase')
                ->where('direction', 'debit')
                ->whereBetween('created_at', [$topUp->created_at, $w->created_at])
                ->sum('amount');

            if ($played >= (float) $topUp->amount / 2) {
                return null;
            }

            return [
                'user_id' => (int) $w->user_id,
                'withdrawal_id' => $w->id,
                'reason' => 'Asked to withdraw '.$topUp->created_at->diffForHumans($w->created_at, true).' after topping up ₦'.number_format((float) $topUp->amount).', having played only ₦'.number_format($played).' of it.',
            ];
        })->filter()->values();
    }

    /**
     * Everything flagged for one customer, in plain words.
     *
     * @return list<array{type: string, text: string}>
     */
    public function flagsFor(WpUser $user): array
    {
        $flags = [];

        foreach ($this->sharedBankAccounts($user->ID) as $shared) {
            $others = collect($shared['user_ids'])->reject(fn ($id) => $id === $user->ID);
            $names = WpUser::query()->whereIn('ID', $others)->pluck('user_login')->implode(', ');
            $flags[] = ['type' => 'shared_bank', 'text' => "Bank account {$shared['account_number']} ({$shared['bank_name']}) is also saved on: {$names}."];
        }

        foreach ($this->rapidTopUps(90, $user->ID) as $burst) {
            $flags[] = ['type' => 'rapid_topups', 'text' => "{$burst['count']} top-ups within ".self::RAPID_WINDOW_MINUTES.' minutes on '.$burst['first_at']->format('j M').' (₦'.number_format($burst['total']).' in total).'];
        }

        foreach ($this->quickCashOuts(90, $user->ID) as $cashOut) {
            $flags[] = ['type' => 'quick_cashout', 'text' => $cashOut['reason']];
        }

        return $flags;
    }

    /**
     * Pending withdrawals worth a second look, keyed by withdrawal id —
     * for the badge on the withdrawals queue.
     *
     * @return array<int, string>
     */
    public function pendingWithdrawalWarnings(): array
    {
        $warnings = $this->quickCashOuts(30, pendingOnly: true)->pluck('reason', 'withdrawal_id')->all();

        $sharedUsers = $this->sharedBankAccounts()->pluck('user_ids')->flatten()->unique();

        if ($sharedUsers->isNotEmpty()) {
            WithdrawalRequest::query()->where('status', 'pending')->whereIn('user_id', $sharedUsers)->pluck('id')
                ->each(function ($id) use (&$warnings) {
                    $warnings[$id] = trim(($warnings[$id] ?? '').' Their bank account is also saved on another customer.');
                });
        }

        return $warnings;
    }
}
