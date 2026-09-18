<?php

namespace App\Filament\Pages;

use App\Exceptions\StatementExtractionException;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\WpUser;
use App\Services\AuditReconciliationService;
use App\Services\StatementExtractionService;
use App\Services\TransactionMonitorService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — a real UI for the two pieces
 * of the Daily Audit workflow that, until this pass, only existed as
 * JSON endpoints an admin would have had to script against by hand:
 * StatementExtractionService (upload → AI-read credit list) and
 * AuditReconciliationService (credit list → flagged transactions),
 * with TransactionMonitorService::revoke() as the action taken on
 * whatever comes back flagged — the same three services, now on one
 * page instead of three separate curl calls.
 */
class DailyAudit extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Daily Audit';

    protected static string $view = 'filament.pages.daily-audit';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<int, array{amount: float, date: ?string, desc: ?string}>|null */
    public ?array $extractedCredits = null;

    /** @var array<int, array{transaction: RaffleTransaction, reason: string}>|null */
    public ?array $flagged = null;

    public function mount(): void
    {
        $this->form->fill([
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('1. Upload the bank statement')
                ->description('Screenshots or PDF pages of the bank statement covering the date range below. Read by the same Gemini AI model the legacy deposit-screenshot check already uses.')
                ->schema([
                    Forms\Components\FileUpload::make('statement_files')
                        ->label('Statement files')
                        ->multiple()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(8192)
                        ->disk('local')
                        ->directory('daily-audit-uploads')
                        ->visibility('private'),
                ]),
            Forms\Components\Section::make('2. Date range to reconcile')
                ->schema([
                    Forms\Components\DatePicker::make('start_date')->required(),
                    Forms\Components\DatePicker::make('end_date')->required(),
                ])
                ->columns(2),
            Forms\Components\Section::make('3. Credits found')
                ->description('Reviewed and correctable before reconciling — a missed or misread line here is exactly the kind of mistake that should be caught before it flags a real transaction.')
                ->visible(fn () => $this->extractedCredits !== null)
                ->schema([
                    Forms\Components\Repeater::make('credits')
                        ->label('')
                        ->schema([
                            Forms\Components\TextInput::make('amount')->numeric()->required()->prefix('₦'),
                            Forms\Components\TextInput::make('date'),
                            Forms\Components\TextInput::make('desc')->label('Description')->columnSpan(2),
                        ])
                        ->columns(4)
                        ->addActionLabel('Add credit manually'),
                ]),
        ])->statePath('data');
    }

    public function extract(): void
    {
        $files = $this->data['statement_files'] ?? [];

        try {
            $credits = app(StatementExtractionService::class)->extractCreditsFromStoredFiles($files, 'local');
        } catch (StatementExtractionException $e) {
            Notification::make()->title('Extraction failed')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->extractedCredits = $credits;
        $this->data['credits'] = $credits;

        Notification::make()->title(count($credits).' credit(s) found')->success()->send();
    }

    public function reconcile(): void
    {
        $credits = $this->data['credits'] ?? [];

        if (empty($credits)) {
            Notification::make()->title('No credits to reconcile against')->body('Extract a statement or add at least one credit manually first.')->warning()->send();

            return;
        }

        $flagged = app(AuditReconciliationService::class)->reconcile(
            $credits,
            $this->data['start_date'],
            $this->data['end_date'],
        );

        $this->flagged = $flagged->all();

        Notification::make()->title(count($this->flagged).' transaction(s) flagged')->send();
    }

    public function revoke(int $transactionId): void
    {
        $transaction = RaffleTransaction::findOrFail($transactionId);

        // Not auth()->user() — that resolves the default 'web' guard,
        // which nothing in this app authenticates against (the same
        // class of bug item 25 found in HandleInertiaRequests::share()).
        // The Filament panel itself authenticates against the
        // 'wordpress' guard (AdminPanelProvider::authGuard('wordpress')).
        /** @var WpUser $admin */
        $admin = Auth::guard('wordpress')->user();

        try {
            app(TransactionMonitorService::class)->revoke($admin, $transaction, 'Daily Audit — no matching bank credit in uploaded statement.');
        } catch (RuntimeException $e) {
            Notification::make()->title('Could not revoke')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->flagged = collect($this->flagged)
            ->reject(fn (array $row) => (int) $row['transaction']->id === $transactionId)
            ->all();

        Notification::make()->title("Transaction #{$transactionId} revoked")->success()->send();
    }
}
