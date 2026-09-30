<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Services\AdminAuditLogService;
use App\Services\Reports\MoneyReports as Reports;
use App\Services\Reports\ReportExporter;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance → Money reports: where the money came from and where it went,
 * on screen for any range of days, and as a spreadsheet. The numbers come
 * from App\Services\Reports\MoneyReports (which explains what "what the
 * site kept" means).
 */
class MoneyReports extends Page implements HasForms
{
    use GuardedByStaffRole, InteractsWithForms, RunsAdminActions;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Money reports';

    protected static ?string $title = 'Money reports';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.money-reports';

    /** @var array<string, mixed> */
    public array $data = [];

    public function getSubheading(): ?string
    {
        return 'Where the money came from and where it went. Days are counted in '.config('raffles.timezone').' time.';
    }

    public function mount(): void
    {
        $today = now(config('raffles.timezone'));
        $this->form->fill([
            'report' => 'overview',
            'group' => 'day',
            'from' => $today->copy()->startOfMonth()->toDateString(),
            'to' => $today->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        $today = fn () => now(config('raffles.timezone'));

        return $form->statePath('data')->columns(['default' => 2, 'md' => 4])->schema([
            Forms\Components\Select::make('report')->label('Report')->options(Reports::REPORTS)->selectablePlaceholder(false)->live()->columnSpan(['default' => 2, 'md' => 2]),
            Forms\Components\Select::make('group')->label('Split')->options(Reports::GROUPS)->selectablePlaceholder(false)->live()
                ->visible(fn (Forms\Get $get) => $get('report') === 'overview')->columnSpan(['default' => 2, 'md' => 2]),
            Forms\Components\DatePicker::make('from')->label('From')->required()->maxDate(fn () => $today()->toDateString())->live()
                ->visible(fn (Forms\Get $get) => $get('report') !== 'holding'),
            Forms\Components\DatePicker::make('to')->label('To')->required()->afterOrEqual('from')->maxDate(fn () => $today()->toDateString())->live()
                ->visible(fn (Forms\Get $get) => $get('report') !== 'holding'),
            Forms\Components\Actions::make([
                $this->quick('Today', fn ($t) => [$t, $t]),
                $this->quick('Yesterday', fn ($t) => [$t->copy()->subDay(), $t->copy()->subDay()]),
                $this->quick('Last 7 days', fn ($t) => [$t->copy()->subDays(6), $t]),
                $this->quick('This month', fn ($t) => [$t->copy()->startOfMonth(), $t]),
                $this->quick('Last month', fn ($t) => [$t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth()]),
                $this->quick('This year', fn ($t) => [$t->copy()->startOfYear(), $t]),
            ])->columnSpanFull()->visible(fn (Forms\Get $get) => $get('report') !== 'holding'),
        ]);
    }

    private function quick(string $label, callable $range): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make(str($label)->camel()->toString())
            ->label($label)->link()->size('sm')
            ->action(function (Forms\Set $set) use ($range) {
                [$from, $to] = $range(now(config('raffles.timezone')));
                $set('from', $from->toDateString());
                $set('to', $to->toDateString());
            });
    }

    /** @return array{0: Carbon, 1: Carbon}|null null while the days aren't a sensible range */
    private function days(): ?array
    {
        $from = $this->data['from'] ?? null;
        $to = $this->data['to'] ?? null;

        if (blank($from) || blank($to) || $from > $to) {
            return null;
        }

        return ReportExporter::range($from, $to);
    }

    /** What the page shows: the report, and (for the overview) the headline numbers against the days before. */
    public function getViewData(): array
    {
        $name = $this->data['report'] ?? 'overview';
        $group = $this->data['group'] ?? 'day';
        $reports = app(Reports::class);
        $days = $name === 'holding' ? [now(), now()] : $this->days();

        if ($days === null) {
            return ['report' => null, 'problem' => 'Pick a start day that is not after the end day.', 'summary' => null, 'previous' => null, 'name' => $name];
        }

        try {
            $report = $reports->report($name, $days[0], $days[1], $group);
        } catch (InvalidArgumentException $e) {
            return ['report' => null, 'problem' => $e->getMessage(), 'summary' => null, 'previous' => null, 'name' => $name];
        }

        return [
            'report' => $report,
            'problem' => null,
            'name' => $name,
            'summary' => $name === 'overview' ? $reports->summary(...$days) : null,
            'previous' => $name === 'overview' ? $reports->summary(...Reports::previous(...$days)) : null,
            'notes' => [
                'overview' => 'Top-ups and withdrawals are customers\' own money moving in and out, so they are not counted in "what the site kept". That is ticket sales minus prizes credited, refunds, bonuses and commissions.',
                'raffles' => 'Sales are for the days you picked. Prizes and refunds cover the whole raffle, so a raffle that is still selling can look worse than it will end up.',
                'topups' => 'Tries that were never paid are counted as tries, not as failures of the provider.',
                'withdrawals' => 'Counted by the day the request was made.',
                'holding' => 'This is what customers could withdraw or spend today. It does not depend on the days.',
            ][$name] ?? null,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')->label('Download spreadsheet')->icon('heroicon-o-arrow-down-tray')->color('gray')->action(fn () => $this->download()),
        ];
    }

    public function download(): ?StreamedResponse
    {
        $name = $this->data['report'] ?? 'overview';
        $days = $name === 'holding' ? [now(), now()] : $this->days();

        if ($days === null) {
            return null;
        }

        try {
            $report = app(Reports::class)->report($name, $days[0], $days[1], $this->data['group'] ?? 'day');
        } catch (InvalidArgumentException) {
            return null;
        }

        app(AdminAuditLogService::class)->record(static::admin(), 'report.downloaded', 'report', 0, [
            'report' => Reports::REPORTS[$name],
            'from' => $this->data['from'] ?? null,
            'to' => $this->data['to'] ?? null,
        ]);

        $filename = str(Reports::REPORTS[$name])->slug().($name === 'holding' ? '-'.now(config('raffles.timezone'))->toDateString() : '-'.$this->data['from'].'-to-'.$this->data['to']).'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₦ and names correctly
            fputcsv($out, $report['headings']);

            foreach ([...$report['rows'], $report['totals']] as $row) {
                fputcsv($out, array_map(ReportExporter::safeCell(...), $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
