<?php

namespace App\Filament\Resources;

use App\Exceptions\PaymentGatewayException;
use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\PaymentMismatchResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Deposit;
use App\Services\DepositMismatchService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md item 44 — card/online payments the gateway
 * confirmed for a DIFFERENT amount than the customer started with. They
 * are never credited automatically. "Credit confirmed amount" asks the
 * gateway again and credits exactly what it confirms right now; "Reject"
 * credits nothing. Both are audit-logged and refuse to run twice.
 */
class PaymentMismatchResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = Deposit::class;

    protected static ?string $slug = 'payment-mismatches';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Payment mismatches';

    protected static ?string $modelLabel = 'payment mismatch';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Deposit::query()->where('status', 'amount_mismatch')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', 'amount_mismatch')->with('user');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (Deposit $record) => [
                    'title' => $record->user?->display_name ?: $record->user?->user_login,
                    'amount' => static::naira($record->amount),
                    'body' => $record->failure_reason,
                    'copy' => ['value' => $record->reference],
                    'badges' => [[ucfirst((string) $record->gateway), 'gray']],
                    'meta' => $record->created_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('user.display_name')
                        ->label('Customer')
                        ->description(fn (Deposit $record) => $record->user?->user_email),
                    Tables\Columns\TextColumn::make('amount')->label('Started with')->formatStateUsing(fn ($state) => static::naira($state)),
                    Tables\Columns\TextColumn::make('failure_reason')->label('What the gateway said')->wrap()->limit(120),
                    Tables\Columns\TextColumn::make('reference')
                        ->visibleFrom('2xl')
                        ->copyable()
                        ->fontFamily('mono')
                        ->size('xs')
                        ->description(fn (Deposit $record) => ucfirst((string) $record->gateway)),
                    Tables\Columns\TextColumn::make('created_at')->label('When')->since(),
                ]),
            ])
            // Actions first: acting on each row is this screen's whole
            // purpose, so the buttons must never be pushed off-screen.
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('credit')
                    // Only staff allowed to move money see this (App\Auth\StaffRoles).
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('Credit')
                    ->tooltip('Credit the amount the gateway confirmed')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Asks the payment gateway again and credits exactly the amount it confirms now, not the amount the customer started with.')
                    ->action(fn (Deposit $record) => static::attempt(function () use ($record) {
                        try {
                            app(DepositMismatchService::class)->creditConfirmedAmount(static::admin(), $record);
                        } catch (PaymentGatewayException $e) {
                            throw new RuntimeException('Couldn\'t reach the payment gateway to double-check this payment. Try again in a few minutes. Nothing was credited.');
                        }
                    }, "Payment #{$record->id} credited.")),
                Tables\Actions\Action::make('reject')
                    // Only staff allowed to move money see this (App\Auth\StaffRoles).
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('Reason (kept in the audit log)')->required()->maxLength(500),
                    ])
                    ->action(fn (Deposit $record, array $data) => static::attempt(
                        fn () => app(DepositMismatchService::class)->reject(static::admin(), $record, $data['reason']),
                        "Payment #{$record->id} rejected.",
                    )),
            ])
            ->emptyStateHeading('No mismatched payments')
            ->emptyStateDescription('Online payments confirmed for a different amount than expected appear here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentMismatches::route('/'),
        ];
    }
}
