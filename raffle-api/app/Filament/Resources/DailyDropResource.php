<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\DailyDropResource\Pages;
use App\Filament\Resources\DailyDropResource\RelationManagers\RunsRelationManager;
use App\Models\DailyDrop;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Services\Engagement\DailyDrops;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use RuntimeException;

/**
 * Raffles → Daily Drops. Set up a drop on a raffle, then switch it on.
 * Switching on (it pays real money every day) needs the "pay money"
 * permission; anyone with raffle access can prepare and pause one.
 */
class DailyDropResource extends Resource
{
    use GuardedByStaffRole;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = DailyDrop::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Raffles';

    protected static ?string $navigationLabel = 'Daily Drops';

    protected static ?string $modelLabel = 'Daily Drop';

    public static function form(Form $form): Form
    {
        $locked = fn (?DailyDrop $record) => in_array($record?->status, ['active', 'ended'], true);

        return $form->schema([
            Forms\Components\Placeholder::make('how')
                ->label('How it works')
                ->content('Every day at the drop time, a share of the ticket money this raffle took since the last drop is paid into the winnings of randomly picked ticket holders. Every ticket is one equal chance, and one person can win at most one share a day. The pick is locked in advance and checkable, like the main draw. Winners keep their tickets in the main draw.')
                ->columnSpanFull(),
            Forms\Components\Select::make('raffle_id')
                ->label('Raffle')
                ->options(fn () => Raffle::query()->whereNull('cancelled_at')->whereIn('status', ['draft', 'published'])->latest('id')->limit(100)->get()->mapWithKeys(fn (Raffle $r) => [$r->id => "#{$r->public_id} · {$r->title}".($r->status === 'draft' ? ' (draft)' : '')]))
                ->searchable()
                ->helperText('A drop on a draft raffle starts paying once the raffle is published.')
                ->disabled($locked),
            Forms\Components\TextInput::make('pot_percent')
                ->label('Share of new ticket sales (%)')
                ->numeric()->required()->minValue(0.1)->maxValue(50)->default(10)
                ->helperText('e.g. 10 = a tenth of each day\'s sales goes into that day\'s drop.')
                ->disabled($locked),
            Forms\Components\TextInput::make('daily_cap')
                ->label('Most paid out in one day (₦, optional)')
                ->numeric()->minValue(1)
                ->disabled($locked),
            Forms\Components\TextInput::make('winners_per_day')
                ->label('Winners each day')
                ->numeric()->integer()->required()->minValue(1)->maxValue(100)->default(3)
                ->helperText('The day\'s pot is split equally between them.')
                ->disabled($locked),
            Forms\Components\Select::make('drop_time')
                ->label('Drop time ('.config('raffles.timezone').')')
                ->options(collect(range(8, 23))->mapWithKeys(fn ($h) => [sprintf('%02d:00', $h) => sprintf('%02d:00', $h)]))
                ->default('20:00')->required()
                ->disabled($locked),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('raffle')->withSum('runs as paid_total', 'pot'))
            ->columns([
                Tables\Columns\TextColumn::make('raffle.title')->label('Raffle')->placeholder('Not chosen yet')->limit(40)->weight('bold'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => DailyDrop::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'paused' => 'warning', default => 'gray'
                    }),
                Tables\Columns\TextColumn::make('rule')->label('Each day')
                    ->state(fn (DailyDrop $r) => rtrim(rtrim(number_format($r->pot_percent, 2), '0'), '.').'% of sales to '.$r->winners_per_day.' winner(s) at '.$r->drop_time.($r->daily_cap ? ', up to ₦'.number_format($r->daily_cap) : '')),
                Tables\Columns\TextColumn::make('paid_total')->label('Paid so far')->formatStateUsing(fn ($state) => '₦'.number_format((float) $state))->placeholder('₦0'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (DailyDrop $r) => $r->status !== 'ended'),
                Tables\Actions\Action::make('activate')
                    ->label(fn (DailyDrop $r) => $r->status === 'paused' ? 'Resume' : 'Switch on')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (DailyDrop $r) => in_array($r->status, ['draft', 'paused'], true) && static::staffCan('money.pay'))
                    ->requiresConfirmation()
                    ->modalDescription('From now on this drop pays real money into winners\' accounts every day at the drop time. Players see the rules on the raffle page.')
                    ->action(function (DailyDrop $record) {
                        try {
                            app(DailyDrops::class)->activate($record, static::admin());
                            Notification::make()->title('Daily Drop is running')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Not switched on')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('pause')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn (DailyDrop $r) => $r->status === 'active')
                    ->requiresConfirmation()
                    ->modalDescription('No drops are paid while it is paused. Sales keep counting, so the next drop after you resume includes them.')
                    ->action(fn (DailyDrop $record) => app(DailyDrops::class)->pause($record, static::admin())),
            ]);
    }

    private static function admin(): WpUser
    {
        return auth('wordpress')->user();
    }

    public static function getRelations(): array
    {
        return [RunsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyDrops::route('/'),
            'create' => Pages\CreateDailyDrop::route('/create'),
            'edit' => Pages\EditDailyDrop::route('/{record}/edit'),
        ];
    }
}
