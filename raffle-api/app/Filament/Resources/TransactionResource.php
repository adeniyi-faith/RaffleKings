<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\TransactionResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\RaffleTransaction;
use App\Services\TransactionMonitorService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * OVERHAUL_CHECKLIST.md item 45 — every payment, ticket purchase, top-up,
 * bonus and balance adjustment in one searchable list (the old admin's
 * "Transaction Monitor" and "Purchase Log" pages, combined). Purchases
 * show the raffle and ticket numbers they bought. **Reverse** undoes a
 * transaction made in error (TransactionMonitorService): a top-up is
 * taken back, a purchase paid from a balance is refunded to it and its
 * tickets cancelled, and any cashback bonus is reversed. Withdrawals have
 * their own screen.
 */
class TransactionResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    public const TYPES = [
        'ticket_purchase_wallet' => 'Tickets (wallet)',
        'ticket_purchase_earnings' => 'Tickets (winnings)',
        'ticket_purchase' => 'Tickets (bank transfer)',
        'wallet_deposit' => 'Top-up',
        'deposit_manual' => 'Top-up (manual)',
        'wallet_payment' => 'Top-up (payment)',
        'deposit_bonus' => 'Cashback bonus',
        'admin_adjustment' => 'Admin adjustment',
    ];

    private const GROUPS = [
        'purchases' => ['ticket_purchase_wallet', 'ticket_purchase_earnings', 'ticket_purchase'],
        'topups' => ['wallet_deposit', 'deposit_manual', 'wallet_payment'],
        'other' => ['deposit_bonus', 'admin_adjustment'],
    ];

    private const NOT_REVERSIBLE = ['deposit_bonus', 'admin_adjustment'];

    protected static ?string $model = RaffleTransaction::class;

    protected static ?string $slug = 'transactions';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?string $modelLabel = 'transaction';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', '!=', 'withdrawal')
            ->with(['user', 'entries']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'verified_final', 'completed' => 'Done',
            'pending', 'manual_review' => 'Waiting',
            'rejected' => 'Rejected / reversed',
            'reversed' => 'Reversed',
            'refunded' => 'Refunded (raffle cancelled)',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'verified_final', 'completed' => 'success',
            'pending', 'manual_review' => 'warning',
            default => 'gray',
        };
    }

    private static function ticketSummary(RaffleTransaction $record): ?string
    {
        if ($record->entries->isEmpty()) {
            return $record->type === 'ticket_purchase' && $record->pending_numbers
                ? "Raffle #{$record->pending_raffle_id} · {$record->pending_numbers} (not issued)"
                : null;
        }

        $numbers = $record->entries->pluck('ticket_number')->sort()->implode(', ');

        return 'Raffle #'.$record->entries->first()->raffle_id.' · '.$record->entries->count().' ticket(s): '.$numbers;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (RaffleTransaction $record) => [
                    'title' => $record->user?->display_name ?: $record->user?->user_login,
                    'amount' => static::naira($record->claimed_amount),
                    'lines' => [static::TYPES[$record->type] ?? ucfirst(str_replace('_', ' ', (string) $record->type)), static::ticketSummary($record)],
                    'badges' => [[static::statusLabel((string) $record->status), static::statusColor((string) $record->status)]],
                    'meta' => $record->created_at?->format('j M, H:i'),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('When')->since()->sortable()->description(fn (RaffleTransaction $r) => $r->created_at?->format('j M, H:i')),
                    Tables\Columns\TextColumn::make('user.display_name')
                        ->label('Customer')
                        ->description(fn (RaffleTransaction $record) => $record->user?->user_email)
                        ->searchable(['display_name', 'user_login', 'user_email']),
                    Tables\Columns\TextColumn::make('type')
                        ->label('What')
                        ->formatStateUsing(fn (string $state) => static::TYPES[$state] ?? ucfirst(str_replace('_', ' ', $state)))
                        ->description(fn (RaffleTransaction $record) => static::ticketSummary($record))
                        ->wrap(),
                    Tables\Columns\TextColumn::make('claimed_amount')->label('Amount')->formatStateUsing(fn ($state) => static::naira($state))->weight('bold')->sortable(),
                    Tables\Columns\TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => static::statusLabel($state))
                        ->color(fn (string $state) => static::statusColor($state)),
                    Tables\Columns\TextColumn::make('order_id')->label('Reference')->placeholder('None')->searchable()->toggleable(isToggledHiddenByDefault: true),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->label('Kind')
                    ->options(['purchases' => 'Ticket purchases', 'topups' => 'Top-ups', 'other' => 'Bonuses & adjustments'])
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereIn('type', self::GROUPS[$data['value']]) : $query),
                Tables\Filters\SelectFilter::make('period')
                    ->label('When')
                    ->options(['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'Last 7 days', 'month' => 'This month'])
                    ->query(function (Builder $query, array $data) {
                        $tz = config('raffles.timezone', 'Africa/Lagos');

                        return match ($data['value'] ?? null) {
                            'today' => $query->where('created_at', '>=', now($tz)->startOfDay()->utc()),
                            'yesterday' => $query->whereBetween('created_at', [now($tz)->subDay()->startOfDay()->utc(), now($tz)->startOfDay()->utc()]),
                            'week' => $query->where('created_at', '>=', now()->subDays(7)),
                            'month' => $query->where('created_at', '>=', now($tz)->startOfMonth()->utc()),
                            default => $query,
                        };
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->options(['verified_final' => 'Done', 'pending' => 'Waiting', 'manual_review' => 'Waiting (review)', 'rejected' => 'Rejected / reversed']),
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('reverse')
                    // Only staff allowed to move money see this (App\Auth\StaffRoles).
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('Reverse')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (RaffleTransaction $record) => $record->status === 'verified_final' && ! in_array($record->type, self::NOT_REVERSIBLE, true))
                    ->modalHeading('Reverse this transaction?')
                    ->modalDescription(fn (RaffleTransaction $record) => match (true) {
                        str_starts_with($record->type, 'ticket_purchase_') => 'Cancels its tickets and refunds '.static::naira($record->claimed_amount).' to the customer\'s '.($record->type === 'ticket_purchase_earnings' ? 'winnings' : 'wallet').'.',
                        $record->type === 'ticket_purchase' => 'Cancels its tickets. No balance changes (it was paid by bank transfer).',
                        default => 'Takes '.static::naira($record->claimed_amount).' back out of the customer\'s wallet (their balance can go below zero), and reverses any cashback bonus it earned. Use this when the money never really arrived.',
                    })
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('Reason (kept in the audit log)')->required()->maxLength(500),
                    ])
                    ->action(fn (RaffleTransaction $record, array $data) => static::attempt(
                        fn () => app(TransactionMonitorService::class)->revoke(static::admin(), $record, $data['reason']),
                        "Transaction #{$record->id} reversed.",
                    )),
            ])
            ->emptyStateHeading('No transactions match');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
        ];
    }
}
