<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Services\AdminAuditLogService;
use App\Services\Reports\ReportExporter;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance → Downloads (item 45b): sales, money in, money out, winners,
 * every money movement, referrals and sign-ups for any range of days, as
 * a spreadsheet file (CSV — opens in Excel, Google Sheets, Numbers).
 */
class Downloads extends Page implements HasForms
{
    use GuardedByStaffRole, InteractsWithForms, RunsAdminActions;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.downloads';

    /** @var array<string, mixed> */
    public array $data = [];

    public function getSubheading(): ?string
    {
        return 'Spreadsheets for your accounts. Days are counted in '.config('raffles.timezone').' time.';
    }

    public function mount(): void
    {
        $today = now(config('raffles.timezone'));
        $this->form->fill([
            'report' => 'sales',
            'from' => $today->copy()->startOfMonth()->toDateString(),
            'to' => $today->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        $today = fn () => now(config('raffles.timezone'));

        return $form->statePath('data')->columns(['default' => 2, 'md' => 4])->schema([
            Forms\Components\Select::make('report')->options(ReportExporter::REPORTS)->required()->selectablePlaceholder(false)->columnSpan(['default' => 2, 'md' => 2]),
            Forms\Components\DatePicker::make('from')->label('From')->required()->maxDate(fn () => $today()->toDateString())->live(),
            Forms\Components\DatePicker::make('to')->label('To')->required()->afterOrEqual('from')->maxDate(fn () => $today()->toDateString())->live(),
            Forms\Components\Actions::make([
                $this->quick('Today', fn ($t) => [$t, $t]),
                $this->quick('Yesterday', fn ($t) => [$t->copy()->subDay(), $t->copy()->subDay()]),
                $this->quick('This month', fn ($t) => [$t->copy()->startOfMonth(), $t]),
                $this->quick('Last month', fn ($t) => [$t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth()]),
                $this->quick('This year', fn ($t) => [$t->copy()->startOfYear(), $t]),
            ])->columnSpanFull(),
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

    /** Totals for the chosen days, shown above the button. */
    public function getViewData(): array
    {
        if (blank($this->data['from'] ?? null) || blank($this->data['to'] ?? null) || $this->data['from'] > $this->data['to']) {
            return ['totals' => null];
        }

        return ['totals' => app(ReportExporter::class)->totals(...ReportExporter::range($this->data['from'], $this->data['to']))];
    }

    protected function getFormActions(): array
    {
        return [Action::make('download')->label('Download spreadsheet')->icon('heroicon-o-arrow-down-tray')->submit('download')];
    }

    public function download(): StreamedResponse
    {
        $state = $this->form->getState();
        [$from, $to] = ReportExporter::range($state['from'], $state['to']);
        $exporter = app(ReportExporter::class);
        $report = $state['report'];
        $filename = str(ReportExporter::REPORTS[$report])->slug().'-'.$state['from'].'-to-'.$state['to'].'.csv';

        app(AdminAuditLogService::class)->record(static::admin(), 'report.downloaded', 'report', 0, [
            'report' => ReportExporter::REPORTS[$report],
            'from' => $state['from'],
            'to' => $state['to'],
        ]);

        return response()->streamDownload(function () use ($exporter, $report, $from, $to) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₦ and names correctly
            fputcsv($out, $exporter->headings($report));

            foreach ($exporter->rows($report, $from, $to) as $row) {
                // Cells starting with = + - @ are formulas in Excel; quote them so a customer's name can't run one.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
