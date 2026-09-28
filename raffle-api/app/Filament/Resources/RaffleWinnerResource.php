<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\RaffleWinnerResource\Pages;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpPost;
use App\Models\Raffle;
use App\Services\WinnerManagementService;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * OVERHAUL_CHECKLIST.md item 44 — winners and payouts. After a draw,
 * winners start hidden and unpaid: pay each cash prize into the winner's
 * winnings (or mark a physical prize delivered), and choose who shows on
 * the public Hall of Fame. Paying is locked per winner and refuses to run
 * twice (WinnerManagementService); everything is audit-logged.
 */
class RaffleWinnerResource extends Resource
{
    use RunsAdminActions;

    protected static ?string $model = RaffleWinner::class;

    protected static ?string $slug = 'winners';

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'Raffles';

    protected static ?string $navigationLabel = 'Winners & payouts';

    protected static ?string $modelLabel = 'winner';

    protected static ?int $navigationSort = 3;

    /** @var array<int, string>|null raffle number => title, loaded once per page */
    private static ?array $raffleTitles = null;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $unpaid = RaffleWinner::query()->where('is_credited', false)->count();

        return $unpaid > 0 ? (string) $unpaid : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /**
     * Winner rows store the raffle's legacy number (its WordPress post id,
     * or its own id for a raffle created natively).
     *
     * @return array<int, string>
     */
    private static function raffleTitles(): array
    {
        if (static::$raffleTitles === null) {
            $native = Raffle::query()->get(['id', 'legacy_post_id', 'title'])
                ->mapWithKeys(fn (Raffle $r) => [($r->legacy_post_id ?? $r->id) => $r->title])
                ->all();
            $legacy = WpPost::query()->where('post_type', 'raffle')->pluck('post_title', 'ID')->all();

            static::$raffleTitles = $native + $legacy;
        }

        return static::$raffleTitles;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('won_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('raffle_id')
                    ->label('Raffle')
                    ->formatStateUsing(fn (int $state) => static::raffleTitles()[$state] ?? "Raffle #{$state}")
                    ->description(fn (RaffleWinner $record) => "#{$record->raffle_id}"),
                Tables\Columns\TextColumn::make('user.display_name')
                    ->label('Winner')
                    ->description(fn (RaffleWinner $record) => $record->user?->user_email)
                    ->searchable(['display_name', 'user_login', 'user_email']),
                Tables\Columns\TextColumn::make('ticket_number')->label('Ticket')->alignCenter(),
                Tables\Columns\TextColumn::make('prize_name')
                    ->label('Prize')
                    ->description(fn (RaffleWinner $record) => (float) $record->prize_cash_value > 0
                        ? static::naira($record->prize_cash_value)
                        : 'Non-cash prize'),
                Tables\Columns\IconColumn::make('is_credited')->label('Paid')->boolean(),
                Tables\Columns\IconColumn::make('is_visible')->label('Public')->boolean()->tooltip('Shown on the Hall of Fame'),
                Tables\Columns\TextColumn::make('won_at')->label('Won')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_credited')
                    ->label('Paid / delivered')
                    ->trueLabel('Done')
                    ->falseLabel('Still to pay')
                    ->default(false),
                Tables\Filters\TernaryFilter::make('is_visible')->label('On Hall of Fame'),
                Tables\Filters\SelectFilter::make('raffle_id')
                    ->label('Raffle')
                    ->options(fn () => RaffleWinner::query()->distinct()->pluck('raffle_id')
                        ->mapWithKeys(fn ($id) => [$id => static::raffleTitles()[$id] ?? "Raffle #{$id}"])
                        ->all()),
            ])
            // Actions first: acting on each row is this screen's whole
            // purpose, so the buttons must never be pushed off-screen.
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('pay')
                    ->label(fn (RaffleWinner $record) => (float) $record->prize_cash_value > 0
                        ? 'Pay '.static::naira($record->prize_cash_value)
                        : 'Mark prize delivered')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (RaffleWinner $record) => ! $record->is_credited)
                    ->requiresConfirmation()
                    ->modalDescription(fn (RaffleWinner $record) => (float) $record->prize_cash_value > 0
                        ? static::naira($record->prize_cash_value).' goes into '.($record->user?->display_name ?? 'the winner').'\'s winnings, which they can withdraw.'
                        : 'Records that "'.$record->prize_name.'" has been handed over. No money moves.')
                    ->action(fn (RaffleWinner $record) => static::attempt(
                        fn () => app(WinnerManagementService::class)->credit(static::admin(), $record),
                        'Done.',
                    )),
                Tables\Actions\Action::make('show')
                    ->label('Publish')
                    ->tooltip('Show on the public Hall of Fame')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (RaffleWinner $record) => ! $record->is_visible)
                    ->action(fn (RaffleWinner $record) => static::attempt(
                        fn () => app(WinnerManagementService::class)->setVisibility(static::admin(), $record, true),
                        'Now on the Hall of Fame.',
                    )),
                Tables\Actions\Action::make('hide')
                    ->label('Unpublish')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (RaffleWinner $record) => $record->is_visible)
                    ->action(fn (RaffleWinner $record) => static::attempt(
                        fn () => app(WinnerManagementService::class)->setVisibility(static::admin(), $record, false),
                        'Hidden from the Hall of Fame.',
                    )),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('showSelected')
                    ->label('Show selected on Hall of Fame')
                    ->icon('heroicon-o-eye')
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        static::attempt(function () use ($records) {
                            $admin = static::admin();
                            $records->each(fn (RaffleWinner $w) => app(WinnerManagementService::class)->setVisibility($admin, $w, true));
                        }, $records->count().' winner(s) now on the Hall of Fame.');
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('Nothing to pay')
            ->emptyStateDescription('Winners appear here as soon as a draw runs.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRaffleWinners::route('/'),
        ];
    }
}
