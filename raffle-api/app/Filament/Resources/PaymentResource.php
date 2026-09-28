<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\Legacy\WpUserResource;
use App\Filament\Resources\PaymentResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Deposit;
use App\Services\AdminAuditLogService;
use App\Services\DepositService;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Finance → Online payments (item 45b): every Paystack / Flutterwave
 * top-up — paid, failed, still open or abandoned — not just the ones that
 * went wrong. For "I paid but got nothing": find the payment, press
 * "Check again", and the gateway is asked directly; if the money really
 * arrived it's credited on the spot (DepositService::confirm, which never
 * credits twice).
 */
class PaymentResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = Deposit::class;

    protected static ?string $slug = 'payments';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Online payments';

    protected static ?string $modelLabel = 'online payment';

    protected static ?int $navigationSort = 3;

    /** A payment still "pending" after this long was almost certainly abandoned. */
    public const ABANDONED_AFTER_MINUTES = 60;

    public static function statusLabel(Deposit $d): string
    {
        return match ($d->status) {
            'successful' => 'Paid & credited',
            'failed' => 'Failed',
            'amount_mismatch' => 'Amount mismatch',
            'pending' => $d->created_at?->lt(now()->subMinutes(self::ABANDONED_AFTER_MINUTES)) ? 'Not finished' : 'In progress',
            default => ucfirst(str_replace('_', ' ', (string) $d->status)),
        };
    }

    public static function statusColor(Deposit $d): string
    {
        return match ($d->status) {
            'successful' => 'success',
            'failed' => 'danger',
            'amount_mismatch' => 'warning',
            default => 'gray',
        };
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Deposit $d) => static::getUrl('view', ['record' => $d]))
            ->searchPlaceholder('Reference, customer or email')
            ->columns([
                MobileCard::make(fn (Deposit $d) => [
                    'title' => $d->user?->display_name ?: $d->user?->user_login,
                    'amount' => '₦'.number_format((float) $d->amount),
                    'lines' => [ucfirst((string) $d->gateway), $d->failure_reason],
                    'copy' => ['value' => $d->reference],
                    'badges' => [[static::statusLabel($d), static::statusColor($d)]],
                    'meta' => $d->created_at?->format('j M, H:i'),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('Started')->dateTime('j M Y, H:i')->sortable()->description(fn (Deposit $d) => $d->created_at?->diffForHumans()),
                    Tables\Columns\TextColumn::make('user.display_name')->label('Customer')->description(fn (Deposit $d) => $d->user?->user_email)
                        ->searchable(['display_name', 'user_login', 'user_email']),
                    Tables\Columns\TextColumn::make('amount')->money('NGN')->weight('bold')->sortable(),
                    Tables\Columns\TextColumn::make('gateway')->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                    Tables\Columns\TextColumn::make('status')->badge()->state(fn (Deposit $d) => static::statusLabel($d))->color(fn (Deposit $d) => static::statusColor($d))
                        ->description(fn (Deposit $d) => $d->failure_reason ? str($d->failure_reason)->limit(50) : null),
                    Tables\Columns\TextColumn::make('reference')->fontFamily('mono')->size('xs')->copyable()->searchable(),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'successful' => 'Paid & credited',
                    'pending' => 'Not finished / in progress',
                    'failed' => 'Failed',
                    'amount_mismatch' => 'Amount mismatch',
                ]),
                Tables\Filters\SelectFilter::make('gateway')->options(['paystack' => 'Paystack', 'flutterwave' => 'Flutterwave']),
                Tables\Filters\SelectFilter::make('period')
                    ->label('When')
                    ->options(['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'today' => $query->where('created_at', '>=', now(config('raffles.timezone'))->startOfDay()->utc()),
                        '7', '30' => $query->where('created_at', '>=', now()->subDays((int) $data['value'])),
                        default => $query,
                    }),
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actions([static::checkAgainAction(Tables\Actions\Action::class)])
            ->emptyStateHeading('No online payments yet');
    }

    /** "Check again" — shared by the list and the payment's own page. */
    public static function checkAgainAction(string $actionClass)
    {
        return $actionClass::make('checkAgain')
            ->label('Check again')
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->visible(fn (Deposit $record) => in_array($record->status, ['pending', 'failed'], true))
            ->hidden(fn () => ! static::staffCan('money.pay') && ! static::staffCan('payments.recheck'))
            ->requiresConfirmation()
            ->modalHeading(fn (Deposit $record) => 'Ask '.ucfirst((string) $record->gateway).' about this payment?')
            ->modalDescription('If the gateway says the money arrived, the customer\'s wallet is credited now. It can never be credited twice.')
            ->modalSubmitActionLabel('Check now')
            ->action(function (Deposit $record) {
                try {
                    $after = app(DepositService::class)->confirm((string) $record->gateway, (string) $record->reference);
                } catch (Throwable $e) {
                    Notification::make()->title('Could not reach '.ucfirst((string) $record->gateway))->body($e->getMessage())->danger()->send();

                    return;
                }

                app(AdminAuditLogService::class)->record(static::admin(), 'deposit.rechecked', Deposit::class, $record->id, [
                    'reference' => $record->reference,
                    'result' => $after->status,
                ]);

                match ($after->status) {
                    'successful' => Notification::make()->title('Paid — ₦'.number_format((float) $after->amount).' credited to the customer.')->success()->send(),
                    'amount_mismatch' => Notification::make()->title('The amount paid doesn\'t match — see Payment mismatches.')->warning()->send(),
                    default => Notification::make()->title('Not paid')->body(ucfirst((string) $record->gateway).' says this payment was not completed. Nothing was credited.')->info()->send(),
                };
            });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Components\Section::make()->columns(['default' => 2, 'md' => 3])->schema([
                Components\TextEntry::make('amount')->money('NGN')->size(Components\TextEntry\TextEntrySize::Large)->weight('bold'),
                Components\TextEntry::make('status')->badge()->state(fn (Deposit $d) => static::statusLabel($d))->color(fn (Deposit $d) => static::statusColor($d)),
                Components\TextEntry::make('gateway')->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                Components\TextEntry::make('user.display_name')->label('Customer')
                    ->url(fn (Deposit $d) => $d->user ? WpUserResource::getUrl('view', ['record' => $d->user]) : null)
                    ->color('primary'),
                Components\TextEntry::make('reference')->copyable()->fontFamily('mono'),
                Components\TextEntry::make('gateway_transaction_id')->label('Gateway\'s own ID')->copyable()->placeholder('—'),
                Components\TextEntry::make('created_at')->label('Started')->dateTime('j M Y, H:i:s'),
                Components\TextEntry::make('verified_at')->label('Confirmed')->dateTime('j M Y, H:i:s')->placeholder('Not confirmed'),
                Components\TextEntry::make('failure_reason')->label('What the gateway said')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }
}
