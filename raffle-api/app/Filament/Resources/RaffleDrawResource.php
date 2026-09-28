<?php

namespace App\Filament\Resources;

use App\Exceptions\DrawAlreadyRunException;
use App\Exceptions\DrawNotCommittedException;
use App\Exceptions\NoEligibleEntriesException;
use App\Exceptions\NoPrizeStructureException;
use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\RaffleDrawResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Raffle;
use App\Models\RaffleDraw;
use App\Services\AdminAuditLogService;
use App\Services\LiveDrawService;
use App\Services\ProvablyFairDrawService;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md item 44 — running draws. Before this, the
 * provably-fair draw engine could only be run through the JSON API; the
 * old WordPress "GENERATE WINNERS" button is gone with the old site.
 *
 * The three steps, in order, each only offered when it makes sense:
 *  1. Lock draw seed — commits a secret random seed and publishes only
 *     its fingerprint (hash). Best done while tickets are still selling,
 *     so buyers can see the fingerprint was fixed before the draw.
 *  2. Generate winners — runs the draw from that seed and every ticket
 *     sold. Winners start hidden and unpaid (see Winners & payouts).
 *  3. Start live reveal — for raffles with a live-draw event.
 * Anyone can re-check the result on the raffle's public "verify" page.
 * Every step is audit-logged.
 */
class RaffleDrawResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = Raffle::class;

    protected static ?string $slug = 'draws';

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Raffles';

    protected static ?string $navigationLabel = 'Draws';

    protected static ?string $modelLabel = 'draw';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('status', ['published', 'closed']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    private static function drawFor(Raffle $raffle): ?RaffleDraw
    {
        return RaffleDraw::query()->where('raffle_id', $raffle->id)->first();
    }

    /** Still selling tickets — the same single rule the site and checkout use (Raffle::closedReason(), item 43). */
    private static function stillOnSale(Raffle $raffle): bool
    {
        return $raffle->closedReason() === null;
    }

    private static function stageLabel(Raffle $raffle): string
    {
        return match (static::stage($raffle)) {
            'not_locked' => 'Seed not locked',
            'locked' => 'Seed locked',
            default => 'Drawn',
        };
    }

    private static function stageColor(string $label): string
    {
        return match ($label) {
            'Drawn' => 'success',
            'Seed locked' => 'info',
            default => 'gray',
        };
    }

    private static function stage(Raffle $raffle): string
    {
        $draw = static::drawFor($raffle);

        return match (true) {
            ! $draw => 'not_locked',
            ! $draw->hasRun() => 'locked',
            default => 'drawn',
        };
    }

    private static function audit(string $action, Raffle $raffle, array $context = []): void
    {
        app(AdminAuditLogService::class)->record(static::admin(), $action, Raffle::class, $raffle->id, $context);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('expiry', 'asc')
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (Raffle $record) => [
                    'title' => $record->title,
                    'lines' => [
                        $record->grand_prize,
                        $record->soldTickets().' / '.$record->max_tickets.' tickets sold · '.($record->expiry ? 'sales end '.$record->expiry->format('j M') : 'no end date'),
                    ],
                    'badges' => [
                        [static::stageLabel($record), static::stageColor(static::stageLabel($record))],
                        [static::stillOnSale($record) ? 'Still selling' : 'Sales finished', 'gray'],
                    ],
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('title')
                        ->searchable()
                        ->limit(40)
                        ->description(fn (Raffle $record) => $record->grand_prize),
                    Tables\Columns\TextColumn::make('sold')
                        ->label('Tickets sold')
                        ->state(fn (Raffle $record) => $record->soldTickets().' / '.$record->max_tickets),
                    Tables\Columns\TextColumn::make('expiry')
                        ->label('Last day of sales')
                        ->date()
                        ->placeholder('No end date')
                        ->sortable()
                        ->description(fn (Raffle $record) => static::stillOnSale($record) ? 'Still selling' : 'Sales finished'),
                    Tables\Columns\TextColumn::make('stage')
                        ->label('Draw')
                        ->badge()
                        ->state(fn (Raffle $record) => static::stageLabel($record))
                        ->color(fn (string $state) => static::stageColor($state)),
                    Tables\Columns\TextColumn::make('fingerprint')
                        ->label('Fingerprint')
                        ->state(fn (Raffle $record) => static::drawFor($record)?->server_seed_hash)
                        ->limit(14)
                        ->copyable()
                        ->fontFamily('mono')
                        ->placeholder('Not locked yet'),
                ]),
            ])
            ->filters([
                Tables\Filters\Filter::make('not_drawn')
                    ->label('Not drawn yet')
                    ->query(fn (Builder $query) => $query->whereNotIn('id', RaffleDraw::query()->whereNotNull('executed_at')->select('raffle_id')))
                    ->default(),
            ])
            // Actions first: acting on each row is this screen's whole
            // purpose, so the buttons must never be pushed off-screen.
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('lockSeed')
                    ->label('Lock draw seed')
                    ->icon('heroicon-o-lock-closed')
                    ->color('info')
                    ->visible(fn (Raffle $record) => static::stage($record) === 'not_locked')
                    ->requiresConfirmation()
                    ->modalDescription('Fixes a secret random seed for this raffle\'s draw now and publishes only its fingerprint, so everyone can later check the draw used it. Best done while tickets are still selling. It can\'t be changed afterwards.')
                    ->action(fn (Raffle $record) => static::attempt(function () use ($record) {
                        $draw = app(ProvablyFairDrawService::class)->commitSeed($record);
                        static::audit('draw.seed_locked', $record, ['fingerprint' => $draw->server_seed_hash]);
                    }, 'Seed locked. Its fingerprint is now public.')),
                Tables\Actions\Action::make('generateWinners')
                    ->label('Generate winners')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->visible(fn (Raffle $record) => static::stage($record) === 'locked')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Raffle $record) => static::stillOnSale($record) ? '⚠️ This raffle is still selling tickets' : 'Generate the winners?')
                    ->modalDescription(fn (Raffle $record) => (static::stillOnSale($record)
                        ? 'Tickets bought after the draw won\'t take part. Usually you wait until sales finish. '
                        : '')
                        .'Draws winners from all '.$record->soldTickets().' tickets using the locked seed. This can\'t be undone. Winners start hidden and unpaid; review them in "Winners & payouts".')
                    ->modalSubmitActionLabel(fn (Raffle $record) => static::stillOnSale($record) ? 'Draw anyway' : 'Generate winners')
                    ->action(fn (Raffle $record) => static::attempt(function () use ($record) {
                        try {
                            $winners = app(ProvablyFairDrawService::class)->runDraw($record);
                        } catch (NoPrizeStructureException) {
                            throw new RuntimeException('This raffle has no prize tiers yet. Add them under Raffles → edit → Prize tiers, then try again.');
                        } catch (NoEligibleEntriesException) {
                            throw new RuntimeException('No eligible tickets to draw from.');
                        } catch (DrawAlreadyRunException) {
                            throw new RuntimeException('This raffle has already been drawn.');
                        } catch (DrawNotCommittedException) {
                            throw new RuntimeException('Lock the draw seed first.');
                        }

                        static::audit('draw.winners_generated', $record, ['winner_count' => count($winners)]);
                    }, 'Winners generated. Review, pay and publish them in "Winners & payouts".')),
                Tables\Actions\Action::make('startLiveReveal')
                    ->label('Start live reveal')
                    ->icon('heroicon-o-play')
                    ->color('danger')
                    ->visible(fn (Raffle $record) => static::stage($record) === 'drawn' && $record->is_live_draw_enabled && $record->live_draw_status === 'idle')
                    ->requiresConfirmation()
                    ->modalDescription('Everyone on this raffle\'s live-draw page sees the winners revealed one at a time, in sync. This can\'t be undone once started.')
                    ->action(fn (Raffle $record) => static::attempt(function () use ($record) {
                        try {
                            app(LiveDrawService::class)->startReveal($record);
                        } catch (DrawNotCommittedException) {
                            throw new RuntimeException('Generate the winners before starting the live reveal.');
                        }

                        static::audit('draw.live_reveal_started', $record);
                    }, 'Live reveal started.')),
                Tables\Actions\Action::make('verify')
                    ->label('Public proof')
                    ->icon('heroicon-o-shield-check')
                    ->color('gray')
                    ->url(fn (Raffle $record) => url("/raffles/{$record->id}/verify"), shouldOpenInNewTab: true)
                    ->visible(fn (Raffle $record) => static::stage($record) === 'drawn'),
            ])
            ->emptyStateHeading('Nothing to draw')
            ->emptyStateDescription('Published raffles that haven\'t been drawn yet appear here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRaffleDraws::route('/'),
        ];
    }
}
