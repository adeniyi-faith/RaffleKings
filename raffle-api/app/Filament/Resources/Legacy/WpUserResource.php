<?php

namespace App\Filament\Resources\Legacy;

use App\Filament\Resources\Legacy\WpUserResource\Pages;
use App\Models\Legacy\WpUser;
use App\Models\Wallet;
use App\Services\UserManagementService;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ID')->sortable(),
                Tables\Columns\TextColumn::make('user_login')->label('Username')->searchable(),
                Tables\Columns\TextColumn::make('user_email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('display_name')->label('Display name'),
                Tables\Columns\TextColumn::make('wallet_balance')
                    ->label('Wallet')
                    ->state(fn (WpUser $record) => Wallet::where('user_id', $record->ID)->value('wallet_balance') ?? 0)
                    ->money('NGN'),
                Tables\Columns\TextColumn::make('earnings_balance')
                    ->label('Earnings')
                    ->state(fn (WpUser $record) => Wallet::where('user_id', $record->ID)->value('earnings_balance') ?? 0)
                    ->money('NGN'),
                Tables\Columns\IconColumn::make('is_administrator')
                    ->label('Admin')
                    ->boolean()
                    ->state(fn (WpUser $record) => $record->isAdministrator()),
                Tables\Columns\IconColumn::make('is_banned')
                    ->label('Banned')
                    ->boolean()
                    ->state(fn (WpUser $record) => $record->isBanned()),
            ])
            ->actions([
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
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWpUsers::route('/'),
        ];
    }
}
