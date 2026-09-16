<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RaffleResource\Pages;
use App\Filament\Resources\RaffleResource\RelationManagers\PrizeTiersRelationManager;
use App\Models\Raffle;
use App\Services\LiveDrawService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use RuntimeException;

class RaffleResource extends Resource
{
    protected static ?string $model = Raffle::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Raffles';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('excerpt')
                    ->maxLength(2000)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('price')
                    ->label('Ticket price (₦)')
                    ->required()
                    ->numeric()
                    ->minValue(0),
                Forms\Components\TextInput::make('max_tickets')
                    ->required()
                    ->numeric()
                    ->minValue(1),
                Forms\Components\TextInput::make('grand_prize')
                    ->maxLength(255),
                Forms\Components\DatePicker::make('expiry'),
                Forms\Components\Select::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed'])
                    ->default('draft')
                    ->required()
                    ->helperText('Admin intent only — a raffle is separately treated as sold out once every ticket number has actually been bought (never inferred from this alone).'),

                Forms\Components\Section::make('Live Draw event')
                    ->description('Item 27 — a raffle can just show static results, or have a live, synchronized reveal event. Draw results (Hall of Fame) are unaffected either way; this only controls the live-draw page/experience.')
                    ->schema([
                        Forms\Components\Toggle::make('is_live_draw_enabled')
                            ->label('Enable a live-draw event for this raffle')
                            ->helperText('Off = the raffle only ever shows static winners (no /live-draw page reveal event).')
                            ->live(),
                        Forms\Components\DateTimePicker::make('live_draw_scheduled_at')
                            ->label('Scheduled start (shown to viewers before it goes live)')
                            ->visible(fn (Forms\Get $get) => $get('is_live_draw_enabled')),
                        Forms\Components\Select::make('live_draw_pace_ms')
                            ->label('Reveal pacing (per winner)')
                            ->options([
                                800 => 'Fast (0.8s)',
                                1500 => 'Normal (1.5s)',
                                2500 => 'Slow (2.5s)',
                                4000 => 'Dramatic (4s)',
                            ])
                            ->default(1500)
                            ->visible(fn (Forms\Get $get) => $get('is_live_draw_enabled')),
                        Forms\Components\ColorPicker::make('live_draw_theme_color')
                            ->label('Accent color')
                            ->default('#dc2626')
                            ->helperText('Legacy livedraw.php\'s red/black high-energy look is the default — change only for a themed event.')
                            ->visible(fn (Forms\Get $get) => $get('is_live_draw_enabled')),
                        Forms\Components\Placeholder::make('live_draw_status')
                            ->label('Current reveal status')
                            ->content(fn (?Raffle $record) => $record ? ucfirst($record->live_draw_status) : 'idle')
                            ->visible(fn (Forms\Get $get, ?Raffle $record) => $get('is_live_draw_enabled') && $record),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('price')->money('NGN'),
                Tables\Columns\TextColumn::make('max_tickets')->label('Max tickets'),
                Tables\Columns\TextColumn::make('sold')
                    ->label('Sold')
                    ->state(fn (Raffle $record): int => $record->soldTickets()),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'published',
                        'danger' => 'closed',
                    ]),
                Tables\Columns\TextColumn::make('expiry')->date(),
                Tables\Columns\IconColumn::make('is_live_draw_enabled')
                    ->label('Live draw')
                    ->boolean(),
                Tables\Columns\BadgeColumn::make('live_draw_status')
                    ->label('Reveal')
                    ->colors([
                        'gray' => 'idle',
                        'warning' => 'revealing',
                        'success' => 'completed',
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('startLiveReveal')
                    ->label('Start live reveal')
                    ->icon('heroicon-o-play')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Every viewer currently on this raffle\'s live-draw page will see winners revealed in real time, one at a time. This cannot be undone once started.')
                    ->visible(fn (Raffle $record) => $record->is_live_draw_enabled && $record->live_draw_status !== 'revealing')
                    ->action(function (Raffle $record) {
                        try {
                            app(LiveDrawService::class)->startReveal($record);
                            Notification::make()->title('Live reveal started')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Could not start reveal')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PrizeTiersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRaffles::route('/'),
            'create' => Pages\CreateRaffle::route('/create'),
            'edit' => Pages\EditRaffle::route('/{record}/edit'),
        ];
    }
}
