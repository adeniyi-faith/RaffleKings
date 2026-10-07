<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\WithdrawalRequestResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\WithdrawalRequest;
use App\Services\PayoutService;
use App\Services\Risk\FraudWatchService;
use App\Services\WithdrawalService;
use App\Support\Features;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * OVERHAUL_CHECKLIST.md item 44 — the withdrawals queue. Before this the
 * only way to pay or reject a withdrawal was the JSON API; the old
 * WordPress admin page that did it is gone with the old site.
 *
 * Mark paid only records that the bank transfer was sent (the transfer
 * itself happens in the bank app); Reject refunds exactly what was taken
 * for that request back to the customer's winnings. Both are refused if
 * someone else already handled the request (WithdrawalService re-checks
 * under a lock) and both are audit-logged.
 */
class WithdrawalRequestResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = WithdrawalRequest::class;

    protected static ?string $slug = 'withdrawals';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Withdrawals';

    protected static ?string $modelLabel = 'withdrawal';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = WithdrawalRequest::query()->where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @var array<int, string>|null Fraud-watch notes for pending withdrawals, worked out once per page. */
    private static ?array $warnings = null;

    /** A plain-words reason to look twice before paying (App\Services\Risk\FraudWatchService). */
    public static function warning(WithdrawalRequest $record): ?string
    {
        if ($record->status !== 'pending') {
            return null;
        }

        static::$warnings ??= app(FraudWatchService::class)->pendingWithdrawalWarnings();

        return static::$warnings[$record->id] ?? null;
    }

    /** Automatic payouts: where Paystack is with this withdrawal, as a badge. */
    public static function payoutBadge(WithdrawalRequest $record): ?array
    {
        return match ($record->payout_status) {
            'sending' => ['Paystack is sending', 'info'],
            'failed' => $record->status === 'pending' ? ['Paystack could not send', 'danger'] : null,
            'reversed' => ['Paystack reversed it', 'danger'],
            'success' => ['Sent by Paystack', 'success'],
            default => null,
        };
    }

    /** One line under the bank details: Paystack's problem, or why this one is paid by hand. */
    public static function payoutNote(WithdrawalRequest $record): ?string
    {
        if (in_array($record->payout_status, ['failed', 'reversed'], true) && $record->payout_error) {
            return $record->payout_error;
        }

        if (Features::on('auto_payouts') && $record->status === 'pending' && $record->payout_status !== 'sending') {
            $blocker = app(PayoutService::class)->blocker($record);

            return $blocker ? 'Pay by hand: '.$blocker : null;
        }

        return null;
    }

    private static function sendWithPaystack(WithdrawalRequest $record): void
    {
        try {
            $message = app(PayoutService::class)->send(static::admin(), $record);
        } catch (RuntimeException $e) {
            Notification::make()->title('Not sent')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title("Withdrawal #{$record->id}")->body($message)->success()->persistent()->send();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'bankAccount']))
            ->defaultSort('created_at', 'asc')
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (WithdrawalRequest $record) => [
                    'title' => $record->user?->display_name ?: $record->user?->user_login,
                    'amount' => static::naira($record->amount_to_send),
                    'lines' => [
                        $record->bankAccount ? "{$record->bankAccount->bank_name} · {$record->bankAccount->masked()} · {$record->bankAccount->account_name}" : 'No bank account on file',
                        (float) $record->fee_amount > 0 ? 'Requested '.static::naira($record->requested_amount).' · fee '.static::naira($record->fee_amount) : null,
                        static::payoutNote($record),
                    ],
                    'copy' => null,
                    'body' => static::warning($record),
                    'badges' => [
                        match ($record->status) {
                            'pending' => ['Waiting to be paid', 'warning'],
                            'paid' => ['Paid', 'success'],
                            default => ['Rejected', 'gray'],
                        },
                        static::warning($record) ? ['Check before paying', 'danger'] : null,
                        static::payoutBadge($record),
                        $record->bankAccount?->isVerified() ? ['Name checked', 'success'] : null,
                        $record->bankAccount?->name_mismatch ? ['Name does not match', 'danger'] : null,
                    ],
                    'meta' => $record->created_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('user.display_name')
                        ->label('Customer')
                        ->icon(fn (WithdrawalRequest $record) => static::warning($record) ? 'heroicon-m-exclamation-triangle' : null)
                        ->iconColor('danger')
                        ->tooltip(fn (WithdrawalRequest $record) => static::warning($record))
                        ->description(fn (WithdrawalRequest $record) => $record->user?->user_email)
                        ->searchable(['display_name', 'user_login', 'user_email']),
                    Tables\Columns\TextColumn::make('amount_to_send')
                        ->label('Send')
                        ->formatStateUsing(fn ($state) => static::naira($state))
                        ->weight('bold')
                        ->description(fn (WithdrawalRequest $record) => (float) $record->fee_amount > 0
                            ? 'Requested ₦'.number_format((float) $record->requested_amount).' · fee ₦'.number_format((float) $record->fee_amount)
                            : null),
                    Tables\Columns\TextColumn::make('bankAccount.account_number')
                        ->label('Send to')
                        ->formatStateUsing(fn ($state, WithdrawalRequest $record) => $record->bankAccount?->masked())
                        ->description(fn (WithdrawalRequest $record) => ($record->bankAccount
                            ? "{$record->bankAccount->bank_name} · {$record->bankAccount->account_name}".($record->bankAccount->isVerified() ? ' ✓ name checked by the bank' : '')
                            : 'No bank account on file').(static::payoutNote($record) ? ' · '.static::payoutNote($record) : '')),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Requested')
                        ->since()
                        ->sortable()
                        ->description(fn (WithdrawalRequest $record) => static::payoutBadge($record)[0] ?? match ($record->status) {
                            'pending' => 'Waiting to be paid',
                            'paid' => 'Paid',
                            default => 'Rejected',
                        }),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Waiting to be paid', 'paid' => 'Paid', 'rejected' => 'Rejected'])
                    ->default('pending'),
            ])
            // Actions first: acting on each row is this screen's whole
            // purpose, so the buttons must never be pushed off-screen.
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('revealAccount')
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('Show full number')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (WithdrawalRequest $record) => $record->bankAccount !== null)
                    ->modalHeading('Show the full account number?')
                    ->modalDescription('Account numbers are hidden by default. Showing one is written to the activity log with your reason.')
                    ->form([
                        Forms\Components\TextInput::make('why')->label('Why do you need it?')->required()->maxLength(200)
                            ->placeholder('e.g. Paying this withdrawal by hand'),
                    ])
                    ->modalSubmitActionLabel('Show it')
                    ->action(function (WithdrawalRequest $record, array $data) {
                        $account = $record->bankAccount;

                        app(\App\Services\AdminAuditLogService::class)->record(static::admin(), 'bank_account.revealed', \App\Models\BankAccount::class, $account->id, [
                            'withdrawal_id' => $record->id,
                            'user_id' => $record->user_id,
                            'why' => $data['why'],
                        ]);

                        Notification::make()
                            ->title("{$account->account_name} · {$account->bank_name}")
                            ->body($account->account_number)
                            ->persistent()
                            ->send();
                    }),
                Tables\Actions\Action::make('sendWithPaystack')
                    ->hidden(fn () => ! static::staffCan('money.pay') || ! Features::on('auto_payouts'))
                    ->label('Send with Paystack')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (WithdrawalRequest $record) => $record->status === 'pending' && $record->payout_status !== 'sending')
                    ->disabled(fn (WithdrawalRequest $record) => app(PayoutService::class)->blocker($record) !== null)
                    ->tooltip(fn (WithdrawalRequest $record) => app(PayoutService::class)->blocker($record))
                    ->requiresConfirmation()
                    ->modalHeading('Send this withdrawal with Paystack?')
                    ->modalDescription(fn (WithdrawalRequest $record) => (static::warning($record) ? '⚠ '.static::warning($record).' ' : '')
                        .'Paystack sends '.static::naira($record->amount_to_send)
                        .($record->bankAccount ? " to {$record->bankAccount->account_name}, {$record->bankAccount->bank_name} {$record->bankAccount->masked()}" : '')
                        .' from your Paystack balance'.(($balance = static::paystackBalance()) !== null ? ' ('.static::naira($balance).' now)' : '')
                        .'. It is marked paid, and the customer told, only when the bank confirms.')
                    ->modalSubmitActionLabel('Yes, send it')
                    ->action(fn (WithdrawalRequest $record) => static::sendWithPaystack($record)),
                Tables\Actions\Action::make('checkPaystack')
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('Check with Paystack')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (WithdrawalRequest $record) => $record->payout_status === 'sending')
                    ->action(function (WithdrawalRequest $record) {
                        try {
                            $message = app(PayoutService::class)->refreshFromPaystack($record);
                        } catch (Throwable $e) {
                            Notification::make()->title('Could not check')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title($message ?? 'Still on the way. Paystack hasn\'t finished yet.')->success()->send();
                    }),
                Tables\Actions\Action::make('markPaid')
                    // Only staff allowed to move money see this (App\Auth\StaffRoles).
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('Mark paid')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WithdrawalRequest $record) => $record->status === 'pending' && $record->payout_status !== 'sending')
                    ->requiresConfirmation()
                    ->modalHeading('Mark this withdrawal as paid?')
                    ->modalDescription(fn (WithdrawalRequest $record) => (static::warning($record) ? '⚠ '.static::warning($record).' ' : '').'Only confirm AFTER you have sent '.static::naira($record->amount_to_send)
                        .($record->bankAccount ? " to {$record->bankAccount->account_name}, {$record->bankAccount->bank_name} {$record->bankAccount->masked()}" : '')
                        .'. The customer is told their money is on the way.')
                    ->modalSubmitActionLabel('Yes, I have sent it')
                    ->action(fn (WithdrawalRequest $record) => static::attempt(
                        fn () => app(WithdrawalService::class)->markPaid(static::admin(), $record),
                        "Withdrawal #{$record->id} marked paid.",
                    )),
                Tables\Actions\Action::make('reject')
                    // Only staff allowed to move money see this (App\Auth\StaffRoles).
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WithdrawalRequest $record) => $record->status === 'pending' && $record->payout_status !== 'sending')
                    ->modalDescription(fn (WithdrawalRequest $record) => 'Returns '.static::naira($record->amount_to_send).' to the customer\'s winnings'.((float) $record->fee_amount > 0 ? ', and takes the '.static::naira($record->fee_amount).' verification fee back out of their spending wallet (as much as is still there)' : '').'.')
                    ->form([
                        Forms\Components\Textarea::make('customer_message')
                            ->label('Message to the customer')
                            ->helperText('The customer sees exactly this. Keep it kind and free of anything about our checks.')
                            ->required()
                            ->maxLength(500),
                        Forms\Components\Textarea::make('reason')
                            ->label('Internal note (staff only)')
                            ->helperText('Why you rejected it. The customer never sees this.')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(fn (WithdrawalRequest $record, array $data) => static::attempt(
                        fn () => app(WithdrawalService::class)->reject(static::admin(), $record, $data['reason'], $data['customer_message']),
                        "Withdrawal #{$record->id} rejected and refunded.",
                    )),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('sendSelectedWithPaystack')
                    ->hidden(fn () => ! static::staffCan('money.pay') || ! Features::on('auto_payouts'))
                    ->label('Send selected with Paystack')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalDescription('Each one that can be sent automatically is sent. The rest (bigger than the limit, unchecked bank names, already handled) are skipped and stay in the queue.')
                    ->modalSubmitActionLabel('Send them')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $service = app(PayoutService::class);
                        $sent = 0;
                        $skipped = [];

                        foreach ($records as $record) {
                            try {
                                $service->send(static::admin(), $record);
                                $sent++;
                            } catch (RuntimeException $e) {
                                $skipped[] = "#{$record->id}: {$e->getMessage()}";
                            }
                        }

                        Notification::make()
                            ->title("{$sent} sent to Paystack, ".count($skipped).' skipped')
                            ->body(implode("\n", array_slice($skipped, 0, 10)) ?: null)
                            ->color($skipped ? 'warning' : 'success')
                            ->persistent()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No withdrawals waiting')
            ->emptyStateDescription('New withdrawal requests appear here.');
    }

    /** The Paystack balance payouts come from, or null if it can't be read. */
    public static function paystackBalance(): ?float
    {
        try {
            return app(\App\Services\Payments\PaystackApi::class)->balance();
        } catch (Throwable) {
            return null;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawalRequests::route('/'),
        ];
    }
}
