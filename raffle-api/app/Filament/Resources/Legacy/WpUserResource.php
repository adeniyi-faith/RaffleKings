<?php

namespace App\Filament\Resources\Legacy;

use App\Filament\Resources\Legacy\WpUserResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Services\UserManagementService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * No create/edit forms — accounts and passwords are still owned by the
 * legacy WordPress `wp_users` table (see App\Models\Legacy\WpUser's
 * docblock). This resource is read + the one action an admin actually
 * needs here: ban/unban, via App\Services\UserManagementService, which
 * writes the SAME `rk_is_banned` usermeta flag the legacy site already
 * reads, and logs the change to the admin audit log (item 19).
 */
class WpUserResource extends Resource
{
    protected static ?string $model = WpUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Users';

    protected static ?string $modelLabel = 'user';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /** @var array<int, ?Wallet> Looked up once per user per page. */
    private static array $wallets = [];

    private static function wallet(WpUser $record): ?Wallet
    {
        return static::$wallets[$record->ID] ??= Wallet::where('user_id', $record->ID)->first();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (WpUser $record) => [
                    'title' => $record->display_name ?: $record->user_login,
                    'amount' => '₦'.number_format((float) static::wallet($record)?->wallet_balance),
                    'lines' => [
                        $record->user_email,
                        '@'.$record->user_login.' · #'.$record->ID.' · winnings ₦'.number_format((float) static::wallet($record)?->earnings_balance),
                    ],
                    'badges' => [
                        $record->isAdministrator() ? ['Admin', 'primary'] : null,
                        $record->isBanned() ? ['Banned', 'danger'] : null,
                    ],
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('ID')->sortable(),
                    Tables\Columns\TextColumn::make('user_login')->label('Username')->searchable(),
                    Tables\Columns\TextColumn::make('user_email')->label('Email')->searchable(),
                    Tables\Columns\TextColumn::make('display_name')->label('Display name'),
                    Tables\Columns\TextColumn::make('wallet_balance')
                        ->label('Wallet')
                        ->state(fn (WpUser $record) => static::wallet($record)?->wallet_balance ?? 0)
                        ->money('NGN'),
                    Tables\Columns\TextColumn::make('earnings_balance')
                        ->label('Earnings')
                        ->state(fn (WpUser $record) => static::wallet($record)?->earnings_balance ?? 0)
                        ->money('NGN'),
                    Tables\Columns\IconColumn::make('is_administrator')
                        ->label('Admin')
                        ->boolean()
                        ->state(fn (WpUser $record) => $record->isAdministrator()),
                    Tables\Columns\IconColumn::make('is_banned')
                        ->label('Banned')
                        ->boolean()
                        ->state(fn (WpUser $record) => $record->isBanned()),
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('adjustBalance')
                    ->label('Adjust balance')
                    ->color('warning')
                    ->icon('heroicon-o-banknotes')
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('Balance')
                            ->options(['wallet' => 'Spending wallet', 'earnings' => 'Earnings', 'points' => 'Points'])
                            ->required(),
                        Forms\Components\Select::make('direction')
                            ->options(['add' => 'Add (+)', 'subtract' => 'Subtract (-)'])
                            ->required(),
                        Forms\Components\TextInput::make('amount')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(),
                    ])
                    ->action(function (WpUser $record, array $data) {
                        try {
                            app(UserManagementService::class)->adjustBalance(auth('wordpress')->user(), $record, $data['type'], (float) $data['amount'], $data['direction']);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title("#{$record->ID}'s balance updated.")->success()->send();
                    }),
                // Less frequent, and ban is drastic: tucked behind "More" so
                // the row stays one line of buttons on a phone.
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('ban')
                        ->label('Ban')
                        ->color('danger')
                        ->icon('heroicon-o-no-symbol')
                        ->visible(fn (WpUser $record) => ! $record->isBanned())
                        ->requiresConfirmation()
                        ->action(function (WpUser $record) {
                            app(UserManagementService::class)->ban(auth('wordpress')->user(), $record);
                            Notification::make()->title("#{$record->ID} banned.")->success()->send();
                        }),
                    Tables\Actions\Action::make('unban')
                        ->label('Unban')
                        ->color('success')
                        ->icon('heroicon-o-check-circle')
                        ->visible(fn (WpUser $record) => $record->isBanned())
                        ->requiresConfirmation()
                        ->action(function (WpUser $record) {
                            app(UserManagementService::class)->unban(auth('wordpress')->user(), $record);
                            Notification::make()->title("#{$record->ID} unbanned.")->success()->send();
                        }),
                    Tables\Actions\Action::make('restrictions')
                        ->label('Restrictions')
                        ->color('gray')
                        ->icon('heroicon-o-shield-exclamation')
                        ->fillForm(fn (WpUser $record) => [
                            'is_banned' => $record->isBanned(),
                            'ban_withdraw' => $record->metaValue('rk_ban_withdraw') === '1',
                            'ban_transfer' => $record->metaValue('rk_ban_transfer') === '1',
                            'ban_expiry' => $record->metaValue('rk_ban_expiry') ?: null,
                        ])
                        ->form([
                            Forms\Components\Checkbox::make('is_banned')->label('Full account ban (login blocked)'),
                            Forms\Components\Checkbox::make('ban_withdraw')
                                ->label('Block withdrawals')
                                ->helperText('They can still play, but any withdrawal request is refused.'),
                            Forms\Components\Checkbox::make('ban_transfer')
                                ->label('Block transfers')
                                ->helperText('Kept from the old site. The new site has no customer-to-customer transfers, so this has no effect today.'),
                            Forms\Components\DatePicker::make('ban_expiry')->label('Restriction expiry (optional)'),
                        ])
                        ->action(function (WpUser $record, array $data) {
                            app(UserManagementService::class)->updateRestrictions(
                                auth('wordpress')->user(),
                                $record,
                                (bool) ($data['is_banned'] ?? false),
                                (bool) ($data['ban_withdraw'] ?? false),
                                (bool) ($data['ban_transfer'] ?? false),
                                $data['ban_expiry'] ?? null,
                            );
                            Notification::make()->title("#{$record->ID}'s restrictions updated.")->success()->send();
                        }),
                ])->label('More')->icon('heroicon-m-ellipsis-vertical')->button()->color('gray'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWpUsers::route('/'),
        ];
    }
}
