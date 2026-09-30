<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Models\GamingTaxPeriod;
use App\Services\AdminAuditLogService;
use App\Services\GamingTaxReminders;
use App\Services\GamingTaxReturnPdf;
use App\Services\GamingTaxService;
use App\Services\Reports\ReportExporter;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance → Gaming tax: the monthly tax on ticket sales minus prizes won
 * (App\Services\GamingTaxService). Look at any month, lock it once it has
 * ended, then mark it filed and record the payment. Locked months never
 * change. Everything staff do here is written to the audit log.
 *
 * Anyone who can see Finance can look; locking, filing and paying need the
 * payout ability; reopening a locked month is for owners.
 */
class GamingTax extends Page
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Gaming tax';

    protected static ?string $title = 'Gaming tax';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.gaming-tax';

    /** The number of months that are due soon or overdue, on the menu. */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = app(GamingTaxReminders::class)->badge()['count'];

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return app(GamingTaxReminders::class)->badge()['danger'] ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Months whose gaming tax is due soon or overdue';
    }

    #[Url(as: 'month')]
    public string $month = '';

    public function getSubheading(): ?string
    {
        return 'Tax on ticket sales minus prizes won, month by month. Months are counted in '.config('raffles.timezone').' time.';
    }

    public function mount(): void
    {
        $service = app(GamingTaxService::class);

        // Land on last month if it is not settled yet (that is usually the one to deal with), otherwise this month.
        if (! $service->isValidPeriod($this->month)) {
            $last = $service->previousPeriod($service->periodOf(now()));
            $record = GamingTaxPeriod::query()->where('period', $last)->first();
            $this->month = (! $record || $record->status !== 'paid') && in_array($last, $service->months(), true) ? $last : $service->periodOf(now());
        }
    }

    public function selectMonth(string $month): void
    {
        if (app(GamingTaxService::class)->isValidPeriod($month)) {
            $this->month = $month;
        }
    }

    private function service(): GamingTaxService
    {
        return app(GamingTaxService::class);
    }

    private function statement(): array
    {
        return $this->service()->statement($this->month);
    }

    public function getViewData(): array
    {
        $service = $this->service();
        $months = $service->months(12);

        if (! in_array($this->month, $months, true)) {
            $months[] = $this->month;
            rsort($months);
        }

        return [
            'statement' => $this->statement(),
            'attention' => $service->attention($this->month),
            'reminders' => array_values(array_filter(app(GamingTaxReminders::class)->attention(), fn ($a) => $a['severity'] !== 'info')),
            'rows' => array_map(fn (string $m) => $service->statement($m), $months),
            'months' => $months,
            'label' => fn (string $m) => Carbon::createFromFormat('!Y-m', $m)->format('F Y'),
            'cannotLock' => $service->whyCannotLock($this->month),
            'canSettings' => static::staffCan('settings'),
            'settingsUrl' => static::staffCan('settings') ? Settings::getUrl() : null,
        ];
    }

    protected function getHeaderActions(): array
    {
        $pay = fn () => static::staffCan('money.pay');
        $status = fn () => $this->statement()['status'];
        $label = fn () => Carbon::createFromFormat('!Y-m', $this->month)->format('F Y');

        return [
            Action::make('lock')
                ->label('Lock month')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn () => $pay() && $status() === 'open' && $this->service()->whyCannotLock($this->month) === null)
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Lock '.$label().'?')
                ->modalDescription(function () {
                    $s = $this->statement();

                    return 'Ticket sales '.static::naira($s['net_sales']).' minus prizes won '.static::naira($s['prizes']).' gives a taxable amount of '.static::naira($s['taxable']).'. Tax due at '.$s['rate'].'%: '.static::naira($s['tax_due']).'. Once locked, these figures and the rate never change.';
                })
                ->modalSubmitActionLabel('Lock month')
                ->action(fn () => static::attempt(fn () => $this->service()->lock(static::admin(), $this->month), 'Month locked')),

            Action::make('file')
                ->label('Mark as filed')
                ->icon('heroicon-o-document-check')
                ->color('warning')
                ->visible(fn () => $pay() && $status() === 'locked')
                ->modalDescription('Record that the return for this month has been sent.')
                ->form([
                    Forms\Components\TextInput::make('reference')->label('Filing reference (optional)')->maxLength(120),
                    Forms\Components\DatePicker::make('filed_on')->label('Filed on')->default(now(config('raffles.timezone'))->toDateString())->maxDate(now(config('raffles.timezone'))->toDateString())->required(),
                ])
                ->action(fn (array $data) => static::attempt(fn () => $this->service()->markFiled(static::admin(), $this->month, (string) ($data['reference'] ?? ''), Carbon::parse($data['filed_on'], config('raffles.timezone'))), 'Marked as filed')),

            Action::make('pay')
                ->label('Record payment')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->visible(fn () => $pay() && $status() === 'filed')
                ->modalDescription('Record the tax you paid for this month. Money is not moved from here.')
                ->fillForm(fn () => ['amount' => $this->statement()['tax_due']])
                ->form([
                    Forms\Components\TextInput::make('amount')->label('Amount paid (₦)')->numeric()->minValue(0)->required(),
                    Forms\Components\TextInput::make('reference')->label('Payment reference (optional)')->maxLength(120),
                    Forms\Components\DatePicker::make('paid_on')->label('Paid on')->default(now(config('raffles.timezone'))->toDateString())->maxDate(now(config('raffles.timezone'))->toDateString())->required(),
                ])
                ->action(fn (array $data) => static::attempt(fn () => $this->service()->recordPayment(static::admin(), $this->month, (float) $data['amount'], (string) ($data['reference'] ?? ''), Carbon::parse($data['paid_on'], config('raffles.timezone'))), 'Payment recorded')),

            Action::make('reopen')
                ->label('Reopen month')
                ->icon('heroicon-o-lock-open')
                ->color('gray')
                ->visible(fn () => static::staffCan('settings') && $status() === 'locked')
                ->modalDescription('Unlocks this month so its figures are worked out again. Only the latest locked month, and only before it is filed. Recorded in the audit log.')
                ->form([Forms\Components\Textarea::make('reason')->label('Why?')->required()->rows(2)->maxLength(300)])
                ->action(fn (array $data) => static::attempt(fn () => $this->service()->reopen(static::admin(), $this->month, $data['reason']), 'Month reopened')),

            Action::make('downloadReturn')
                ->label('Download return (PDF)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => $this->downloadReturn()),

            Action::make('download')
                ->label('Download spreadsheet')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->download()),
        ];
    }

    /** The printable return for the month on screen. */
    public function downloadReturn(): StreamedResponse
    {
        $pdf = app(GamingTaxReturnPdf::class);
        $month = $this->month;
        $status = $this->statement()['status'];

        try {
            $bytes = $pdf->render($month);
            $name = $pdf->filename($month);
            $type = 'application/pdf';
            $what = 'Gaming tax return (PDF)';
        } catch (\Throwable $e) {
            // Never a bare "500": say what went wrong (staff only see this), keep a record, and still hand over
            // the return as a web page that any browser can print or save as PDF.
            report($e);
            Notification::make()->danger()->persistent()
                ->title('The PDF could not be made on this server')
                ->body('You are getting the same return as a web page instead: open it and use Print, then Save as PDF. Tell the developer: '.class_basename($e).': '.\Illuminate\Support\Str::limit($e->getMessage(), 220))
                ->send();

            $bytes = $pdf->html($month);
            $name = str_replace('.pdf', '.html', $pdf->filename($month));
            $type = 'text/html; charset=UTF-8';
            $what = 'Gaming tax return (web page)';
        }

        app(AdminAuditLogService::class)->record(static::admin(), 'report.downloaded', 'report', 0, ['report' => $what, 'month' => $month, 'status' => $status]);

        return response()->streamDownload(function () use ($bytes) {
            echo $bytes;
        }, $name, ['Content-Type' => $type]);
    }

    /** Every listed month, one row each, as a spreadsheet. */
    public function download(): StreamedResponse
    {
        $service = $this->service();
        $export = $service->exportRows(...array_reverse($service->months(24)));

        app(AdminAuditLogService::class)->record(static::admin(), 'report.downloaded', 'report', 0, ['report' => 'Gaming tax', 'months' => count($export['rows'])]);

        return response()->streamDownload(function () use ($export) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₦ correctly
            fputcsv($out, $export['headings']);

            foreach ($export['rows'] as $row) {
                fputcsv($out, array_map(ReportExporter::safeCell(...), $row));
            }

            fclose($out);
        }, 'gaming-tax-'.now(config('raffles.timezone'))->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
