<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Filament\Resources\BroadcastResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBroadcasts extends ListRecords
{
    protected static string $resource = BroadcastResource::class;

    protected static ?string $title = 'Message customers';

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New message')->icon('heroicon-o-pencil-square')];
    }
}
