<?php

namespace App\Filament\Pages;

use App\Auth\StaffRoles;
use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\AdminAuditLogResource;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\WpUser;
use App\Services\Admin\StaffActivity as Activity;
use App\Services\Auth\StaffTwoStep;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Users → Staff activity (owners only): who has signed in to the admin and
 * from where, failed sign-in attempts, what each person changed, and the
 * switch for two-step sign-in (an emailed code after the password). The
 * numbers come from App\Services\Admin\StaffActivity.
 */
class StaffActivity extends Page implements HasTable
{
    use GuardedByStaffRole, InteractsWithTable, RunsAdminActions;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Users';

    protected static ?string $navigationLabel = 'Staff activity';

    protected static ?string $title = 'Staff activity';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.staff-activity';

    public function getSubheading(): ?string
    {
        return 'Who signed in, from where, and what they changed in the last '.Activity::DAYS.' days.';
    }

    /** For the page's own view: the things worth a look. */
    public function getViewData(): array
    {
        return ['alerts' => app(Activity::class)->alerts(), 'twoStep' => StaffTwoStep::enabled()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('turnOnTwoStep')
                ->label('Turn on two-step sign-in')
                ->icon('heroicon-o-shield-check')
                ->visible(fn () => ! StaffTwoStep::enabled())
                ->requiresConfirmation()
                ->modalHeading('Turn on two-step sign-in?')
                ->modalDescription('After the password, staff must type a 6-digit code we email them. We send YOU a test email first, and it only switches on if that works. Everyone signed in now will be asked to sign in again. If email ever breaks, whoever looks after the server can switch it off with: php artisan staff:two-step off')
                ->modalSubmitActionLabel('Send a test email and turn it on')
                ->action(function () {
                    $problem = app(StaffTwoStep::class)->turnOn(static::admin());

                    Notification::make()
                        ->title($problem ? 'Not switched on' : 'Two-step sign-in is on')
                        ->body($problem ?? 'Staff now need the emailed code every time they sign in.')
                        ->{$problem ? 'danger' : 'success'}()->persistent((bool) $problem)->send();
                }),
            Action::make('turnOffTwoStep')
                ->label('Turn off two-step sign-in')
                ->icon('heroicon-o-shield-exclamation')
                ->color('gray')
                ->visible(fn () => StaffTwoStep::enabled())
                ->requiresConfirmation()
                ->modalHeading('Turn off two-step sign-in?')
                ->modalDescription('Staff will be able to sign in with just their password again. That is less safe.')
                ->action(function () {
                    app(StaffTwoStep::class)->turnOff(static::admin());
                    Notification::make()->title('Two-step sign-in is off')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(Activity::class)->people())
            ->defaultSort('last_sign_in_at', 'desc')
            ->paginated(false)
            ->columns([
                MobileCard::make(fn (WpUser $u) => [
                    'title' => $u->display_name ?: $u->user_login,
                    'lines' => [
                        $u->last_sign_in_at ? 'Last signed in '.\Illuminate\Support\Carbon::parse($u->last_sign_in_at)->diffForHumans() : 'Has not signed in yet',
                        (int) $u->sign_ins_30.' sign-ins · '.(int) $u->actions_30.' changes in '.Activity::DAYS.' days',
                    ],
                    'badges' => [
                        [StaffRoles::ROLES[$u->staffRole()]['label'] ?? 'No access', $u->staffRole() === 'owner' ? 'primary' : 'info'],
                        (int) $u->failed_30 > 0 ? [(int) $u->failed_30.' failed', 'danger'] : null,
                    ],
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('display_name')->label('Name')->description(fn (WpUser $u) => $u->user_email),
                    Tables\Columns\TextColumn::make('role')->badge()
                        ->state(fn (WpUser $u) => StaffRoles::ROLES[$u->staffRole()]['label'] ?? 'No access')
                        ->color(fn (WpUser $u) => $u->staffRole() === 'owner' ? 'primary' : 'info'),
                    Tables\Columns\TextColumn::make('last_sign_in_at')->label('Last signed in')->since()->placeholder('Never')->sortable(),
                    Tables\Columns\TextColumn::make('sign_ins_30')->label('Sign-ins')->wholeNumber()->sortable(),
                    Tables\Columns\TextColumn::make('failed_30')->label('Failed tries')->wholeNumber()->sortable()
                        ->color(fn ($state) => (int) $state > 0 ? 'danger' : null),
                    Tables\Columns\TextColumn::make('actions_30')->label('Changes made')->wholeNumber()->sortable(),
                    Tables\Columns\TextColumn::make('last_action_at')->label('Last change')->since()->placeholder('None')->sortable(),
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('activity')
                    ->label('See activity')
                    ->icon('heroicon-o-eye')
                    ->slideOver()
                    ->modalHeading(fn (WpUser $record) => ($record->display_name ?: $record->user_login).': sign-ins and changes')
                    ->modalContent(fn (WpUser $record) => view('filament.staff-activity-person', [
                        'signIns' => app(Activity::class)->signIns($record),
                        'actions' => app(Activity::class)->actions($record),
                        'describe' => fn (string $action) => AdminAuditLogResource::describe($action),
                        'details' => fn ($log) => AdminAuditLogResource::details($log),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No staff yet');
    }
}
