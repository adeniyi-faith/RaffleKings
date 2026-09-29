<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\RaffleResource\Pages;
use App\Filament\Resources\RaffleResource\RelationManagers\PrizeTiersRelationManager;
use App\Filament\Support\MobileCard;
use App\Models\Raffle;
use App\Services\LiveDrawService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class RaffleResource extends Resource
{
    use GuardedByStaffRole;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

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
                Forms\Components\Select::make('prize_type')
                    ->label('Prize type')
                    ->options(['cash' => 'Cash', 'gadgets' => 'Gadgets', 'vouchers' => 'Vouchers', 'other' => 'Other'])
                    ->default('other')
                    ->required()
                    ->helperText('Drives the filter chips on the public raffle list.'),
                Forms\Components\Textarea::make('prize_list')
                    ->label('Other prizes ("What You Can Win")')
                    ->rows(3)
                    ->helperText('One prize per line, e.g. "2nd: ₦50,000". Leave empty to list the prize tiers below the grand prize instead.')
                    ->columnSpanFull(),
                Forms\Components\DatePicker::make('expiry')
                    ->label('Last day of sales')
                    ->helperText('Ticket sales stop automatically at the end of this day (Lagos time). Leave empty for no end date.')
                    ->hidden(fn (Forms\Get $get) => (bool) $get('is_flash')),
                // Phase 11 flash raffles: a short raffle with a real countdown.
                Forms\Components\Toggle::make('is_flash')
                    ->label('⚡ Flash raffle')
                    ->helperText('A short raffle that stops selling at an exact time (for example in 1 hour), shown with a live countdown and a Flash badge. Keep the number of tickets small.')
                    ->live()
                    ->inline(false),
                Forms\Components\DateTimePicker::make('sales_end_at')
                    ->label('Sales stop at (exact time)')
                    ->seconds(false)
                    ->timezone(config('raffles.timezone'))
                    ->required(fn (Forms\Get $get) => (bool) $get('is_flash'))
                    ->visible(fn (Forms\Get $get) => (bool) $get('is_flash'))
                    ->helperText('In '.config('raffles.timezone').' time. The countdown customers see ends here.'),
                Forms\Components\Select::make('status')
                    ->options(['draft' => 'Draft (hidden from customers)', 'published' => 'Published (on sale)', 'closed' => 'Closed (visible, not on sale)'])
                    ->default('draft')
                    ->required()
                    ->helperText('Publish to put it on the site. A published raffle still stops selling on its own once it sells out or its last day passes.'),

                Forms\Components\Section::make('Live Draw event')
                    ->description('A raffle can simply show its results, or have a live reveal that viewers watch together. The Hall of Fame is the same either way; this only controls the live-draw page.')
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
                            ->helperText('Legacy livedraw.php\'s red/black high-energy look is the default. Change it only for a themed event.')
                            ->visible(fn (Forms\Get $get) => $get('is_live_draw_enabled')),
                        Forms\Components\Placeholder::make('live_draw_status')
                            ->label('Current reveal status')
                            ->content(fn (?Raffle $record) => $record ? ucfirst($record->live_draw_status) : 'idle')
                            ->visible(fn (Forms\Get $get, ?Raffle $record) => $get('is_live_draw_enabled') && $record),
                    ]),

                // Raffle Rules Engine: every rule here is published on the
                // raffle page and locked into the draw's fairness proof.
                Forms\Components\Section::make('Draw rules')
                    ->description(fn (?Raffle $record) => $record?->drawRulesLocked()
                        ? '🔒 Locked: tickets have been sold or the draw seed is locked, and customers bought under these rules.'
                        : 'Shape how prizes spread. Winners are still picked at random, and every rule here is shown to customers on the raffle page ("How this draw works").')
                    ->collapsible()
                    ->disabled(fn (?Raffle $record) => (bool) $record?->drawRulesLocked())
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('draw_rules.max_wins_per_person')
                            ->label('Most prizes one person can win')
                            ->numeric()->minValue(1)->maxValue(50)
                            ->default(fn () => config('raffles.default_draw_rules.max_wins_per_person'))
                            ->helperText('1 = everyone wins at most one prize.'),
                        Forms\Components\TextInput::make('draw_rules.recent_winner_cooldown_days')
                            ->label('Recent-winner rest (days)')
                            ->numeric()->minValue(0)->maxValue(365)
                            ->default(fn () => config('raffles.default_draw_rules.recent_winner_cooldown_days'))
                            ->helperText('Anyone who won any raffle this many days before the draw sits it out. 0 = off.'),
                        Forms\Components\TextInput::make('draw_rules.top_prize_cooldown_days')
                            ->label('Top-prize rest (days)')
                            ->numeric()->minValue(0)->maxValue(365)
                            ->default(0)
                            ->helperText('A recent top-prize winner can\'t take this top prize (other prizes are fine). 0 = off.'),
                        Forms\Components\Select::make('draw_rules.min_tier')
                            ->label('Who can enter')
                            ->options(['' => 'Everyone', 'silver' => 'Silver members and above', 'gold' => 'Gold members and above', 'diamond' => 'Diamond members only'])
                            ->default(''),
                        Forms\Components\Toggle::make('draw_rules.new_players_only')
                            ->label('New players only')
                            ->helperText('Only people who have never bought a ticket in another raffle.'),
                        Forms\Components\Toggle::make('draw_rules.loyalty_bonus_entries')
                            ->label('Give loyalty bonus entries')
                            ->helperText('Loyal players get free extra entries (numbers set in Settings → Loyalty). They join the draw like tickets.'),
                        Forms\Components\TextInput::make('draw_rules.consolation_min_tickets')
                            ->label('Consolation: tickets needed')
                            ->numeric()->minValue(0)->default(0)
                            ->helperText('Buy at least this many and don\'t win…'),
                        Forms\Components\TextInput::make('draw_rules.consolation_points')
                            ->label('Consolation: reward points')
                            ->numeric()->minValue(0)->default(0)
                            ->helperText('…and get this many points. 0 = off.'),
                    ]),
            ]);
    }

    private static function salesState(Raffle $record): string
    {
        if ($record->status === 'draft') {
            return 'Draft';
        }

        return match ($record->closedReason((int) ($record->sold_count ?? $record->soldTickets()))) {
            null => 'On sale',
            'ended' => 'Ended',
            'sold_out' => 'Sold out',
            default => 'Closed',
        };
    }

    private static function salesColor(string $state): string
    {
        return $state === 'On sale' ? 'success' : ($state === 'Draft' ? 'gray' : 'warning');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('entries as sold_count'))
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (Raffle $record) => [
                    'title' => "#{$record->public_id} · {$record->title}",
                    'amount' => '₦'.number_format((float) $record->price),
                    'lines' => [
                        ((int) ($record->sold_count ?? $record->soldTickets())).' / '.$record->max_tickets.' sold · '.($record->expiry ? 'last day '.$record->expiry->format('j M') : 'no end date'),
                    ],
                    'badges' => [
                        [static::salesState($record), static::salesColor(static::salesState($record))],
                        $record->is_live_draw_enabled ? ['Live draw · '.$record->live_draw_status, 'info'] : null,
                    ],
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('public_id')
                        ->label('No.')
                        ->sortable()
                        ->tooltip('The raffle\'s permanent public number. Its page is /raffles/{number}.'),
                    Tables\Columns\TextColumn::make('title')->searchable()->limit(40)->weight('bold'),
                    Tables\Columns\TextColumn::make('price')->formatStateUsing(fn ($state) => '₦'.number_format((float) $state)),
                    Tables\Columns\TextColumn::make('sold')
                        ->label('Sold')
                        ->state(fn (Raffle $record): string => ((int) ($record->sold_count ?? $record->soldTickets())).' / '.$record->max_tickets),
                    Tables\Columns\TextColumn::make('sales')
                        ->label('Sales')
                        ->badge()
                        ->state(fn (Raffle $record): string => static::salesState($record))
                        ->color(fn ($state): string => static::salesColor((string) $state)),
                    Tables\Columns\TextColumn::make('expiry')->label('Last day')->date('j M Y')->placeholder('No end date')->sortable(),
                    Tables\Columns\TextColumn::make('live_draw')
                        ->label('Live draw')
                        ->visibleFrom('2xl')
                        ->badge()
                        ->state(fn (Raffle $record): string => $record->is_live_draw_enabled ? ucfirst((string) $record->live_draw_status ?: 'idle') : 'Off')
                        ->color(fn ($state): string => match ($state) {
                            'Revealing' => 'warning',
                            'Completed' => 'success',
                            'Off' => 'gray',
                            default => 'info',
                        }),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed']),
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('viewOnSite')
                        ->label('View on site')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (Raffle $record) => url("/raffles/{$record->public_id}"))
                        ->openUrlInNewTab()
                        ->visible(fn (Raffle $record) => in_array($record->status, Raffle::PUBLIC_STATUSES, true)),
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
                ])->label('More')->icon('heroicon-m-ellipsis-vertical')->button()->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Skips (and names) any raffle with tickets or a draw —
                    // deleting those would orphan real customers' tickets
                    // and winners (item 45).
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            [$deletable, $kept] = $records->partition(fn (Raffle $r) => $r->canBeDeleted());
                            $deletable->each->delete();

                            $kept->isEmpty()
                                ? Notification::make()->title($deletable->count().' raffle(s) deleted.')->success()->send()
                                : Notification::make()
                                    ->title($deletable->count().' deleted, '.$kept->count().' kept')
                                    ->body('Kept because they have tickets or a draw: '.$kept->pluck('title')->implode(', ').'. Close them instead.')
                                    ->warning()
                                    ->persistent()
                                    ->send();
                        }),
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
