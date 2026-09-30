<?php

namespace App\Filament\Resources\Legacy;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\Legacy\WpUserResource\Pages;
use App\Filament\Resources\Legacy\WpUserResource\RelationManagers;
use App\Filament\Support\MobileCard;
use App\Models\Admin\CustomerTag;
use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use App\Services\UserManagementService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Users → Customers. Accounts and passwords still live in the legacy
 * WordPress `wp_users` table (see App\Models\Legacy\WpUser), so there's
 * no create/edit form. Tapping a customer opens their profile
 * (Pages\ViewWpUser): balances, warning signs and every record about
 * them in one place (item 45b). Balance changes, bans and restrictions
 * go through App\Services\UserManagementService and are audit-logged.
 */
class WpUserResource extends Resource
{
    use GuardedByStaffRole;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = WpUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Users';

    protected static ?string $navigationLabel = 'Customers';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'customer';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    // Ctrl/⌘+K finds a customer from anywhere in the admin.
    public static function getGloballySearchableAttributes(): array
    {
        return ['user_login', 'user_email', 'display_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->display_name ?: $record->user_login;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Email' => $record->user_email, 'Username' => $record->user_login];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('wallet'))
            ->defaultSort('ID', 'desc')
            ->recordUrl(fn (WpUser $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (WpUser $record) => [
                    'title' => $record->display_name ?: $record->user_login,
                    'amount' => '₦'.number_format((float) $record->wallet?->wallet_balance),
                    'lines' => [
                        $record->user_email,
                        '@'.$record->user_login.' · #'.$record->ID.' · winnings ₦'.number_format((float) $record->wallet?->earnings_balance),
                    ],
                    'badges' => [
                        $record->isAdministrator() ? ['Admin', 'primary'] : null,
                        $record->isBanned() ? ['Banned', 'danger'] : null,
                    ],
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('display_name')
                        ->label('Customer')
                        ->description(fn (WpUser $record) => '@'.$record->user_login.' · #'.$record->ID)
                        ->searchable(['display_name', 'user_login']),
                    Tables\Columns\TextColumn::make('user_email')->label('Email')->searchable(),
                    Tables\Columns\TextColumn::make('wallet_balance')
                        ->label('Wallet')
                        ->state(fn (WpUser $record) => $record->wallet?->wallet_balance ?? 0)
                        ->naira(),
                    Tables\Columns\TextColumn::make('earnings_balance')
                        ->label('Winnings')
                        ->state(fn (WpUser $record) => $record->wallet?->earnings_balance ?? 0)
                        ->naira(),
                    Tables\Columns\TextColumn::make('user_registered')->label('Joined')->since()->sortable()->visibleFrom('2xl'),
                    Tables\Columns\IconColumn::make('is_banned')
                        ->label('Banned')
                        ->boolean()
                        ->state(fn (WpUser $record) => $record->isBanned()),
                ]),
            ])
            // Tapping a customer opens their profile, so no separate Open button.
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actions(static::accountActions(table: true));
    }

    /**
     * Adjust balance, ban/unban and restrictions — the same buttons on the
     * list (table actions) and the profile page (header actions).
     */
    public static function accountActions(bool $table): array
    {
        $action = $table ? Tables\Actions\Action::class : Actions\Action::class;
        $group = $table ? Tables\Actions\ActionGroup::class : Actions\ActionGroup::class;

        $adjust = $action::make('adjustBalance')
            ->label('Adjust balance')
            ->color('warning')
            ->icon('heroicon-o-banknotes')
            ->modalDescription('Adds or takes away money or points. It shows in the customer\'s history as an admin adjustment and in the audit log.')
            ->form([
                Forms\Components\Select::make('type')
                    ->label('Balance')
                    ->options(['wallet' => 'Spending wallet', 'earnings' => 'Winnings', 'points' => 'Points'])
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
            });

        $ban = $action::make('ban')
            ->label('Ban')
            ->color('danger')
            ->icon('heroicon-o-no-symbol')
            ->visible(fn (WpUser $record) => ! $record->isBanned())
            ->requiresConfirmation()
            ->modalDescription('They are logged out and can\'t log in until unbanned.')
            ->action(function (WpUser $record) {
                app(UserManagementService::class)->ban(auth('wordpress')->user(), $record);
                Notification::make()->title("#{$record->ID} banned.")->success()->send();
            });

        $unban = $action::make('unban')
            ->label('Unban')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (WpUser $record) => $record->isBanned())
            ->requiresConfirmation()
            ->action(function (WpUser $record) {
                app(UserManagementService::class)->unban(auth('wordpress')->user(), $record);
                Notification::make()->title("#{$record->ID} unbanned.")->success()->send();
            });

        $restrictions = $action::make('restrictions')
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
            });

        // Look-only staff (e.g. Support) don't get these buttons at all.
        $manage = fn () => ! static::staffCan('customers.manage');
        foreach ([$adjust, $ban, $unban, $restrictions] as $button) {
            $button->hidden($manage);
        }

        return [
            $adjust,
            // Less frequent, and ban is drastic: tucked behind "More" so
            // the row stays one line of buttons on a phone.
            $group::make([$ban, $unban, $restrictions])->label('More')->icon('heroicon-m-ellipsis-vertical')->button()->color('gray'),
        ];
    }

    /**
     * Replaces a customer's staff tags. Audit-logged.
     *
     * @param  list<string>  $tags
     * @return bool whether anything changed
     */
    public static function setTags(int $userId, array $tags): bool
    {
        $wanted = collect($tags)->map(fn ($t) => CustomerTag::clean((string) $t))->filter()->unique(fn ($t) => mb_strtolower($t))->values();
        $current = CustomerTag::query()->where('user_id', $userId)->pluck('tag');

        $removed = $current->reject(fn ($t) => $wanted->contains(fn ($w) => mb_strtolower($w) === mb_strtolower($t)))->values();
        $added = $wanted->reject(fn ($w) => $current->contains(fn ($t) => mb_strtolower($w) === mb_strtolower($t)))->values();

        if ($removed->isEmpty() && $added->isEmpty()) {
            return false;
        }

        CustomerTag::query()->where('user_id', $userId)->whereIn('tag', $removed)->delete();
        foreach ($added as $tag) {
            CustomerTag::query()->firstOrCreate(['user_id' => $userId, 'tag' => $tag], ['added_by' => auth('wordpress')->id(), 'created_at' => now()]);
        }

        app(AdminAuditLogService::class)->record(auth('wordpress')->user(), 'customer.tags_changed', WpUser::class, $userId, [
            'added' => $added->all(),
            'removed' => $removed->all(),
        ]);

        return true;
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MoneyMovementsRelationManager::class,
            RelationManagers\TicketsRelationManager::class,
            RelationManagers\TopUpsRelationManager::class,
            RelationManagers\WithdrawalsRelationManager::class,
            RelationManagers\ReferralsRelationManager::class,
            RelationManagers\SupportTicketsRelationManager::class,
            RelationManagers\AdminActionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWpUsers::route('/'),
            'view' => Pages\ViewWpUser::route('/{record}'),
        ];
    }
}
