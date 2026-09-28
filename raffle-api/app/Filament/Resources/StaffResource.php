<?php

namespace App\Filament\Resources;

use App\Auth\StaffRoles;
use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\StaffResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Services\AdminAuditLogService;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Users → Staff (item 45b, owners only): who can use this admin and what
 * they can do. Roles are explained in App\Auth\StaffRoles. Guard rails:
 * you can't change your own role, and the last owner can't be removed.
 */
class StaffResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = WpUser::class;

    protected static ?string $slug = 'staff';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Users';

    protected static ?string $navigationLabel = 'Staff & roles';

    protected static ?string $modelLabel = 'staff member';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    /** Everyone who has a role or is a WordPress administrator. */
    public static function getEloquentQuery(): Builder
    {
        $prefix = config('legacy.wp_prefix');

        return parent::getEloquentQuery()->whereIn('ID', WpUserMeta::query()
            ->where(fn ($q) => $q->where('meta_key', StaffRoles::META_KEY)->where('meta_value', '!=', StaffRoles::NO_ACCESS))
            ->orWhere(fn ($q) => $q->where('meta_key', $prefix.'capabilities')->where('meta_value', 'like', '%"administrator"%'))
            ->select('user_id'));
    }

    public static function roleHelp(): HtmlString
    {
        return new HtmlString(collect(StaffRoles::ROLES)->map(fn ($r) => '<b>'.e($r['label']).'</b>: '.e($r['description']))->implode('<br>'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                MobileCard::make(fn (WpUser $u) => [
                    'title' => $u->display_name ?: $u->user_login,
                    'lines' => [$u->user_email],
                    'badges' => [[StaffRoles::ROLES[$u->staffRole()]['label'] ?? 'No access', $u->staffRole() === 'owner' ? 'primary' : 'info']],
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('display_name')->label('Name')->description(fn (WpUser $u) => '@'.$u->user_login),
                    Tables\Columns\TextColumn::make('user_email')->label('Email'),
                    Tables\Columns\TextColumn::make('role')->badge()
                        ->state(fn (WpUser $u) => StaffRoles::ROLES[$u->staffRole()]['label'] ?? 'No access')
                        ->description(fn (WpUser $u) => StaffRoles::ROLES[$u->staffRole()]['description'] ?? null)
                        ->color(fn (WpUser $u) => $u->staffRole() === 'owner' ? 'primary' : 'info')
                        ->wrap(),
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('changeRole')
                    ->label('Change role')
                    ->icon('heroicon-o-arrows-right-left')
                    ->hidden(fn (WpUser $record) => $record->ID === static::admin()->ID)
                    ->fillForm(fn (WpUser $record) => ['role' => $record->staffRole()])
                    ->form([
                        Forms\Components\Radio::make('role')->options(StaffRoles::options())
                            ->descriptions(array_map(fn ($r) => $r['description'], StaffRoles::ROLES))->required(),
                    ])
                    ->action(fn (WpUser $record, array $data) => static::attempt(fn () => static::setRole($record, $data['role'], static::admin()), 'Role changed.')),
                Tables\Actions\Action::make('remove')
                    ->label('Remove access')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->hidden(fn (WpUser $record) => $record->ID === static::admin()->ID)
                    ->requiresConfirmation()
                    ->modalDescription('They go back to being an ordinary customer and can\'t open the admin any more.')
                    ->action(fn (WpUser $record) => static::attempt(fn () => static::setRole($record, StaffRoles::NO_ACCESS, static::admin()), 'Access removed.')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('add')
                    ->label('Add staff member')
                    ->icon('heroicon-o-user-plus')
                    ->form([
                        Forms\Components\Select::make('user_id')->label('Customer account')
                            ->helperText('They must have signed up on the site first.')
                            ->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search) => WpUser::query()
                                ->where(fn ($q) => $q->where('user_email', 'like', "%{$search}%")->orWhere('user_login', 'like', "%{$search}%")->orWhere('display_name', 'like', "%{$search}%"))
                                ->limit(20)->get()->mapWithKeys(fn (WpUser $u) => [$u->ID => ($u->display_name ?: $u->user_login).' · '.$u->user_email]))
                            ->getOptionLabelUsing(fn ($value) => WpUser::find($value)?->user_email),
                        Forms\Components\Radio::make('role')->options(StaffRoles::options())
                            ->descriptions(array_map(fn ($r) => $r['description'], StaffRoles::ROLES))->default('support')->required(),
                    ])
                    ->action(fn (array $data) => static::attempt(fn () => static::setRole(WpUser::findOrFail($data['user_id']), $data['role'], static::admin()), 'Staff member added.')),
            ])
            ->emptyStateHeading('No staff yet');
    }

    public static function setRole(WpUser $user, string $role, WpUser $admin): void
    {
        if ($user->ID === $admin->ID) {
            throw new RuntimeException('You can\'t change your own role. Ask another owner.');
        }

        $was = $user->staffRole();

        if ($was === 'owner' && $role !== 'owner' && static::ownerCount() <= 1) {
            throw new RuntimeException('This is the only owner. Make someone else an owner first.');
        }

        WpUserMeta::query()->updateOrCreate(['user_id' => $user->ID, 'meta_key' => StaffRoles::META_KEY], ['meta_value' => $role]);
        $user->forgetStaffRole();

        app(AdminAuditLogService::class)->record($admin, 'staff.role_changed', WpUser::class, $user->ID, [
            'from' => $was ?? 'customer',
            'to' => $role === StaffRoles::NO_ACCESS ? 'no access' : $role,
        ]);
    }

    private static function ownerCount(): int
    {
        return static::getEloquentQuery()->get()->filter(fn (WpUser $u) => $u->staffRole() === 'owner')->count();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
        ];
    }
}
