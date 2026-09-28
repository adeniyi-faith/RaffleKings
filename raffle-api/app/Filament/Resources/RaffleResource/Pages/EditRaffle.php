<?php

namespace App\Filament\Resources\RaffleResource\Pages;

use App\Filament\Resources\RaffleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRaffle extends EditRecord
{
    protected static string $resource = RaffleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Only a raffle with no tickets and no draw can be deleted (item 45).
            Actions\DeleteAction::make()->visible(fn () => $this->getRecord()->canBeDeleted()),
        ];
    }
}
