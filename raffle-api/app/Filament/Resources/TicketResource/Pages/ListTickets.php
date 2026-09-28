<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use Filament\Resources\Pages\ListRecords;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    protected static ?string $title = 'Ticket lookup';

    public function getSubheading(): ?string
    {
        return 'Who owns a ticket? Pick the raffle and type the number. Or search a customer to see every ticket they hold. Tap a ticket to open its owner.';
    }
}
