<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\WithdrawalRequestResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
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
    use RunsAdminActions;

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
                        $record->bankAccount ? "{$record->bankAccount->bank_name} · {$record->bankAccount->account_name}" : 'No bank account on file',
                        (float) $record->fee_amount > 0 ? 'Requested '.static::naira($record->requested_amount).' · fee '.static::naira($record->fee_amount) : null,
                    ],
                    'copy' => $record->bankAccount ? ['value' => $record->bankAccount->account_number] : null,
                    'badges' => [match ($record->status) {
                        'pending' => ['Waiting to be paid', 'warning'],
                        'paid' => ['Paid', 'success'],
                        default => ['Rejected', 'gray'],
                    }],
                    'meta' => $record->created_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('user.display_name')
                        ->label('Customer')
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
                        ->copyable()
                        ->copyMessage('Account number copied')
                        ->description(fn (WithdrawalRequest $record) => $record->bankAccount
                            ? "{$record->bankAccount->bank_name} · {$record->bankAccount->account_name}"
                            : 'No bank account on file'),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Requested')
                        ->since()
                        ->sortable()
                        ->description(fn (WithdrawalRequest $record) => match ($record->status) {
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
                Tables\Actions\Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WithdrawalRequest $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Mark this withdrawal as paid?')
                    ->modalDescription(fn (WithdrawalRequest $record) => 'Only confirm AFTER you have sent '.static::naira($record->amount_to_send)
                        .($record->bankAccount ? " to {$record->bankAccount->account_name}, {$record->bankAccount->bank_name} {$record->bankAccount->account_number}" : '')
                        .'. The customer is told their money is on the way.')
                    ->modalSubmitActionLabel('Yes, I have sent it')
                    ->action(fn (WithdrawalRequest $record) => static::attempt(
                        fn () => app(WithdrawalService::class)->markPaid(static::admin(), $record),
                        "Withdrawal #{$record->id} marked paid.",
                    )),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WithdrawalRequest $record) => $record->status === 'pending')
                    ->modalDescription(fn (WithdrawalRequest $record) => 'Refunds '.static::naira((float) $record->amount_to_send + (float) $record->fee_amount).' to the customer\'s winnings. They see the reason below.')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason (shown to the customer)')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(fn (WithdrawalRequest $record, array $data) => static::attempt(
                        fn () => app(WithdrawalService::class)->reject(static::admin(), $record, $data['reason']),
                        "Withdrawal #{$record->id} rejected and refunded.",
                    )),
            ])
            ->emptyStateHeading('No withdrawals waiting')
            ->emptyStateDescription('New withdrawal requests appear here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawalRequests::route('/'),
        ];
    }
}
