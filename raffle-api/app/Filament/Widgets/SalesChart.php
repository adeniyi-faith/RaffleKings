<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\BusinessDay;
use Filament\Widgets\ChartWidget;

/** OVERHAUL_CHECKLIST.md item 45 — ticket sales and money in, last 14 days. */
class SalesChart extends ChartWidget
{
    use BusinessDay;

    protected static ?int $sort = 3;

    protected static ?string $heading = 'Last 14 days';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $tz = config('raffles.timezone', 'Africa/Lagos');

        return [
            'datasets' => [
                [
                    'label' => 'Ticket sales (₦)',
                    'data' => static::dailyTotals(static::ticketSales(), 'claimed_amount', 14),
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Money in (₦)',
                    'data' => static::dailyTotals(static::moneyIn(), 'amount', 14),
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.05)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => collect(range(13, 0))->map(fn ($ago) => now($tz)->subDays($ago)->format('j M'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
