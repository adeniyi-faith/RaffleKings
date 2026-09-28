<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\Legacy\RaffleTransaction;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Today" on the dashboard means today in Lagos (config/raffles.php), not
 * the server's UTC day — otherwise the first hour of every Nigerian
 * morning would count as yesterday.
 */
trait BusinessDay
{
    protected static function dayStart(int $daysAgo = 0): Carbon
    {
        return now(config('raffles.timezone', 'Africa/Lagos'))->subDays($daysAgo)->startOfDay()->utc();
    }

    /** Ticket purchases that went through (any way of paying). */
    protected static function ticketSales()
    {
        return RaffleTransaction::query()
            ->whereIn('type', ['ticket_purchase_wallet', 'ticket_purchase_earnings', 'ticket_purchase'])
            ->where('status', 'verified_final');
    }

    /** Money customers put in: gateway top-ups and approved bank transfers. */
    protected static function moneyIn()
    {
        return WalletLedgerEntry::query()->where('direction', 'credit')->where('reason', 'deposit');
    }

    /**
     * Daily totals for the last $days days (oldest first), bucketed by
     * Lagos date — one query, grouped in PHP.
     *
     * @return array<int, float>
     */
    protected static function dailyTotals($query, string $amountColumn, int $days): array
    {
        $tz = config('raffles.timezone', 'Africa/Lagos');

        /** @var Collection $rows */
        $rows = (clone $query)->where('created_at', '>=', static::dayStart($days - 1))->get(['created_at', $amountColumn]);

        $byDay = $rows->groupBy(fn ($r) => Carbon::parse($r->created_at)->setTimezone($tz)->toDateString())
            ->map(fn ($group) => (float) $group->sum($amountColumn));

        return collect(range($days - 1, 0))
            ->map(fn ($ago) => round((float) ($byDay[now($tz)->subDays($ago)->toDateString()] ?? 0), 2))
            ->all();
    }

    protected static function naira(float $amount): string
    {
        return '₦'.number_format($amount);
    }
}
