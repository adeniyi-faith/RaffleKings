<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\BankTransferResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\RaffleTransaction;
use App\Services\DepositApprovalService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * OVERHAUL_CHECKLIST.md item 44 — bank-transfer payments waiting for an
 * admin: wallet top-ups and ticket purchases the automatic receipt check
 * couldn't clear. Approving a top-up credits the wallet; approving a
 * ticket purchase issues exactly the tickets the customer picked (the
 * last job only the retired WordPress admin could do). If those numbers
 * were taken meanwhile, "Credit wallet instead" gives the customer their
 * money as wallet balance so they can pick again.
 */
class BankTransferResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    private const TYPES = ['wallet_deposit', 'deposit_manual', 'ticket_purchase'];

    private const WAITING = ['pending', 'manual_review'];

    protected static ?string $model = RaffleTransaction::class;

    protected static ?string $slug = 'bank-transfers';

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Bank transfers';

    protected static ?string $modelLabel = 'bank transfer';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = RaffleTransaction::query()->whereIn('type', self::TYPES)->whereIn('status', self::WAITING)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('type', self::TYPES)->with('user');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    private static function isWaiting(RaffleTransaction $record): bool
    {
        return in_array($record->status, self::WAITING, true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (RaffleTransaction $record) => [
                    'title' => $record->user?->display_name ?: $record->user?->user_login,
                    'amount' => static::naira($record->claimed_amount),
                    'lines' => [
                        $record->type === 'ticket_purchase'
                            ? 'Tickets'.($record->pending_raffle_id ? " · raffle #{$record->pending_raffle_id} · numbers {$record->pending_numbers}" : '')
                            : 'Wallet top-up',
                        $record->gemini_amount !== null ? 'Receipt check read '.static::naira($record->gemini_amount) : null,
                    ],
                    'copy' => $record->order_id ? ['label' => "Ref {$record->order_id}", 'value' => $record->order_id] : null,
                    'badges' => [match (true) {
                        static::isWaiting($record) => ['Waiting for review', 'warning'],
                        $record->status === 'verified_final' => ['Approved', 'success'],
                        default => ['Rejected', 'gray'],
                    }],
                    'meta' => $record->created_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('user.display_name')
                        ->label('Customer')
                        ->description(fn (RaffleTransaction $record) => $record->user?->user_email)
                        ->searchable(['display_name', 'user_login', 'user_email']),
                    Tables\Columns\TextColumn::make('type')
                        ->label('For')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => $state === 'ticket_purchase' ? 'Tickets' : 'Wallet top-up')
                        ->color(fn (string $state) => $state === 'ticket_purchase' ? 'info' : 'gray')
                        ->description(fn (RaffleTransaction $record) => $record->type === 'ticket_purchase'
                            ? ($record->pending_raffle_id ? "Raffle #{$record->pending_raffle_id} · numbers {$record->pending_numbers}" : 'No numbers recorded')
                            : null),
                    Tables\Columns\TextColumn::make('claimed_amount')
                        ->label('Amount')
                        ->formatStateUsing(fn ($state) => static::naira($state))
                        ->weight('bold')
                        ->description(fn (RaffleTransaction $record) => $record->gemini_amount !== null
                            ? 'Receipt check read '.static::naira($record->gemini_amount)
                            : null),
                    Tables\Columns\TextColumn::make('order_id')
                        ->label('Reference')
                        ->copyable()
                        ->placeholder('None')
                        ->description(fn (RaffleTransaction $record) => $record->txn_ref),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Paid')
                        ->since()
                        ->sortable()
                        ->description(fn (RaffleTransaction $record) => match (true) {
                            static::isWaiting($record) => 'Waiting for review',
                            $record->status === 'verified_final' => 'Approved',
                            default => 'Rejected',
                        }),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'waiting' => 'Waiting for review',
                        'verified_final' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->default('waiting')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'waiting' => $query->whereIn('status', self::WAITING),
                        null, '' => $query,
                        default => $query->where('status', $data['value']),
                    }),
            ])
            // Actions first: acting on each row is this screen's whole
            // purpose, so the buttons must never be pushed off-screen.
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('approve')
                    // Only staff allowed to move money see this (App\Auth\StaffRoles).
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (RaffleTransaction $record) => static::isWaiting($record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (RaffleTransaction $record) => 'Only approve once you can see '.static::naira($record->claimed_amount).' in the bank account. '
                        .($record->type === 'ticket_purchase'
                            ? "Issues tickets {$record->pending_numbers} in raffle #{$record->pending_raffle_id}."
                            : 'Credits it to the customer\'s wallet.'))
                    ->action(fn (RaffleTransaction $record) => static::attempt(
                        fn () => app(DepositApprovalService::class)->approve(static::admin(), $record),
                        "Payment #{$record->id} approved.",
                    )),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('receipt')
                        ->label('Receipt')
                        ->icon('heroicon-o-photo')
                        ->color('gray')
                        ->url(fn (RaffleTransaction $record) => $record->proof_url, shouldOpenInNewTab: true)
                        ->visible(fn (RaffleTransaction $record) => str_starts_with((string) $record->proof_url, 'http')),
                    Tables\Actions\Action::make('creditWalletInstead')
                        // Only staff allowed to move money see this (App\Auth\StaffRoles).
                        ->hidden(fn () => ! static::staffCan('money.pay'))
                        ->label('Credit wallet instead')
                        ->icon('heroicon-o-wallet')
                        ->color('warning')
                        ->visible(fn (RaffleTransaction $record) => static::isWaiting($record) && $record->type === 'ticket_purchase')
                        ->requiresConfirmation()
                        ->modalDescription(fn (RaffleTransaction $record) => 'For when the tickets can\'t be issued (numbers taken, raffle already drawn). Puts '.static::naira($record->claimed_amount).' in the customer\'s wallet instead, so they can pick again. Only if the money is really in the bank.')
                        ->action(fn (RaffleTransaction $record) => static::attempt(
                            fn () => app(DepositApprovalService::class)->approve(static::admin(), $record, creditWalletInstead: true),
                            "Payment #{$record->id} credited to the customer's wallet.",
                        )),
                    Tables\Actions\Action::make('reject')
                        // Only staff allowed to move money see this (App\Auth\StaffRoles).
                        ->hidden(fn () => ! static::staffCan('money.pay'))
                        ->label('Reject')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (RaffleTransaction $record) => static::isWaiting($record))
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason (kept in the audit log)')
                                ->required()
                                ->maxLength(500),
                        ])
                        ->action(fn (RaffleTransaction $record, array $data) => static::attempt(
                            fn () => app(DepositApprovalService::class)->reject(static::admin(), $record, $data['reason']),
                            "Payment #{$record->id} rejected.",
                        )),
                ])->label('More')->icon('heroicon-m-ellipsis-vertical')->button()->color('gray'),
            ])
            ->emptyStateHeading('No bank transfers waiting')
            ->emptyStateDescription('Payments the automatic receipt check couldn\'t clear appear here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankTransfers::route('/'),
        ];
    }
}
