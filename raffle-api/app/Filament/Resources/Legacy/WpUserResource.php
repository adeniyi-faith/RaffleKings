<?php

namespace App\Filament\Resources\Legacy;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\BroadcastResource;
use App\Filament\Resources\Legacy\WpUserResource\Pages;
use App\Filament\Resources\Legacy\WpUserResource\RelationManagers;
use App\Filament\Support\MobileCard;
use App\Models\Admin\CustomerTag;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\Wallet;
use App\Services\Admin\CustomerBulkActions;
use App\Services\AdminAuditLogService;
use App\Services\Reports\ReportExporter;
use App\Services\UserManagementService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
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
            ->modifyQueryUsing(fn ($query) => $query->with(['wallet', 'meta' => fn ($q) => $q->where('meta_key', 'profile_pic_url')]))
            ->defaultSort('ID', 'desc')
            ->recordUrl(fn (WpUser $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (WpUser $record) => [
                    'avatar' => $record->avatarOrInitialsUrl(),
                    'avatar_fallback' => $record->initialsAvatarUrl(),
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
                    Tables\Columns\ImageColumn::make('profile_picture')
                        ->label('')
                        ->circular()
                        ->size(40)
                        ->state(fn (WpUser $record) => $record->avatarOrInitialsUrl())
                        ->extraImgAttributes(fn (WpUser $record) => $record->avatarImgAttributes()),
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
            ->filters(static::filters())
            // Tapping a customer opens their profile, so no separate Open button.
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actions(static::accountActions(table: true))
            ->bulkActions(static::bulkActions());
    }

    /** @throws RuntimeException if the admin's login has expired mid-session */
    private static function currentAdmin(): WpUser
    {
        $admin = auth('wordpress')->user();

        if (! $admin instanceof WpUser) {
            throw new \RuntimeException('Your admin login has expired. Please sign in again. Nothing was changed.');
        }

        return $admin;
    }

    /** Ways to narrow the list, so "select all" means exactly the customers you meant. */
    public static function filters(): array
    {
        $banned = fn () => WpUserMeta::query()->where('meta_key', 'rk_is_banned')->where('meta_value', '1')->select('user_id');

        return [
            Tables\Filters\SelectFilter::make('tag')->label('Has tag')->multiple()
                ->options(fn () => array_combine(CustomerTag::inUse(), CustomerTag::inUse()))
                ->query(fn ($query, array $data) => filled($data['values'] ?? null)
                    ? $query->whereIn('ID', CustomerTag::query()->whereIn('tag', $data['values'])->select('user_id'))
                    : $query),
            Tables\Filters\TernaryFilter::make('banned')->label('Banned')->placeholder('Everyone')->trueLabel('Banned only')->falseLabel('Not banned')
                ->queries(
                    true: fn ($query) => $query->whereIn('ID', $banned()),
                    false: fn ($query) => $query->whereNotIn('ID', $banned()),
                    blank: fn ($query) => $query,
                ),
            Tables\Filters\Filter::make('never_bought')->label('Never bought a ticket')->toggle()
                ->query(fn ($query) => $query->whereNotIn('ID', RaffleEntry::query()->select('user_id'))),
            Tables\Filters\Filter::make('has_money')->label('Has money in wallet or winnings')->toggle()
                ->query(fn ($query) => $query->whereIn('ID', Wallet::query()->where(fn ($w) => $w->where('wallet_balance', '>', 0)->orWhere('earnings_balance', '>', 0))->select('user_id'))),
            Tables\Filters\Filter::make('joined')->label('Joined')
                ->form([
                    Forms\Components\DatePicker::make('from')->label('Joined from'),
                    Forms\Components\DatePicker::make('until')->label('Joined until'),
                ])->columns(2)
                ->query(fn ($query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->where('user_registered', '>=', \Illuminate\Support\Carbon::parse($d, config('raffles.timezone'))->startOfDay()->utc()))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->where('user_registered', '<=', \Illuminate\Support\Carbon::parse($d, config('raffles.timezone'))->endOfDay()->utc())))
                ->indicateUsing(fn (array $data) => array_values(array_filter([
                    ($data['from'] ?? null) ? 'Joined from '.$data['from'] : null,
                    ($data['until'] ?? null) ? 'Joined until '.$data['until'] : null,
                ]))),
        ];
    }

    /**
     * Do something to every ticked customer (or, after "select all", every
     * customer matching the filters). Work is done by
     * App\Services\Admin\CustomerBulkActions, 500 at a time, and each change
     * is audit-logged against each customer.
     */
    public static function bulkActions(): array
    {
        $service = fn () => app(CustomerBulkActions::class);
        // fetchSelectedRecords(false): the action is given just the picked ids, not a heavy model for each.
        $ids = fn (Tables\Actions\BulkAction $action) => $action->getRecords();
        $manage = fn () => static::staffCan('customers.manage');
        $count = fn (HasTable $livewire) => number_format($livewire->getSelectedTableRecords(false)->count());

        $message = Tables\Actions\BulkAction::make('message')
            ->label('Message these customers')
            ->icon('heroicon-o-megaphone')
            ->visible(fn () => static::staffCan('messages'))
            ->fetchSelectedRecords(false)
            ->action(function (Tables\Actions\BulkAction $action, HasTable $livewire) use ($service, $ids) {
                $token = $service()->stashForMessage($ids($action));

                $livewire->redirect(BroadcastResource::getUrl('create', ['list' => $token]));
            });

        $addTags = Tables\Actions\BulkAction::make('addTags')
            ->label('Add tags')
            ->icon('heroicon-o-tag')
            ->visible($manage)
            ->fetchSelectedRecords(false)
            ->modalHeading(fn (HasTable $livewire) => 'Add tags to '.$count($livewire).' customers')
            ->form([
                Forms\Components\TagsInput::make('tags')->label('Tags')->required()->suggestions(fn () => CustomerTag::inUse())
                    ->splitKeys(['Tab', ','])->helperText('Only staff see tags. A customer who already has a tag keeps it, without a copy.'),
            ])
            ->action(function (Tables\Actions\BulkAction $action, array $data) use ($service, $ids) {
                $result = $service()->addTags(static::currentAdmin(), $ids($action), $data['tags']);

                Notification::make()->title($result['added'] === 0 ? 'Nothing to add: they all had those tags already' : "Added {$result['added']} tag".($result['added'] === 1 ? '' : 's')." to {$result['customers']} customer".($result['customers'] === 1 ? '' : 's'))->success()->send();
            });

        $removeTags = Tables\Actions\BulkAction::make('removeTags')
            ->label('Remove tags')
            ->icon('heroicon-o-x-mark')
            ->visible($manage)
            ->fetchSelectedRecords(false)
            ->modalHeading(fn (HasTable $livewire) => 'Remove tags from '.$count($livewire).' customers')
            ->form([
                Forms\Components\Select::make('tags')->label('Tags to remove')->multiple()->required()
                    ->options(fn () => array_combine(CustomerTag::inUse(), CustomerTag::inUse())),
            ])
            ->action(function (Tables\Actions\BulkAction $action, array $data) use ($service, $ids) {
                $result = $service()->removeTags(static::currentAdmin(), $ids($action), $data['tags']);

                Notification::make()->title($result['removed'] === 0 ? 'Nothing to remove: none of them had those tags' : "Removed {$result['removed']} tag".($result['removed'] === 1 ? '' : 's')." from {$result['customers']} customer".($result['customers'] === 1 ? '' : 's'))->success()->send();
            });

        $export = Tables\Actions\BulkAction::make('export')
            ->label('Download as spreadsheet')
            ->icon('heroicon-o-arrow-down-tray')
            ->visible($manage)
            ->fetchSelectedRecords(false)
            ->action(function (Tables\Actions\BulkAction $action) use ($service, $ids) {
                $picked = $ids($action);

                app(AdminAuditLogService::class)->record(static::currentAdmin(), 'report.downloaded', 'report', 0, ['report' => 'Customers (ticked on the list)', 'customers' => $picked->count()]);

                return response()->streamDownload(function () use ($service, $picked) {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names correctly
                    fputcsv($out, CustomerBulkActions::exportHeadings());

                    foreach ($service()->exportRows($picked) as $row) {
                        // A customer's name can't run as a spreadsheet formula.
                        fputcsv($out, array_map(ReportExporter::safeCell(...), $row));
                    }

                    fclose($out);
                }, 'customers-'.now(config('raffles.timezone'))->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
            });

        $ban = Tables\Actions\BulkAction::make('ban')
            ->label('Ban')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible($manage)
            ->fetchSelectedRecords(false)
            ->modalHeading(fn (HasTable $livewire) => 'Ban '.$count($livewire).' customers?')
            ->modalDescription('They are logged out and can\'t sign in until unbanned. Staff, and you, are never banned this way. At most '.CustomerBulkActions::MAX_BAN.' at a time.')
            ->form([Forms\Components\Textarea::make('reason')->label('Reason (kept in the audit log)')->required()->maxLength(200)->rows(2)])
            ->modalSubmitActionLabel('Yes, ban them')
            ->action(fn (Tables\Actions\BulkAction $action, array $data) => static::runBan($service()->ban(...), $ids($action)->all(), $data['reason'], 'banned'));

        $unban = Tables\Actions\BulkAction::make('unban')
            ->label('Unban')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible($manage)
            ->requiresConfirmation()
            ->fetchSelectedRecords(false)
            ->modalHeading(fn (HasTable $livewire) => 'Unban '.$count($livewire).' customers?')
            ->action(fn (Tables\Actions\BulkAction $action) => static::runBan(fn ($admin, $picked) => $service()->unban($admin, $picked), $ids($action)->all(), null, 'unbanned'));

        return [
            Tables\Actions\BulkActionGroup::make([$message, $addTags, $removeTags, $export, $ban, $unban])
                ->label('With the ticked customers')
                ->visible(fn () => static::staffCan('customers.manage') || static::staffCan('messages')),
        ];
    }

    /** Runs a bulk ban/unban and says in plain words what happened. */
    private static function runBan(callable $run, array $ids, ?string $reason, string $word): void
    {
        try {
            $result = $reason === null ? $run(static::currentAdmin(), $ids) : $run(static::currentAdmin(), $ids, $reason);
        } catch (\RuntimeException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        $extra = array_filter([
            $result['staff'] > 0 ? "{$result['staff']} left alone (staff or you)" : null,
            $result['already'] > 0 ? "{$result['already']} already ".($word === 'banned' ? 'banned' : 'not banned') : null,
        ]);

        Notification::make()->title("{$result['banned']} customer".($result['banned'] === 1 ? '' : 's')." {$word}")
            ->body($extra ? implode('. ', $extra).'.' : null)->success()->send();
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
                Forms\Components\Textarea::make('reason')
                    ->label('Why')
                    ->helperText('Kept with the adjustment and in the audit log. Changes above ₦'.number_format((float) config('ledger.adjustment_approval_over')).' wait for a second staff member to approve.')
                    ->required()
                    ->minLength(5),
            ])
            ->action(function (WpUser $record, array $data) {
                try {
                    $adjustment = app(UserManagementService::class)->adjustBalance(auth('wordpress')->user(), $record, $data['type'], (float) $data['amount'], $data['direction'], $data['reason']);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()
                    ->title($adjustment->status === 'applied' ? "#{$record->ID}'s balance updated." : "Saved. A second staff member has to approve this before #{$record->ID}'s balance changes.")
                    ->success()->send();
            });

        $ban = $action::make('ban')
            ->label('Ban')
            ->color('danger')
            ->icon('heroicon-o-no-symbol')
            ->visible(fn (WpUser $record) => ! $record->isBanned())
            ->modalDescription('They are logged out everywhere and can\'t sign in or spend until the ban is lifted. Lifting takes two staff members.')
            ->form([Forms\Components\Textarea::make('reason')->label('Why')->required()->minLength(5)])
            ->action(function (WpUser $record, array $data) {
                try {
                    app(UserManagementService::class)->ban(auth('wordpress')->user(), $record, $data['reason']);
                } catch (\RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title("#{$record->ID} banned.")->success()->send();
            });

        $unban = $action::make('unban')
            ->label('Unban')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->label(fn (WpUser $record) => app(\App\Services\AccountRestrictions::class)->active($record->ID)->contains(fn ($r) => $r->lift_requested_by !== null && (int) $r->lift_requested_by !== (int) auth('wordpress')->id()) ? 'Approve unban' : 'Ask to unban')
            ->visible(fn (WpUser $record) => $record->isBanned())
            ->form(fn (WpUser $record) => app(\App\Services\AccountRestrictions::class)->active($record->ID)->contains(fn ($r) => $r->lift_requested_by !== null && (int) $r->lift_requested_by !== (int) auth('wordpress')->id())
                ? []
                : [Forms\Components\Textarea::make('reason')->label('Why they should be unbanned')->required()->minLength(5)])
            ->action(function (WpUser $record, array $data) {
                $restrictions = app(\App\Services\AccountRestrictions::class);
                $me = auth('wordpress')->user();

                try {
                    $waiting = $restrictions->active($record->ID)->first(fn ($r) => $r->lift_requested_by !== null && (int) $r->lift_requested_by !== (int) $me->ID);

                    if ($waiting) {
                        foreach ($restrictions->active($record->ID)->whereNotNull('lift_requested_by') as $r) {
                            $restrictions->approveLift($me, $r);
                        }
                        Notification::make()->title("#{$record->ID} unbanned.")->success()->send();

                        return;
                    }

                    app(UserManagementService::class)->unban($me, $record, $data['reason'] ?? null);
                } catch (\RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Asked. A different staff member has to approve lifting the ban.')->success()->send();
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
                Forms\Components\Textarea::make('reason')->label('Why')->required()->minLength(5)
                    ->helperText('Turning one off only asks for it to be lifted; a different staff member approves that.'),
            ])
            ->action(function (WpUser $record, array $data) {
                app(UserManagementService::class)->updateRestrictions(
                    auth('wordpress')->user(),
                    $record,
                    (bool) ($data['is_banned'] ?? false),
                    (bool) ($data['ban_withdraw'] ?? false),
                    (bool) ($data['ban_transfer'] ?? false),
                    $data['ban_expiry'] ?? null,
                    $data['reason'],
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
