<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\BusinessDay;
use App\Models\Legacy\WpUser;
use App\Models\UserPoints;
use App\Models\WithdrawalRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * OVERHAUL_CHECKLIST.md item 45 — today's business at a glance, each with
 * a 7-day trend line. Replaces Filament's default "about Filament" boxes.
 */
class TodayOverview extends StatsOverviewWidget
{
    // Money figures: only staff who work with money or reports (App\Auth\StaffRoles).
    public static function canView(): bool
    {
        $user = auth('wordpress')->user();

        return $user instanceof WpUser && ($user->staffCan('money.view') || $user->staffCan('reports'));
    }

    use BusinessDay;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Today';

    protected function getStats(): array
    {
        $salesTrend = static::dailyTotals(static::ticketSales(), 'claimed_amount', 7);
        $inTrend = static::dailyTotals(static::moneyIn(), 'amount', 7);
        $salesToday = end($salesTrend);
        $salesYesterday = $salesTrend[5] ?? 0;
        $purchasesToday = static::ticketSales()->where('created_at', '>=', static::dayStart())->count();

        $paidOutToday = (float) WithdrawalRequest::query()->where('status', 'paid')->where('updated_at', '>=', static::dayStart())->sum('amount_to_send');
        $signUps = WpUser::query()->where('user_registered', '>=', static::dayStart())->count();
        $pointsOwed = (int) UserPoints::query()->sum('balance');

        return [
            Stat::make('Ticket sales', static::naira($salesToday))
                ->description($purchasesToday.' purchase(s) · yesterday '.static::naira($salesYesterday))
                ->descriptionIcon($salesToday >= $salesYesterday ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->chart($salesTrend)
                ->color($salesToday >= $salesYesterday ? 'success' : 'warning'),
            Stat::make('Money in', static::naira(end($inTrend)))
                ->description('Top-ups credited today')
                ->chart($inTrend)
                ->color('info'),
            Stat::make('Paid out', static::naira($paidOutToday))
                ->description('Withdrawals marked paid today')
                ->color('gray'),
            Stat::make('New sign-ups', (string) $signUps)
                ->description('Accounts created today'),
            Stat::make('Points owed', number_format($pointsOwed).' pts')
                ->description('Worth '.static::naira($pointsOwed / max(1, (int) config('rewards.points_per_naira'))).' if everyone redeemed'),
        ];
    }
}
