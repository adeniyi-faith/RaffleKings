<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\Legacy\WpUserResource;
use App\Filament\Resources\TicketResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleWinner;
use App\Models\Raffle;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Raffles → Ticket lookup (item 45b): "who owns ticket 45 in raffle 12?"
 * and "every ticket this person bought" — pick a raffle, type a ticket
 * number (exact) or search a customer. Read-only.
 */
class TicketResource extends Resource
{
    use GuardedByStaffRole;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = RaffleEntry::class;

    protected static ?string $slug = 'tickets';

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationGroup = 'Raffles';

    protected static ?string $navigationLabel = 'Ticket lookup';

    protected static ?string $modelLabel = 'ticket';

    protected static ?int $navigationSort = 4;

    /** @var array<int, string>|null public_id => title */
    private static ?array $titles = null;

    /** @var array<string, true>|null "raffle:ticket" of every winning ticket */
    private static ?array $winning = null;

    public static function raffleTitle(int $publicId): string
    {
        static::$titles ??= Raffle::query()->pluck('title', 'public_id')->all();

        return static::$titles[$publicId] ?? "Raffle #{$publicId}";
    }

    /** @return array<int, string> newest raffle first */
    public static function raffleOptions(): array
    {
        return Raffle::query()->orderByDesc('public_id')->get(['public_id', 'title'])
            ->mapWithKeys(fn (Raffle $r) => [$r->public_id => "#{$r->public_id} · {$r->title}"])
            ->all();
    }

    private static function isWinner(RaffleEntry $entry): bool
    {
        static::$winning ??= RaffleWinner::query()->get(['raffle_id', 'ticket_number'])
            ->mapWithKeys(fn ($w) => ["{$w->raffle_id}:{$w->ticket_number}" => true])->all();

        return isset(static::$winning["{$entry->raffle_id}:{$entry->ticket_number}"]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (RaffleEntry $e) => $e->user ? WpUserResource::getUrl('view', ['record' => $e->user]) : null)
            ->searchPlaceholder('Customer name, username or email')
            ->columns([
                MobileCard::make(fn (RaffleEntry $e) => [
                    'title' => 'Ticket '.$e->ticket_number.' · '.static::raffleTitle($e->raffle_id),
                    'lines' => [($e->user?->display_name ?: $e->user?->user_login ?: "User #{$e->user_id}").' · '.$e->user?->user_email],
                    'badges' => [static::isWinner($e) ? ['Winning ticket', 'success'] : null],
                    'meta' => $e->created_at?->format('j M Y, H:i'),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('ticket_number')->label('Ticket')->weight('bold')->sortable()
                        ->badge()->color(fn (RaffleEntry $e) => static::isWinner($e) ? 'success' : 'gray')
                        ->tooltip(fn (RaffleEntry $e) => static::isWinner($e) ? 'Winning ticket' : null),
                    Tables\Columns\TextColumn::make('raffle_id')->label('Raffle')->formatStateUsing(fn ($state) => static::raffleTitle((int) $state))->description(fn (RaffleEntry $e) => "#{$e->raffle_id}"),
                    Tables\Columns\TextColumn::make('user.display_name')->label('Owner')
                        ->description(fn (RaffleEntry $e) => $e->user?->user_email)
                        ->searchable(['display_name', 'user_login', 'user_email']),
                    Tables\Columns\TextColumn::make('created_at')->label('Bought')->dateTime('j M Y, H:i')->sortable(),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('raffle_id')->label('Raffle')->options(fn () => static::raffleOptions())->searchable(),
                Tables\Filters\Filter::make('ticket_number')
                    ->form([Forms\Components\TextInput::make('number')->label('Ticket number')->integer()->minValue(1)])
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['number'] ?? null), fn ($q) => $q->where('ticket_number', (int) $data['number'])))
                    ->indicateUsing(fn (array $data) => filled($data['number'] ?? null) ? 'Ticket '.$data['number'] : null),
                Tables\Filters\TernaryFilter::make('won')
                    ->label('Winning tickets')
                    ->placeholder('All tickets')
                    ->trueLabel('Only winning tickets')
                    ->falseLabel('Only non-winning')
                    ->queries(
                        true: fn (Builder $query) => $query->whereExists(fn ($s) => $s->from((new RaffleWinner)->getTable().' as w')->whereColumn('w.raffle_id', (new RaffleEntry)->getTable().'.raffle_id')->whereColumn('w.ticket_number', (new RaffleEntry)->getTable().'.ticket_number')),
                        false: fn (Builder $query) => $query->whereNotExists(fn ($s) => $s->from((new RaffleWinner)->getTable().' as w')->whereColumn('w.raffle_id', (new RaffleEntry)->getTable().'.raffle_id')->whereColumn('w.ticket_number', (new RaffleEntry)->getTable().'.ticket_number')),
                    ),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'md' => 3])
            ->emptyStateHeading('No tickets match')
            ->emptyStateDescription('Pick a raffle and type a ticket number, or search for a customer.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
        ];
    }
}
