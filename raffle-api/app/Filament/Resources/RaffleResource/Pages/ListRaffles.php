<?php

namespace App\Filament\Resources\RaffleResource\Pages;

use App\Filament\Pages\RaffleAdvisor;
use App\Filament\Resources\RaffleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRaffles extends ListRecords
{
    protected static string $resource = RaffleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('advisor')
                ->label('Ask the advisor')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->url(RaffleAdvisor::getUrl())
                ->visible(fn () => RaffleAdvisor::canAccess()),
            Actions\CreateAction::make(),
        ];
    }
}
