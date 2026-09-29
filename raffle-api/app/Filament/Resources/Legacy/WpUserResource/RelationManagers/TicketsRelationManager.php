<?php

namespace App\Filament\Resources\Legacy\WpUserResource\RelationManagers;

use App\Filament\Resources\TicketResource;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\RaffleEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Every ticket this customer holds, newest first. */
class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Tickets';

    protected static ?string $icon = 'heroicon-o-ticket';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                MobileCard::make(fn (RaffleEntry $e) => [
                    'title' => TicketResource::raffleTitle($e->raffle_id),
                    'amount' => 'No. '.$e->ticket_number,
                    'meta' => $e->created_at?->format('j M Y, H:i'),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('raffle_id')->label('Raffle')->formatStateUsing(fn ($state) => TicketResource::raffleTitle((int) $state))->description(fn (RaffleEntry $e) => "#{$e->raffle_id}"),
                    Tables\Columns\TextColumn::make('ticket_number')->label('Ticket no.')->weight('bold')->sortable(),
                    Tables\Columns\TextColumn::make('created_at')->label('Bought')->dateTime('j M Y, H:i')->sortable(),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('raffle_id')->label('Raffle')->options(fn () => TicketResource::raffleOptions()),
            ])
            ->emptyStateHeading('No tickets yet');
    }
}
